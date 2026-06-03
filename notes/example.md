# Example

Here are coding examples that users of the taalkic library would write their
code.

## server.php
```php
<?php
declare( strict_types = 1 );

require __DIR__ . '/vendor/autoload.php';

$router = new Taalkic\Router();
require __DIR__ . '/url-routes.php';

$app = new Taalkic\App();
$app->run();
```


## url-routes.php
```php
<?php
declare( strict_types = 1 );

$router->get( '/', __DIR__ . '/routes/index.php' );
$router->get( '/hello[/{name}]', __DIR__ . '/routes/hello.php' );
```
