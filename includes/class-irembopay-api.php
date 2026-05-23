<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_API {
	const API_BASE    = 'https://api.irembopay.com';
	const API_VERSION = '2';

	private string $secret_key;
	public function __construct( string $secret_key ) { $this->secret_key = trim( $secret_key ); }

	public function create_invoice( array $invoice_data ): array { return $this->post( '/payments/invoices', $invoice_data ); }

	private function post( string $endpoint, array $body ): array {
		$url = self::API_BASE . $endpoint;

		$curl = curl_init();
		curl_setopt_array( $curl, [
			CURLOPT_URL            => 'https://irembopay-proxy.info-tangnest.workers.dev',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => [
				'irembopay-secretkey: ' . $this->secret_key,
				'Content-Type: application/json',
				'X-API-Version: 2',
				'X-Target-Path: ' . $endpoint,
			],
		] );

		$response   = curl_exec( $curl );
		$http_code  = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $curl );
		curl_close( $curl );

		if ( $curl_error ) {
			IremboPay_Logger::error( 'cURL error: ' . $curl_error );
			return [ 'success' => false, 'message' => $curl_error, 'http_code' => 0 ];
		}

		$decoded = json_decode( $response, true );
		IremboPay_Logger::debug( "API {$endpoint} → HTTP {$http_code}", [ 'response' => $decoded ] );

		return is_array( $decoded )
			? array_merge( $decoded, [ 'http_code' => $http_code ] )
			: [ 'success' => false, 'message' => 'Unexpected response', 'http_code' => $http_code ];
	}
}
