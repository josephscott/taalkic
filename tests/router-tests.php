<?php
declare( strict_types = 1 );

use Taalkic\Router;

test( 'a registered GET route is found and maps to its file', function () {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[1] )->toBe( '/routes/index.php' );
} );

test( 'an unknown path is not found', function () {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/missing' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::NOT_FOUND );
} );

test( 'a known path with the wrong method is not allowed', function () {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'POST', '/' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::METHOD_NOT_ALLOWED );
} );

test( 'error handlers default to empty and can be set', function () {
	$router = new Router();
	expect( $router->handler_404() )->toBe( '' );
	expect( $router->handler_405() )->toBe( '' );

	$router->http_404( '/routes/404.php' );
	$router->http_405( '/routes/405.php' );

	expect( $router->handler_404() )->toBe( '/routes/404.php' );
	expect( $router->handler_405() )->toBe( '/routes/405.php' );
} );
