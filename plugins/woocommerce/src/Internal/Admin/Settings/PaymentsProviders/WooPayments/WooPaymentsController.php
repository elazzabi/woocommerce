<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Payments;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PseudoWCPaymentGateway;
use Automattic\WooCommerce\Internal\Features\FeaturesController;

defined( 'ABSPATH' ) || exit;

/**
 * WooPayments provider controller class.
 *
 * Use this class for hooks and actions related to the WooPayments provider as it relates to the Payments settings page.
 *
 * @internal
 */
class WooPaymentsController {
	/**
	 * Feature flag id for the core WooPayments gateway.
	 */
	private const CORE_GATEWAY_FEATURE_ID = 'woopayments_core';

	/**
	 * The payments settings page service.
	 *
	 * @var Payments
	 */
	private Payments $payments;

	/**
	 * The WooPayments-specific Payments settings page service.
	 *
	 * @var WooPaymentsService
	 */
	private WooPaymentsService $woopayments;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_returns_from_wpcom' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'maybe_register_core_gateway' ), 5 );
	}

	/**
	 * Initialize the class instance.
	 *
	 * @param Payments           $payments The general payments settings page service.
	 * @param WooPaymentsService $woopayments The WooPayments-specific Payments settings page service.
	 *
	 * @internal
	 */
	final public function init( Payments $payments, WooPaymentsService $woopayments ): void {
		$this->payments    = $payments;
		$this->woopayments = $woopayments;
	}

	/**
	 * Handle returns from WordPress.com after the user has accepted or declined the WPCOM connection.
	 *
	 * @internal
	 */
	public function handle_returns_from_wpcom(): void {
		// Handle the return from WPCOM after the user has accepted or declined the WordPress.com connection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET[ WooPaymentsService::WPCOM_CONNECTION_RETURN_PARAM ] ) ) {
			// We are only interested in connection flows that are initiated from NOX session entry points.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $_GET['source'] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$source = sanitize_text_field( wp_unslash( $_GET['source'] ) );
			if ( ! in_array( $source, array( WooPaymentsService::SESSION_ENTRY_DEFAULT, WooPaymentsService::SESSION_ENTRY_LYS ), true ) ) {
				return;
			}

			$location = $this->payments->get_country();

			// Determine the connection state by querying the WPCOM connection onboarding step status.
			$wpcom_connected = WooPaymentsService::ONBOARDING_STEP_STATUS_COMPLETED === $this->woopayments->get_onboarding_step_status( WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION, $location );

			// Track the connection attempt result.
			$event_props = array(
				'step_id' => WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION,
				'source'  => $source,
			);
			$this->woopayments->record_event(
				$wpcom_connected ? 'wpcom_connection_success' : 'wpcom_connection_failure',
				$location,
				$event_props
			);

			// On successful connection, mark the onboarding step as completed, if not already.
			if ( $wpcom_connected ) {
				$this->woopayments->mark_onboarding_step_completed( WooPaymentsService::ONBOARDING_STEP_WPCOM_CONNECTION, $location );
			}
		}
	}

	/**
	 * Register the core WooPayments gateway when the extension is inactive.
	 *
	 * @param array $gateways The payment gateways list.
	 *
	 * @return array The updated payment gateways list.
	 */
	public function maybe_register_core_gateway( $gateways ): array {
		if ( ! is_array( $gateways ) ) {
			$gateways = array();
		}

		if ( $this->is_extension_active() || ! $this->is_core_gateway_enabled() ) {
			return $gateways;
		}

		foreach ( $gateways as $gateway ) {
			if ( $gateway instanceof \WC_Payment_Gateway &&
				WooPaymentsService::GATEWAY_ID === $gateway->id ) {
				return $gateways;
			}
		}

		$gateways[] = new PseudoWCPaymentGateway(
			WooPaymentsService::GATEWAY_ID,
			array(
				'title'                => 'WooPayments',
				'method_title'         => 'WooPayments',
				'description'          => esc_html__(
					'Accept card payments, local payment methods, and mobile wallets.',
					'woocommerce'
				),
				'method_description'   => esc_html__(
					'Accept card payments, local payment methods, and mobile wallets.',
					'woocommerce'
				),
				'enabled'              => 'no',
				'needs_setup'          => false,
				'account_connected'    => true,
				'onboarding_started'   => true,
				'onboarding_completed' => true,
				'test_mode_onboarding' => false,
				'test_mode'            => false,
				'dev_mode'             => false,
				'plugin_slug'          => 'woocommerce',
				'plugin_file'          => 'woocommerce/woocommerce',
			)
		);

		return $gateways;
	}

	/**
	 * Check if the WooPayments extension is active.
	 *
	 * @return bool
	 */
	private function is_extension_active(): bool {
		return class_exists( '\WC_Payments' );
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
}
