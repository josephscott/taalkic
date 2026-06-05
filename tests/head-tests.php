<?php
declare( strict_types = 1 );

// Integration test for HEAD handling. It stands up a real Taalkic server with
// a single GET route ( no explicit HEAD route ) and confirms a HEAD request
// falls back to that GET route ( status 200 rather than 405 ) and comes back
// with the body stripped, per the HTTP spec.

test( 'a HEAD request falls back to the GET route and returns no body', function() {
	foreach ( [ 'posix', 'pcntl' ] as $ext ) {
		if ( ! extension_loaded( $ext ) ) {
			$this->markTestSkipped( "the {$ext} extension is required" );
		}
	}
	if ( trim( (string) shell_exec( 'command -v curl' ) ) === '' ) {
		$this->markTestSkipped( 'the curl command is required' );
	}

	$php = escapeshellarg( PHP_BINARY );
	$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

	// Grab a free TCP port for the test server.
	$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	$name = (string) stream_socket_get_name( $probe, false );
	$port = (int) substr( $name, strrpos( $name, ':' ) + 1 );
	fclose( $probe );

	// Build an isolated server with a single GET route ( no HEAD route ).
	$dir = sys_get_temp_dir() . '/taalkic-head-' . uniqid();
	mkdir( $dir );
	mkdir( "{$dir}/routes" );
	mkdir( "{$dir}/templates" );

	file_put_contents( "{$dir}/routes/page.php", "<?php echo 'PAGE BODY';" );
	file_put_contents(
		"{$dir}/url-routes.php",
		"<?php\n\$router->get( '/page', '{$dir}/routes/page.php' );\n"
	);
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

	// A HEAD request over a raw socket so the actual bytes on the wire can be
	// inspected. curl ( -I ) will not read a body for a HEAD even if the server
	// wrongly sends one, so it cannot prove the body was stripped; reading the
	// socket directly can. Connection: close makes the server close after the
	// response so the whole thing can be read to EOF.
	$head = function( string $path ) use ( $port ): array {
		$socket = fsockopen( '127.0.0.1', $port, $errno, $errstr, 2 );
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

		// The server is up once the GET route answers.
		$up = $wait_for( fn () => $get( '/page' )['status'] === 200 );
		expect( $up )->toBeTrue();

		// GET returns the route's body.
		$page = $get( '/page' );
		expect( $page['status'] )->toBe( 200 );
		expect( $page['body'] )->toBe( 'PAGE BODY' );

		// HEAD on the same path falls back to the GET route ( 200, not 405 )
		// and the body is stripped, so nothing is sent on the wire.
		$head_page = $head( '/page' );
		expect( $head_page['status'] )->toBe( 200 );
		expect( $head_page['body'] )->toBe( '' );

		// HEAD on an unknown path still falls through to a 404, also with no
		// body on the wire.
		$head_missing = $head( '/missing' );
		expect( $head_missing['status'] )->toBe( 404 );
		expect( $head_missing['body'] )->toBe( '' );
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
