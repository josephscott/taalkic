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

	// The resolved worker count, computed from the 'workers' config in the
	// constructor ( see worker_count() ).
	private int $workers = 2;

	private int $port = 4200;

	private Router $router;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$required = [ 'router', 'template_dir' ];
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

		// router and template_dir are required, so they are always present here.
		$this->router = $config['router'];
		self::$template_dir = $config['template_dir'];
	}

	// Start the server. Bind only to 127.0.0.1; in production taalkic runs
	// behind a web server like Nginx, which handles TLS termination.
	public function run(): void {
		$worker = new Worker( 'http://127.0.0.1:' . $this->port );
		$worker->count = $this->workers;

		$dispatcher = $this->router->dispatcher();
		$worker->onMessage = function( TcpConnection $connection, Request $request ) use ( $dispatcher ): void {
			$connection->send( $this->handle( $dispatcher, $request ) );
		};

		Worker::runAll();
	}

	// Turn a single request into a response.
	private function handle( Dispatcher $dispatcher, Request $request ): Response {
		$method = $request->method();
		$path = $request->path();
		$route_info = $dispatcher->dispatch( $method, $path );

		// A HEAD request with no explicit HEAD route falls back to the GET route.
		if ( $method === 'HEAD' && $route_info[0] !== Dispatcher::FOUND ) {
			$route_info = $dispatcher->dispatch( 'GET', $path );
		}

		$response = $this->route_response( $route_info, $request );

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
	private function route_response( array $route_info, Request $request ): Response {
		if ( $route_info[0] === Dispatcher::NOT_FOUND ) {
			return $this->error_response( 404, $this->router->handler_404(), $request );
		}

		if ( $route_info[0] === Dispatcher::METHOD_NOT_ALLOWED ) {
			return $this->error_response( 405, $this->router->handler_405(), $request );
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
		if ( $handler_file === '' ) {
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
