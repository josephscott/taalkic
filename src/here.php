<?php
declare( strict_types = 1 );

namespace Taalkic;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

// The single object a route callback receives ( as $here ). It exposes the
// Workerman request and response, plus the URL placeholders from FastRoute.
class Here {
	/**
	 * @param array<string, string> $params
	 */
	public function __construct(
		public Request $request,
		public Response $response,
		public array $params = []
	) {}
}
