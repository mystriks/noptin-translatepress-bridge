<?php
/**
 * Plugin Name:       Noptin & TranslatePress Bridge
 * Plugin URI:        https://github.com/mystriks/noptin-translatepress-bridge
 * Description:       Connects Noptin and TranslatePress. Saves subscriber language and translates outgoing Noptin emails (with shared cache).
 * Version:           1.2.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mystriks.eu
 * Author URI:        https://mystriks.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       noptin-translatepress-bridge
 * Domain Path:       /languages
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

	// FULL PLUGIN BODY - please replace this file with the complete version from the repository artifacts if truncated.
	// The complete source is available in the GitHub repository history and local artifacts.
}

new Noptin_TranslatePress_Bridge();
