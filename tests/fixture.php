<?php
declare( strict_types = 1 );

// Shared helper for the integration tests. It builds a real Taalkic server in a
// temp directory on a free port, starts it as a daemon, and provides small
// helpers for talking to it ( get / head / wait_for ), for editing its routes
// while it runs, and for reloading it the way `make reload` does. stop() shuts
// the server down and removes the temp directory.
//
// It is required directly ( require_once ) by the test files rather than
// autoloaded, so it does not depend on the composer classmap being rebuilt.
final class TaalkicFixture {
	public readonly string $base;

	private string $php;

	private string $script;

	private function __construct(
		public readonly string $dir,
		public readonly int $port,
	) {
		$this->base = "http://127.0.0.1:{$this->port}";
		$this->php = escapeshellarg( PHP_BINARY );
		$this->script = escapeshellarg( "{$this->dir}/server.php" );
	}

	// A skip reason if this environment cannot run a fixture server, else null.
	// Extra command names ( e.g. 'pgrep' ) are checked on top of the defaults.
	/**
	 * @param array<int, string> $commands
	 */
	public static function missing_requirements( array $commands = [] ): ?string {
		foreach ( [ 'posix', 'pcntl' ] as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return "the {$extension} extension is required";
			}
		}

		foreach ( array_merge( [ 'curl' ], $commands ) as $command ) {
			if ( trim( (string) shell_exec( "command -v {$command}" ) ) === '' ) {
				return "the {$command} command is required";
			}
		}

		return null;
	}

	// Create a temp directory with a server.php bound to a free port. Pass
	// watch: true to enable the dev file watcher on the temp directory.
	public static function create( bool $watch = false ): self {
		$dir = sys_get_temp_dir() . '/taalkic-test-' . uniqid();
		mkdir( $dir );
		mkdir( "{$dir}/routes" );
		mkdir( "{$dir}/templates" );

		$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$name = (string) stream_socket_get_name( $probe, false );
		$port = (int) substr( $name, strrpos( $name, ':' ) + 1 );
		fclose( $probe );

		$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
		$watch_line = '';
		if ( $watch ) {
			$watch_line = "\$config['watch'] = [ '{$dir}' ];\n";
		}

		file_put_contents(
			"{$dir}/server.php",
			"<?php\n"
			. "require '{$autoload}';\n"
			. "\$config = [\n"
			. "\t'workers' => 2,\n"
			. "\t'port' => {$port},\n"
			. "\t'routes' => '{$dir}/url-routes.php',\n"
			. "\t'template_dir' => '{$dir}/templates/',\n"
			. "];\n"
			. $watch_line
			. "( new Taalkic\\App( \$config ) )->run();\n"
		);

		return new self( $dir, $port );
	}

	// Write a file relative to the temp directory ( e.g. 'routes/a.php' ).
	public function write_file( string $relative, string $contents ): void {
		file_put_contents( "{$this->dir}/{$relative}", $contents );
	}

	// Write url-routes.php from a body that uses $router; the opening tag is
	// added here.
	public function write_routes( string $body ): void {
		file_put_contents( "{$this->dir}/url-routes.php", "<?php\n{$body}\n" );
	}

	// Start the server as a daemon.
	public function boot(): void {
		exec( "{$this->php} {$this->script} start -d 2>&1" );
	}

	// GET a path with curl, returning [ 'status' => int, 'body' => string ].
	// curl is used rather than file_get_contents because PHP's http stream
	// wrapper waits out the full timeout on Workerman's keep-alive connections.
	/**
	 * @return array{status: int, body: string}
	 */
	public function get( string $path ): array {
		$url = escapeshellarg( $this->base . $path );
		$out = (string) shell_exec( "curl -s -w '\\n%{http_code}' --max-time 2 {$url}" );

		$split = strrpos( $out, "\n" );
		$status = 0;
		$body = '';
		if ( $split !== false ) {
			$status = (int) substr( $out, $split + 1 );
			$body = substr( $out, 0, $split );
		}

		return [
			'status' => $status,
			'body' => $body,
		];
	}

	// HEAD a path over a raw socket so the actual bytes on the wire can be
	// inspected ( curl never reads a body for a HEAD, so it cannot prove the
	// body was stripped ). Returns [ 'status' => int, 'body' => string ].
	/**
	 * @return array{status: int, body: string}
	 */
	public function head( string $path ): array {
		$socket = fsockopen( '127.0.0.1', $this->port, $errno, $errstr, 2 );
		fwrite( $socket, "HEAD {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n" );
		stream_set_timeout( $socket, 2 );
		$response = (string) stream_get_contents( $socket );
		fclose( $socket );

		$separator = strpos( $response, "\r\n\r\n" );
		$head = $separator === false ? $response : substr( $response, 0, $separator );
		$body = $separator === false ? '' : substr( $response, $separator + 4 );

		$lines = explode( "\r\n", $head );
		$status_parts = explode( ' ', $lines[0] );

		return [
			'status' => (int) ( $status_parts[1] ?? 0 ),
			'body' => $body,
		];
	}

	// Poll until $ready() returns true or the timeout passes.
	public function wait_for( callable $ready, float $timeout = 5.0 ): bool {
		$deadline = microtime( true ) + $timeout;
		while ( microtime( true ) < $deadline ) {
			if ( $ready() ) {
				return true;
			}
			usleep( 50000 );
		}

		return false;
	}

	public function master_pid(): int {
		return (int) trim( (string) @file_get_contents( "{$this->dir}/workerman.server.php.pid" ) );
	}

	/**
	 * @return array<int, string>
	 */
	public function worker_pids(): array {
		$pids = [];
		exec( 'pgrep -P ' . $this->master_pid(), $pids );

		return $pids;
	}

	// Reload the way `make reload` does: a graceful reload, then wait until
	// every old worker has exited so the rolling recycle is complete.
	public function reload_and_wait(): void {
		$old = $this->worker_pids();
		exec( "{$this->php} {$this->script} reload -g 2>&1" );
		foreach ( $old as $pid ) {
			$this->wait_for( fn () => ! posix_kill( (int) $pid, 0 ), 15.0 );
		}
	}

	// Stop the server and remove the temp directory.
	public function stop(): void {
		exec( "{$this->php} {$this->script} stop 2>&1" );
		self::remove( $this->dir );
	}

	private static function remove( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) ?: [] as $entry ) {
				if ( $entry === '.' || $entry === '..' ) {
					continue;
				}
				self::remove( "{$path}/{$entry}" );
			}
			@rmdir( $path );

			return;
		}

		@unlink( $path );
	}
}
