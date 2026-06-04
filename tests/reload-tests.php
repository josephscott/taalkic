<?php
declare( strict_types = 1 );

// Integration test for the graceful production reload that `make reload`
// performs. It stands up a real Taalkic server in a temp directory, adds a
// route while the server is running, then reloads the same way `make reload`
// does ( a graceful "reload -g" followed by waiting until every old worker has
// exited ) and confirms the new route is served. This guards the behavior the
// `make reload` target depends on: routes are loaded inside the workers, so a
// reload picks up changes without restarting the server.

test( 'a graceful reload picks up a new route without restarting the server', function() {
	foreach ( [ 'posix', 'pcntl' ] as $ext ) {
		if ( ! extension_loaded( $ext ) ) {
			$this->markTestSkipped( "the {$ext} extension is required" );
		}
	}
	foreach ( [ 'pgrep', 'curl' ] as $cmd ) {
		if ( trim( (string) shell_exec( "command -v {$cmd}" ) ) === '' ) {
			$this->markTestSkipped( "the {$cmd} command is required" );
		}
	}

	$php = escapeshellarg( PHP_BINARY );
	$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

	// Grab a free TCP port for the test server.
	$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	$name = (string) stream_socket_get_name( $probe, false );
	$port = (int) substr( $name, strrpos( $name, ':' ) + 1 );
	fclose( $probe );

	// Build an isolated server in a temp directory.
	$dir = sys_get_temp_dir() . '/taalkic-reload-' . uniqid();
	mkdir( $dir );
	mkdir( "{$dir}/routes" );
	mkdir( "{$dir}/templates" );

	$write_routes = function( bool $with_b ) use ( $dir ): void {
		$routes = "<?php\n\$router->get( '/a', '{$dir}/routes/a.php' );\n";
		if ( $with_b ) {
			$routes .= "\$router->get( '/b', '{$dir}/routes/b.php' );\n";
		}
		file_put_contents( "{$dir}/url-routes.php", $routes );
	};

	file_put_contents( "{$dir}/routes/a.php", "<?php echo 'AAA';" );
	$write_routes( false );
	file_put_contents(
		"{$dir}/server.php",
		"<?php\n"
		. "require '{$autoload}';\n"
		. "\$app = new Taalkic\\App( [\n"
		. "\t'workers' => 2,\n"
		. "\t'port' => {$port},\n"
		. "\t'routes' => '{$dir}/url-routes.php',\n"
		. "\t'template_dir' => '{$dir}/templates/',\n"
		. "] );\n"
		. "\$app->run();\n"
	);

	$script = escapeshellarg( "{$dir}/server.php" );
	$base = "http://127.0.0.1:{$port}";

	// curl is used instead of file_get_contents: PHP's http stream wrapper
	// dawdles for the full timeout on Workerman's keep-alive connections even
	// when Content-Length is set, while curl returns as soon as the body is in.
	$get = function( string $path ) use ( $base ): array {
		$url = escapeshellarg( $base . $path );
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
	};

	$wait_for = function( callable $ready, float $timeout = 5.0 ): bool {
		$deadline = microtime( true ) + $timeout;
		while ( microtime( true ) < $deadline ) {
			if ( $ready() ) {
				return true;
			}
			usleep( 50000 );
		}

		return false;
	};

	try {
		exec( "{$php} {$script} start -d 2>&1" );

		// The server is up once the existing route answers.
		$up = $wait_for( fn () => $get( '/a' )['status'] === 200 );
		expect( $up )->toBeTrue();

		// The new route is not known yet.
		expect( $get( '/b' )['status'] )->toBe( 404 );

		// Add the new route and its callback while the server is running.
		file_put_contents( "{$dir}/routes/b.php", "<?php echo 'BBB';" );
		$write_routes( true );

		// Open an idle keep-alive connection: send a request, read the reply,
		// then leave the socket open. A graceful reload must not block waiting
		// for this connection to hit its keep-alive timeout ( ~90s ) -- the
		// worker closes idle connections itself ( see App::onWorkerStop ).
		$idle = fsockopen( '127.0.0.1', $port, $errno, $errstr, 2 );
		expect( $idle )->not->toBeFalse();
		fwrite( $idle, "GET /a HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n" );
		stream_set_timeout( $idle, 2 );
		fread( $idle, 8192 );
		stream_set_blocking( $idle, false );

		// Reload the way `make reload` does: a graceful reload, then wait until
		// every old worker has exited so the rolling recycle is complete.
		$master = (int) trim( (string) file_get_contents( "{$dir}/workerman.server.php.pid" ) );
		$old = [];
		exec( "pgrep -P {$master}", $old );
		exec( "{$php} {$script} reload -g 2>&1" );
		foreach ( $old as $pid ) {
			$wait_for( fn () => ! posix_kill( (int) $pid, 0 ), 15.0 );
		}

		// The worker recycled the idle connection instead of waiting it out, so
		// the client sees the socket closed. Without that, a graceful reload
		// would stall on this connection for the full keep-alive timeout.
		$closed = $wait_for( function() use ( $idle ): bool {
			fread( $idle, 1 );
			return feof( $idle );
		}, 5.0 );
		fclose( $idle );
		expect( $closed )->toBeTrue();

		// The existing route still works and the new route is now served.
		expect( $get( '/a' )['status'] )->toBe( 200 );

		$new = $get( '/b' );
		expect( $new['status'] )->toBe( 200 );
		expect( $new['body'] )->toBe( 'BBB' );
	} finally {
		exec( "{$php} {$script} stop 2>&1" );

		foreach ( glob( "{$dir}/routes/*" ) ?: [] as $file ) {
			@unlink( $file );
		}
		foreach ( glob( "{$dir}/templates/*" ) ?: [] as $file ) {
			@unlink( $file );
		}
		foreach ( glob( "{$dir}/*" ) ?: [] as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( "{$dir}/routes" );
		@rmdir( "{$dir}/templates" );
		@rmdir( $dir );
	}
} );
