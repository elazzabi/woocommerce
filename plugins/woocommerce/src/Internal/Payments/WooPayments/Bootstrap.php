<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\WooPayments;

use Automattic\WooCommerce\Internal\Features\FeaturesController;

defined( 'ABSPATH' ) || exit;

/**
 * Core bootstrapper for WooPayments module.
 *
 * @internal
 */
class Bootstrap {
	/**
	 * Feature flag id for the core WooPayments gateway.
	 */
	private const CORE_GATEWAY_FEATURE_ID = 'woopayments_core';

	/**
	 * Register the WooPayments module hooks.
	 */
	public function register(): void {
		$this->register_migration_controller();

		if ( $this->is_plugin_active() || ! $this->is_core_gateway_enabled() ) {
			return;
		}

		$this->include_module_bootstrap();
	}

	/**
	 * Determine if the WooPayments plugin is active.
	 *
	 * @return bool
	 */
	private function is_plugin_active(): bool {
		$plugin_slug = 'woocommerce-payments/woocommerce-payments.php';

		if ( class_exists( '\Automattic\WooCommerce\Admin\PluginsHelper' ) ) {
			return \Automattic\WooCommerce\Admin\PluginsHelper::is_plugin_active( $plugin_slug );
		}

		if ( is_multisite() ) {
			$plugins = get_site_option( 'active_sitewide_plugins' );
			if ( isset( $plugins[ $plugin_slug ] ) ) {
				return true;
			}
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_slug );
	}

	/**
	 * Check if the core gateway feature is enabled.
	 *
	 * @return bool
	 */
	private function is_core_gateway_enabled(): bool {
		$features_controller = wc_get_container()->get( FeaturesController::class );
		if ( ! $features_controller instanceof FeaturesController ) {
			return false;
		}

		return $features_controller->feature_is_enabled( self::CORE_GATEWAY_FEATURE_ID );
	}

	/**
	 * Load the WooPayments bootstrap file from core.
	 */
	private function include_module_bootstrap(): void {
		$bootstrap_path = WC_ABSPATH . 'includes/woopayments/woocommerce-payments.php';
		if ( ! file_exists( $bootstrap_path ) ) {
			return;
		}

		require_once $bootstrap_path;
	}

	/**
	 * Register the migration controller hooks.
	 */
	private function register_migration_controller(): void {
		( new MigrationController() )->register();
	}
}
