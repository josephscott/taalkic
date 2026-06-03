<?php
declare( strict_types = 1 );

namespace Taalkic;

use Laminas\Escaper\Escaper;

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

	private string|int $workers = 'half';
	private int $port = 4200;
	private object $router;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$required = [ 'router', 'template_dir' ];
		foreach ( $required as $key ) {
			if ( ! isset( $config[ $key ] ) ) {
				$this->fail( "Taalkic\\App: missing required config arg '{$key}'" );
			}
		}

		if ( isset( $config['workers'] ) ) {
			$this->workers = $config['workers'];
		}

		if ( isset( $config['port'] ) ) {
			$this->port = $config['port'];
		}

		if ( isset( $config['charset'] ) ) {
			self::$charset = $config['charset'];
		}

		if ( isset( $config['router'] ) ) {
			$this->router = $config['router'];
		}

		if ( isset( $config['template_dir'] ) ) {
			self::$template_dir = $config['template_dir'];
		}
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

		$render = static function ( array $data ): void {
			include self::$template_file;
		};
		$render( $data );
	}

	// Resolve the configured 'workers' value into an actual worker count.
	// A specific integer is used as-is; 'half' uses half of the CPU cores.
	private function worker_count(): int {
		$count = 2;
		if ( is_int( $this->workers ) ) {
			$count = $this->workers;
		}

		// 'half': half of the available cores, rounded down.
		if ( $this->workers === 'half' ) {
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
