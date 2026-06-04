<?php
declare( strict_types = 1 );

// Integration test for routes whose callback file is missing. It stands up a
// real Taalkic server in a temp directory with a valid route, a route pointing
// at a file that does not exist, and a 404 handler pointing at a missing file,
// then confirms the server returns sensible responses instead of a blank 200.

test( 'a missing callback file returns a 500 and a missing error handler falls back to the plain page', function() {
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

	// Build an isolated server in a temp directory. /good has a real callback;
	// /broken points at a file that does not exist; the 404 handler also points
	// at a missing file.
	$dir = sys_get_temp_dir() . '/taalkic-missing-' . uniqid();
	mkdir( $dir );
	mkdir( "{$dir}/routes" );
	mkdir( "{$dir}/templates" );

	file_put_contents( "{$dir}/routes/good.php", "<?php echo 'good';" );
	file_put_contents(
		"{$dir}/url-routes.php",
		"<?php\n"
		. "\$router->get( '/good', '{$dir}/routes/good.php' );\n"
		. "\$router->get( '/broken', '{$dir}/routes/missing.php' );\n"
		. "\$router->http_404( '{$dir}/routes/also-missing.php' );\n"
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

		// The server is up once the valid route answers.
		$up = $wait_for( fn () => $get( '/good' )['status'] === 200 );
		expect( $up )->toBeTrue();

		// A valid route works as usual.
		$good = $get( '/good' );
		expect( $good['status'] )->toBe( 200 );
		expect( $good['body'] )->toBe( 'good' );

		// A route whose callback file is missing returns a 500, not a blank 200.
		$broken = $get( '/broken' );
		expect( $broken['status'] )->toBe( 500 );
		expect( $broken['body'] )->toBe( '500 Internal Server Error' );

		// An unknown path whose 404 handler file is missing falls back to the
		// plain 404 page rather than a 500.
		$unknown = $get( '/nope' );
		expect( $unknown['status'] )->toBe( 404 );
		expect( $unknown['body'] )->toBe( '404 Not Found' );
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
