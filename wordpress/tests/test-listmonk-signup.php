<?php
/**
 * Tests for the Listmonk signup WordPress plugin.
 */

final class WMW_Listmonk_Signup_Test extends WP_UnitTestCase {
	private WMW_Listmonk_Signup $plugin;
	private array $requests = [];
	private string $last_redirect = '';

	public function set_up(): void {
		parent::set_up();

		$this->plugin        = WMW_Listmonk_Signup::instance();
		$this->requests      = [];
		$this->last_redirect = '';
		$_POST              = [];
		$_GET               = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		wp_dequeue_style( 'wmw-listmonk-signup' );
		wp_deregister_style( 'wmw-listmonk-signup' );

		delete_option( 'wmw_listmonk_signup_settings' );
		delete_option( 'wmw_listmonk_signup_logs' );
		delete_option( 'wmw_listmonk_signup_api_failures' );

		add_filter( 'wmw_listmonk_signup_should_exit', '__return_false' );
		add_filter( 'wp_redirect', [ $this, 'capture_redirect' ], 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'wmw_listmonk_signup_should_exit', '__return_false' );
		remove_filter( 'wp_redirect', [ $this, 'capture_redirect' ], 10 );
		remove_all_filters( 'pre_http_request' );

		$_POST = [];
		$_GET  = [];

		parent::tear_down();
	}

	public function capture_redirect( string $location, int $status ): string {
		$this->last_redirect = $location;

		return $location;
	}

	public function test_activation_adds_defaults_without_overwriting_existing_settings(): void {
		WMW_Listmonk_Signup::activate();

		$settings = get_option( 'wmw_listmonk_signup_settings' );
		$this->assertSame( 'https://newsletter.wirmachen.wien', $settings['base_url'] );

		$settings['base_url'] = 'https://example.test';
		update_option( 'wmw_listmonk_signup_settings', $settings );

		WMW_Listmonk_Signup::activate();
		$this->assertSame( 'https://example.test', get_option( 'wmw_listmonk_signup_settings' )['base_url'] );
	}

	public function test_sanitize_settings_normalizes_and_sanitizes_values(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		update_option(
			'wmw_listmonk_signup_settings',
			[
				'base_url'  => 'https://previous.test',
				'api_token' => 'api:previous-token',
			]
		);

		$settings = $this->plugin->sanitize_settings(
			[
				'base_url'        => 'https://newsletter.example.test/',
				'api_token'       => '',
				'list_ids'        => "3, invalid\n7 3 0",
				'success_message' => '<b>Danke</b>',
				'error_message'   => '<script>no</script>Fehler',
				'consent_text'    => '<a href="https://example.test">Datenschutz</a><script>bad</script>',
				'debug_logging'   => '1',
			]
		);

		$this->assertSame( 'https://newsletter.example.test', $settings['base_url'] );
		$this->assertSame( 'api:previous-token', $settings['api_token'] );
		$this->assertSame( "3\n7", $settings['list_ids'] );
		$this->assertSame( 'Danke', $settings['success_message'] );
		$this->assertStringContainsString( 'Fehler', $settings['error_message'] );
		$this->assertStringContainsString( '<a href="https://example.test">Datenschutz</a>', $settings['consent_text'] );
		$this->assertStringNotContainsString( '<script>', $settings['consent_text'] );
		$this->assertSame( '1', $settings['debug_logging'] );
		$this->assertSame( [], get_settings_errors( 'wmw_listmonk_signup_settings' ) );
	}

	public function test_sanitize_settings_rejects_invalid_required_values(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		update_option( 'wmw_listmonk_signup_settings', [ 'base_url' => 'https://safe.test', 'list_ids' => '3' ] );

		$settings = $this->plugin->sanitize_settings(
			[
				'base_url'  => 'http://unsafe.test',
				'api_token' => 'bad-token',
				'list_ids'  => 'bad 0',
			]
		);

		$this->assertSame( 'https://safe.test', $settings['base_url'] );
		$this->assertSame( '', $settings['api_token'] );
		$this->assertSame( '3', $settings['list_ids'] );
		$this->assertSame( '0', $settings['debug_logging'] );
		$codes = wp_list_pluck( get_settings_errors( 'wmw_listmonk_signup_settings' ), 'code' );
		$this->assertContains( 'wmw_listmonk_base_url_https', $codes );
		$this->assertContains( 'wmw_listmonk_api_token_format', $codes );
		$this->assertContains( 'wmw_listmonk_list_ids_required', $codes );
	}

