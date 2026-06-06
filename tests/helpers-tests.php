<?php
declare( strict_types = 1 );

use Taalkic\App;

test( 'esc_html escapes HTML special characters', function() {
	expect( esc_html( '<b>"x"</b>' ) )->toBe( '&lt;b&gt;&quot;x&quot;&lt;/b&gt;' );
} );

test( 'esc_html leaves plain text unchanged', function() {
	expect( esc_html( 'abc' ) )->toBe( 'abc' );
} );

test( 'esc_html_attr escapes a quoted attribute value', function() {
	expect( esc_html_attr( 'a"b' ) )->toBe( 'a&quot;b' );
} );

test( 'esc_js escapes characters that would break out of a script', function() {
	expect( esc_js( '</script>' ) )->toBe( '\x3C\x2Fscript\x3E' );
} );

test( 'esc_css escapes characters that would break out of a style', function() {
	expect( esc_css( 'a<b' ) )->toBe( 'a\3C b' );
} );

test( 'esc_url encodes spaces and ampersands', function() {
	expect( esc_url( 'a b&c' ) )->toBe( 'a%20b%26c' );
} );

test( 'template renders a file with $data and outputs directly', function() {
	$file = sys_get_temp_dir() . '/taalkic-tpl-data.php';
	file_put_contents( $file, '<?php echo "title=" . $data["title"];' );
	App::$template_dir = sys_get_temp_dir() . '/';

	ob_start();
	template( 'taalkic-tpl-data.php', [ 'title' => 'Hi' ] );
	$output = ob_get_clean();

	unlink( $file );
	expect( $output )->toBe( 'title=Hi' );
} );

test( 'template exposes only $data in the template scope', function() {
	$file = sys_get_temp_dir() . '/taalkic-tpl-scope.php';
	file_put_contents( $file, '<?php echo implode( ",", array_keys( get_defined_vars() ) );' );
	App::$template_dir = sys_get_temp_dir() . '/';

	ob_start();
	template( 'taalkic-tpl-scope.php', [ 'x' => 1 ] );
	$output = ob_get_clean();

	unlink( $file );
	expect( $output )->toBe( 'data' );
} );

test( 'a template cannot reach App class scope via self::', function() {
	$file = sys_get_temp_dir() . '/taalkic-tpl-selfscope.php';
	file_put_contents( $file, '<?php echo self::class;' );
	App::$template_dir = sys_get_temp_dir() . '/';

	// The template is included by a free function ( see src/scope.php ), so it
	// has no class scope: self:: is invalid rather than resolving to Taalkic\App.
	// Before that, self::class leaked the class and the template could read and
	// write App's private statics.
	$threw = false;
	ob_start();
	try {
		template( 'taalkic-tpl-selfscope.php', [] );
	} catch ( \Throwable $e ) {
		$threw = true;
	} finally {
		ob_end_clean();
		unlink( $file );
	}

	expect( $threw )->toBeTrue();
} );
