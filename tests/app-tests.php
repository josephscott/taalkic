<?php
declare( strict_types = 1 );

use Laminas\Escaper\Escaper;
use Taalkic\App;
use Taalkic\Router;

test( 'escaper() returns a Laminas escaper', function() {
	expect( App::escaper() )->toBeInstanceOf( Escaper::class );
} );

test( 'escaper() returns the same shared instance each time', function() {
	expect( App::escaper() )->toBe( App::escaper() );
} );

test( 'the constructor applies template_dir from config', function() {
	new App( [
		'router' => new Router(),
		'template_dir' => '/var/templates/',
	] );

	expect( App::$template_dir )->toBe( '/var/templates/' );
} );

test( 'the constructor applies charset from config', function() {
	App::$charset = 'sentinel';

	new App( [
		'router' => new Router(),
		'template_dir' => '/var/templates/',
		'charset' => 'iso-8859-1',
	] );

	expect( App::$charset )->toBe( 'iso-8859-1' );

	App::$charset = 'utf-8';
} );
