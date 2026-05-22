<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_GitHub_Updater {

	private string  $plugin_slug;
	private string  $plugin_folder;
	private string  $current_version;
	private string  $github_user;
	private string  $github_repo;
	private string  $github_token;
	private ?object $release_cache = null;

	public function __construct(
		string $plugin_file,
		string $github_user,
		string $github_repo,
		string $github_token = ''
	) {
		$this->plugin_slug     = plugin_basename( $plugin_file );
		$this->plugin_folder   = dirname( $this->plugin_slug );
		$this->github_user     = $github_user;
		$this->github_repo     = $github_repo;
		$this->github_token    = $github_token;

		$data                  = get_plugin_data( $plugin_file, false, false );
		$this->current_version = $data['Version'] ?? '0.0.0';

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
	}

	private function get_latest_release(): ?object {
		$cached = get_site_transient( 'irembopay_github_latest_release' );
		if ( $cached !== false ) {
			$this->release_cache = $cached;
			return $this->release_cache;
		}

		if ( $this->release_cache !== null ) {
			return $this->release_cache;
		}

		$url     = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
		$headers = [ 'User-Agent' => 'WooCommerce-IremboPay-Updater/' . WC_IREMBOPAY_VERSION ];

		if ( $this->github_token !== '' ) {
			$headers['Authorization'] = 'token ' . $this->github_token;
		}

		$response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 10 ] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
			return null;
		}

		set_site_transient( 'irembopay_github_latest_release', $release, 21600 );
		$this->release_cache = $release;

		return $this->release_cache;
	}

	public function check_for_update( mixed $transient ): mixed {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $transient;
		}

		$latest_version = ltrim( $release->tag_name, 'v' );

		if ( ! version_compare( $latest_version, $this->current_version, '>' ) ) {
			return $transient;
		}

		$zip_url = '';
		foreach ( $release->assets ?? [] as $asset ) {
			if ( str_ends_with( $asset->name, '.zip' ) ) {
				$zip_url = $asset->browser_download_url;
				break;
			}
		}

		if ( $zip_url === '' ) {
			return $transient;
		}

		$transient->response[ $this->plugin_slug ] = (object) [
			'slug'        => $this->plugin_folder,
			'plugin'      => $this->plugin_slug,
			'new_version' => $latest_version,
			'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'package'     => $zip_url,
		];

		return $transient;
	}

	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== $this->plugin_folder ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $result;
		}

		$latest_version = ltrim( $release->tag_name, 'v' );
		$zip_url        = '';
		foreach ( $release->assets ?? [] as $asset ) {
			if ( str_ends_with( $asset->name, '.zip' ) ) {
				$zip_url = $asset->browser_download_url;
				break;
			}
		}

		$info                = new stdClass();
		$info->name          = 'WooCommerce IremboPay Gateway';
		$info->slug          = $this->plugin_folder;
		$info->version       = $latest_version;
		$info->author        = 'frisoftltd';
		$info->homepage      = "https://github.com/{$this->github_user}/{$this->github_repo}";
		$info->download_link = $zip_url;
		$info->sections      = [
			'description' => 'WooCommerce payment gateway for IremboPay with built-in subscriptions.',
			'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
		];

		return $info;
	}

	public function fix_folder_name( string $source, string $remote_source, mixed $upgrader, array $hook_extra ): string {
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . $this->plugin_folder . '/';

		if ( $source === $expected ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $expected ) ) {
			return $source;
		}

		return $expected;
	}
}
