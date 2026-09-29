# Listmonk Signup Plugin

Small WordPress plugin that provides the `[listmonk_signup]` shortcode for Listmonk-backed newsletter signups.

The WordPress.org plugin metadata lives in `readme.txt`.

## Installation

Copy `wordpress/listmonk-signup/` to `wp-content/plugins/listmonk-signup/`, then activate **Listmonk Signup** in WordPress.

## Configuration

Open **Settings > Listmonk Signup** and configure:

- Listmonk base URL, for example `https://newsletter.example.com`
- Listmonk API credential in `api_user:token` format. A token alone is not enough for Listmonk's Authorization header.
- Numeric List IDs, one per line or comma-separated
- Success message, error message, and consent text
- Temporary debug logging for testing Listmonk subscriber API requests

The default list ID is `3`, but it is stored as an option and should be confirmed in Listmonk.

## Usage

Place this shortcode on the newsletter page:

```txt
[listmonk_signup]
```

The form submits JSON to the public WordPress REST route `/wp-json/listmonk-signup/v1/submit` with nonce protection, a one-time submission token, a honeypot field, and transient-based rate limiting. Email is shown first, followed by optional personalization fields and an optional styled selector for all 23 Vienna districts.

## Listmonk Behavior

The signup uses authenticated `POST /api/subscribers` with `email`, optional `name`, `status: "enabled"`, numeric `lists`, and `attribs` for `anrede`, `vorname`, `nachname`, and `bezirke`. `bezirke` is stored as an array of Vienna postal-code strings, for example `["1020", "1070"]`. The plugin does not send `preconfirm_subscriptions`, so Listmonk list settings control double opt-in behavior.

The plugin does not use the public subscription endpoint and does not perform a separate attribute PATCH. New subscribers are created through the subscriber API request. If Listmonk returns a `409` because the subscriber already exists, the plugin looks up the existing subscriber by email, verifies current list membership, adds any missing lists with the correct confirmed or unconfirmed status based on each list's opt-in setting, and sends an opt-in request when a double opt-in list needs confirmation.

The salutation field shows the translated value for `Dear` as a placeholder, not as a prefilled value. If a visitor provides a first or last name but leaves salutation empty, the plugin stores the translated value for `Dear`; if they ignore the personalization fields entirely, salutation stays empty.

## Debug Logging

For testing, enable **Temporary debug logging** in **Settings > Listmonk Signup**. After submitting the form, recent Listmonk subscriber API request and response logs appear on the same settings page. Disable logging again after testing and clear the logs.

## Test Checklist

- Submit with an empty email and confirm the translated validation message appears.
- Submit without consent and confirm the translated validation message appears.
- Submit with a valid email and consent and confirm Listmonk receives the subscriber API request.
- Select one or more Bezirke and confirm `bezirke` is stored in Listmonk.
- For a double opt-in list, confirm Listmonk sends the opt-in email instead of directly confirming the subscriber.
- Confirm entered values are preserved after validation errors.
- Confirm the honeypot field is visually hidden and empty in normal submissions.
- Confirm the API token is not present in the rendered frontend HTML.

## Automated Tests

From `wordpress/`, install PHP test dependencies and run the WordPress test suite:

```bash
composer install
composer test
```

`composer test` installs the WordPress test suite into `/tmp/wordpress-tests-lib` by default when it is missing. Override the defaults with `WP_TESTS_DIR`, `WP_CORE_DIR`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASS`, and `WP_TESTS_DB_HOST` as needed.

The local test command requires a reachable MySQL/MariaDB server and creates/uses the configured test database. CI runs the same PHPUnit suite against a disposable MySQL service.

For a fully automatic local run with a disposable Docker MySQL database:

```bash
composer test:docker
```

This starts MySQL on host port `3307`, installs the WordPress test suite, runs PHPUnit, and removes the database container and volume afterwards.
