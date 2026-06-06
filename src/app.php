<?php
declare( strict_types = 1 );

namespace Taalkic;

use FastRoute\Dispatcher;
use Laminas\Escaper\Escaper;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

class App {
	// Shared with the helper functions ( esc_*, template ) via App::charset
	// and App::template_dir, so they are static.
	public static string $charset = 'utf-8';

	public static string $template_dir = '';

	// Created once, on first use, from the charset above and shared by the
	// esc_* helper functions via App::escaper().
	private static ?Escaper $escaper = null;

	// The paths for the routes file, route callbacks, and templates are held on
	// the Scope holder ( see scope.php ), which is where they are included, so the
	// included files run with no App class scope and cannot reach the statics
	// here. They are not kept on App.

	// Memoized is_file() results for callback files, keyed by path. The route
	// hot path runs once per request, so caching this avoids a filesystem stat
	// on every request ( see file_exists() ). It lives for the life of the
	// worker; a reload re-forks the worker and starts with a fresh cache.
	/** @var array<string, bool> */
	private static array $file_exists = [];

	// The resolved worker count, computed from the 'workers' config in the
	// constructor ( see worker_count() ).
	private int $workers = 2;

	private int $port = 4200;

	// Path to the file that registers the URL routes. It is loaded inside each
	// worker ( see run() ) so a reload picks up changes to it.
	private string $routes_path = '';

	// Paths to watch in development. When set, a monitor process reloads the
	// workers on any change ( see start_watching() ). Empty in production.
	/** @var array<int, string> */
	private array $watch = [];

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$required = [ 'routes', 'template_dir' ];
		foreach ( $required as $key ) {
			if ( ! isset( $config[$key] ) ) {
				$this->fail( "Taalkic\\App: missing required config arg '{$key}'" );
			}
		}

		$workers = 'half'; // default
		if ( isset( $config['workers'] ) ) {
			$workers = $config['workers'];
		}
		$this->workers = $this->worker_count( $workers );

		if ( isset( $config['port'] ) ) {
			$this->port = $config['port'];
		}

		if ( isset( $config['charset'] ) ) {
			self::$charset = $config['charset'];
		}

		if ( isset( $config['watch'] ) ) {
			$this->watch = $config['watch'];
		}

