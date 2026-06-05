<?php
declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

// Integration test for HEAD handling. It stands up a real Taalkic server with
// a single GET route ( no explicit HEAD route ) and confirms a HEAD request
// falls back to that GET route ( status 200 rather than 405 ) and comes back
// with the body stripped, per the HTTP spec.

test( 'a HEAD request falls back to the GET route and returns no body', function() {
	$skip = TaalkicFixture::missing_requirements();
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	$fixture = TaalkicFixture::create();
	$fixture->write_file( 'routes/page.php', "<?php echo 'PAGE BODY';" );
	$fixture->write_routes( "\$router->get( '/page', '{$fixture->dir}/routes/page.php' );" );

	try {
		$fixture->boot();

		// The server is up once the GET route answers.
		expect( $fixture->wait_for( fn () => $fixture->get( '/page' )['status'] === 200 ) )->toBeTrue();

		// GET returns the route's body.
		$page = $fixture->get( '/page' );
		expect( $page['status'] )->toBe( 200 );
		expect( $page['body'] )->toBe( 'PAGE BODY' );

		// HEAD on the same path falls back to the GET route ( 200, not 405 )
		// and the body is stripped, so nothing is sent on the wire.
		$head_page = $fixture->head( '/page' );
		expect( $head_page['status'] )->toBe( 200 );
		expect( $head_page['body'] )->toBe( '' );

		// HEAD on an unknown path still falls through to a 404, also with no
		// body on the wire.
		$head_missing = $fixture->head( '/missing' );
		expect( $head_missing['status'] )->toBe( 404 );
		expect( $head_missing['body'] )->toBe( '' );
	} finally {
		$fixture->stop();
	}
} );
