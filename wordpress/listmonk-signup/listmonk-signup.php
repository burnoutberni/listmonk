<?php
/**
 * Plugin Name: Listmonk Signup
 * Plugin URI: https://github.com/burnoutberni/listmonk
 * Description: Adds a configurable Listmonk newsletter signup shortcode for WordPress.
 * Version: 1.1.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Bernhard Hayden
 * Author URI: https://bhayden.at
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: listmonk-signup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Listmonk_Signup {
	private const OPTION_NAME = 'listmonk_signup_settings';
	private const LOG_OPTION_NAME = 'listmonk_signup_logs';
	private const API_FAILURE_OPTION_NAME = 'listmonk_signup_api_failures';
	private const NONCE_ACTION = 'listmonk_signup_submit';
	private const NONCE_NAME = 'listmonk_signup_nonce';
	private const SUBMISSION_TOKEN_NAME = 'listmonk_submission_token';
	private const SUBMISSION_TOKEN_PREFIX = 'listmonk_submission_token_';
	private const SUBMISSION_TOKEN_CLAIM_PREFIX = 'listmonk_submission_token_claim_';
	private const CLEAR_LOGS_ACTION = 'listmonk_signup_clear_logs';
	private const RESULT_QUERY_ARG = 'listmonk_signup_result';
	private const RESULT_TRANSIENT_PREFIX = 'listmonk_signup_result_';
	private const RATE_LIMIT_SECONDS = 300;
	private const RATE_LIMIT_EMAIL_IP_MAX = 5;
	private const RATE_LIMIT_IP_MAX = 25;
	private const RESULT_TTL_SECONDS = 300;
	private const SUBMISSION_TOKEN_TTL_SECONDS = 600;
	private const MAX_LOG_ENTRIES = 50;
	private const MAX_API_FAILURES = 10;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_clear_logs' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_shortcode( 'listmonk_signup', [ $this, 'render_shortcode' ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'listmonk-signup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public static function activate(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	private static function defaults(): array {
		return [
			'base_url'        => 'https://newsletter.example.com',
			'api_token'       => '',
			'list_ids'        => '3',
			'success_message' => __( 'Thank you! Please check your email inbox and confirm your subscription.', 'listmonk-signup' ),
			'error_message'   => __( 'The subscription could not be completed. Please try again later.', 'listmonk-signup' ),
			'consent_text'    => __( 'I want to subscribe to the newsletter and accept that my information will be processed to send the newsletter. You can find details in the privacy policy. I can unsubscribe at any time.', 'listmonk-signup' ),
			'debug_logging'   => '0',
		];
	}

	private function districts(): array {
		return [
			'1010' => '1., Innere Stadt',
			'1020' => '2., Leopoldstadt',
			'1030' => __( '3., Landstrasse', 'listmonk-signup' ),
			'1040' => '4., Wieden',
			'1050' => '5., Margareten',
			'1060' => '6., Mariahilf',
			'1070' => '7., Neubau',
			'1080' => '8., Josefstadt',
			'1090' => '9., Alsergrund',
			'1100' => '10., Favoriten',
			'1110' => '11., Simmering',
			'1120' => '12., Meidling',
			'1130' => '13., Hietzing',
			'1140' => '14., Penzing',
			'1150' => '15., RH5H',
			'1160' => '16., Ottakring',
			'1170' => '17., Hernals',
			'1180' => __( '18., Waehring', 'listmonk-signup' ),
			'1190' => __( '19., Doebling', 'listmonk-signup' ),
			'1200' => '20., Brigittenau',
			'1210' => '21., Floridsdorf',
			'1220' => '22., Donaustadt',
			'1230' => '23., Liesing',
		];
	}

	private function settings(): array {
		$settings = get_option( self::OPTION_NAME, [] );

		return wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
	}

	public function add_settings_page(): void {
		add_options_page(
			__( 'Listmonk Signup', 'listmonk-signup' ),
			__( 'Listmonk Signup', 'listmonk-signup' ),
			'manage_options',
			'listmonk-signup',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'listmonk_signup',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => self::defaults(),
			]
		);
	}

	public function sanitize_settings( $input ): array {
		$defaults = self::defaults();
		$previous = $this->settings();
		$input    = is_array( $input ) ? $input : [];

		$base_url = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : '';
		$base_url = $base_url ? untrailingslashit( $base_url ) : '';
		if ( '' !== $base_url && 'https' !== wp_parse_url( $base_url, PHP_URL_SCHEME ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'listmonk_base_url_https',
				__( 'Listmonk base URL must start with https://.', 'listmonk-signup' ),
				'error'
			);
			$base_url = ! empty( $previous['base_url'] ) && 'https' === wp_parse_url( $previous['base_url'], PHP_URL_SCHEME ) ? $previous['base_url'] : $defaults['base_url'];
		}

		$api_token = isset( $input['api_token'] ) ? trim( (string) $input['api_token'] ) : '';
		if ( '' === $api_token && ! empty( $previous['api_token'] ) ) {
			$api_token = $previous['api_token'];
		}
		if ( '' === $api_token ) {
			add_settings_error(
				self::OPTION_NAME,
				'listmonk_api_token_required',
				__( 'Listmonk API credential is required and must use api_user:token format.', 'listmonk-signup' ),
				'error'
			);
		} elseif ( ! $this->api_token_has_valid_format( $api_token ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'listmonk_api_token_format',
				__( 'Listmonk API credential must use api_user:token format.', 'listmonk-signup' ),
				'error'
			);
			$api_token = ! empty( $previous['api_token'] ) && $this->api_token_has_valid_format( $previous['api_token'] ) ? $previous['api_token'] : '';
		}

		$list_ids = isset( $input['list_ids'] ) ? sanitize_textarea_field( (string) $input['list_ids'] ) : '';
		$list_ids = implode( "\n", $this->parse_list_ids( $list_ids ) );
		if ( '' === $list_ids ) {
			add_settings_error(
				self::OPTION_NAME,
				'listmonk_list_ids_required',
				__( 'At least one numeric Listmonk list ID is required.', 'listmonk-signup' ),
				'error'
			);
			$list_ids = ! empty( $previous['list_ids'] ) ? implode( "\n", $this->parse_list_ids( $previous['list_ids'] ) ) : '';
		}

		return [
			'base_url'        => $base_url ?: $defaults['base_url'],
			'api_token'       => sanitize_text_field( $api_token ),
			'list_ids'        => $list_ids,
			'success_message' => isset( $input['success_message'] ) ? sanitize_text_field( (string) $input['success_message'] ) : $defaults['success_message'],
			'error_message'   => isset( $input['error_message'] ) ? sanitize_text_field( (string) $input['error_message'] ) : $defaults['error_message'],
			'consent_text'    => isset( $input['consent_text'] ) ? wp_kses_post( (string) $input['consent_text'] ) : $defaults['consent_text'],
			'debug_logging'   => ! empty( $input['debug_logging'] ) ? '1' : '0',
		];
	}

	public function handle_clear_logs(): void {
		if ( empty( $_POST['listmonk_clear_logs'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::CLEAR_LOGS_ACTION );
		delete_option( self::LOG_OPTION_NAME );
		delete_option( self::API_FAILURE_OPTION_NAME );
		wp_safe_redirect( add_query_arg( 'listmonk_logs_cleared', '1', menu_page_url( 'listmonk-signup', false ) ) );
		$this->terminate_request();
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings();
		$logs     = $this->logs();
		$failures = $this->api_failures();
		$has_logs = ! empty( $logs ) || ! empty( $failures );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( ! empty( $_GET['listmonk_logs_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Listmonk Signup logs and API notices were deleted.', 'listmonk-signup' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $failures ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Listmonk Signup:</strong> <?php echo esc_html__( 'At least one Listmonk API request could not be completed.', 'listmonk-signup' ); ?></p>
					<ul>
						<?php foreach ( array_reverse( $failures ) as $failure ) : ?>
							<li><?php echo esc_html( $failure['time'] ?? '' ); ?>: <?php echo esc_html( $failure['message'] ?? '' ); ?> <code><?php echo esc_html( wp_json_encode( $failure['context'] ?? [] ) ); ?></code></li>
						<?php endforeach; ?>
					</ul>
					<p><?php echo esc_html__( 'Please check the Listmonk API credentials and logs. The notices are removed with "Clear logs".', 'listmonk-signup' ); ?></p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'listmonk_signup' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="listmonk-base-url"><?php echo esc_html__( 'Listmonk Base URL', 'listmonk-signup' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[base_url]" id="listmonk-base-url" type="url" class="regular-text" value="<?php echo esc_attr( $settings['base_url'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="listmonk-api-token"><?php echo esc_html__( 'Listmonk API User + Token', 'listmonk-signup' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_token]" id="listmonk-api-token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $settings['api_token'] ) ? 'api_user:token' : __( 'Saved - leave empty to keep', 'listmonk-signup' ) ); ?>">
							<p class="description"><?php echo wp_kses( __( 'Required. Listmonk expects <code>api_user:token</code>, for example <code>newsletter_api:abc123...</code>. The signup uses the authenticated subscriber API so attributes are saved directly.', 'listmonk-signup' ), [ 'code' => [] ] ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="listmonk-list-ids"><?php echo esc_html__( 'List IDs', 'listmonk-signup' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[list_ids]" id="listmonk-list-ids" class="large-text code" rows="4" required><?php echo esc_textarea( $settings['list_ids'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'One numeric list ID per line or comma-separated. Default: 3.', 'listmonk-signup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="listmonk-success-message"><?php echo esc_html__( 'Success message', 'listmonk-signup' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[success_message]" id="listmonk-success-message" type="text" class="large-text" value="<?php echo esc_attr( $settings['success_message'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="listmonk-error-message"><?php echo esc_html__( 'Error message', 'listmonk-signup' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[error_message]" id="listmonk-error-message" type="text" class="large-text" value="<?php echo esc_attr( $settings['error_message'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="listmonk-consent-text"><?php echo esc_html__( 'Consent text', 'listmonk-signup' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[consent_text]" id="listmonk-consent-text" class="large-text" rows="5"><?php echo esc_textarea( $settings['consent_text'] ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Safe HTML such as links is allowed.', 'listmonk-signup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Temporary debug logging', 'listmonk-signup' ); ?></th>
						<td>
							<label><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_logging]" type="checkbox" value="1" <?php checked( $settings['debug_logging'], '1' ); ?>> <?php echo esc_html__( 'Store Listmonk API debug logs', 'listmonk-signup' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Enable only briefly for testing. No API tokens are stored, but technical API responses and shortened email addresses can be visible.', 'listmonk-signup' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Debug logs', 'listmonk-signup' ); ?></h2>
			<p><?php printf( esc_html__( 'The latest %s entries from the temporary plugin logging.', 'listmonk-signup' ), esc_html( (string) self::MAX_LOG_ENTRIES ) ); ?></p>
			<?php if ( empty( $logs ) ) : ?>
				<p><em><?php echo esc_html__( 'No logs available.', 'listmonk-signup' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Time', 'listmonk-signup' ); ?></th>
							<th><?php echo esc_html__( 'Message', 'listmonk-signup' ); ?></th>
							<th><?php echo esc_html__( 'Context', 'listmonk-signup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $logs ) as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
								<td><?php echo esc_html( $log['message'] ?? '' ); ?></td>
								<td><code><?php echo esc_html( wp_json_encode( $log['context'] ?? [] ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'API notices', 'listmonk-signup' ); ?></h2>
			<p><?php printf( esc_html__( 'The latest %s Listmonk API notices, even when temporary debug logging is disabled.', 'listmonk-signup' ), esc_html( (string) self::MAX_API_FAILURES ) ); ?></p>
			<?php if ( empty( $failures ) ) : ?>
				<p><em><?php echo esc_html__( 'No API notices available.', 'listmonk-signup' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Time', 'listmonk-signup' ); ?></th>
							<th><?php echo esc_html__( 'Message', 'listmonk-signup' ); ?></th>
							<th><?php echo esc_html__( 'Context', 'listmonk-signup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $failures ) as $failure ) : ?>
							<tr>
								<td><?php echo esc_html( $failure['time'] ?? '' ); ?></td>
								<td><?php echo esc_html( $failure['message'] ?? '' ); ?></td>
								<td><code><?php echo esc_html( wp_json_encode( $failure['context'] ?? [] ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $has_logs ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=listmonk-signup' ) ); ?>" style="margin-top:1rem;">
					<?php wp_nonce_field( self::CLEAR_LOGS_ACTION ); ?>
					<button class="button" type="submit" name="listmonk_clear_logs" value="1"><?php echo esc_html__( 'Clear logs', 'listmonk-signup' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'listmonk-signup/v1',
			'/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_rest_submission' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle_rest_submission( WP_REST_Request $request ): WP_REST_Response {
		nocache_headers();

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		$result          = $this->process_submission( $payload, 'rest_json' );
		$submission_type = $result['result']['type'] ?? 'error';
		$response        = rest_ensure_response(
			[
				'redirect_url' => $result['redirect_url'],
				'result'       => $submission_type,
				'message'      => $result['result']['message'] ?? '',
				'error_code'   => 'error' === $submission_type ? ( $result['result']['code'] ?? 'submission_failed' ) : null,
			]
		);
		$response->set_status( $result['result']['status'] ?? 200 );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	private function process_submission( array $raw_input, string $source ): array {
		$redirect_url = $this->submission_redirect_url( $raw_input );
		$values       = $this->sanitize_frontend_values( $raw_input );

		$this->debug_log(
			'Frontend signup submission received via ' . $source . '.',
			[
				'source'       => $source,
				'redirect_url' => $redirect_url,
				'has_email'    => '' !== $values['email'],
				'has_consent'  => $values['consent'],
			]
		);

		if ( ! isset( $raw_input[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( (string) $raw_input[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			$this->debug_log( 'Frontend signup rejected: invalid nonce.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload_without_storage( $redirect_url, $this->error_result( __( 'Your session has expired. Please reload the page and try again.', 'listmonk-signup' ), 'invalid_nonce', 403 ), $values );
		}

		if ( ! empty( $raw_input['website'] ) ) {
			$this->debug_log( 'Frontend signup accepted as honeypot submission.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload_without_storage( $redirect_url, $this->success_result() );
		}

		if ( ! $this->consume_submission_token( $raw_input ) ) {
			$this->debug_log( 'Frontend signup rejected: invalid submission token.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload_without_storage( $redirect_url, $this->error_result( __( 'Your session has expired. Please reload the page and try again.', 'listmonk-signup' ), 'invalid_submission_token', 403 ), $values );
		}

		if ( empty( $values['email'] ) || ! is_email( $values['email'] ) ) {
			$this->debug_log( 'Frontend signup rejected: invalid email.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload( $redirect_url, $this->error_result( __( 'Please enter a valid email address.', 'listmonk-signup' ), 'invalid_email', 400 ), $values );
		}

		if ( empty( $values['consent'] ) ) {
			$this->debug_log( 'Frontend signup rejected: missing consent.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload( $redirect_url, $this->error_result( __( 'Please confirm that you want to subscribe to the newsletter.', 'listmonk-signup' ), 'missing_consent', 400 ), $values );
		}

		if ( $this->is_rate_limited( $values['email'] ) ) {
			$this->debug_log( 'Frontend signup rejected: rate limited.', [ 'source' => $source, 'redirect_url' => $redirect_url ] );
			return $this->submission_result_payload( $redirect_url, $this->error_result( __( 'Please wait a moment before trying again.', 'listmonk-signup' ), 'rate_limited', 429 ), $values );
		}

		$settings = $this->settings();
		$list_ids = $this->parse_list_ids( $settings['list_ids'] );

		if ( empty( $settings['base_url'] ) || empty( $settings['api_token'] ) || ! $this->api_token_has_valid_format( $settings['api_token'] ) || empty( $list_ids ) ) {
			$this->debug_log(
				'Frontend signup rejected: invalid plugin configuration.',
				[
					'source'          => $source,
					'redirect_url'    => $redirect_url,
					'has_base_url'    => ! empty( $settings['base_url'] ),
					'has_api_token'   => ! empty( $settings['api_token'] ),
					'valid_api_token' => ! empty( $settings['api_token'] ) && $this->api_token_has_valid_format( $settings['api_token'] ),
					'list_count'      => count( $list_ids ),
				]
			);
			return $this->submission_result_payload( $redirect_url, $this->error_result( $settings['error_message'], 'configuration_error', 500 ), $values );
		}

		$request_id = wp_generate_uuid4();
		$result     = $this->subscribe_via_subscribers_endpoint( $settings, $list_ids, $values, $request_id );

		if ( is_wp_error( $result ) ) {
			return $this->submission_result_payload( $redirect_url, $this->error_result( $settings['error_message'], 'subscription_failed', 500 ), $values );
		}

		return $this->submission_result_payload( $redirect_url, $this->success_result( $settings['success_message'] ) );
	}

	public function render_shortcode(): string {
		$this->enqueue_shortcode_assets();

		$settings          = $this->settings();
		$submission        = $this->consume_submission_result();
		$submission_result = $submission['result'] ?? [];
		$submission_token  = $this->create_submission_token();
		$values            = wp_parse_args(
			$submission['values'] ?? [],
			[
				'anrede'                    => '',
				'vorname'                   => '',
				'nachname'                  => '',
				'email'                     => '',
				'bezirke'                   => [],
			]
		);
		$districts = $this->districts();

		ob_start();
		?>
		<form class="listmonk-signup" method="post" data-listmonk-rest-url="<?php echo esc_url( rest_url( 'listmonk-signup/v1/submit' ) ); ?>">
			<?php if ( ! empty( $submission_result['message'] ) ) : ?>
				<div class="listmonk-signup__message listmonk-signup__message--<?php echo esc_attr( $submission_result['type'] ); ?>" role="status">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<section class="listmonk-signup__step">
				<h2><span>1</span><?php echo esc_html__( 'Email', 'listmonk-signup' ); ?></h2>
				<p class="listmonk-signup__field">
					<label for="listmonk-email"><?php echo esc_html__( 'Email address *', 'listmonk-signup' ); ?></label>
					<input id="listmonk-email" name="email" type="email" value="<?php echo esc_attr( $values['email'] ); ?>" autocomplete="email" required>
				</p>
			</section>

			<section class="listmonk-signup__step listmonk-signup__details">
				<h2><span>2</span><?php echo esc_html__( 'Optional: How should we address you?', 'listmonk-signup' ); ?></h2>

				<div class="listmonk-signup__grid">
					<p class="listmonk-signup__field">
						<label for="listmonk-anrede"><?php echo esc_html__( 'Salutation', 'listmonk-signup' ); ?></label>
						<input id="listmonk-anrede" name="anrede" type="text" value="<?php echo esc_attr( $values['anrede'] ); ?>" placeholder="<?php echo esc_attr__( 'Dear', 'listmonk-signup' ); ?>" autocomplete="honorific-prefix">
					</p>

					<p class="listmonk-signup__field">
						<label for="listmonk-vorname"><?php echo esc_html__( 'First name', 'listmonk-signup' ); ?></label>
						<input id="listmonk-vorname" name="vorname" type="text" value="<?php echo esc_attr( $values['vorname'] ); ?>" autocomplete="given-name">
					</p>

					<p class="listmonk-signup__field">
						<label for="listmonk-nachname"><?php echo esc_html__( 'Last name', 'listmonk-signup' ); ?></label>
						<input id="listmonk-nachname" name="nachname" type="text" value="<?php echo esc_attr( $values['nachname'] ); ?>" autocomplete="family-name">
					</p>
				</div>
			</section>

			<fieldset class="listmonk-signup__step listmonk-signup__districts">
				<legend><span>3</span><?php echo esc_html__( 'Optional: Which districts are you interested in?', 'listmonk-signup' ); ?></legend>
				<p class="listmonk-signup__hint"><?php echo esc_html__( 'If you select one or more districts, you agree that we may use your email address to send you information about local initiatives in these districts in the future.', 'listmonk-signup' ); ?></p>
				<div class="listmonk-signup__district-grid">
					<?php foreach ( $districts as $district_id => $district_label ) : ?>
						<label class="listmonk-signup__district">
							<input name="bezirke[]" type="checkbox" value="<?php echo esc_attr( $district_id ); ?>" <?php checked( in_array( $district_id, $values['bezirke'], true ) ); ?>>
							<span><?php echo esc_html( $district_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<section class="listmonk-signup__step">
				<h2><span>4</span><?php echo esc_html__( 'Consent', 'listmonk-signup' ); ?></h2>
				<p class="listmonk-signup__field listmonk-signup__field--consent">
					<label>
						<input name="consent" type="checkbox" value="1" required>
						<span><?php echo wp_kses_post( $settings['consent_text'] ); ?></span>
					</label>
				</p>
			</section>

			<p class="listmonk-signup__field listmonk-signup__field--hp" aria-hidden="true">
				<label for="listmonk-website"><?php echo esc_html__( 'Website', 'listmonk-signup' ); ?></label>
				<input id="listmonk-website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
			</p>

			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::SUBMISSION_TOKEN_NAME ); ?>" value="<?php echo esc_attr( $submission_token ); ?>">
			<input type="hidden" name="return_to" value="<?php echo esc_url( get_permalink() ); ?>">
			<input type="hidden" name="listmonk_signup_submit" value="1">
			<section class="listmonk-signup__step listmonk-signup__step--submit">
				<h2><span>5</span><?php echo esc_html__( 'Submit now!', 'listmonk-signup' ); ?></h2>
				<button type="submit"><?php echo esc_html__( 'Subscribe to newsletter', 'listmonk-signup' ); ?></button>
			</section>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	private function enqueue_shortcode_assets(): void {
		wp_enqueue_style(
			'listmonk-signup',
			plugins_url( 'assets/listmonk-signup.css', __FILE__ ),
			[],
			'1.1.2'
		);
		wp_enqueue_script(
			'listmonk-signup',
			plugins_url( 'assets/listmonk-signup.js', __FILE__ ),
			[],
			'1.1.2',
			true
		);
		wp_localize_script(
			'listmonk-signup',
			'listmonkSignup',
			[
				'errorMessage' => __( 'The subscription could not be completed. Please try again later.', 'listmonk-signup' ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	private function submission_redirect_url( array $input ): string {
		$return_to = '';

		if ( isset( $input['return_to'] ) ) {
			$return_to = esc_url_raw( (string) $input['return_to'] );
		}

		if ( '' === $return_to ) {
			$return_to = wp_get_referer() ?: home_url( '/' );
		}

		$return_to = wp_validate_redirect( $return_to, home_url( '/' ) );

		return remove_query_arg( self::RESULT_QUERY_ARG, $return_to );
	}

	private function submission_result_payload( string $redirect_url, array $result, array $values = [] ): array {
		$token        = $this->store_submission_result( $result, $values );
		$redirect_url = add_query_arg( self::RESULT_QUERY_ARG, rawurlencode( $token ), $redirect_url );

		return [
			'redirect_url' => $redirect_url,
			'result'       => $result,
			'values'       => $values,
		];
	}

	private function submission_result_payload_without_storage( string $redirect_url, array $result, array $values = [] ): array {
		return [
			'redirect_url' => $redirect_url,
			'result'       => $result,
			'values'       => $values,
		];
	}

	private function store_submission_result( array $result, array $values = [] ): string {
		$token = wp_generate_uuid4();

		set_transient(
			self::RESULT_TRANSIENT_PREFIX . $token,
			[
				'result' => $result,
				'values' => $values,
			],
			self::RESULT_TTL_SECONDS
		);

		return $token;
	}

	private function terminate_request(): void {
		if ( apply_filters( 'listmonk_signup_should_exit', true ) ) {
			exit;
		}

		throw new RuntimeException( 'Listmonk Signup request terminated.' );
	}

	private function consume_submission_result(): array {
		if ( empty( $_GET[ self::RESULT_QUERY_ARG ] ) ) {
			return [];
		}

		$token = sanitize_key( wp_unslash( $_GET[ self::RESULT_QUERY_ARG ] ) );
		if ( '' === $token ) {
			return [];
		}

		$key     = self::RESULT_TRANSIENT_PREFIX . $token;
		$payload = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $payload ) ) {
			return [];
		}

		$result = isset( $payload['result'] ) && is_array( $payload['result'] ) ? $payload['result'] : [];
		$values = isset( $payload['values'] ) && is_array( $payload['values'] ) ? $payload['values'] : [];

		return [
			'result' => [
				'type'    => isset( $result['type'] ) && 'success' === $result['type'] ? 'success' : 'error',
				'message' => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			],
			'values' => $this->sanitize_frontend_values( $values ),
		];
	}

	private function create_submission_token(): string {
		$token = wp_generate_uuid4();

		set_transient( self::SUBMISSION_TOKEN_PREFIX . $token, '1', self::SUBMISSION_TOKEN_TTL_SECONDS );

		return $token;
	}

	private function consume_submission_token( ?array $input = null ): bool {
		$input = null === $input ? wp_unslash( $_POST ) : $input;

		if ( empty( $input[ self::SUBMISSION_TOKEN_NAME ] ) ) {
			return false;
		}

		$token = sanitize_key( (string) $input[ self::SUBMISSION_TOKEN_NAME ] );
		if ( '' === $token ) {
			return false;
		}

		$key = self::SUBMISSION_TOKEN_PREFIX . $token;
		if ( false === get_transient( $key ) ) {
			return false;
		}

		if ( ! $this->claim_submission_token( $token ) ) {
			return false;
		}

		delete_transient( $key );

		return true;
	}

	private function claim_submission_token( string $token ): bool {
		$claim_key   = self::SUBMISSION_TOKEN_CLAIM_PREFIX . $token;
		$timeout_key = '_transient_timeout_' . $claim_key;
		$value_key   = '_transient_' . $claim_key;

		if ( ! add_option( $value_key, '1', '', false ) ) {
			return false;
		}

		add_option( $timeout_key, time() + self::SUBMISSION_TOKEN_TTL_SECONDS, '', false );

		return true;
	}

	private function sanitize_frontend_values( array $input ): array {
		$districts = $this->districts();
		$selected_districts = [];
		if ( isset( $input['bezirke'] ) && is_array( $input['bezirke'] ) ) {
			foreach ( $input['bezirke'] as $district_id ) {
				$district_id = sanitize_text_field( (string) $district_id );
				if ( isset( $districts[ $district_id ] ) ) {
					$selected_districts[] = $district_id;
				}
			}
		}

		return [
			'anrede'   => isset( $input['anrede'] ) ? sanitize_text_field( (string) $input['anrede'] ) : '',
			'vorname'  => isset( $input['vorname'] ) ? sanitize_text_field( (string) $input['vorname'] ) : '',
			'nachname' => isset( $input['nachname'] ) ? sanitize_text_field( (string) $input['nachname'] ) : '',
			'email'    => isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '',
			'consent'  => ! empty( $input['consent'] ),
			'bezirke'  => array_values( array_unique( $selected_districts ) ),
		];
	}

	private function subscribe_via_subscribers_endpoint( array $settings, array $list_ids, array $values, string $request_id = '' ) {
		$attribs = $this->subscriber_attribs( $values );
		$body    = [
			'email'   => $values['email'],
			'name'    => $this->build_name( $values ),
			'status'  => 'enabled',
			'lists'   => array_values( $list_ids ),
			'attribs' => $attribs,
		];

		$this->debug_log(
			'Submitting subscriber API request.',
			[
				'request_id'  => $request_id,
				'list_count'  => count( $list_ids ),
				'has_name'    => '' !== $body['name'],
				'attrib_keys' => array_keys( $attribs ),
			]
		);

		$response = wp_remote_request(
			trailingslashit( $settings['base_url'] ) . 'api/subscribers',
			[
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => $this->api_headers( $settings ),
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_api_failure(
				sprintf( __( 'Listmonk Subscriber API failed: %s', 'listmonk-signup' ), $response->get_error_message() ),
				[
					'email'      => $values['email'],
					'request_id' => $request_id,
				]
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->debug_log(
			'Subscriber API response.',
			[
				'request_id'        => $request_id,
				'http_code'         => $code,
				'response_has_body' => '' !== trim( wp_remote_retrieve_body( $response ) ),
			]
		);

		if ( $code < 200 || $code >= 300 ) {
			if ( 409 === $code ) {
				$recovery = $this->recover_existing_subscriber_signup( $settings, $list_ids, $values, $request_id );
				if ( true === $recovery ) {
					return true;
				}

				return $recovery;
			}

			$this->record_api_failure(
				__( 'Listmonk Subscriber API returned an error status.', 'listmonk-signup' ),
				[
					'email'      => $values['email'],
					'http_code'  => $code,
					'request_id' => $request_id,
					'body'       => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
			return new WP_Error( 'listmonk_subscriber_api_failed', 'Listmonk subscriber API failed.' );
		}

		return true;
	}

	private function recover_existing_subscriber_signup( array $settings, array $list_ids, array $values, string $request_id = '' ) {
		$this->debug_log(
			'Subscriber API reported conflict; verifying existing subscriber state.',
			[
				'request_id' => $request_id,
				'list_count' => count( $list_ids ),
			]
		);

		$subscriber = $this->find_listmonk_subscriber_by_email( $settings, $values['email'], $request_id );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		$subscriber_id = isset( $subscriber['id'] ) ? absint( $subscriber['id'] ) : 0;
		if ( $subscriber_id < 1 ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Subscriber API conflict recovery failed: subscriber ID missing.', 'listmonk-signup' ),
				[
					'email'      => $values['email'],
					'request_id' => $request_id,
				]
			);
		}

		$current_list_ids = $this->subscriber_list_ids( $subscriber );
		$missing_list_ids = array_values( array_diff( $list_ids, $current_list_ids ) );
		$subscriber_needs_optin = $this->subscriber_needs_optin_for_lists( $subscriber, $list_ids );

		if ( empty( $missing_list_ids ) ) {
			if ( $subscriber_needs_optin ) {
				$optin = $this->send_subscriber_optin( $settings, $subscriber_id, $values['email'], $request_id );
				if ( is_wp_error( $optin ) ) {
					return $optin;
				}
			}

			$this->debug_log(
				'Existing subscriber already has requested list memberships; treating signup as successful.',
				[
					'request_id'    => $request_id,
					'subscriber_id' => $subscriber_id,
					'list_count'    => count( $list_ids ),
				]
			);
			return true;
		}

		$list_optins = $this->list_optins_by_id( $settings, $missing_list_ids, $values['email'], $request_id );
		if ( is_wp_error( $list_optins ) ) {
			return $list_optins;
		}

		$single_optin_list_ids = [];
		$double_optin_list_ids = [];
		foreach ( $missing_list_ids as $list_id ) {
			if ( 'double' === ( $list_optins[ $list_id ] ?? '' ) ) {
				$double_optin_list_ids[] = $list_id;
			} else {
				$single_optin_list_ids[] = $list_id;
			}
		}

		if ( ! empty( $single_optin_list_ids ) ) {
			$added = $this->add_subscriber_to_lists( $settings, $subscriber_id, $single_optin_list_ids, 'confirmed', $values['email'], $request_id );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
		}

		if ( ! empty( $double_optin_list_ids ) ) {
			$added = $this->add_subscriber_to_lists( $settings, $subscriber_id, $double_optin_list_ids, 'unconfirmed', $values['email'], $request_id );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
		}

		if ( $subscriber_needs_optin || ! empty( $double_optin_list_ids ) ) {
			$optin = $this->send_subscriber_optin( $settings, $subscriber_id, $values['email'], $request_id );
			if ( is_wp_error( $optin ) ) {
				return $optin;
			}
		}

		return true;
	}

	private function find_listmonk_subscriber_by_email( array $settings, string $email, string $request_id = '' ) {
		$response = wp_remote_request(
			add_query_arg(
				[
					'search'   => $email,
					'per_page' => 'all',
				],
				trailingslashit( $settings['base_url'] ) . 'api/subscribers'
			),
			[
				'method'  => 'GET',
				'timeout' => 15,
				'headers' => $this->api_headers( $settings ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_api_failure(
				sprintf( __( 'Listmonk Subscriber Lookup API failed: %s', 'listmonk-signup' ), $response->get_error_message() ),
				[
					'email'      => $email,
					'request_id' => $request_id,
				]
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->debug_log(
			'Subscriber lookup API response.',
			[
				'request_id' => $request_id,
				'http_code'  => $code,
			]
		);

		if ( $code < 200 || $code >= 300 ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Subscriber Lookup API returned an error status.', 'listmonk-signup' ),
				[
					'email'      => $email,
					'http_code'  => $code,
					'request_id' => $request_id,
					'body'       => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = $decoded['data']['results'] ?? [];
		if ( ! is_array( $results ) ) {
			$results = [];
		}

		foreach ( $results as $subscriber ) {
			if ( is_array( $subscriber ) && isset( $subscriber['email'] ) && strtolower( (string) $subscriber['email'] ) === strtolower( $email ) ) {
				return $subscriber;
			}
		}

		return $this->listmonk_recovery_error(
			__( 'Listmonk Subscriber API conflict recovery failed: exact subscriber not found.', 'listmonk-signup' ),
			[
				'email'      => $email,
				'request_id' => $request_id,
			]
		);
	}

	private function subscriber_list_ids( array $subscriber ): array {
		$lists = $subscriber['lists'] ?? [];
		if ( is_string( $lists ) ) {
			$decoded = json_decode( $lists, true );
			$lists   = is_array( $decoded ) ? $decoded : [];
		}

		$list_ids = [];
		foreach ( is_array( $lists ) ? $lists : [] as $list ) {
			if ( is_array( $list ) && isset( $list['id'] ) ) {
				if ( 'unsubscribed' === ( $list['subscription_status'] ?? '' ) ) {
					continue;
				}

				$list_ids[] = absint( $list['id'] );
			} elseif ( is_numeric( $list ) ) {
				$list_ids[] = absint( $list );
			}
		}

		return array_values( array_unique( array_filter( $list_ids ) ) );
	}

	private function subscriber_needs_optin_for_lists( array $subscriber, array $list_ids ): bool {
		$lists = $subscriber['lists'] ?? [];
		if ( is_string( $lists ) ) {
			$decoded = json_decode( $lists, true );
			$lists   = is_array( $decoded ) ? $decoded : [];
		}

		foreach ( is_array( $lists ) ? $lists : [] as $list ) {
			if ( ! is_array( $list ) || ! isset( $list['id'] ) || ! in_array( absint( $list['id'] ), $list_ids, true ) ) {
				continue;
			}

			if ( 'double' === ( $list['optin'] ?? '' ) && 'unconfirmed' === ( $list['subscription_status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function list_optins_by_id( array $settings, array $list_ids, string $email, string $request_id = '' ) {
		$response = wp_remote_request(
			add_query_arg(
				[
					'per_page' => 'all',
					'minimal'  => 'true',
				],
				trailingslashit( $settings['base_url'] ) . 'api/lists'
			),
			[
				'method'  => 'GET',
				'timeout' => 15,
				'headers' => $this->api_headers( $settings ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_api_failure(
				sprintf( __( 'Listmonk Lists API failed: %s', 'listmonk-signup' ), $response->get_error_message() ),
				[
					'email'      => $email,
					'request_id' => $request_id,
				]
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->debug_log(
			'Lists API response.',
			[
				'request_id' => $request_id,
				'http_code'  => $code,
			]
		);

		if ( $code < 200 || $code >= 300 ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Lists API returned an error status.', 'listmonk-signup' ),
				[
					'email'      => $email,
					'http_code'  => $code,
					'request_id' => $request_id,
					'body'       => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = $decoded['data']['results'] ?? [];
		if ( ! is_array( $results ) ) {
			$results = [];
		}

		$optins = [];
		foreach ( $results as $list ) {
			if ( is_array( $list ) && isset( $list['id'], $list['optin'] ) ) {
				$optins[ absint( $list['id'] ) ] = (string) $list['optin'];
			}
		}

		$missing_optin_ids = array_values( array_diff( $list_ids, array_keys( $optins ) ) );
		if ( ! empty( $missing_optin_ids ) ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Subscriber API conflict recovery failed: target list opt-in metadata missing.', 'listmonk-signup' ),
				[
					'email'      => $email,
					'request_id' => $request_id,
					'list_count' => count( $missing_optin_ids ),
				]
			);
		}

		return $optins;
	}

	private function add_subscriber_to_lists( array $settings, int $subscriber_id, array $list_ids, string $status, string $email, string $request_id = '' ) {
		$response = wp_remote_request(
			trailingslashit( $settings['base_url'] ) . 'api/subscribers/lists',
			[
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => $this->api_headers( $settings ),
				'body'    => wp_json_encode(
					[
						'ids'             => [ $subscriber_id ],
						'action'          => 'add',
						'target_list_ids' => array_values( $list_ids ),
						'status'          => $status,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_api_failure(
				sprintf( __( 'Listmonk Subscriber Lists API failed: %s', 'listmonk-signup' ), $response->get_error_message() ),
				[
					'email'         => $email,
					'request_id'    => $request_id,
					'subscriber_id' => $subscriber_id,
				]
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->debug_log(
			'Subscriber lists API response.',
			[
				'request_id'         => $request_id,
				'http_code'          => $code,
				'subscriber_id'      => $subscriber_id,
				'missing_list_count' => count( $list_ids ),
				'status'             => $status,
			]
		);

		if ( $code < 200 || $code >= 300 ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Subscriber Lists API returned an error status.', 'listmonk-signup' ),
				[
					'email'         => $email,
					'http_code'     => $code,
					'request_id'    => $request_id,
					'subscriber_id' => $subscriber_id,
					'body'          => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
		}

		return true;
	}

	private function send_subscriber_optin( array $settings, int $subscriber_id, string $email, string $request_id = '' ) {
		$response = wp_remote_request(
			trailingslashit( $settings['base_url'] ) . 'api/subscribers/' . $subscriber_id . '/optin',
			[
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => $this->api_headers( $settings ),
				'body'    => '{}',
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_api_failure(
				sprintf( __( 'Listmonk Subscriber Opt-in API failed: %s', 'listmonk-signup' ), $response->get_error_message() ),
				[
					'email'         => $email,
					'request_id'    => $request_id,
					'subscriber_id' => $subscriber_id,
				]
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->debug_log(
			'Subscriber opt-in API response.',
			[
				'request_id'    => $request_id,
				'http_code'     => $code,
				'subscriber_id' => $subscriber_id,
			]
		);

		if ( $code < 200 || $code >= 300 ) {
			return $this->listmonk_recovery_error(
				__( 'Listmonk Subscriber Opt-in API returned an error status.', 'listmonk-signup' ),
				[
					'email'         => $email,
					'http_code'     => $code,
					'request_id'    => $request_id,
					'subscriber_id' => $subscriber_id,
					'body'          => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
		}

		return true;
	}

	private function listmonk_recovery_error( string $message, array $context = [] ): WP_Error {
		$this->record_api_failure( $message, $context );

		return new WP_Error( 'listmonk_subscriber_api_failed', 'Listmonk subscriber API failed.' );
	}

	private function api_headers( array $settings ): array {
		return [
			'Authorization' => 'token ' . $settings['api_token'],
			'Content-Type'  => 'application/json',
		];
	}

	private function api_token_has_valid_format( string $api_token ): bool {
		return (bool) preg_match( '/^[^:\s]+:[^:\s]+$/', $api_token );
	}

	private function build_name( array $values ): string {
		return trim( implode( ' ', array_filter( [ $values['vorname'] ?? '', $values['nachname'] ?? '' ] ) ) );
	}

	private function subscriber_attribs( array $values ): array {
		$anrede = $values['anrede'];
		if ( '' === trim( $anrede ) && '' !== $this->build_name( $values ) ) {
			$anrede = __( 'Dear', 'listmonk-signup' );
		}

		return [
			'anrede'   => $anrede,
			'vorname'  => $values['vorname'],
			'nachname' => $values['nachname'],
			'bezirke'  => $this->selected_district_postal_codes( $values['bezirke'] ?? [] ),
		];
	}

	private function selected_district_postal_codes( array $selected_ids ): array {
		$districts = $this->districts();
		$postal_codes = [];

		foreach ( $selected_ids as $district_id ) {
			$district_id = (string) $district_id;
			if ( isset( $districts[ $district_id ] ) ) {
				$postal_codes[] = $district_id;
			}
		}

		return $postal_codes;
	}

	private function record_api_failure( string $message, array $context = [] ): void {
		$this->debug_log( $message, $context );

		$failures   = $this->api_failures();
		$failures[] = [
			'time'    => current_time( 'mysql' ),
			'message' => sanitize_text_field( $this->redact_log_value( $message ) ),
			'context' => $this->sanitize_log_context( $context ),
		];

		if ( count( $failures ) > self::MAX_API_FAILURES ) {
			$failures = array_slice( $failures, -self::MAX_API_FAILURES );
		}

		update_option( self::API_FAILURE_OPTION_NAME, $failures, false );
	}

	private function api_failures(): array {
		$failures = get_option( self::API_FAILURE_OPTION_NAME, [] );

		return is_array( $failures ) ? $failures : [];
	}

	private function debug_log( string $message, array $context = [] ): void {
		$settings = $this->settings();
		if ( empty( $settings['debug_logging'] ) || '1' !== $settings['debug_logging'] ) {
			return;
		}

		$message = $this->redact_log_value( $message );
		$context = $this->redact_log_context( $context );

		$this->store_log_entry( $message, $context );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		error_log( '[Listmonk Signup] ' . $message );
	}

	private function store_log_entry( string $message, array $context = [] ): void {
		$logs   = $this->logs();
		$logs[] = [
			'time'    => current_time( 'mysql' ),
			'message' => sanitize_text_field( $message ),
			'context' => $this->sanitize_log_context( $context ),
		];

		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION_NAME, $logs, false );
	}

	private function logs(): array {
		$logs = get_option( self::LOG_OPTION_NAME, [] );

		return is_array( $logs ) ? $logs : [];
	}

	private function sanitize_log_context( array $context ): array {
		$sanitized = [];

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_log_context( $value );
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( $this->redact_log_value( (string) $value ) );
		}

		return $sanitized;
	}

	private function redact_log_context( array $context ): array {
		$redacted = [];

		foreach ( $context as $key => $value ) {
			$key = (string) $key;

			if ( is_array( $value ) ) {
				$redacted[ $key ] = $this->redact_log_context( $value );
				continue;
			}

			if ( $this->log_key_is_sensitive( $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = $this->redact_log_value( (string) $value );
		}

		return $redacted;
	}

	private function log_key_is_sensitive( string $key ): bool {
		return (bool) preg_match( '/(authorization|api[_-]?token|token|password|secret|email)/i', $key );
	}

	private function redact_log_value( string $value ): string {
		$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value );
		$value = preg_replace( '/\b(Bearer|Basic|token)\s+[^\s,;]+/i', '$1 [redacted]', $value );
		$value = preg_replace( '/\b(api[_-]?token|token|password|secret|authorization)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value );
		$value = preg_replace( '/\b[a-z0-9._-]+:[^\s,;]{8,}/i', '[redacted-credential]', $value );

		return $value;
	}

	private function debug_body_snippet( string $body ): string {
		$body = trim( $this->redact_log_value( $body ) );

		if ( '' === $body ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 500 ) : substr( $body, 0, 500 );
	}

	private function parse_list_ids( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', $raw );
		$ids   = [];

		foreach ( $parts ?: [] as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^[1-9][0-9]*$/', $part ) ) {
				$ids[] = (int) $part;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function is_rate_limited( string $email ): bool {
		$client_ip          = $this->client_ip();
		$email_ip_key       = 'listmonk_signup_email_ip_' . hash( 'sha256', $client_ip . '|' . strtolower( $email ) );
		$ip_key             = 'listmonk_signup_ip_' . hash( 'sha256', $client_ip );
		$email_ip_count     = (int) get_transient( $email_ip_key );
		$ip_count           = (int) get_transient( $ip_key );

		if ( $email_ip_count >= self::RATE_LIMIT_EMAIL_IP_MAX || $ip_count >= self::RATE_LIMIT_IP_MAX ) {
			return true;
		}

		set_transient( $email_ip_key, $email_ip_count + 1, self::RATE_LIMIT_SECONDS );
		set_transient( $ip_key, $ip_count + 1, self::RATE_LIMIT_SECONDS );

		return false;
	}

	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return $ip ?: 'unknown';
	}

	private function success_result( $message = null ): array {
		$settings = $this->settings();

		return [
			'type'    => 'success',
			'message' => $message ?: $settings['success_message'],
			'status'  => 200,
		];
	}

	private function error_result( string $message, string $code = 'submission_failed', int $status = 400 ): array {
		return [
			'type'    => 'error',
			'message' => $message,
			'code'    => $code,
			'status'  => $status,
		];
	}
}

register_activation_hook( __FILE__, [ 'Listmonk_Signup', 'activate' ] );
Listmonk_Signup::instance();
