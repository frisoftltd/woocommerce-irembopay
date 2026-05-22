<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Logger {
	const SOURCE = 'irembopay';
	public static function log( string $message, string $level = 'info', array $context = [] ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) { return; }
		wc_get_logger()->log( $level, $message, array_merge( [ 'source' => self::SOURCE ], $context ) );
	}
	public static function debug( string $message, array $context = [] ): void { self::log( $message, 'debug', $context ); }
	public static function info( string $message, array $context = [] ): void  { self::log( $message, 'info',  $context ); }
	public static function error( string $message, array $context = [] ): void { self::log( $message, 'error', $context ); }
}