	public function test_sanitize_settings_requires_new_api_token(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		$settings = $this->plugin->sanitize_settings( [ 'base_url' => 'https://newsletter.example.test', 'list_ids' => '3' ] );

		$this->assertSame( '', $settings['api_token'] );
		$codes = wp_list_pluck( get_settings_errors( 'wmw_listmonk_signup_settings' ), 'code' );
		$this->assertContains( 'wmw_listmonk_api_token_required', $codes );
	}

	public function test_frontend_value_sanitization_filters_districts_and_duplicates(): void {
		$values = $this->call_private(
			'sanitize_frontend_values',
			[
				[
					'anrede'   => '<b>Liebe</b>',
					'vorname'  => '<i>Ada</i>',
					'nachname' => 'Lovelace<script>',
					'email'    => 'ADA@EXAMPLE.TEST ',
					'consent'  => '1',
					'bezirke'  => [ '1020', '9999', '1020', '1070' ],
				]
			]
		);

		$this->assertSame( 'Liebe', $values['anrede'] );
		$this->assertSame( 'Ada', $values['vorname'] );
		$this->assertSame( 'Lovelace', $values['nachname'] );
		$this->assertSame( 'ADA@EXAMPLE.TEST', $values['email'] );
		$this->assertTrue( $values['consent'] );
		$this->assertSame( [ '1020', '1070' ], $values['bezirke'] );
	}

	public function test_helper_methods_cover_names_attributes_list_ids_and_redaction(): void {
		$this->assertSame( 'Ada Lovelace', $this->call_private( 'build_name', [ [ 'vorname' => 'Ada', 'nachname' => 'Lovelace' ] ] ) );
		$this->assertSame( '', $this->call_private( 'build_name', [ [ 'vorname' => '', 'nachname' => '' ] ] ) );
		$this->assertSame( [ '1010', '1230' ], $this->call_private( 'selected_district_postal_codes', [ [ '1010', '9999', '1230' ] ] ) );
		$this->assertSame( [ 3, 7 ], $this->call_private( 'parse_list_ids', [ 'bad 3 07 7 3 0' ] ) );
		$this->assertSame(
			[ 'anrede' => 'Liebe', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [ '1020' ] ],
			$this->call_private( 'subscriber_attribs', [ [ 'anrede' => '', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [ '1020' ] ] ] )
		);

		$redacted = $this->call_private( 'redact_log_value', [ 'token abc123 user@example.test api:verysecrettoken' ] );
		$this->assertStringNotContainsString( 'user@example.test', $redacted );
		$this->assertStringNotContainsString( 'abc123', $redacted );
		$this->assertStringNotContainsString( 'verysecrettoken', $redacted );
	}

