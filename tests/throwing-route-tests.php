<?php
declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

// Integration test for a route that throws. A thrown Throwable must not take the
// worker down: the request gets a 500 and the worker keeps serving later
// requests. Before onMessage caught Throwable, an uncaught exception reached
// Workerman's TcpConnection::error(), which calls Worker::stopAll() and stops
// the whole worker — a remote DoS.

test( 'a throwing route returns a 500 and leaves the worker serving', function() {
	$skip = TaalkicFixture::missing_requirements();
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	// One worker so the same process must survive the throw and answer the
	// follow-up request; with more workers a fresh one could mask a crash.
	$fixture = TaalkicFixture::create();
	$fixture->write_file( 'routes/boom.php', "<?php throw new \\RuntimeException( 'boom' );" );
	$fixture->write_file( 'routes/ok.php', "<?php echo 'ok';" );
	$fixture->write_routes(
		"\$router->get( '/boom', '{$fixture->dir}/routes/boom.php' );\n"
		. "\$router->get( '/ok', '{$fixture->dir}/routes/ok.php' );"
	);

	try {
		$fixture->boot();

		// The server is up once the valid route answers.
		expect( $fixture->wait_for( fn () => $fixture->get( '/ok' )['status'] === 200 ) )->toBeTrue();

		// The throwing route returns a 500, not a dropped connection.
		$boom = $fixture->get( '/boom' );
		expect( $boom['status'] )->toBe( 500 );
		expect( $boom['body'] )->toBe( '500 Internal Server Error' );

		// The worker survived the throw and still serves requests.
		$after = $fixture->get( '/ok' );
		expect( $after['status'] )->toBe( 200 );
		expect( $after['body'] )->toBe( 'ok' );
	} finally {
		$fixture->stop();
	}
} );

// A route that throws after it has already echoed output must not leak that
// partial output into a later request. The ob_start/finally in run_route closes
// the buffer the throw left open; without it, in a long-lived worker the next
// response could carry the previous request's partial body.
test( 'output from a route that throws does not bleed into the next request', function() {
	$skip = TaalkicFixture::missing_requirements();
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	$fixture = TaalkicFixture::create();
	$fixture->write_file(
		'routes/partial.php',
		"<?php echo 'LEAK'; throw new \\RuntimeException( 'after output' );"
	);
	$fixture->write_file( 'routes/clean.php', "<?php echo 'clean';" );
	$fixture->write_routes(
		"\$router->get( '/partial', '{$fixture->dir}/routes/partial.php' );\n"
		. "\$router->get( '/clean', '{$fixture->dir}/routes/clean.php' );"
	);

	try {
		$fixture->boot();
		expect( $fixture->wait_for( fn () => $fixture->get( '/clean' )['status'] === 200 ) )->toBeTrue();

		// The throwing route's partial output is discarded; the client gets a 500.
		$partial = $fixture->get( '/partial' );
		expect( $partial['status'] )->toBe( 500 );
		expect( $partial['body'] )->not->toContain( 'LEAK' );

		// The next request is clean, with no bleed-through from the throw.
		$clean = $fixture->get( '/clean' );
		expect( $clean['status'] )->toBe( 200 );
		expect( $clean['body'] )->toBe( 'clean' );
	} finally {
		$fixture->stop();
	}
} );
