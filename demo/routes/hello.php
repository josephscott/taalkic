<?php
declare( strict_types = 1 );
/** @var Taalkic\Here $here */
$name = $here->params['name'] ?? 'world';

template( 'header.php', [ 'title' => 'Hello' ] );
?>

Hello, <?= esc_html( $name ); ?>

<?php
template( 'footer.php' );
