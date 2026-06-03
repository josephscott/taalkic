<?php
declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php';

$router = new Taalkic\Router();
require __DIR__ . '/url-routes.php';

$app = new Taalkic\App( [
	'workers' => 'half',
	'port' => 4200,
	'router' => $router,
	'charset' => 'utf-8',
	'template_dir' => __DIR__ . '/templates/',
] );
$app->run();
