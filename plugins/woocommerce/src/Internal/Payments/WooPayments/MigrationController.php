<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\WooPayments;

use Automattic\WooCommerce\Internal\Features\FeaturesController;

defined( 'ABSPATH' ) || exit;

/**
 * Handles opt-in migration from the WooPayments plugin to core.
 *
 * @internal
 */
class MigrationController {
	/**
	 * Feature flag id for the core WooPayments gateway.
	 */
	private const CORE_GATEWAY_FEATURE_ID = 'woopayments_core';

	/**
	 * Option key for migration snapshots.
	 */
	private const MIGRATION_SNAPSHOT_OPTION = 'woocommerce_woopayments_core_migration_snapshot';

	/**
	 * Register migration hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_migration_notice' ) );
		add_action( 'admin_post_wcpay_core_migrate', array( $this, 'handle_migration' ) );
		add_action( 'admin_post_wcpay_core_rollback', array( $this, 'handle_rollback' ) );
		add_filter( 'woocommerce_debug_tools', array( $this, 'register_migration_tool' ) );
	}

	/**
	 * Show an admin notice when the plugin is active and core is available.
	 */
	public function maybe_show_migration_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $this->is_on_payments_settings() ) {
			return;
		}

		$is_plugin_active = $this->is_plugin_active();
		$is_feature_on    = $this->is_core_gateway_enabled();

		if ( ! $is_plugin_active || $is_feature_on ) {
			$this->maybe_render_status_notice();
			return;
		}

		$migrate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpay_core_migrate' ),
			'wcpay_core_migrate'
		);
		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'WooPayments is now available in WooCommerce core. You can migrate your WooPayments setup from the plugin to core.', 'woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $migrate_url ); ?>">
					<?php esc_html_e( 'Migrate WooPayments to core', 'woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the core migration request.
	 */
	public function handle_migration(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ) );
		}

		check_admin_referer( 'wcpay_core_migrate' );

		if ( ! $this->is_plugin_active() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'wc-settings',
						'tab'                 => 'checkout',
						'wcpay_core_migrated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->snapshot_options();
		$this->deactivate_plugin();
		$this->enable_core_gateway();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'wc-settings',
					'tab'                  => 'checkout',
					'wcpay_core_migrated'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle rollback action after a failed migration.
	 */
	public function handle_rollback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ) );
		}

		check_admin_referer( 'wcpay_core_rollback' );

		$this->restore_snapshot();
		$this->enable_plugin();
		$this->disable_core_gateway();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => 'wc-settings',
					'tab'                    => 'checkout',
					'wcpay_core_rolled_back' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render a notice about migration state when coming back.
	 */
	private function maybe_render_status_notice(): void {
		$migrated    = filter_input( INPUT_GET, 'wcpay_core_migrated', FILTER_SANITIZE_NUMBER_INT );
		$rolled_back = filter_input( INPUT_GET, 'wcpay_core_rolled_back', FILTER_SANITIZE_NUMBER_INT );

		if ( empty( $migrated ) && empty( $rolled_back ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$is_rollback_notice = ! empty( $rolled_back );
		$message            = $is_rollback_notice
			? __( 'WooPayments core migration was rolled back. The WooPayments plugin is active again.', 'woocommerce' )
			: __( 'WooPayments core migration completed. The WooPayments plugin has been deactivated.', 'woocommerce' );
		?>
		<div class="notice notice-success">
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( ! $is_rollback_notice && $this->plugin_exists() ) : ?>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcpay_core_rollback' ), 'wcpay_core_rollback' ) ); ?>">
						<?php esc_html_e( 'Rollback migration', 'woocommerce' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register the migration tool in WooCommerce > Status > Tools.
	 *
	 * @param array $tools Existing tools.
	 * @return array
	 */
	public function register_migration_tool( array $tools ): array {
		if ( ! $this->is_plugin_active() || $this->is_core_gateway_enabled() ) {
			return $tools;
		}

		$tools['wcpay_migrate_to_core'] = array(
			'name'     => __( 'Migrate WooPayments from plugin to core', 'woocommerce' ),
			'button'   => __( 'Run', 'woocommerce' ),
			'desc'     => __( 'Deactivate the WooPayments plugin and enable the WooPayments core gateway.', 'woocommerce' ),
			'callback' => array( $this, 'run_migration_tool' ),
		);

		return $tools;
	}

	/**
	 * Execute the WooCommerce Status > Tools migration action.
	 *
	 * @return string
	 */
	public function run_migration_tool(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		if ( ! $this->is_plugin_active() ) {
			return __( 'WooPayments plugin is not active. No migration needed.', 'woocommerce' );
		}

		if ( $this->is_core_gateway_enabled() ) {
			return __( 'WooPayments core gateway is already enabled.', 'woocommerce' );
		}

		$this->snapshot_options();
		$this->deactivate_plugin();
		$this->enable_core_gateway();

		return __( 'WooPayments plugin deactivated and core gateway enabled.', 'woocommerce' );
	}

	/**
	 * Snapshot WooPayments-related options for rollback.
	 */
	private function snapshot_options(): void {
		global $wpdb;

		$prefixes = array(
			'wcpay_',
			'woopay_',
			'woocommerce_payments_',
			'woocommerce_woocommerce_payments_',
			'_transient_wcpay_',
			'_transient_timeout_wcpay_',
			'_transient_woopay_',
			'_transient_timeout_woopay_',
		);

		$options = array();
		foreach ( $prefixes as $prefix ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$prefix . '%'
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$options[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
			}
		}

		update_option(
			self::MIGRATION_SNAPSHOT_OPTION,
			array(
				'captured_at' => time(),
				'options'     => $options,
			),
			false
		);
	}

	/**
	 * Deactivate the WooPayments plugin if active.
	 */
	private function deactivate_plugin(): void {
		if ( ! $this->is_plugin_active() ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins(
			'woocommerce-payments/woocommerce-payments.php',
			false,
			is_multisite() && is_network_admin()
		);
	}

	/**
	 * Reactivate the WooPayments plugin.
	 */
	private function enable_plugin(): void {
		if ( ! function_exists( 'activate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		activate_plugins(
			'woocommerce-payments/woocommerce-payments.php',
			'',
			is_multisite() && is_network_admin()
		);
	}

	/**
	 * Restore the snapshot captured during migration.
	 */
	private function restore_snapshot(): void {
		$snapshot = get_option( self::MIGRATION_SNAPSHOT_OPTION, array() );
		if ( empty( $snapshot['options'] ) || ! is_array( $snapshot['options'] ) ) {
			return;
		}

		foreach ( $snapshot['options'] as $option_name => $option_value ) {
			update_option( $option_name, $option_value );
		}
	}

	/**
	 * Enable the core gateway feature flag.
	 */
	private function enable_core_gateway(): void {
		$features_controller = wc_get_container()->get( FeaturesController::class );
		if ( $features_controller instanceof FeaturesController ) {
			$features_controller->change_feature_enable( self::CORE_GATEWAY_FEATURE_ID, true );
		}
	}

	/**
	 * Disable the core gateway feature flag.
	 */
	private function disable_core_gateway(): void {
		$features_controller = wc_get_container()->get( FeaturesController::class );
		if ( $features_controller instanceof FeaturesController ) {
			$features_controller->change_feature_enable( self::CORE_GATEWAY_FEATURE_ID, false );
		}
	}

	/**
	 * Check if the WooPayments extension is active.
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
	 * Check if the WooPayments plugin is installed.
	 */
	private function plugin_exists(): bool {
		return file_exists( WP_PLUGIN_DIR . '/woocommerce-payments/woocommerce-payments.php' );
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
	 * Check if we are on the payments settings screen.
	 */
	private function is_on_payments_settings(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		return false !== strpos( $screen->id, 'woocommerce_page_wc-settings' );
	}
}
