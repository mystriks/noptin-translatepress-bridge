<?php
/**
 * Plugin Name: Noptin & TranslatePress Bridge
 * Description: Propojuje Noptin a TranslatePress. Ukládá jazyk odběratele při registraci i přihlášení k odběru a překládá odchozí e-maily včetně sdílené cache.
 * Version: 1.2.3
 * Author: Mystriks.eu
 * Text Domain: mystriks.eu
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

	public function save_subscriber_language( $subscriber_id, $data = array() ) {
		$current_lang = $this->get_current_trp_language();
		if ( empty( $current_lang ) || ! empty( $data['language'] ) ) {
			return;
		}

		$this->set_noptin_subscriber_language( $subscriber_id, $current_lang );
	}

	public function maybe_update_subscriber_language( $subscriber_id, $data = array() ) {
		$settings = $this->get_settings();
		if ( empty( $settings['update_existing_lang'] ) || is_admin() ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) || isset( $_POST['noptin_fields'] ) || isset( $_POST['email'] ) ) {
			$current_lang = $this->get_current_trp_language();
			if ( ! empty( $current_lang ) ) {
				$this->set_noptin_subscriber_language( $subscriber_id, $current_lang );
			}
		}
	}

	public function save_wp_user_language( $user_id ) {
		$current_lang = $this->get_current_trp_language();
		if ( empty( $current_lang ) ) {
			return;
		}

		update_user_meta( $user_id, 'trp_language', $current_lang );

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_email ) ) {
			$subscriber_id = $this->get_subscriber_id_by_email( $user->user_email );
			if ( $subscriber_id ) {
				$this->set_noptin_subscriber_language( $subscriber_id, $current_lang );
			}
		}
	}

	public function sync_user_language_change_to_noptin( $user_id, $language ) {
		$settings = $this->get_settings();
		if ( empty( $settings['sync_wp_user_lang'] ) || empty( $language ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_email ) ) {
			$subscriber_id = $this->get_subscriber_id_by_email( $user->user_email );
			if ( $subscriber_id ) {
				$this->set_noptin_subscriber_language( $subscriber_id, $language );
			}
		}
	}

	private function set_noptin_subscriber_language( $subscriber_id, $language ) {
		$clean_lang = sanitize_text_field( $language );
		if ( empty( $clean_lang ) || empty( $subscriber_id ) ) {
			return;
		}

		try {
			if ( function_exists( 'update_noptin_subscriber_meta' ) ) {
				update_noptin_subscriber_meta( $subscriber_id, 'language', $clean_lang );
			}
			if ( function_exists( 'noptin_get_subscriber' ) ) {
				$subscriber = noptin_get_subscriber( $subscriber_id );
				if ( $subscriber && method_exists( $subscriber, 'set' ) && method_exists( $subscriber, 'save' ) ) {
					$subscriber->set( 'language', $clean_lang );
					$subscriber->save();
				}
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Noptin TRP Bridge: Chyba při ukládání jazyka odběratele - ' . $e->getMessage() );
			}
		}
	}

	private function get_subscriber_id_by_email( $email ) {
		if ( function_exists( 'get_noptin_subscriber_id_by_email' ) ) {
			return get_noptin_subscriber_id_by_email( $email );
		}
		if ( function_exists( 'noptin_get_subscriber' ) ) {
			$sub = noptin_get_subscriber( $email );
			return $sub && ! empty( $sub->id ) ? $sub->id : 0;
		}
		return 0;
	}

	private function get_target_language_for_email( $email ) {
		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return '';
		}

		$subscriber_id = $this->get_subscriber_id_by_email( $email );
		if ( $subscriber_id ) {
			if ( function_exists( 'noptin_get_subscriber' ) ) {
				$sub = noptin_get_subscriber( $subscriber_id );
				if ( $sub && ! empty( $sub->language ) ) {
					return $sub->language;
				}
			}
			if ( function_exists( 'get_noptin_subscriber_meta' ) ) {
				$meta_lang = get_noptin_subscriber_meta( $subscriber_id, 'language', true );
				if ( ! empty( $meta_lang ) ) {
					return $meta_lang;
				}
			}
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$trp_user_lang = get_user_meta( $user->ID, 'trp_language', true );
			if ( ! empty( $trp_user_lang ) ) {
				return $trp_user_lang;
			}
		}

		return '';
	}

	/**
	 * Označení, že aktuálně zpracovávaný e-mail pochází z Noptin
	 */
	public function mark_noptin_email_context( $sending_function, $email_args = array() ) {
		$this->is_noptin_mail_context = true;
		add_action( 'wp_mail_succeeded', array( $this, 'reset_noptin_mail_context' ), 1 );
		add_action( 'wp_mail_failed', array( $this, 'reset_noptin_mail_context' ), 1 );
		return $sending_function;
	}

	public function reset_noptin_mail_context() {
		$this->is_noptin_mail_context = false;
	}

	public function translate_outgoing_email( $args ) {
		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) || ! function_exists( 'trp_translate' ) ) {
			return $args;
		}

		if ( ! empty( $settings['only_noptin_emails'] ) && ! $this->is_noptin_mail_context ) {
			return $args;
		}

		// Nejdříve bezpečně vytáhneme e-mail příjemce
		$to = is_array( $args['to'] ) ? reset( $args['to'] ) : $args['to'];
		if ( ! empty( $to ) && preg_match( '/<([^>]+)>/', $to, $matches ) ) {
			$to = $matches[1];
		}

		$default_lang = $this->get_default_trp_language();
		$target_lang  = '';

		// Debug: vynucený překlad má absolutní prioritu
		if ( ! empty( $settings['debug_force_translate'] ) && ! empty( $settings['debug_force_lang'] ) ) {
			$target_lang = sanitize_text_field( $settings['debug_force_lang'] );
		} else {
			$target_lang = $this->get_target_language_for_email( $to );
		}

		// Pokud nemáme cílový jazyk nebo je stejný jako výchozí, nepřekládáme
		if ( empty( $target_lang ) || $target_lang === $default_lang ) {
			return $args;
		}

		$cache_hit          = false;
		$original_subject   = isset( $args['subject'] ) ? $args['subject'] : '';
		$original_body_len  = isset( $args['message'] ) ? strlen( $args['message'] ) : 0;
		$translated_subject = $original_subject;
		$translated_body    = isset( $args['message'] ) ? $args['message'] : '';

		try {
			if ( ! empty( $args['subject'] ) ) {
				$translated_subject = $this->translate_with_cache( $args['subject'], $target_lang, 'subject', $to, $cache_hit );
				$args['subject']    = $translated_subject;
			}

			if ( ! empty( $args['message'] ) ) {
				$translated_body = $this->translate_with_cache( $args['message'], $target_lang, 'body', $to, $cache_hit );

				if ( ! empty( $settings['translate_links'] ) ) {
					$translated_body = $this->rewrite_internal_links( $translated_body, $target_lang );
				}

				$args['message'] = $translated_body;
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Noptin TRP Bridge: Chyba při překladu e-mailu - ' . $e->getMessage() );
			}
			return $args;
		}

		if ( ! empty( $settings['debug_log'] ) ) {
			$subject_changed = ( $original_subject !== $translated_subject );
			$body_changed    = ( $original_body_len !== strlen( $translated_body ) ) || ( isset( $args['message'] ) && $args['message'] !== $translated_body );

			// Pro jistotu porovnáme i hash, pokud se délka nezměnila
			if ( ! $body_changed && isset( $args['message'] ) ) {
				$body_changed = ( md5( $args['message'] ) !== md5( $translated_body ) );
			}

			update_option( 'noptin_trp_last_debug', array(
				'time'              => current_time( 'mysql' ),
				'to'                => $to,
				'lang'              => $target_lang,
				'default_lang'      => $default_lang,
				'subject'           => $translated_subject,
				'subject_changed'   => $subject_changed ? 'ANO' : 'NE',
				'body_len_before'   => $original_body_len,
				'body_len_after'    => strlen( $translated_body ),
				'body_changed'      => $body_changed ? 'ANO' : 'NE',
				'cache_hit'         => $cache_hit ? 'ANO (načteno z cache)' : 'NE (nově přeloženo)',
				'preview_subject'   => mb_substr( $translated_subject, 0, 120 ),
				'preview_body'      => mb_substr( wp_strip_all_tags( $translated_body ), 0, 200 ),
			), false );
		}

		return $args;
	}

	private function translate_with_cache( $content, $target_lang, $type, $recipient_email, &$cache_hit ) {
		$settings      = $this->get_settings();
		$cache_enabled = ! empty( $settings['cache_enabled'] );
		$shared_cache  = ! empty( $settings['shared_cache'] );

		$placeholders = array();
		$template     = $content;

		if ( $cache_enabled && $shared_cache ) {
			$counter = 0;

			if ( ! empty( $recipient_email ) ) {
				$token_email                  = '[[NOPTIN_RECIP_EMAIL]]';
				$placeholders[ $token_email ] = $recipient_email;

				// Bezpečná náhrada e-mailu s hranicemi slov
				$escaped_email = preg_quote( $recipient_email, '/' );
				$template = preg_replace(
					'/(?<![a-zA-Z0-9._%+-])' . $escaped_email . '(?![a-zA-Z0-9.-])/i',
					$token_email,
					$template
				);
				if ( false === $template ) {
					$template = $content;
				}

				$encoded_email = rawurlencode( $recipient_email );
				if ( $encoded_email !== $recipient_email ) {
					$token_enc_email                  = '[[NOPTIN_RECIP_ENC_EMAIL]]';
					$placeholders[ $token_enc_email ] = $encoded_email;

					$escaped_encoded = preg_quote( $encoded_email, '/' );
					$template_replaced = preg_replace(
						'/(?<![a-zA-Z0-9._%+-])' . $escaped_encoded . '(?![a-zA-Z0-9.-])/i',
						$token_enc_email,
						$template
					);
					if ( false !== $template_replaced ) {
						$template = $template_replaced;
					}
				}
			}

			$template_result = preg_replace_callback(
				'/\b(noptin_key|confirm_key|unsubscribe_key|sid|cid|subscriber_id)=([a-zA-Z0-9_%-]+)/i',
				function ( $m ) use ( &$placeholders, &$counter ) {
					$key_name               = $m[1];
					$token                  = '[[NOPTIN_VAR_' . $counter . ']]';
					$placeholders[ $token ] = $m[2];
					$counter++;
					return $key_name . '=' . $token;
				},
				$template
			);
			if ( false !== $template_result ) {
				$template = $template_result;
			}
		}

		$cache_key = $this->cache_prefix . $target_lang . '_' . $type . '_' . md5( $template );
		$lock_key  = 'noptin_trp_lock_' . md5( $cache_key );

		// --- Zámek proti souběžnému překladu ---
		if ( $cache_enabled ) {
			$lock_ttl = 30;

			if ( ! add_option( $lock_key, time(), '', false ) ) {
				$lock_time = get_option( $lock_key );
				// Pokud zámek vypršel, převezměte jej
				if ( $lock_time && ( time() - intval( $lock_time ) ) > $lock_ttl ) {
					delete_option( $lock_key );
					add_option( $lock_key, time(), '', false );
				} else {
					// Krátce počkejte, zda jiný požadavek nedokončí překlad
					usleep( 500000 );
					$cached = get_transient( $cache_key );
					if ( false !== $cached ) {
						$cache_hit = true;
						return ! empty( $placeholders ) ? $this->safe_strtr( $cached, $placeholders ) : $cached;
					}
				}
			}
		}

		if ( $cache_enabled ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cache_hit = true;
				delete_option( $lock_key );
				return ! empty( $placeholders ) ? $this->safe_strtr( $cached, $placeholders ) : $cached;
			}
		}

		// Silnější způsob překladu – nejdřív zkusíme přímé volání translation_render,
		// protože trp_translate() občas v admin/email kontextu neaplikuje existující překlady správně.
		$translated_template = $this->do_trp_translate( $template, $target_lang );

		if ( $cache_enabled && ! empty( $translated_template ) ) {
			$hours      = max( 1, intval( $settings['cache_expiration_hours'] ) );
			$expiration = $hours * HOUR_IN_SECONDS;
			set_transient( $cache_key, $translated_template, $expiration );
		}

		if ( $cache_enabled ) {
			delete_option( $lock_key );
		}

		return ! empty( $placeholders ) ? $this->safe_strtr( $translated_template, $placeholders ) : $translated_template;
	}

	/**
	 * Robustnější překlad přes TranslatePress.
	 * Preferuje přímé volání translation_render + správné nastavení $TRP_LANGUAGE.
	 */
	private function do_trp_translate( $content, $target_lang ) {
		if ( empty( $content ) || empty( $target_lang ) ) {
			return $content;
		}

		// 1) Preferuj přímé volání komponenty (spolehlivější v admin kontextu)
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			try {
				$trp        = TRP_Translate_Press::get_trp_instance();
				$trp_render = $trp->get_component( 'translation_render' );

				if ( $trp_render && method_exists( $trp_render, 'translate_page' ) ) {
					global $TRP_LANGUAGE;
					$lang_backup   = $TRP_LANGUAGE;
					$TRP_LANGUAGE  = $target_lang;

					$translated = $trp_render->translate_page( $content );

					$TRP_LANGUAGE = $lang_backup;

					if ( ! empty( $translated ) && is_string( $translated ) ) {
						return $translated;
					}
				}
			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Noptin TRP Bridge: do_trp_translate exception - ' . $e->getMessage() );
				}
			}
		}

		// 2) Fallback na oficiální helper
		if ( function_exists( 'trp_translate' ) ) {
			// prevent_over_translation = false – e-mail se nikde nevypisuje do HTML
			$translated = trp_translate( $content, $target_lang, false );
			if ( ! empty( $translated ) && is_string( $translated ) ) {
				return $translated;
			}
		}

		return $content;
	}

	private function safe_strtr( $string, $replace_pairs ) {
		if ( empty( $replace_pairs ) || ! is_string( $string ) ) {
			return $string;
		}
		$result = @strtr( $string, $replace_pairs );
		return ( false === $result ) ? $string : $result;
	}

	private function rewrite_internal_links( $html, $target_lang ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) || empty( $html ) ) {
			return $html;
		}

		try {
			$trp           = TRP_Translate_Press::get_trp_instance();
			$url_converter = $trp->get_component( 'url_converter' );

			if ( ! $url_converter || ! method_exists( $url_converter, 'get_url_for_language' ) ) {
				return $html;
			}

			$home_url = home_url();
			$result = preg_replace_callback(
				'/href=["\'](' . preg_quote( $home_url, '/' ) . '[^"\']*)["\']/i',
				function ( $matches ) use ( $url_converter, $target_lang ) {
					$original_url = $matches[1];
					if ( strpos( $original_url, 'noptin_' ) !== false || strpos( $original_url, 'wp-admin' ) !== false ) {
						return $matches[0];
					}
					$translated_url = $url_converter->get_url_for_language( $target_lang, $original_url, '' );
					if ( empty( $translated_url ) ) {
						return $matches[0];
					}
					return 'href="' . esc_url( $translated_url ) . '"';
				},
				$html
			);

			return ( false === $result ) ? $html : $result;
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Noptin TRP Bridge: Chyba při přepisu odkazů - ' . $e->getMessage() );
			}
			return $html;
		}
	}

	public function add_admin_menu() {
		add_options_page(
			'Noptin & TranslatePress',
			'Noptin & TranslatePress',
			'manage_options',
			'noptin-trp-bridge',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'noptin_trp_bridge_group', $this->option_name, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();
		$clean['enabled']                = ! empty( $input['enabled'] ) ? 1 : 0;
		$clean['only_noptin_emails']     = ! empty( $input['only_noptin_emails'] ) ? 1 : 0;
		$clean['update_existing_lang']   = ! empty( $input['update_existing_lang'] ) ? 1 : 0;
		$clean['sync_wp_user_lang']      = ! empty( $input['sync_wp_user_lang'] ) ? 1 : 0;
		$clean['translate_links']        = ! empty( $input['translate_links'] ) ? 1 : 0;
		$clean['cache_enabled']          = ! empty( $input['cache_enabled'] ) ? 1 : 0;
		$clean['shared_cache']           = ! empty( $input['shared_cache'] ) ? 1 : 0;
		$clean['cache_expiration_hours'] = isset( $input['cache_expiration_hours'] ) ? max( 1, intval( $input['cache_expiration_hours'] ) ) : 48;
		$clean['debug_log']              = ! empty( $input['debug_log'] ) ? 1 : 0;
		$clean['debug_force_translate']  = ! empty( $input['debug_force_translate'] ) ? 1 : 0;
		$clean['debug_force_lang']       = ! empty( $input['debug_force_lang'] ) ? sanitize_text_field( $input['debug_force_lang'] ) : '';
		return $clean;
	}

	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'noptin_trp_clear_cache_nonce' ) ) {
			wp_die( 'Neoprávněný přístup.' );
		}

		global $wpdb;
		$like_transient = $wpdb->esc_like( '_transient_' . $this->cache_prefix ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . $this->cache_prefix ) . '%';
		$like_lock      = $wpdb->esc_like( 'noptin_trp_lock_' ) . '%';

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$like_transient,
			$like_timeout,
			$like_lock
		) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'noptin-trp-bridge', 'cache_cleared' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_settings_page() {
		$settings   = $this->get_settings();
		$debug      = get_option( 'noptin_trp_last_debug', false );
		$trp_langs  = $this->get_trp_available_languages();
		?>
		<div class="wrap">
			<h1>Noptin & TranslatePress – Nastavení integrace</h1>

			<?php if ( isset( $_GET['cache_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Mezipaměť (cache) překladů byla úspěšně vymazána.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'noptin_trp_bridge_group' ); ?>

				<h2 class="title">Obecné nastavení překladu</h2>
				<table class="form-table">
					<tr>
						<th scope="row">Stav integrace</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enabled]" value="1" <?php checked( 1, $settings['enabled'] ); ?> />
								Povolit automatické ukládání jazyka a překlad odchozích e-mailů
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Rozsah e-mailů</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[only_noptin_emails]" value="1" <?php checked( 1, $settings['only_noptin_emails'] ); ?> />
								Překládat pouze e-maily odeslané z Noptinu (doporučeno)
							</label>
							<p class="description">Pokud volbu odškrtnete, plugin přeloží všechny e-maily z WordPressu podle uloženého jazyka příjemce.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Přepis odkazů v e-mailu</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[translate_links]" value="1" <?php checked( 1, $settings['translate_links'] ); ?> />
								Automaticky převést odkazy na články a stránky do jazyka příjemce (např. /en/clanek/)
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title">Debug: Vynucený překlad</h2>
				<table class="form-table">
					<tr>
						<th scope="row">Zapnout vynucený překlad</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[debug_force_translate]" value="1" <?php checked( 1, $settings['debug_force_translate'] ); ?> />
								Všechny e-maily Noptinu překládat do níže zadaného jazyka (ignorovat jazyk příjemce)
							</label>
							<p class="description">Používá se pouze pro testování. Po zapnutí můžete v Noptinu kliknout na „Resend" a ověřit překladový efekt.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Cílový jazyk</th>
						<td>
							<?php if ( ! empty( $trp_langs ) ) : ?>
								<select name="<?php echo esc_attr( $this->option_name ); ?>[debug_force_lang]" class="regular-text">
									<option value=""><?php esc_html_e( '— vyberte jazyk —', 'noptin-trp-bridge' ); ?></option>
									<?php foreach ( $trp_langs as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['debug_force_lang'], $code ); ?>>
											<?php echo esc_html( $name . ' (' . $code . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[debug_force_lang]" value="<?php echo esc_attr( $settings['debug_force_lang'] ); ?>" placeholder="např. en_US" class="regular-text" />
								<p class="description" style="color:#b32d2e;">Nepodařilo se načíst jazyky z TranslatePress. Zadejte kód ručně (např. en_US).</p>
							<?php endif; ?>
							<p class="description">Vyberte jazyk, do kterého se mají e-maily při debug režimu překládat. Jazyky se načítají automaticky z nastavení TranslatePress.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Synchronizace jazyka uživatelů a odběratelů</h2>
				<table class="form-table">
					<tr>
						<th scope="row">Aktualizace při opětovném přihlášení</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[update_existing_lang]" value="1" <?php checked( 1, $settings['update_existing_lang'] ); ?> />
								Přepsat jazyk stávajícího odběratele, pokud znovu odešle formulář z jiné jazykové verze webu
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Synchronizace s WP účty</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[sync_wp_user_lang]" value="1" <?php checked( 1, $settings['sync_wp_user_lang'] ); ?> />
								Aktualizovat jazyk odběratele v Noptinu, když si přihlášený uživatel změní jazyk na webu
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title">Nastavení mezipaměti (Cache)</h2>
				<table class="form-table">
					<tr>
						<th scope="row">Povolit Cache překladů</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[cache_enabled]" value="1" <?php checked( 1, $settings['cache_enabled'] ); ?> />
								Ukládat přeložené e-maily do mezipaměti
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Sdílená cache pro hromadné rozesílky</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[shared_cache]" value="1" <?php checked( 1, $settings['shared_cache'] ); ?> />
								Sdílet jeden překlad pro všechny odběratele stejného jazyka (ignoruje unikátní odkazy na odhlášení při ukládání do cache)
							</label>
							<p class="description">Pokud v těle newsletteru používáte osobní oslovení jménem (např. „Ahoj Petře“), tuto volbu raději vypněte, aby se jména nepřepsala.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Doba platnosti cache (hodiny)</th>
						<td>
							<input type="number" min="1" max="8760" name="<?php echo esc_attr( $this->option_name ); ?>[cache_expiration_hours]" value="<?php echo esc_attr( $settings['cache_expiration_hours'] ); ?>" class="small-text" /> hodin
							<p class="description">Po uplynutí této doby se překlad vygeneruje znovu. Výchozí: 48 hodin.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Diagnostika (Debug)</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[debug_log]" value="1" <?php checked( 1, $settings['debug_log'] ); ?> />
								Zaznamenat informace o posledním přeloženém e-mailu níže na této stránce
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>

			<hr />
			<h2>Správa mezipaměti</h2>
			<p>Pokud jste v TranslatePressu ručně upravili překlad článku a chcete ho okamžitě rozeslat v nové podobě, vymažte mezipaměť:</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="noptin_trp_clear_cache" />
				<?php wp_nonce_field( 'noptin_trp_clear_cache_nonce' ); ?>
				<?php submit_button( 'Vymazat cache překladů nyní', 'secondary' ); ?>
			</form>

			<?php if ( ! empty( $settings['debug_log'] ) && is_array( $debug ) ) : ?>
				<hr />
				<h2>Poslední zachycený překlad (Debug)</h2>
				<pre style="background:#fff; padding:15px; border:1px solid #ccd0d4;"><?php echo esc_html( print_r( $debug, true ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}
}

new Noptin_TranslatePress_Bridge();
