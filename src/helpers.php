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
