<?php
declare( strict_types = 1 );

namespace Taalkic;

class Router {
	/** @var array<int, array{method: string, path: string, file: string}> */
	private array $routes = [];

	private string $error_404 = '';

	private string $error_405 = '';

	public function head( string $path, string $file ): void {
		$this->add( 'HEAD', $path, $file );
	}

	public function get( string $path, string $file ): void {
		$this->add( 'GET', $path, $file );
	}

	public function post( string $path, string $file ): void {
		$this->add( 'POST', $path, $file );
	}

	public function put( string $path, string $file ): void {
		$this->add( 'PUT', $path, $file );
	}

	public function delete( string $path, string $file ): void {
		$this->add( 'DELETE', $path, $file );
	}

	public function options( string $path, string $file ): void {
		$this->add( 'OPTIONS', $path, $file );
	}

	public function patch( string $path, string $file ): void {
		$this->add( 'PATCH', $path, $file );
	}

	// Handler file for requests that match no route.
	public function http_404( string $file ): void {
		$this->error_404 = $file;
	}

	// Handler file for requests to a known path with an unsupported method.
	public function http_405( string $file ): void {
		$this->error_405 = $file;
	}

	public function handler_404(): string {
		return $this->error_404;
	}

	public function handler_405(): string {
		return $this->error_405;
	}

	// The declared routes, so the App can check that their callback files
	// exist when the routes are loaded.
	/**
	 * @return array<int, array{method: string, path: string, file: string}>
	 */
	public function routes(): array {
		return $this->routes;
	}

	// A direct method => path => file map of the static routes only ( those
	// with no {placeholder} or [optional] segment ). The App checks this before
	// FastRoute so an exact match skips the dispatcher entirely; variable routes
	// are left out and still go through FastRoute, which keeps their params.
	/**
	 * @return array<string, array<string, string>>
	 */
	public function static_map(): array {
		$map = [];
		foreach ( $this->routes as $route ) {
			$is_static = true; // default
			if ( str_contains( $route['path'], '{' ) ) {
				$is_static = false;
			}
			if ( str_contains( $route['path'], '[' ) ) {
				$is_static = false;
			}

			if ( ! $is_static ) {
				continue;
			}

			$map[$route['method']][$route['path']] = $route['file'];
		}

		return $map;
	}

	// Build the FastRoute dispatcher from the declared routes. Routes are
	// registered exactly as declared; HEAD fallback is handled by the App.
	public function dispatcher(): \FastRoute\Dispatcher {
		$build = function( \FastRoute\RouteCollector $collector ): void {
			foreach ( $this->routes as $route ) {
				$collector->addRoute( $route['method'], $route['path'], $route['file'] );
			}
		};

		return \FastRoute\simpleDispatcher( $build );
	}

	// Store a single route. Callbacks map to a single file on disk.
	private function add( string $method, string $path, string $file ): void {
		$this->routes[] = [
			'method' => $method,
			'path' => $path,
			'file' => $file,
		];
	}
}
