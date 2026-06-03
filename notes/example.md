# Example

Here are coding examples that users of the taalkic library would write their
code.

## demo/server.php
```php
<?php
declare( strict_types = 1 );

require __DIR__ . '/vendor/autoload.php';

$router = new Taalkic\Router();
require __DIR__ . '/url-routes.php';

$app = new Taalkic\App( [
	'router' => $router,
	'charset' => 'utf-8',
	'template_dir' => __DIR__ . '/templates/',
] );
$app->run();
```

## demo/url-routes.php
```php
<?php
declare( strict_types = 1 );

$router->get( '/', __DIR__ . '/routes/index.php' );
$router->get( '/go', __DIR__ . '/routes/go.php' );
$router->get( '/hello[/{name}]', __DIR__ . '/routes/hello.php' );
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

## routes/hello.php
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
