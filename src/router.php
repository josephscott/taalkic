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
