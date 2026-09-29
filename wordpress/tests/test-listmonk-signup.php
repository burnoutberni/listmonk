<?php
/**
 * Tests for the Listmonk signup WordPress plugin.
 */

final class Listmonk_Signup_Test extends WP_UnitTestCase {
	private Listmonk_Signup $plugin;
	private array $requests = [];
	private string $last_redirect = '';

	public function set_up(): void {
		parent::set_up();

		$this->plugin        = Listmonk_Signup::instance();
		$this->requests      = [];
		$this->last_redirect = '';
		$_POST              = [];
		$_GET               = [];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		wp_dequeue_style( 'listmonk-signup' );
		wp_deregister_style( 'listmonk-signup' );
		wp_dequeue_script( 'listmonk-signup' );
		wp_deregister_script( 'listmonk-signup' );

		delete_option( 'listmonk_signup_settings' );
		delete_option( 'listmonk_signup_logs' );
		delete_option( 'listmonk_signup_api_failures' );

		add_filter( 'listmonk_signup_should_exit', '__return_false' );
		add_filter( 'wp_redirect', [ $this, 'capture_redirect' ], 10, 2 );
	}

	public function test_admin_post_hooks_are_not_registered_and_rest_route_is_registered(): void {
		$this->assertFalse( has_action( 'admin_post_nopriv_listmonk_signup' ) );
		$this->assertFalse( has_action( 'admin_post_listmonk_signup' ) );

		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/listmonk-signup/v1/submit', $routes );
	}

	public function tear_down(): void {
		remove_filter( 'listmonk_signup_should_exit', '__return_false' );
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
		Listmonk_Signup::activate();

		$settings = get_option( 'listmonk_signup_settings' );
		$this->assertSame( 'https://newsletter.example.com', $settings['base_url'] );

		$settings['base_url'] = 'https://example.test';
		update_option( 'listmonk_signup_settings', $settings );

		Listmonk_Signup::activate();
		$this->assertSame( 'https://example.test', get_option( 'listmonk_signup_settings' )['base_url'] );
	}

	public function test_sanitize_settings_normalizes_and_sanitizes_values(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		update_option(
			'listmonk_signup_settings',
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
				'success_message' => '<b>Thanks</b>',
				'error_message'   => '<script>no</script>Error',
				'consent_text'    => '<a href="https://example.test">Privacy</a><script>bad</script>',
				'debug_logging'   => '1',
			]
		);

