<?php
/**
 * Plugin Name: Noptin & TranslatePress Bridge
 * Description: Propojuje Noptin a TranslatePress. Ukládá jazyk odběratele při registraci i přihlášení k odběru a překládá odchozí e-maily včetně sdílené cache.
 * Version: 1.2.3
 * Author: Custom Integration
 * Text Domain: noptin-trp-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Noptin_TranslatePress_Bridge {

	private $option_name  = 'noptin_trp_bridge_settings';
	private $cache_prefix = 'noptin_trp_cache_';
	private $is_noptin_mail_context = false;

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'check_dependencies' ) );

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_noptin_trp_clear_cache', array( $this, 'handle_clear_cache' ) );

		add_filter( 'noptin_subscriber_custom_fields', array( $this, 'ensure_language_field_exists' ) );
		add_action( 'noptin_insert_subscriber', array( $this, 'save_subscriber_language' ), 10, 2 );
		add_action( 'noptin_update_subscriber', array( $this, 'maybe_update_subscriber_language' ), 10, 2 );
		add_action( 'user_register', array( $this, 'save_wp_user_language' ), 20 );
		add_action( 'trp_set_user_language', array( $this, 'sync_user_language_change_to_noptin' ), 10, 2 );

		// Přesné označení e-mailů Noptin přes oficiální filtr
		add_filter( 'noptin_email_sending_function', array( $this, 'mark_noptin_email_context' ), 5, 2 );
		// Priorita musí být vyšší než Noptinův ensure_email_content (1000000), jinak Noptin přepíše přeložené tělo
		add_filter( 'wp_mail', array( $this, 'translate_outgoing_email' ), 1000001 );
	}

	public function get_default_settings() {
		return array(
			'enabled'                => 1,
			'only_noptin_emails'     => 1,
			'update_existing_lang'   => 1,
			'sync_wp_user_lang'      => 1,
			'translate_links'        => 1,
			'cache_enabled'          => 1,
			'shared_cache'           => 1,
			'cache_expiration_hours' => 48,
			'debug_log'              => 0,
			'debug_force_translate'  => 0,
			'debug_force_lang'       => '',
		);
	}

	// NOTE: Full file content continues - using push_files for complete upload
