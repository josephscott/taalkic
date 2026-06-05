<?php
declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

// Integration test for routes whose callback file is missing. It stands up a
// real Taalkic server with a valid route, a route pointing at a file that does
// not exist, and a 404 handler pointing at a missing file, then confirms the
// server returns sensible responses instead of a blank 200.

test( 'a missing callback file returns a 500 and a missing error handler falls back to the plain page', function() {
	$skip = TaalkicFixture::missing_requirements();
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	// /good has a real callback; /broken points at a file that does not exist;
	// the 404 handler also points at a missing file.
	$fixture = TaalkicFixture::create();
	$fixture->write_file( 'routes/good.php', "<?php echo 'good';" );
	$fixture->write_routes(
		"\$router->get( '/good', '{$fixture->dir}/routes/good.php' );\n"
		. "\$router->get( '/broken', '{$fixture->dir}/routes/missing.php' );\n"
		. "\$router->http_404( '{$fixture->dir}/routes/also-missing.php' );"
	);

	try {
		$fixture->boot();

		// The server is up once the valid route answers.
		expect( $fixture->wait_for( fn () => $fixture->get( '/good' )['status'] === 200 ) )->toBeTrue();

		// A valid route works as usual.
		$good = $fixture->get( '/good' );
		expect( $good['status'] )->toBe( 200 );
		expect( $good['body'] )->toBe( 'good' );

		// A route whose callback file is missing returns a 500, not a blank 200.
		$broken = $fixture->get( '/broken' );
		expect( $broken['status'] )->toBe( 500 );
		expect( $broken['body'] )->toBe( '500 Internal Server Error' );

		// An unknown path whose 404 handler file is missing falls back to the
		// plain 404 page rather than a 500.
		$unknown = $fixture->get( '/nope' );
		expect( $unknown['status'] )->toBe( 404 );
		expect( $unknown['body'] )->toBe( '404 Not Found' );
	} finally {
		$fixture->stop();
	}
} );
