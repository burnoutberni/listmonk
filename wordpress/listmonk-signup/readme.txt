=== Listmonk Signup ===
Contributors: burnoutberni
Tags: newsletter, listmonk, signup, shortcode, email
Requires at least: 6.4
Tested up to: 7.0.4
Requires PHP: 8.1
Stable tag: 1.1.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Adds a configurable Listmonk newsletter signup shortcode for WordPress.

== Description ==

Listmonk Signup provides a WordPress shortcode for newsletter signup forms backed by the Listmonk subscriber API.

The plugin includes:

* A `[listmonk_signup]` shortcode.
* Configurable Listmonk base URL, API credential, and list IDs.
* Consent text and success/error messages.
* Optional personalization fields.
* Optional Vienna district selection.
* Nonce protection, honeypot protection, and transient-based rate limiting.
* Temporary debug logging for testing Listmonk API requests.

The plugin uses Listmonk's authenticated subscriber API. Listmonk list settings control double opt-in behavior.

== Installation ==

1. Upload the `listmonk-signup` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open Settings > Listmonk Signup.
4. Configure the Listmonk base URL, API credential, and list IDs.
5. Add the `[listmonk_signup]` shortcode to a page.

== Frequently Asked Questions ==

= Is this plugin specific to wirmachen.wien? =

The plugin was originally built for use with wirmachen.wien, so some defaults and optional fields reflect that use case. It can still be configured freely for other Listmonk-backed newsletter signup forms.

= Does this plugin require JavaScript? =

Yes. The signup form submits JSON to `/wp-json/listmonk-signup/v1/submit` so anonymous signups do not depend on `admin-post.php` access.

= Does the plugin control double opt-in? =

No. Double opt-in behavior is controlled by the configured Listmonk list settings.

= What API credential format is required? =

Use the Listmonk API credential in `api_user:token` format.

== Screenshots ==

== Changelog ==

= 1.1.0 =
* Improved signup submission handling and frontend REST-based flow.
* Added stronger nonce, submission token, honeypot, and rate-limit protections.
* Added temporary debug logging and expanded automated test coverage.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Includes substantial signup flow, security, logging, and test coverage improvements. Review settings after updating and clear temporary debug logs if enabled.

= 1.0.0 =
Initial release.
