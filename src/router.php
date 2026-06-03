<?php
declare( strict_types = 1 );

namespace Taalkic;

class Router {
	/** @var array<int, array{method: string, path: string, file: string}> */
	private array $routes = [];

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

	// Store a single route. Callbacks map to a single file on disk.
	private function add( string $method, string $path, string $file ): void {
		$this->routes[] = [
			'method' => $method,
			'path' => $path,
			'file' => $file,
		];
	}
}
