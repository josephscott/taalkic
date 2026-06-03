<?php
declare( strict_types = 1 );

use Taalkic\Router;

test( 'a registered GET route is found and maps to its file', function() {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[1] )->toBe( '/routes/index.php' );
} );

test( 'an unknown path is not found', function() {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/missing' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::NOT_FOUND );
} );

test( 'a known path with the wrong method is not allowed', function() {
	$router = new Router();
	$router->get( '/', '/routes/index.php' );

	$result = $router->dispatcher()->dispatch( 'POST', '/' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::METHOD_NOT_ALLOWED );
} );

test( 'error handlers default to empty and can be set', function() {
	$router = new Router();
	expect( $router->handler_404() )->toBe( '' );
	expect( $router->handler_405() )->toBe( '' );

	$router->http_404( '/routes/404.php' );
	$router->http_405( '/routes/405.php' );

	expect( $router->handler_404() )->toBe( '/routes/404.php' );
	expect( $router->handler_405() )->toBe( '/routes/405.php' );
} );

test( 'a route placeholder is returned in the params', function() {
	$router = new Router();
	$router->get( '/hello[/{name}]', '/routes/hello.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/hello/sam' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[2] )->toBe( [ 'name' => 'sam' ] );
} );

test( 'an optional placeholder is absent from params when not supplied', function() {
	$router = new Router();
	$router->get( '/hello[/{name}]', '/routes/hello.php' );

	$result = $router->dispatcher()->dispatch( 'GET', '/hello' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[2] )->toBe( [] );
} );

test( 'an explicit HEAD route is matched', function() {
	$router = new Router();
	$router->head( '/thing/head', '/routes/head.php' );

	$result = $router->dispatcher()->dispatch( 'HEAD', '/thing/head' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[1] )->toBe( '/routes/head.php' );
} );

test( 'each HTTP method registers a matching route', function( string $method ) {
	$router = new Router();
	$router->$method( '/thing', "/routes/{$method}.php" );

	$result = $router->dispatcher()->dispatch( strtoupper( $method ), '/thing' );

	expect( $result[0] )->toBe( FastRoute\Dispatcher::FOUND );
	expect( $result[1] )->toBe( "/routes/{$method}.php" );
} )->with( [ 'post', 'put', 'delete', 'options', 'patch' ] );
