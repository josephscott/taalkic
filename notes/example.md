# Example

Here are coding examples that users of the taalkic library would write their
code.

## demo/server.php
```php
<?php
declare( strict_types = 1 );

require __DIR__ . '/vendor/autoload.php';

$app = new Taalkic\App( [
	'workers' => 'half',
	'port' => 4200,
	'routes' => __DIR__ . '/url-routes.php',
	'charset' => 'utf-8',
	'template_dir' => __DIR__ . '/templates/',
] );
$app->run();
```

## demo/url-routes.php

The App loads this file and provides `$router` in scope; it is not required
by server.php directly.

```php
<?php
declare( strict_types = 1 );
/** @var Taalkic\Router $router */

$router->get( '/', __DIR__ . '/routes/index.php' );
$router->get( '/go', __DIR__ . '/routes/go.php' );
$router->get( '/hello[/{name}]', __DIR__ . '/routes/hello.php' );

$router->post( '/thing/post', __DIR__ '/routes/thing/post.php' );
$router->put( '/thing/put', __DIR__ . '/routes/thing/put.php' );
$router->delete( '/thing/delete', __DIR__ . '/routes/thing/delete.php' );
$router->head( '/thing/head', __DIR__ . '/routes/thing/head.php' );
$router->options( '/thing/options', __DIR__ . '/routes/thing/options.php' );
$router->patch( '/thing/patch', __DIR__ . '/routes/thing/patch.php' );

$router->http_404( __DIR__ . '/routes/404.php' );
$router->http_405( __DIR__ . '/routes/405.php' );
```

## demo/routes/index.php
```
<?php
declare( strict_types = 1 );
/** @var $here */

$here->response->withHeaders( [ 'Content-Type' => 'text/plain' ] );

echo "GET variables:\n";
print_r( $here->request->get() );
```

## demo/routes/go.php
```
<?php
declare( strict_types = 1 );
/** @var $here */

$here->response->withStatus( 302 );
$here->response->withHeaders( [ 'Location' => '/' ] );
```

## demo/routes/hello.php
```
<?php
declare( strict_types = 1 );
/** @var $here */

$name = $here->params['name'] ?? 'world';

template( 'header.php', [ 'title' => 'Hello' ] );
?>

Hello, <?= esc_html( $name ); ?>

<?php
template( 'footer.php' );
```

## demo/templates/header.php
```
<?php
declare( strict_types = 1 );
/** @var $data */

<html>
<head>
<title><?= esc_html( $data['title'] ?? 'The Title' ) ?></title>
</head>
```
