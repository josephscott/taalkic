<?php
declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php';

$config = [
	'workers' => 'half',
	'port' => 4200,
	'routes' => __DIR__ . '/url-routes.php',
	'charset' => 'utf-8',
	'template_dir' => __DIR__ . '/templates/',
];

// In development ( make dev ) watch the demo and framework source so changes
// reload automatically. Left off otherwise so production does not scan files.
if ( getenv( 'TAALKIC_DEV' ) === '1' ) {
	$config['watch'] = [ __DIR__, __DIR__ . '/../src' ];
}

$app = new Taalkic\App( $config );
$app->run();
