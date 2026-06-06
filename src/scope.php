<?php
declare( strict_types = 1 );

namespace Taalkic;

// The routes file, route callbacks, and templates are included here, by these
// free functions, and deliberately NOT by a method or by a closure defined
// inside App. A file included from within a class's scope runs in that class's
// scope, so the included code could reach App's private statics ( the charset,
// template_dir, the is_file() cache, ... ) through self:: / static:: and mutate
// framework state shared across every request the worker handles. Including from
// a plain function gives the included file no class scope at all, so self:: /
// static:: / $this are unavailable and App's internals stay private.
//
// The path is held on the Scope holder rather than passed as an argument, so the
// only variable in the included file's scope is the one each function names
// ( $router / $here / $data ) and never the path itself. Each property is set
// immediately before its include; an include reads the path once when it runs,
// so a template rendered from inside a route ( which sets template_file ) does
// not disturb the route's own include.
//
// PHP cannot fully sandbox itself: code in an included file can still reach into
// a class through Reflection. This isolates accidental coupling, not deliberately
// hostile first-party code.
final class Scope {
	public static string $routes_file = '';

	public static string $route_file = '';

	public static string $template_file = '';
}

// Load the routes file with only $router in scope.
function include_routes( Router $router ): void {
	include Scope::$routes_file;
}

// Run a route callback with only $here in scope.
function include_route( Here $here ): void {
	include Scope::$route_file;
}

// Render a template with only $data in scope.
/**
 * @param array<string, mixed> $data
 */
function include_template( array $data ): void {
	include Scope::$template_file;
}
