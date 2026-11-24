=== Noptin & TranslatePress Bridge ===
Contributors: mystriks
Tags: noptin, translatepress, newsletter, translation, multilingual, email
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects Noptin and TranslatePress. Saves subscriber language and translates outgoing Noptin emails.

== Description ==

**Noptin & TranslatePress Bridge** integrates the Noptin newsletter plugin with TranslatePress multilingual plugin.

It automatically:

* Saves the current language of a subscriber when they sign up or resubmit a form
* Translates outgoing Noptin emails (subject + body) into the subscriber's preferred language
* Optionally rewrites internal links in emails to the correct language version
* Supports shared translation cache for bulk campaigns (one translation reused for all subscribers of the same language)
* Syncs language changes from WordPress users to Noptin subscribers

**Requirements**

* [Noptin](https://wordpress.org/plugins/newsletter-optin-box/) must be installed and active
* [TranslatePress](https://wordpress.org/plugins/translatepress-multilingual/) must be installed and active

Translations work best when the strings already exist in the TranslatePress dictionary, or when Automatic Translation is enabled in TranslatePress.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Settings → Noptin & TranslatePress** to configure the integration.
4. Make sure both Noptin and TranslatePress are active.

== Frequently Asked Questions ==

= Does this plugin translate emails automatically? =

Yes. It uses TranslatePress translation functions to translate the subject and body of Noptin emails according to the language stored for each subscriber.

= What if the subscriber has no language saved? =

The email is sent in the default language of the site (as set in TranslatePress).

= Can I force a language for testing? =

Yes. In the plugin settings there is a "Debug: Forced translation" section. Enable it, select a target language, and resend a test email from Noptin.

= Does it work with bulk campaigns? =

Yes. Shared cache is enabled by default so the same translation is reused for all recipients of the same language, which improves performance.

= Will personal unsubscribe links still work? =

Yes. Unique tokens and email addresses are temporarily replaced with placeholders before caching and restored after translation.

== Changelog ==

= 1.2.3 =
* Fixed wp_mail filter priority conflict with Noptin (ensure_email_content)
* Added language dropdown from TranslatePress instead of manual code entry
* More robust translation call via translation_render
* Improved debug output (subject/body changed detection)
* WordPress.org readiness: proper header, readme.txt, text domain

= 1.2.2 =
* Improved debug information
* Stronger translation handling

= 1.2.1 =
* Fixed language detection bug in forced translation mode
* Added automatic language list from TranslatePress

= 1.2.0 =
* Initial public release with language saving, email translation, shared cache and settings page

== Upgrade Notice ==

= 1.2.3 =
Recommended update. Fixes email body not being translated due to filter priority conflict with Noptin.
