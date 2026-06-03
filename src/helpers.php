<?php
declare( strict_types = 1 );

use Taalkic\App;

function esc_html( string $value ): string {
	return App::escaper()->escapeHtml( $value );
}

function esc_html_attr( string $value ): string {
	return App::escaper()->escapeHtmlAttr( $value );
}

function esc_js( string $value ): string {
	return App::escaper()->escapeJs( $value );
}

function esc_css( string $value ): string {
	return App::escaper()->escapeCss( $value );
}

function esc_url( string $value ): string {
	return App::escaper()->escapeUrl( $value );
}

/**
 * Render a template file. The path is relative to App::$template_dir and the
 * template does its own direct output. Only $data is provided to the template.
 *
 * @param array<string, mixed> $data
 */
function template( string $file_path, array $data = [] ): void {
	App::render_template( $file_path, $data );
}
