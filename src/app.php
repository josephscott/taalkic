<?php
declare( strict_types = 1 );

namespace Taalkic;

class App {
	// Shared with the helper functions ( esc_*, template ) via App::charset
	// and App::template_dir, so they are static.
	public static string $charset = 'utf-8';
	public static string $template_dir = '';

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

	// Write the message to the error log, then exit with that same message.
	private function fail( string $message ): never {
		error_log( $message );
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
