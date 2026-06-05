<?php
declare( strict_types = 1 );

require_once __DIR__ . '/fixture.php';

// Integration test for the dev file watcher ( make dev / the 'watch' config ).
// It stands up a server with watching enabled, then adds a route to the running
// server's routes file and confirms the route goes live on its own, with no
// manual reload -- the monitor process notices the change and reloads.

test( 'the dev watcher reloads automatically when the routes file changes', function() {
	$skip = TaalkicFixture::missing_requirements();
	if ( $skip !== null ) {
		$this->markTestSkipped( $skip );
	}

	$fixture = TaalkicFixture::create( watch: true );
	$fixture->write_file( 'routes/a.php', "<?php echo 'AAA';" );
	$fixture->write_routes( "\$router->get( '/a', '{$fixture->dir}/routes/a.php' );" );

	try {
		$fixture->boot();

		// The server is up and the new route is not declared yet.
		expect( $fixture->wait_for( fn () => $fixture->get( '/a' )['status'] === 200 ) )->toBeTrue();
		expect( $fixture->get( '/added' )['status'] )->toBe( 404 );

		// The watcher compares mtimes at one-second resolution, so make sure the
		// edit lands in a later second than its startup snapshot.
		usleep( 1100000 );

		// Add a route to the running server's routes file. No reload is issued;
		// the watcher should notice and reload on its own.
		$fixture->write_routes(
			"\$router->get( '/a', '{$fixture->dir}/routes/a.php' );\n"
			. "\$router->get( '/added', '{$fixture->dir}/routes/a.php' );"
		);

		$live = $fixture->wait_for( fn () => $fixture->get( '/added' )['status'] === 200, 10.0 );
		expect( $live )->toBeTrue();
	} finally {
		$fixture->stop();
	}
} );
