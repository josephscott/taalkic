<?php
declare( strict_types = 1 );

namespace Taalkic;

use FastRoute\Dispatcher;
use Laminas\Escaper\Escaper;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

class App {
	// Shared with the helper functions ( esc_*, template ) via App::charset
	// and App::template_dir, so they are static.
	public static string $charset = 'utf-8';

	public static string $template_dir = '';

	// Created once, on first use, from the charset above and shared by the
	// esc_* helper functions via App::escaper().
	private static ?Escaper $escaper = null;

	// Set just before a template is included, so the included file's scope
	// holds only $data and not the template path ( see render_template() ).
	private static string $template_file = '';

	// Set just before a route file is run, so the route's scope holds only
	// $here and not the route path ( see run_route() ).
	private static string $route_file = '';

	// Set just before the routes file is loaded, so its scope holds only
	// $router and not the routes path ( see load_router() ).
	private static string $routes_file = '';

	// The resolved worker count, computed from the 'workers' config in the
	// constructor ( see worker_count() ).
	private int $workers = 2;

	private int $port = 4200;

	// Path to the file that registers the URL routes. It is loaded inside each
	// worker ( see run() ) so a reload picks up changes to it.
	private string $routes_path = '';

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
			$router = $this->load_router();
			$dispatcher = $router->dispatcher();

			$worker->onMessage = function( TcpConnection $connection, Request $request ) use ( $router, $dispatcher ): void {
				$connection->send( $this->handle( $dispatcher, $router, $request ) );
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

		Worker::runAll();
	}

	// Build a fresh router by loading the routes file in an isolated scope
	// where only $router is available. The path is held on a static property
	// so it is not a local variable, and therefore not in scope, when the
	// routes file is included.
	private function load_router(): Router {
		$router = new Router();

		self::$routes_file = $this->routes_path;
		$load = static function( Router $router ): void {
			include self::$routes_file;
		};
		$load( $router );

		$this->warn_missing_files( $router );

		return $router;
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
	private function handle( Dispatcher $dispatcher, Router $router, Request $request ): Response {
		$method = $request->method();
		$path = $request->path();
		$route_info = $dispatcher->dispatch( $method, $path );

		// A HEAD request with no explicit HEAD route falls back to the GET route.
		if ( $method === 'HEAD' && $route_info[0] !== Dispatcher::FOUND ) {
			$route_info = $dispatcher->dispatch( 'GET', $path );
		}

		$response = $this->route_response( $route_info, $router, $request );

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

	// Run a route file in an isolated scope where only $here is available, and
	// use its captured output as the response body. The route can also mutate
	// the response via $here->response. The path is held on a static property
	// so it is not a local variable, and therefore not in scope, when the route
	// file is included.
	/**
	 * @param array<string, string> $params
	 */
	private function run_route( string $file, array $params, Request $request ): Response {
		// A route can be declared for a callback file that does not exist. That
		// is a server-side misconfiguration, so return a 500 rather than the
		// blank 200 an empty include() would otherwise produce.
		if ( ! is_file( $file ) ) {
			error_log( "Taalkic\\App: route callback file not found: {$file}" );
			return $this->plain_error( 500 );
		}

		// Routes start at 200 by default and can change it via $here->response.
		$response = new Response( 200 );
		$here = new Here( $request, $response, $params );

		self::$route_file = $file;
		$run = static function( Here $here ): void {
			include self::$route_file;
		};

		ob_start();
		$run( $here );
		$body = (string) ob_get_clean();

		$response->withBody( $body );

		return $response;
	}

	// Build an error response. The registered handler file is run like a route;
	// when no handler is declared, a plain error page is returned instead.
	private function error_response( int $status, string $handler_file, Request $request ): Response {
		// Fall back to the plain error page when no handler is declared, or when
		// one is declared but its file is missing, so a missing handler still
		// returns its own status ( e.g. 404 ) rather than a 500.
		if ( ! is_file( $handler_file ) ) {
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

	// Render a template in an isolated scope where only $data is available.
	// The path is held on a static property so it is not a local variable,
	// and therefore not in scope, when the template is included.
	/**
	 * @param array<string, mixed> $data
	 */
	public static function render_template( string $file_path, array $data ): void {
		self::$template_file = self::$template_dir . $file_path;

		$render = static function( array $data ): void {
			include self::$template_file;
		};
		$render( $data );
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
