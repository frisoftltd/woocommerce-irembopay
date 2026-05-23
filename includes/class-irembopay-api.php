<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_API {
	const API_BASE    = 'https://api.irembopay.com';
	const API_VERSION = '2';

	private string $secret_key;
	public function __construct( string $secret_key ) { $this->secret_key = trim( $secret_key ); }

	public function create_invoice( array $invoice_data ): array { return $this->post( '/payments/invoices', $invoice_data ); }

	private function post( string $endpoint, array $body ): array {
		$response = wp_remote_post( self::API_BASE . $endpoint, [
			'headers' => [
				'irembopay-secretkey' => $this->secret_key,
				'Content-Type'        => 'application/json',
				'X-API-Version'       => '2',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			IremboPay_Logger::error( 'API error: ' . $response->get_error_message() );
			return [ 'success' => false, 'message' => $response->get_error_message(), 'http_code' => 0 ];
		}

		$http_code    = wp_remote_retrieve_response_code( $response );
		$decoded_body = json_decode( wp_remote_retrieve_body( $response ), true );
		IremboPay_Logger::debug( "API {$endpoint} → HTTP {$http_code}", [ 'response' => $decoded_body ] );

		return is_array( $decoded_body )
			? array_merge( $decoded_body, [ 'http_code' => $http_code ] )
			: [ 'success' => false, 'message' => 'Unexpected response format.', 'http_code' => $http_code ];
	}
}
