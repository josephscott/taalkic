<?php
declare( strict_types = 1 );

/** @var Taalkic\Router $router */
$router->get( '/', __DIR__ . '/routes/index.php' );
$router->get( '/hello[/{name}]', __DIR__ . '/routes/hello.php' );

$router->http_404( __DIR__ . '/routes/404.php' );
$router->http_405( __DIR__ . '/routes/405.php' );
