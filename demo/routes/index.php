<?php
declare( strict_types = 1 );
/** @var Taalkic\Here $here */
$here->response->withHeaders( [ 'Content-Type' => 'text/plain' ] );

echo "GET variables:\n";
print_r( $here->request->get() );