		$this->assertSame( 'https://newsletter.example.test', $settings['base_url'] );
		$this->assertSame( 'api:previous-token', $settings['api_token'] );
		$this->assertSame( "3\n7", $settings['list_ids'] );
		$this->assertSame( 'Thanks', $settings['success_message'] );
		$this->assertStringContainsString( 'Error', $settings['error_message'] );
		$this->assertStringContainsString( '<a href="https://example.test">Privacy</a>', $settings['consent_text'] );
		$this->assertStringNotContainsString( '<script>', $settings['consent_text'] );
		$this->assertSame( '1', $settings['debug_logging'] );
		$this->assertSame( [], get_settings_errors( 'listmonk_signup_settings' ) );
	}

	public function test_sanitize_settings_rejects_invalid_required_values(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		update_option( 'listmonk_signup_settings', [ 'base_url' => 'https://safe.test', 'list_ids' => '3' ] );

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
		$codes = wp_list_pluck( get_settings_errors( 'listmonk_signup_settings' ), 'code' );
		$this->assertContains( 'listmonk_base_url_https', $codes );
		$this->assertContains( 'listmonk_api_token_format', $codes );
		$this->assertContains( 'listmonk_list_ids_required', $codes );
	}

	public function test_sanitize_settings_requires_new_api_token(): void {
		global $wp_settings_errors;
		$wp_settings_errors = [];

		$settings = $this->plugin->sanitize_settings( [ 'base_url' => 'https://newsletter.example.test', 'list_ids' => '3' ] );

		$this->assertSame( '', $settings['api_token'] );
		$codes = wp_list_pluck( get_settings_errors( 'listmonk_signup_settings' ), 'code' );
		$this->assertContains( 'listmonk_api_token_required', $codes );
	}

	public function test_frontend_value_sanitization_filters_districts_and_duplicates(): void {
		$values = $this->call_private(
			'sanitize_frontend_values',
			[
				[
					'anrede'   => '<b>Dear</b>',
					'vorname'  => '<i>Ada</i>',
					'nachname' => 'Lovelace<script>',
					'email'    => 'ADA@EXAMPLE.TEST ',
					'consent'  => '1',
					'bezirke'  => [ '1020', '9999', '1020', '1070' ],
				]
			]
		);

		$this->assertSame( 'Dear', $values['anrede'] );
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
			[ 'anrede' => 'Dear', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [ '1020' ] ],
			$this->call_private( 'subscriber_attribs', [ [ 'anrede' => '', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [ '1020' ] ] ] )
		);

		$redacted = $this->call_private( 'redact_log_value', [ 'token abc123 user@example.test api:verysecrettoken' ] );
		$this->assertStringNotContainsString( 'user@example.test', $redacted );
		$this->assertStringNotContainsString( 'abc123', $redacted );
		$this->assertStringNotContainsString( 'verysecrettoken', $redacted );
	}

	public function test_shortcode_renders_secure_form_without_api_token(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'      => 'https://newsletter.example.test',
				'api_token'     => 'api:super-secret-token',
				'list_ids'      => '3',
				'consent_text'  => 'Accept <a href="https://example.test/privacy">privacy</a>',
				'debug_logging' => '0',
			]
		);

		$html = $this->plugin->render_shortcode();

		$this->assertTrue( wp_style_is( 'listmonk-signup', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'listmonk-signup', 'enqueued' ) );
		$this->assertStringNotContainsString( '<style>', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'name="action" value="listmonk_signup"', $html );
		$this->assertStringContainsString( 'data-listmonk-rest-url="' . esc_url( rest_url( 'listmonk-signup/v1/submit' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="listmonk_signup_nonce"', $html );
		$this->assertStringContainsString( 'name="listmonk_submission_token"', $html );
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
				'listmonk_signup_settings',
				[
					'base_url'        => 'https://newsletter.example.test',
					'api_token'       => $case['api_token'],
					'list_ids'        => $case['list_ids'],
					'success_message' => 'Thanks!',
					'error_message'   => 'Error!',
				]
			);

			$result = $this->process_submission_payload(
			[
				'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
				'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
				'return_to' => home_url( '/newsletter/' ),
				'email' => 'ada@example.test',
				'consent' => '1',
			]
			);

			$payload = $this->redirect_payload_from_url( $result['redirect_url'] );
			$this->assertSame( 'error', $payload['result']['type'] );
			$this->assertSame( 'Error!', $payload['result']['message'] );
			$this->assertSame( [], $this->requests );
		}
	}

	public function test_rest_submission_bad_nonce_does_not_store_result_transient(): void {
		$request = $this->rest_request(
			[
				'listmonk_signup_nonce' => 'bad-nonce',
				'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
				'return_to' => home_url( '/newsletter/' ),
				'email' => 'ada@example.test',
				'consent' => '1',
			]
		);

		$response = $this->plugin->handle_rest_submission( $request );
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayHasKey( 'redirect_url', $data );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'invalid_nonce', $data['error_code'] );
		$this->assertSame( 'Your session has expired. Please reload the page and try again.', $data['message'] );
		$this->assertNoSubmissionResultToken( $data['redirect_url'] );
	}

	public function test_rest_submission_invalid_submission_token_does_not_store_result_transient(): void {
		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'invalid_submission_token', $data['error_code'] );
		$this->assertSame( 'Your session has expired. Please reload the page and try again.', $data['message'] );
		$this->assertNoSubmissionResultToken( $data['redirect_url'] );
		$this->assertSame( [], $this->requests );
	}

	public function test_rest_submission_validation_returns_bad_request_status(): void {
		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'bad-email',
					'consent' => '1',
				]
			)
		);
		$data     = $response->get_data();
		$payload  = $this->redirect_payload_from_url( $data['redirect_url'] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'invalid_email', $data['error_code'] );
		$this->assertSame( 'Please enter a valid email address.', $data['message'] );
		$this->assertSame( 'invalid_email', $payload['result']['code'] );
		$this->assertSame( 400, $payload['result']['status'] );
	}

	public function test_rest_submission_rate_limit_returns_too_many_requests_status(): void {
		set_transient( 'listmonk_signup_email_ip_' . hash( 'sha256', '203.0.113.10|ada@example.test' ), 5, 300 );

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'rate_limited', $data['error_code'] );
		$this->assertSame( 'Please wait a moment before trying again.', $data['message'] );
	}

	public function test_rest_submission_configuration_error_returns_server_error_status(): void {
		update_option( 'listmonk_signup_settings', [ 'api_token' => '' ] );

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'configuration_error', $data['error_code'] );
		$this->assertSame( 'The subscription could not be completed. Please try again later.', $data['message'] );
	}

	public function test_rest_submission_success_returns_json_redirect_with_result(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_response( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );
		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
					'bezirke' => [ '1020' ],
				]
			)
		);

		$data    = $response->get_data();
		$payload = $this->redirect_payload_from_url( $data['redirect_url'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'listmonk_signup_result=', $data['redirect_url'] );
		$this->assertSame( 'success', $data['result'] );
		$this->assertSame( 'Thanks!', $data['message'] );
		$this->assertNull( $data['error_code'] );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertSame( 'Thanks!', $payload['result']['message'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_rest_duplicate_409_returns_success_after_existing_list_membership_verified(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"some conflict"}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 12, 'email' => 'ADA@example.test', 'lists' => [ [ 'id' => 3 ] ] ],
								],
							],
						]
					),
				],
			]
		);
		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);

		$payload = $this->redirect_payload_from_url( $response->get_data()['redirect_url'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertSame( 'Thanks!', $payload['result']['message'] );
		$this->assertCount( 2, $this->requests );
		$this->assertStringStartsWith( 'https://newsletter.example.test/api/subscribers?', $this->requests[1]['url'] );
	}

	public function test_rest_duplicate_409_resends_optin_for_existing_unconfirmed_double_optin_list(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"conflict"}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[
										'id'    => 12,
										'email' => 'ada@example.test',
										'lists' => [
											[ 'id' => 3, 'optin' => 'double', 'subscription_status' => 'unconfirmed' ],
										],
									],
								],
							],
						]
					),
				],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
			]
		);

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);

		$payload = $this->redirect_payload_from_url( $response->get_data()['redirect_url'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 'POST', $this->requests[2]['args']['method'] );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers/12/optin', $this->requests[2]['url'] );
	}

	public function test_rest_duplicate_409_adds_missing_lists_with_correct_statuses_and_sends_one_optin(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => "3\n7\n9\n11",
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"conflict"}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 12, 'email' => 'ada@example.test', 'lists' => [ [ 'id' => 3 ] ] ],
								],
							],
						]
					),
				],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 7, 'optin' => 'single' ],
									[ 'id' => 9, 'optin' => 'double' ],
									[ 'id' => 11, 'optin' => 'double' ],
								],
							],
						]
					),
				],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
			]
		);

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);

		$payload = $this->redirect_payload_from_url( $response->get_data()['redirect_url'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertCount( 6, $this->requests );
		$this->assertStringStartsWith( 'https://newsletter.example.test/api/lists?', $this->requests[2]['url'] );
		$this->assertSame( 'PUT', $this->requests[3]['args']['method'] );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers/lists', $this->requests[3]['url'] );
		$this->assertSame(
			[
				'ids'             => [ 12 ],
				'action'          => 'add',
				'target_list_ids' => [ 7 ],
				'status'          => 'confirmed',
			],
			json_decode( $this->requests[3]['args']['body'], true )
		);
		$this->assertSame( 'PUT', $this->requests[4]['args']['method'] );
		$this->assertSame(
			[
				'ids'             => [ 12 ],
				'action'          => 'add',
				'target_list_ids' => [ 9, 11 ],
				'status'          => 'unconfirmed',
			],
			json_decode( $this->requests[4]['args']['body'], true )
		);
		$this->assertSame( 'POST', $this->requests[5]['args']['method'] );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers/12/optin', $this->requests[5]['url'] );
	}

	public function test_rest_duplicate_409_sends_optin_for_mixed_existing_unconfirmed_and_missing_single_list(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => "3\n7",
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"conflict"}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[
										'id'    => 12,
										'email' => 'ada@example.test',
										'lists' => [
											[ 'id' => 3, 'optin' => 'double', 'subscription_status' => 'unconfirmed' ],
										],
									],
								],
							],
						]
					),
				],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 7, 'optin' => 'single' ],
								],
							],
						]
					),
				],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":true}' ],
			]
		);

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);

		$payload = $this->redirect_payload_from_url( $response->get_data()['redirect_url'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertCount( 5, $this->requests );
		$this->assertStringStartsWith( 'https://newsletter.example.test/api/lists?', $this->requests[2]['url'] );
		$this->assertSame( 'PUT', $this->requests[3]['args']['method'] );
		$this->assertSame(
			[
				'ids'             => [ 12 ],
				'action'          => 'add',
				'target_list_ids' => [ 7 ],
				'status'          => 'confirmed',
			],
			json_decode( $this->requests[3]['args']['body'], true )
		);
		$this->assertSame( 'POST', $this->requests[4]['args']['method'] );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers/12/optin', $this->requests[4]['url'] );
	}

	public function test_rest_duplicate_409_lookup_miss_returns_generic_error(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"already exists maybe"}' ],
				[ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"results":[]}}' ],
			]
		);

		$response = $this->plugin->handle_rest_submission(
			$this->rest_request(
				[
					'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
					'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
					'return_to' => home_url( '/newsletter/' ),
					'email' => 'ada@example.test',
					'consent' => '1',
				]
			)
		);

		$data    = $response->get_data();
		$payload = $this->redirect_payload_from_url( $data['redirect_url'] );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'error', $data['result'] );
		$this->assertSame( 'subscription_failed', $data['error_code'] );
		$this->assertSame( 'Error!', $data['message'] );
		$this->assertSame( 'Error!', $payload['result']['message'] );
	}

	public function test_shortcode_consumes_result_once_and_restores_values(): void {
		$token = wp_generate_uuid4();
		set_transient(
			'listmonk_signup_result_' . $token,
			[
				'result' => [ 'type' => 'error', 'message' => 'Please enter a valid email address.' ],
				'values' => [ 'email' => 'ada@example.test', 'vorname' => 'Ada', 'bezirke' => [ '1020' ] ],
			],
			300
		);
		$_GET['listmonk_signup_result'] = $token;

		$html = $this->plugin->render_shortcode();

		$this->assertStringContainsString( 'listmonk-signup__message--error', $html );
		$this->assertStringContainsString( 'value="ada@example.test"', $html );
		$this->assertStringContainsString( 'value="Ada"', $html );
		$this->assertStringContainsString( 'value="1020"', $html );
		$this->assertFalse( get_transient( 'listmonk_signup_result_' . $token ) );
	}

	public function test_subscriber_api_request_success_and_failure(): void {
		update_option( 'listmonk_signup_settings', [ 'debug_logging' => '1' ] );
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
				'attribs' => [ 'anrede' => 'Dear', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [] ],
			],
			json_decode( $this->requests[0]['args']['body'], true )
		);
		$logs = get_option( 'listmonk_signup_logs' );
		$this->assertSame( 'Submitting subscriber API request.', $logs[0]['message'] );
		$this->assertSame( 'req-1', $logs[0]['context']['request_id'] );
		$this->assertSame( '1', $logs[0]['context']['list_count'] );
		$this->assertSame( '1', $logs[0]['context']['has_name'] );
		$this->assertSame( 'Subscriber API response.', $logs[1]['message'] );
		$this->assertSame( 'req-1', $logs[1]['context']['request_id'] );
		$this->assertSame( '201', $logs[1]['context']['http_code'] );

		remove_all_filters( 'pre_http_request' );
		delete_option( 'listmonk_signup_logs' );
		$this->mock_http_response( [ 'response' => [ 'code' => 500 ], 'body' => 'error' ] );
		$this->assertWPError( $this->call_private( 'subscribe_via_subscribers_endpoint', [ $settings, [ 3 ], $values, 'req-2' ] ) );
		$logs = get_option( 'listmonk_signup_logs' );
		$this->assertSame( 'Subscriber API response.', $logs[1]['message'] );
		$this->assertSame( '500', $logs[1]['context']['http_code'] );
		$failures = get_option( 'listmonk_signup_api_failures' );
		$this->assertCount( 1, $failures );
		$this->assertStringNotContainsString( 'ada@example.test', wp_json_encode( $failures ) );
		$this->assertStringContainsString( '500', wp_json_encode( $failures ) );

		remove_all_filters( 'pre_http_request' );
		delete_option( 'listmonk_signup_logs' );
		delete_option( 'listmonk_signup_api_failures' );
		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"Email already exists."}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 12, 'email' => 'ada@example.test', 'lists' => [ [ 'id' => 3 ] ] ],
								],
							],
						]
					),
				],
			]
		);
		$this->assertTrue( $this->call_private( 'subscribe_via_subscribers_endpoint', [ $settings, [ 3 ], $values, 'req-3' ] ) );
		$logs = get_option( 'listmonk_signup_logs' );
		$this->assertSame( 'Subscriber API reported conflict; verifying existing subscriber state.', $logs[2]['message'] );
		$this->assertSame( 'Subscriber lookup API response.', $logs[3]['message'] );
		$this->assertSame( 'Existing subscriber already has requested list memberships; treating signup as successful.', $logs[4]['message'] );
		$this->assertFalse( get_option( 'listmonk_signup_api_failures' ) );
	}

	public function test_duplicate_409_missing_list_update_failure_is_error(): void {
		$settings = [ 'base_url' => 'https://newsletter.example.test', 'api_token' => 'api:token' ];
		$values   = [ 'email' => 'ada@example.test', 'anrede' => '', 'vorname' => 'Ada', 'nachname' => 'Lovelace', 'bezirke' => [] ];

		$this->mock_http_responses(
			[
				[ 'response' => [ 'code' => 409 ], 'body' => '{"message":"already exists"}' ],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 12, 'email' => 'ada@example.test', 'lists' => [ [ 'id' => 3 ] ] ],
								],
							],
						]
					),
				],
				[
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'data' => [
								'results' => [
									[ 'id' => 7, 'optin' => 'double' ],
								],
							],
						]
					),
				],
				[ 'response' => [ 'code' => 500 ], 'body' => '{"message":"failed"}' ],
			]
		);

		$this->assertWPError( $this->call_private( 'subscribe_via_subscribers_endpoint', [ $settings, [ 3, 7 ], $values, 'req-4' ] ) );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers/lists', $this->requests[3]['url'] );
		$this->assertCount( 1, get_option( 'listmonk_signup_api_failures' ) );
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

	public function test_submission_without_nonce_returns_retry_error_without_result_transient(): void {
		$result = $this->process_submission_payload(
		[
			'return_to' => home_url( '/newsletter/?listmonk_signup_result=old' ),
			'email'     => 'bad-email',
			'vorname'   => 'Ada',
		]
		);

		$this->assertSame( home_url( '/newsletter/' ), $result['redirect_url'] );
		$this->assertStringNotContainsString( 'listmonk_signup_result=old', $result['redirect_url'] );
		$this->assertNoSubmissionResultToken( $result['redirect_url'] );
		$this->assertSame( 'error', $result['result']['type'] );
		$this->assertSame( 'Your session has expired. Please reload the page and try again.', $result['result']['message'] );
		$this->assertSame( 'Ada', $result['values']['vorname'] );
	}

	public function test_submission_rejects_missing_consent_and_subscriber_api_failures(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$input = [
			'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
			'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
		];

		$result  = $this->process_submission_payload( $input );
		$payload = $this->redirect_payload_from_url( $result['redirect_url'] );
		$this->assertSame( 'error', $payload['result']['type'] );
		$this->assertSame( 'Please confirm that you want to subscribe to the newsletter.', $payload['result']['message'] );
		$this->assertSame( 'ada@example.test', $payload['values']['email'] );

		$input['listmonk_submission_token'] = $this->call_private( 'create_submission_token' );
		$input['consent'] = '1';
		$this->mock_http_response( [ 'response' => [ 'code' => 500 ], 'body' => 'error' ] );

		$result  = $this->process_submission_payload( $input );
		$payload = $this->redirect_payload_from_url( $result['redirect_url'] );
		$this->assertSame( 'error', $payload['result']['type'] );
		$this->assertSame( 'Error!', $payload['result']['message'] );
		$this->assertSame( 'ada@example.test', $payload['values']['email'] );
	}

	public function test_successful_submission_calls_subscriber_api(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
			]
		);

		$nonce = wp_create_nonce( 'listmonk_signup_submit' );
		$token = $this->call_private( 'create_submission_token' );
		$input = [
			'listmonk_signup_nonce' => $nonce,
			'listmonk_submission_token' => $token,
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
			'nachname' => 'Lovelace',
			'bezirke' => [ '1020' ],
		];

		$this->mock_http_response( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );

		$result = $this->process_submission_payload( $input );
		$this->assertStringContainsString( 'listmonk_signup_result=', $result['redirect_url'] );
		$payload = $this->redirect_payload_from_url( $result['redirect_url'] );
		$this->assertSame( 'success', $payload['result']['type'] );
		$this->assertSame( 'Thanks!', $payload['result']['message'] );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://newsletter.example.test/api/subscribers', $this->requests[0]['url'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( [ 3 ], $body['lists'] );
		$this->assertSame( [ '1020' ], $body['attribs']['bezirke'] );
	}

	public function test_successful_submission_correlates_debug_logs(): void {
		update_option(
			'listmonk_signup_settings',
			[
				'base_url'        => 'https://newsletter.example.test',
				'api_token'       => 'api:token',
				'list_ids'        => '3',
				'success_message' => 'Thanks!',
				'error_message'   => 'Error!',
				'debug_logging'   => '1',
			]
		);

		$input = [
			'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
			'listmonk_submission_token' => $this->call_private( 'create_submission_token' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
			'nachname' => 'Lovelace',
			'bezirke' => [ '1020' ],
		];

		$this->mock_http_response( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );

		$this->process_submission_payload( $input );
		$logs         = get_option( 'listmonk_signup_logs' );
		$request_logs = array_values(
			array_filter(
				$logs,
				static fn ( array $log ): bool => isset( $log['context']['request_id'] )
			)
		);
		$request_id = $request_logs[0]['context']['request_id'];

		$this->assertNotSame( '', $request_id );
		$this->assertSame( 'Frontend signup submission received via rest_json.', $logs[0]['message'] );
		$this->assertSame( 'Submitting subscriber API request.', $request_logs[0]['message'] );
		$this->assertSame( 'Subscriber API response.', $request_logs[1]['message'] );
		foreach ( $request_logs as $log ) {
			$this->assertSame( $request_id, $log['context']['request_id'] );
		}
	}

	public function test_submission_token_can_only_be_claimed_once(): void {
		$token = $this->call_private( 'create_submission_token' );
		$_POST = [ 'listmonk_submission_token' => $token ];

		$this->assertTrue( $this->call_private( 'consume_submission_token' ) );

		set_transient( 'listmonk_submission_token_' . $token, '1', 600 );
		$this->assertFalse( $this->call_private( 'consume_submission_token' ) );
	}

	public function test_invalid_submission_token_returns_retry_error_without_http_or_result_transient(): void {
		$result = $this->process_submission_payload(
		[
			'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'vorname' => 'Ada',
		]
		);

		$this->assertSame( [], $this->requests );
		$this->assertSame( 'error', $result['result']['type'] );
		$this->assertSame( 'invalid_submission_token', $result['result']['code'] );
		$this->assertSame( 'Your session has expired. Please reload the page and try again.', $result['result']['message'] );
		$this->assertSame( 'ada@example.test', $result['values']['email'] );
		$this->assertSame( 'Ada', $result['values']['vorname'] );
		$this->assertNoSubmissionResultToken( $result['redirect_url'] );
	}

	public function test_honeypot_short_circuits_as_success_without_http_or_token(): void {
		update_option( 'listmonk_signup_settings', [ 'success_message' => 'Thanks!' ] );
		$result = $this->process_submission_payload(
		[
			'listmonk_signup_nonce' => wp_create_nonce( 'listmonk_signup_submit' ),
			'return_to' => home_url( '/newsletter/' ),
			'email' => 'ada@example.test',
			'consent' => '1',
			'website' => 'bot',
		]
		);

		$this->assertSame( [], $this->requests );
		$this->assertSame( 'success', $result['result']['type'] );
		$this->assertSame( 'Thanks!', $result['result']['message'] );
		$this->assertNoSubmissionResultToken( $result['redirect_url'] );
	}

	public function test_debug_logs_caps_and_clear_logs(): void {
		update_option( 'listmonk_signup_settings', [ 'debug_logging' => '1' ] );

		for ( $i = 0; $i < 55; $i++ ) {
			$this->call_private( 'debug_log', [ 'Log user' . $i . '@example.test token secretvalue', [ 'email' => 'user@example.test', 'api_token' => 'secret' ] ] );
		}

		$logs = get_option( 'listmonk_signup_logs' );
		$this->assertCount( 50, $logs );
		$this->assertStringNotContainsString( 'user@example.test', wp_json_encode( $logs ) );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $logs ) );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->call_private( 'record_api_failure', [ 'Failure ' . $i, [ 'email' => 'ada@example.test' ] ] );
		}
		$this->assertCount( 10, get_option( 'listmonk_signup_api_failures' ) );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$_POST = [
			'listmonk_clear_logs' => '1',
			'_wpnonce' => wp_create_nonce( 'listmonk_signup_clear_logs' ),
		];
		$_REQUEST = $_POST;

		try {
			$this->plugin->handle_clear_logs();
			$this->fail( 'Expected redirect termination.' );
		} catch ( RuntimeException $exception ) {
			$this->assertFalse( get_option( 'listmonk_signup_logs' ) );
			$this->assertFalse( get_option( 'listmonk_signup_api_failures' ) );
		}
	}

	public function test_settings_page_shows_api_failures_with_clear_button_without_debug_logs(): void {
		$this->call_private( 'record_api_failure', [ 'Failure', [ 'http_code' => 500, 'email' => 'ada@example.test' ] ] );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		ob_start();
		$this->plugin->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'API notices', $html );
		$this->assertStringContainsString( 'Failure', $html );
		$this->assertStringContainsString( 'Clear logs', $html );
		$this->assertStringContainsString( 'name="listmonk_clear_logs"', $html );
		$this->assertStringNotContainsString( 'ada@example.test', $html );
	}

	private function call_private( string $method, array $args = [] ) {
		$reflection = new ReflectionMethod( $this->plugin, $method );

		return $reflection->invokeArgs( $this->plugin, $args );
	}

	private function mock_http_response( $response ): void {
		$this->mock_http_responses( [ $response ] );
	}

	private function mock_http_responses( array $responses ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) use ( $responses ) {
				static $index = 0;
				$this->requests[] = [ 'args' => $args, 'url' => $url ];

				$response = $responses[ min( $index, count( $responses ) - 1 ) ];
				$index++;

				return $response;
			},
			10,
			3
		);
	}

	private function process_submission_payload( array $payload ): array {
		return $this->call_private( 'process_submission', [ $payload, 'rest_json' ] );
	}

	private function rest_request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/listmonk-signup/v1/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	private function redirect_payload(): array {
		return $this->redirect_payload_from_url( $this->last_redirect );
	}

	private function redirect_payload_from_url( string $url ): array {
		$parts = wp_parse_url( $url );
		$this->assertIsArray( $parts );
		parse_str( $parts['query'] ?? '', $query );
		$this->assertArrayHasKey( 'listmonk_signup_result', $query );
		$payload = get_transient( 'listmonk_signup_result_' . sanitize_key( $query['listmonk_signup_result'] ) );
		$this->assertIsArray( $payload );

		return $payload;
	}

	private function assertNoSubmissionResultToken( string $url ): void {
		$parts = wp_parse_url( $url );
		$this->assertIsArray( $parts );
		parse_str( $parts['query'] ?? '', $query );
		$this->assertArrayNotHasKey( 'listmonk_signup_result', $query );
	}
}
