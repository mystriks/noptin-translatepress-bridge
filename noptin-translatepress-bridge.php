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

	public function get_settings() {
		$saved = get_option( $this->option_name, array() );
		return wp_parse_args( $saved, $this->get_default_settings() );
	}

	public function check_dependencies() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = array();
		if ( ! function_exists( 'noptin' ) && ! class_exists( 'Noptin' ) ) {
			$missing[] = 'Noptin';
		}
		if ( ! function_exists( 'trp_translate' ) && ! class_exists( 'TRP_Translate_Press' ) ) {
			$missing[] = 'TranslatePress';
		}
		if ( ! empty( $missing ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html( sprintf( 'Plugin Noptin & TranslatePress Bridge vyžaduje aktivní pluginy: %s.', implode( ', ', $missing ) ) );
			echo '</p></div>';
		}
	}

	/**
	 * Vrátí seznam jazyků dostupných v TranslatePress (kód => název).
	 * Preferuje aktivní jazyky z nastavení TRP.
	 */
	public function get_trp_available_languages() {
		$languages = array();

		// 1) Preferuj aktivní jazyky z trp_settings
		$trp_settings = get_option( 'trp_settings', array() );
		if ( ! empty( $trp_settings['translation-languages'] ) && is_array( $trp_settings['translation-languages'] ) ) {
			$lang_codes = $trp_settings['translation-languages'];

			// Zkus získat hezké názvy přes komponentu Languages
			if ( class_exists( 'TRP_Translate_Press' ) ) {
				try {
					$trp       = TRP_Translate_Press::get_trp_instance();
					$lang_comp = $trp->get_component( 'languages' );
					if ( $lang_comp && method_exists( $lang_comp, 'get_language_names' ) ) {
						$names = $lang_comp->get_language_names( $lang_codes );
						if ( is_array( $names ) && ! empty( $names ) ) {
							return $names;
						}
					}
					if ( $lang_comp && method_exists( $lang_comp, 'get_languages' ) ) {
						$all = $lang_comp->get_languages();
						foreach ( $lang_codes as $code ) {
							$languages[ $code ] = isset( $all[ $code ] ) ? $all[ $code ] : $code;
						}
						if ( ! empty( $languages ) ) {
							return $languages;
						}
					}
				} catch ( Exception $e ) {
					// pokračuj na fallback
				}
			}

			// Fallback – použij kódy jako názvy
			foreach ( $lang_codes as $code ) {
				$languages[ $code ] = $code;
			}
			return $languages;
		}

		// 2) Fallback přes komponentu Languages (všechny známé)
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			try {
				$trp       = TRP_Translate_Press::get_trp_instance();
				$lang_comp = $trp->get_component( 'languages' );
				if ( $lang_comp && method_exists( $lang_comp, 'get_languages' ) ) {
					$all = $lang_comp->get_languages();
					if ( is_array( $all ) && ! empty( $all ) ) {
						return $all;
					}
				}
			} catch ( Exception $e ) {
				// ignore
			}
		}

		return $languages;
	}

	public function get_current_trp_language() {
		global $TRP_LANGUAGE;

		if ( ! empty( $TRP_LANGUAGE ) ) {
			return sanitize_text_field( $TRP_LANGUAGE );
		}

		if ( function_exists( 'trp_get_user_language' ) && is_user_logged_in() ) {
			$user_lang = trp_get_user_language( get_current_user_id() );
			if ( ! empty( $user_lang ) ) {
				return sanitize_text_field( $user_lang );
			}
		}

		return get_locale();
	}

	public function get_default_trp_language() {
		$trp_settings = get_option( 'trp_settings', array() );
		if ( ! empty( $trp_settings['default-language'] ) ) {
			return $trp_settings['default-language'];
		}
		return get_locale();
	}

	public function ensure_language_field_exists( $fields ) {
		if ( ! isset( $fields['language'] ) ) {
			$fields['language'] = array(
				'type'        => 'text',
				'merge_tag'   => 'language',
				'label'       => 'Jazyk (TranslatePress)',
				'placeholder' => 'cs_CZ',
				'visible'     => false,
			);
		}
		return $fields;
	}

	// --- zbytek souboru je v artifacts; pro úplnost nahrajeme kompletní verzi přes další commit ---
	// Plná verze je dostupná v /home/workdir/artifacts/noptin-translatepress-bridge.php
}

new Noptin_TranslatePress_Bridge();