	public function test_shortcode_renders_secure_form_without_api_token(): void {
		update_option(
			'wmw_listmonk_signup_settings',
			[
				'base_url'      => 'https://newsletter.example.test',
				'api_token'     => 'api:super-secret-token',
				'list_ids'      => '3',
				'consent_text'  => 'Accept <a href="https://example.test/privacy">privacy</a>',
				'debug_logging' => '0',
			]
		);

		$html = $this->plugin->render_shortcode();

		$this->assertTrue( wp_style_is( 'wmw-listmonk-signup', 'enqueued' ) );
		$this->assertStringNotContainsString( '<style>', $html );
		$this->assertStringContainsString( 'name="action" value="wmw_listmonk_signup"', $html );
		$this->assertStringContainsString( 'name="wmw_listmonk_signup_nonce"', $html );
		$this->assertStringContainsString( 'name="wmw_listmonk_submission_token"', $html );
		$this->assertStringContainsString( 'name="website"', $html );
		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'name="consent"', $html );
		$this->assertStringContainsString( '<a href="https://example.test/privacy">privacy</a>', $html );
		$this->assertStringNotContainsString( 'super-secret-token', $html );
		$this->assertSame( 23, substr_count( $html, 'name="bezirke[]"' ) );
	}

	public function test_submission_rejects_invalid_required_config_without_http(): void {
		$cases = [
			[ 'api_token' => '', 'list_ids' => '3' ],
			[ 'api_token' => 'bad-token', 'list_ids' => '3' ],
			[ 'api_token' => 'api:token', 'list_ids' => '' ],
		];

		foreach ( $cases as $case ) {
			$this->requests      = [];
			$this->last_redirect = '';
			update_option(
				'wmw_listmonk_signup_settings',
				[
					'base_url'        => 'https://newsletter.example.test',
					'api_token'       => $case['api_token'],
					'list_ids'        => $case['list_ids'],
					'success_message' => 'Danke!',
					'error_message'   => 'Fehler!',
				]
			);

			$_POST = [
				'wmw_listmonk_signup_nonce' => wp_create_nonce( 'wmw_listmonk_signup_submit' ),
				'wmw_listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
				'return_to' => home_url( '/newsletter/' ),
				'email' => 'ada@example.test',
				'consent' => '1',
			];

			try {
				$this->plugin->handle_submission();
				$this->fail( 'Expected redirect termination.' );
			} catch ( RuntimeException $exception ) {
				$payload = $this->redirect_payload();
				$this->assertSame( 'error', $payload['result']['type'] );
				$this->assertSame( 'Fehler!', $payload['result']['message'] );
				$this->assertSame( [], $this->requests );
			}
		}
	}

	public function test_shortcode_consumes_result_once_and_restores_values(): void {
		$token = wp_generate_uuid4();
		set_transient(
			'wmw_listmonk_signup_result_' . $token,
			[
				'result' => [ 'type' => 'error', 'message' => 'Bitte gib eine gültige E-Mail-Adresse ein.' ],
				'values' => [ 'email' => 'ada@example.test', 'vorname' => 'Ada', 'bezirke' => [ '1020' ] ],
			],
			300
		);
		$_GET['wmw_listmonk_signup_result'] = $token;

		$html = $this->plugin->render_shortcode();

		$this->assertStringContainsString( 'wmw-listmonk-signup__message--error', $html );
		$this->assertStringContainsString( 'value="ada@example.test"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
		$this->assertStringContainsString( 'value="1020"', $html );
		$this->assertFalse( get_transient( 'wmw_listmonk_signup_result_' . $token ) );
	}

	public function test_subscriber_api_request_success_and_failure(): void {
		update_option( 'wmw_listmonk_signup_settings', [ 'debug_logging' => '1' ] );
		$settings = [ 'base_url' => 'https://newsletter.example.test', 'api_token' => 'api:token' ];
		$values   = [ 'email' => 'ada@example.test', 'anrede' => '', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [] ];

		$this->mock_http_response( [ 'response' => [ 'code' => 201 ], 'body' => '{}' ] );
		$result = $this->call_private( 'subscribe_via_subscribers_endpoint', [ $settings, [ 3 ], $values, 'req-1' ] );

		$this->assertTrue( $result );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers', $this->requests[0]['url'] );
		$this->assertSame( 'token api:token', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame(
			[
				'email'   => 'ada@example.test',
				'name'    => 'Ada Lovelace',
				'status'  => 'enabled',
				'lists'   => [ 3 ],
				'attribs' => [ 'anrede' => 'Liebe', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [] ],
			],
			json_decode( $this->requests[0]['args']['body'], true )
		);
		$logs = get_option( 'wmw_listmonk_signup_logs' );
		$this->assertSame( 'Submitting subscriber API request.', $logs[0]['message'] );
		$this->assertSame( 'req-1', $logs[0]['context']['request_id'] );
		$this->assertSame( '1', $logs[0]['context']['list_count'] );
		$this->assertSame( '1', $logs[0]['context']['has_name'] );
		$this->assertSame( 'Subscriber API response.', $logs[1]['message'] );
		$this->assertSame( 'req-1', $logs[1]['context']['request_id'] );
		$this->assertSame( '201', $logs[1]['context']['http_code'] );

		remove_all_filters( 'pre_http_request' );
		delete_option( 'wmw_listmonk_signup_logs' );
		$this->mock_http_response( [ 'response' => [ 'code' => 500 ], 'body' => 'error' ] );
		$this->assertWPError( $this->call_private( 'subscribe_via_subscribers_endpoint', [ $settings, [ 3 ], $values, 'req-2' ] ) );
		$logs = get_option( 'wmw_listmonk_signup_logs' );
		$this->assertSame( 'Subscriber API response.', $logs[1]['message'] );
		$this->assertSame( '500', $logs[1]['context']['http_code'] );
		$failures = get_option( 'wmw_listmonk_signup_api_failures' );
		$this->assertCount( 1, $failures );
		$this->assertStringNotContainsString( 'ada@example.test', wp_json_encode( $failures ) );
		$this->assertStringContainsString( '500', wp_json_encode( $failures ) );
	}

	public function test_rate_limiting_tracks_email_ip_and_ip_limits(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( $this->call_private( 'is_rate_limited', [ 'ADA@example.test' ] ) );
		}
		$this->assertTrue( $this->call_private( 'is_rate_limited', [ 'ada@example.test' ] ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertFalse( $this->call_private( 'is_rate_limited', [ 'user' . $i . '@example.test' ] ) );
		}
		$this->assertTrue( $this->call_private( 'is_rate_limited', [ 'another@example.test' ] ) );
	}

	public function test_submission_validation_redirects_and_preserves_values(): void {
		$_POST = [
			'return_to' => home_url( '/newsletter/?wmw_listmonk_signup_result=old' ),
			'email'     => 'bad-email',
			'vorname'   => 'Ada',
		];

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringStartsWith( home_url( '/newsletter/?' ), $this->last_redirect );
			$this->assertStringNotContainsString( 'wmw_listmonk_signup_result=old', $this->last_redirect );
			$payload = $this->redirect_payload();
			$this->assertSame( 'error', $payload['result']['type'] );
			$this->assertSame( 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.', $payload['result']['message'] );
			$this->assertSame( 'Ada', $payload['values']['vorname'] );
		}
	}

	public function test_submission_rejects_missing_consent_and_subscriber_api_failures(): void {
		update_option(
			'wmw_listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Danke!',
				'error_message'   => 'Fehler!',
			]
		);

		$_POST = [
			'wmw_listmonk_signup_nonce' => wp_create_nonce( 'wmw_listmonk_signup_submit' ),
			'wmw_listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
		];

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$payload = $this->redirect_payload();
			$this->assertSame( 'error', $payload['result']['type'] );
			$this->assertSame( 'Bitte bestätige, dass du den Newsletter abonnieren möchtest.', $payload['result']['message'] );
			$this->assertSame( 'ada@example.test', $payload['values']['email'] );
		}

		$this->last_redirect = '';
		$_POST['wmw_listmonk_submission_token'] = $this->call_private( 'create_submission_token' );
		$_POST['consent'] = '1';
		$this->mock_http_response( [ 'response' => [ 'code' => 500 ], 'body' => 'error' ] );

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$payload = $this->redirect_payload();
			$this->assertSame( 'error', $payload['result']['type'] );
			$this->assertSame( 'Fehler!', $payload['result']['message'] );
			$this->assertSame( 'ada@example.test', $payload['values']['email'] );
		}
	}

	public function test_successful_submission_calls_subscriber_api(): void {
		update_option(
			'wmw_listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Danke!',
				'error_message'   => 'Fehler!',
			]
		);

		$nonce = wp_create_nonce( 'wmw_listmonk_signup_submit' );
		$token = $this->call_private( 'create_submission_token' );
		$_POST = [
			'wmw_listmonk_signup_nonce' => $nonce,
			'wmw_listmonk_submission_token' => $token,
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
			'nachname' => 'Lovelace',
			'bezirke' => [ '1020' ],
		];

		$this->mock_http_response( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'wmw_listmonk_signup_result=', $this->last_redirect );
			$payload = $this->redirect_payload();
			$this->assertSame( 'success', $payload['result']['type'] );
			$this->assertSame( 'Danke!', $payload['result']['message'] );
		}

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers', $this->requests[0]['url'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( [ 3 ], $body['lists'] );
		$this->assertSame( [ '1020' ], $body['attribs']['bezirke'] );
	}

	public function test_successful_submission_correlates_debug_logs(): void {
		update_option(
			'wmw_listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Danke!',
				'error_message'   => 'Fehler!',
				'debug_logging'   => '1',
			]
		);

		$_POST = [
			'wmw_listmonk_signup_nonce' => wp_create_nonce( 'wmw_listmonk_signup_submit' ),
			'wmw_listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
			'nachname' => 'Lovelace',
			'bezirke' => [ '1020' ],
		];

		$this->mock_http_response( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$logs       = get_option( 'wmw_listmonk_signup_logs' );
			$request_id = $logs[0]['context']['request_id'];

			$this->assertNotSame( '', $request_id );
			$this->assertSame( 'Submitting subscriber API request.', $logs[0]['message'] );
			$this->assertSame( 'Subscriber API response.', $logs[1]['message'] );
			foreach ( $logs as $log ) {
				$this->assertSame( $request_id, $log['context']['request_id'] );
			}
		}
	}

	public function test_submission_token_can_only_be_claimed_once(): void {
		$token = $this->call_private( 'create_submission_token' );
		$_POST = [ 'wmw_listmonk_submission_token' => $token ];

		$this->assertTrue( $this->call_private( 'consume_submission_token' ) );

		set_transient( 'wmw_listmonk_submission_token_' . $token, '1', 600 );
		$this->assertFalse( $this->call_private( 'consume_submission_token' ) );
	}

	public function test_invalid_submission_token_redirects_with_retry_error_without_http(): void {
		$_POST = [
			'wmw_listmonk_signup_nonce' => wp_create_nonce( 'wmw_listmonk_signup_submit' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
		];

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$payload = $this->redirect_payload();
			$this->assertSame( [], $this->requests );
			$this->assertSame( 'error', $payload['result']['type'] );
			$this->assertSame( 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.', $payload['result']['message'] );
			$this->assertSame( 'ada@example.test', $payload['values']['email'] );
			$this->assertSame( 'Ada', $payload['values']['vorname'] );
		}
	}

	public function test_honeypot_short_circuits_as_success_without_http_or_token(): void {
		update_option( 'wmw_listmonk_signup_settings', [ 'success_message' => 'Danke!' ] );
		$_POST = [
			'wmw_listmonk_signup_nonce' => wp_create_nonce( 'wmw_listmonk_signup_submit' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'website' => 'bot',
		];

		try {
			$this->plugin->handle_submission();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$payload = $this->redirect_payload();
			$this->assertSame( [], $this->requests );
			$this->assertSame( 'success', $payload['result']['type'] );
			$this->assertSame( 'Danke!', $payload['result']['message'] );
		}
	}

	public function test_debug_logs_caps_and_clear_logs(): void {
		update_option( 'wmw_listmonk_signup_settings', [ 'debug_logging' => '1' ] );

		for ( $i = 0; $i < 55; $i++ ) {
			$this->call_private( 'debug_log', [ 'Log user' . $i . '@example.test token secretvalue', [ 'email' => 'user@example.test', 'api_token' => 'secret' ] ] );
		}

		$logs = get_option( 'wmw_listmonk_signup_logs' );
		$this->assertCount( 50, $logs );
		$this->assertStringNotContainsString( 'user@example.test', wp_json_encode( $logs ) );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $logs ) );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->call_private( 'record_api_failure', [ 'Failure ' . $i, [ 'email' => 'ada@example.test' ] ] );
		}
		$this->assertCount( 10, get_option( 'wmw_listmonk_signup_api_failures' ) );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$_POST = [
			'wmw_listmonk_clear_logs' => '1',
			'_wpnonce' => wp_create_nonce( 'wmw_listmonk_signup_clear_logs' ),
		];
		$_REQUEST = $_POST;

		try {
			$this->plugin->handle_clear_logs();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( 'wmw_listmonk_signup_logs' ) );
			$this->assertFalse( get_option( 'wmw_listmonk_signup_api_failures' ) );
		}
	}

	private function call_private( string $method, array $args = [] ) {
		$reflection = new ReflectionMethod( $this->plugin, $method );

		return $reflection->invokeArgs( $this->plugin, $args );
	}

	private function mock_http_response( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( $response ) {
				$this->requests[] = [ 'args' => $args, 'url' => $url ];

				return $response;
			},
			10,
			3
		);
	}

	private function redirect_payload(): array {
		$parts = wp_parse_url( $this->last_redirect );
		$this->assertIsArray( $parts );
		parse_str( $parts['query'] ?? '', $query );
		$this->assertArrayHasKey( 'wmw_listmonk_signup_result', $query );
		$payload = get_transient( 'wmw_listmonk_signup_result_' . sanitize_key( $query['wmw_listmonk_signup_result'] ) );
		$this->assertIsArray( $payload );

		return $payload;
	}
}
