<?php
declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

// Integration test for the graceful production reload that `make reload`
// performs. It stands up a real Taalkic server, adds a route while the server
// is running, then reloads the same way `make reload` does ( a graceful
// "reload -g" followed by waiting until every old worker has exited ) and
// confirms the new route is served. This guards the behavior the `make reload`
// target depends on: routes are loaded inside the workers, so a reload picks up
// changes without restarting the server.

test( 'a graceful reload picks up a new route without restarting the server', function() {
	$skip = TaalkicFixture::missing_requirements( [ 'pgrep' ] );
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	$fixture = TaalkicFixture::create();
	$fixture->write_file( 'routes/a.php', "<?php echo 'AAA';" );
	$fixture->write_routes( "\$router->get( '/a', '{$fixture->dir}/routes/a.php' );" );

	try {
		$fixture->boot();

		// The server is up once the existing route answers.
		expect( $fixture->wait_for( fn () => $fixture->get( '/a' )['status'] === 200 ) )->toBeTrue();

		// The new route is not known yet.
		expect( $fixture->get( '/b' )['status'] )->toBe( 404 );

		// Add the new route and its callback while the server is running.
		$fixture->write_file( 'routes/b.php', "<?php echo 'BBB';" );
		$fixture->write_routes(
			"\$router->get( '/a', '{$fixture->dir}/routes/a.php' );\n"
			. "\$router->get( '/b', '{$fixture->dir}/routes/b.php' );"
		);

		// Open an idle keep-alive connection: send a request, read the reply,
		// then leave the socket open. A graceful reload must not block waiting
		// for this connection to hit its keep-alive timeout ( ~90s ) -- the
		// worker closes idle connections itself ( see App::onWorkerStop ).
		$idle = fsockopen( '127.0.0.1', $fixture->port, $errno, $errstr, 2 );
		expect( $idle )->not->toBeFalse();
		fwrite( $idle, "GET /a HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n" );
		stream_set_timeout( $idle, 2 );
		fread( $idle, 8192 );
		stream_set_blocking( $idle, false );

		$fixture->reload_and_wait();

		// The worker recycled the idle connection instead of waiting it out, so
		// the client sees the socket closed. Without that, a graceful reload
		// would stall on this connection for the full keep-alive timeout.
		$closed = $fixture->wait_for( function() use ( $idle ): bool {
			fread( $idle, 1 );
			return feof( $idle );
		}, 5.0 );
		fclose( $idle );
		expect( $closed )->toBeTrue();

		// The existing route still works and the new route is now served.
		expect( $fixture->get( '/a' )['status'] )->toBe( 200 );

		$new = $fixture->get( '/b' );
		expect( $new['status'] )->toBe( 200 );
		expect( $new['body'] )->toBe( 'BBB' );
	} finally {
		$fixture->stop();
	}
} );