		// routes and template_dir are required, so they are always present here.
		$this->routes_path = $config['routes'];
		self::$template_dir = $config['template_dir'];
	}

	// Start the server. Bind only to 127.0.0.1; in production taalkic runs
	// behind a web server like Nginx, which handles TLS termination.
	public function run(): void {
		$worker = new Worker( 'http://127.0.0.1:' . $this->port );
		$worker->count = $this->workers;

		// Load the routes inside the worker, not the master. A reload re-forks
		// the workers and re-runs onWorkerStart, so changes to the routes file,
		// the route callbacks, and the templates are all picked up. The master
		// is never re-executed, so anything built before runAll() would be
		// frozen for the life of the server.
		$worker->onWorkerStart = function( Worker $worker ): void {
			// A reload re-forks the worker and re-runs this. When the opcode
			// cache is frozen ( the production setting, see the Makefile ), the
			// routes file would otherwise be served from the cache, so force it
			// to recompile from disk before it is loaded. The callbacks and
			// templates are handled below, once the router lists them.
			$this->fresh_compile( [ $this->routes_path ] );

			$router = $this->load_router();
			$dispatcher = $router->dispatcher();
			$static_map = $router->static_map();

			// The route callbacks and templates are included per request, so
			// force them to recompile too, so a reload picks up their changes.
			// taalkic's own src/ files are left cached; changing those needs a
			// full restart, which re-execs the master with a fresh cache.
			$this->fresh_compile( $this->app_files( $router ) );

			$worker->onMessage = function( TcpConnection $connection, Request $request ) use ( $router, $dispatcher, $static_map ): void {
				// A route can throw. Workerman has no error handler set here, so an
				// exception out of onMessage reaches TcpConnection::error(), which
				// calls Worker::stopAll() and takes the whole worker down — a remote
				// DoS where one bad request kills every in-flight request on the
				// worker. Catch it, log it, and return a 500 instead so the worker
				// keeps serving.
				try {
					$response = $this->handle( $dispatcher, $static_map, $router, $request );
				} catch ( \Throwable $e ) {
					error_log(
						'Taalkic\\App: uncaught ' . $e::class . ' handling '
						. $request->method() . ' ' . $request->path() . ': '
						. $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
					);
					$response = $this->plain_error( 500 );
				}

				$connection->send( $response );
			};
		};

		// A graceful reload waits for every connection on a worker to close
		// before the worker exits, and it does not close idle ones itself. An
		// idle keep-alive connection ( a browser, or Nginx's upstream pool )
		// would then pin the worker open until the keep-alive timeout, stalling
		// the whole reload. Close idle connections here so the worker exits
		// right away. A connection that is part way through receiving a request
		// is left to finish, and close() flushes any buffered response before
		// the connection is closed, so no request is dropped.
		$worker->onWorkerStop = function( Worker $worker ): void {
			foreach ( $worker->connections as $connection ) {
				if ( $connection->getRecvBufferQueueSize() === 0 ) {
					$connection->close();
				}
			}
		};

		if ( $this->watch !== [] ) {
			$this->start_watching();
		}

		Worker::runAll();
	}

	// Run a monitor process that watches the configured paths and reloads the
	// workers whenever a file changes, for `make dev`. It is a separate worker
	// with no socket, marked not reloadable so the reload it triggers does not
	// also restart it. Route callbacks and templates are included per request,
	// so they already pick up edits on their own; the reload is what makes a
	// changed routes file ( and the framework's own classes ) take effect.
	private function start_watching(): void {
		$monitor = new Worker();
		$monitor->name = 'taalkic-watch';
		$monitor->reloadable = false;

		$paths = $this->watch;
		$monitor->onWorkerStart = function() use ( $paths ): void {
			$signature = $this->watch_signature( $paths );

			Timer::add( 1, function() use ( $paths, &$signature ): void {
				$current = $this->watch_signature( $paths );
				if ( $current !== $signature ) {
					$signature = $current;
					// Ask the master ( this process's parent ) to reload.
					posix_kill( posix_getppid(), SIGUSR1 );
				}
			} );
		};
	}

	// A snapshot of the watched PHP files as a map of path to modified time.
	// Comparing two snapshots detects added, removed, and changed files.
	/**
	 * @param array<int, string> $paths
	 * @return array<string, int>
	 */
	private function watch_signature( array $paths ): array {
		// The monitor is long-lived, so clear PHP's stat cache each scan or
		// filemtime() would keep returning the value from the first scan.
		clearstatcache();

		$signature = [];
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				$signature[$path] = (int) filemtime( $path );
				continue;
			}

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $files as $file ) {
				if ( $file->isFile() && $file->getExtension() === 'php' ) {
					$signature[$file->getPathname()] = (int) $file->getMTime();
				}
			}
		}

		return $signature;
	}

	// Build a fresh router by loading the routes file in an isolated scope where
	// only $router is available. The include happens in a free function ( see
	// scope.php ), so the routes file gets neither the path as a local nor any
	// access to App's internals through self::.
	private function load_router(): Router {
		$router = new Router();

		Scope::$routes_file = $this->routes_path;
		include_routes( $router );

		$this->warn_missing_files( $router );

		return $router;
	}

	// Force the given files to recompile from disk on their next include, so a
	// reload picks up their changes even when the opcode cache is frozen
	// ( opcache.validate_timestamps off, the fastest production setting ). It is
	// a no-op when timestamps are validated ( dev, where OPcache revalidates on
	// its own ) or when OPcache is not loaded.
	/**
	 * @param array<int, string> $files
	 */
	private function fresh_compile( array $files ): void {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}

		// ini_get returns a falsy string ( '' or '0' ) when the directive is
		// off, so this returns early whenever timestamps are validated.
		if ( ini_get( 'opcache.validate_timestamps' ) ) {
			return;
		}

		foreach ( $files as $file ) {
			opcache_invalidate( $file, true );
		}
	}

	// The application files a reload should pick up: every route callback, the
	// error handlers, and every template under template_dir. The routes file is
	// handled separately ( it has to be fresh before it is loaded ).
	/**
	 * @return array<int, string>
	 */
	private function app_files( Router $router ): array {
		$files = [];

		foreach ( $router->routes() as $route ) {
			$files[] = $route['file'];
		}

		$handlers = [ $router->handler_404(), $router->handler_405() ];
		foreach ( $handlers as $handler ) {
			if ( $handler !== '' ) {
				$files[] = $handler;
			}
		}

		if ( is_dir( self::$template_dir ) ) {
			$templates = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( self::$template_dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $templates as $template ) {
				if ( $template->isFile() && $template->getExtension() === 'php' ) {
					$files[] = $template->getPathname();
				}
			}
		}

		return $files;
	}

	// Log any declared route or error-handler file that does not exist. This
	// runs when the routes are loaded ( on worker start, so also on reload ),
	// surfacing a typo right away instead of only on the first matching
	// request. It only warns; one bad path should not stop the server.
	private function warn_missing_files( Router $router ): void {
		foreach ( $router->routes() as $route ) {
			if ( ! is_file( $route['file'] ) ) {
				error_log( "Taalkic\\App: route callback file not found for {$route['method']} {$route['path']}: {$route['file']}" );
			}
		}

		$handlers = [
			404 => $router->handler_404(),
			405 => $router->handler_405(),
		];
		foreach ( $handlers as $status => $file ) {
			if ( $file !== '' && ! is_file( $file ) ) {
				error_log( "Taalkic\\App: {$status} handler file not found: {$file}" );
			}
		}
	}

	// Turn a single request into a response.
	/**
	 * @param array<string, array<string, string>> $static_map
	 */
	private function handle( Dispatcher $dispatcher, array $static_map, Router $router, Request $request ): Response {
		$method = $request->method();
		$path = $request->path();

		// Fast path: an exact static-route match skips FastRoute entirely. A
		// HEAD request with no HEAD route falls back to the GET route, which is
		// what FastRoute does. Variable routes, 404, and 405 are not in the map
		// and fall through to the dispatcher below.
		$file = ''; // default
		if ( isset( $static_map[$method][$path] ) ) {
			$file = $static_map[$method][$path];
		}
		if ( $file === '' && $method === 'HEAD' && isset( $static_map['GET'][$path] ) ) {
			$file = $static_map['GET'][$path];
		}

		$response = null; // default
		if ( $file !== '' ) {
			$response = $this->run_route( $file, [], $request );
		}

		// FastRoute already falls a HEAD request back to the GET route when no
		// HEAD route is declared, so no failover is needed here. It does not
		// strip the body, though, so that is handled below.
		if ( $response === null ) {
			$route_info = $dispatcher->dispatch( $method, $path );
			$response = $this->route_response( $route_info, $router, $request );
		}

		// A HEAD response carries no body, per the HTTP spec.
		if ( $method === 'HEAD' ) {
			$response->withBody( '' );
		}

		return $response;
	}

	// Map a FastRoute dispatch result to a response.
	/**
	 * @param array<int, mixed> $route_info
	 */
	private function route_response( array $route_info, Router $router, Request $request ): Response {
		if ( $route_info[0] === Dispatcher::NOT_FOUND ) {
			return $this->error_response( 404, $router->handler_404(), $request );
		}

		if ( $route_info[0] === Dispatcher::METHOD_NOT_ALLOWED ) {
			return $this->error_response( 405, $router->handler_405(), $request );
		}

		// FOUND: $route_info[1] is the route file, $route_info[2] its params.
		$file = $route_info[1];
		$params = $route_info[2];
		return $this->run_route( $file, $params, $request );
	}

	// Cached is_file() for the request hot path. The first check for a path
	// stats the disk; later checks reuse the result for the life of the worker,
	// so a matched route does not stat the disk on every request. A reload
	// re-forks the worker, which starts with an empty cache and stats afresh.
	private static function file_exists( string $file ): bool {
		if ( ! isset( self::$file_exists[$file] ) ) {
			self::$file_exists[$file] = is_file( $file );
		}

		return self::$file_exists[$file];
	}

	// Run a route file in an isolated scope where only $here is available, and
	// use its captured output as the response body. The route can also mutate
	// the response via $here->response. The include happens in a free function
	// ( see scope.php ), so the route file gets neither the path as a local nor
	// any access to App's internals through self::.
	/**
	 * @param array<string, string> $params
	 */
	private function run_route( string $file, array $params, Request $request ): Response {
		// A route can be declared for a callback file that does not exist. That
		// is a server-side misconfiguration, so return a 500 rather than the
		// blank 200 an empty include() would otherwise produce.
		if ( ! self::file_exists( $file ) ) {
			error_log( "Taalkic\\App: route callback file not found: {$file}" );
			return $this->plain_error( 500 );
		}

		// Routes start at 200 by default and can change it via $here->response.
		$response = new Response( 200 );
		$here = new Here( $request, $response, $params );

		Scope::$route_file = $file;

		// Capture the route's output. If the route throws, the finally still
		// closes the buffer this opened, so a thrown route does not leak an open
		// output buffer ( which, in a long-lived worker, would let one request's
		// partial output bleed into a later request ). The exception then
		// propagates to onMessage, which turns it into a 500.
		ob_start();
		try {
			include_route( $here );
		} finally {
			$body = (string) ob_get_clean();
		}

		$response->withBody( $body );

		return $response;
	}

	// Build an error response. The registered handler file is run like a route;
	// when no handler is declared, a plain error page is returned instead.
	private function error_response( int $status, string $handler_file, Request $request ): Response {
		// Fall back to the plain error page when no handler is declared, or when
		// one is declared but its file is missing, so a missing handler still
		// returns its own status ( e.g. 404 ) rather than a 500.
		if ( ! self::file_exists( $handler_file ) ) {
			return $this->plain_error( $status );
		}

		// Run the handler like a route, then force the error status so it is
		// always correct, even if the handler file does not set it itself.
		$response = $this->run_route( $handler_file, [], $request );
		$response->withStatus( $status );

		return $response;
	}

	// A minimal text error page, used when no error handler is declared.
	private function plain_error( int $status ): Response {
		$response = new Response( $status );
		$response->withHeader( 'Content-Type', 'text/plain' );
		$response->withBody( $status . ' ' . $this->reason_phrase( $status ) );

		return $response;
	}

	// The HTTP reason phrase for the error statuses taalkic returns itself.
	private function reason_phrase( int $status ): string {
		$phrases = [
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			500 => 'Internal Server Error',
		];

		$phrase = 'Error';
		if ( isset( $phrases[$status] ) ) {
			$phrase = $phrases[$status];
		}

		return $phrase;
	}

	// Shared escaper for the esc_* helpers, built once from App::$charset.
	public static function escaper(): Escaper {
		if ( self::$escaper === null ) {
			self::$escaper = new Escaper( self::$charset );
		}

		return self::$escaper;
	}

	// Render a template in an isolated scope where only $data is available. The
	// include happens in a free function ( see scope.php ), so the template gets
	// neither the path as a local nor any access to App's internals through
	// self::.
	/**
	 * @param array<string, mixed> $data
	 */
	public static function render_template( string $file_path, array $data ): void {
		Scope::$template_file = self::$template_dir . $file_path;
		include_template( $data );
	}

	// Resolve the configured 'workers' value into an actual worker count.
	// A specific integer is used as-is; 'half' uses half of the CPU cores.
	private function worker_count( string|int $workers ): int {
		$count = 2;
		if ( is_int( $workers ) ) {
			$count = $workers;
		}

		// 'half': half of the available cores, rounded down.
		if ( $workers === 'half' ) {
			$count = intdiv( $this->cpu_cores(), 2 );
		}

		// The minimum number of workers is 2.
		if ( $count < 2 ) {
			$count = 2;
		}

		return $count;
	}

	// Count the CPU cores on the host. Only macOS and Linux are supported.
	private function cpu_cores(): int {
		$command = 'nproc'; // Linux
		if ( PHP_OS_FAMILY === 'Darwin' ) {
			$command = 'sysctl -n hw.ncpu';
		}

		$cores = (int) trim( (string) shell_exec( $command ) );
		if ( $cores < 1 ) {
			$cores = 1;
		}

		return $cores;
	}

	// Write the message to the error log, then exit with that same message.
	private function fail( string $message ): never {
		error_log( $message );
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
