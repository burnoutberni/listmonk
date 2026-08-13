<?php
/**
 * Plugin Name: Wir machen Wien Listmonk Signup
 * Description: Site-specific newsletter signup shortcode for Listmonk.
 * Version: 1.0.0
 * Author: Bernhard Hayden
 * License: AGPL-3.0
 * Text Domain: wmw-listmonk-signup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WMW_Listmonk_Signup {
	private const OPTION_NAME = 'wmw_listmonk_signup_settings';
	private const LOG_OPTION_NAME = 'wmw_listmonk_signup_logs';
	private const API_FAILURE_OPTION_NAME = 'wmw_listmonk_signup_api_failures';
	private const SUBMISSION_ACTION = 'wmw_listmonk_signup';
	private const NONCE_ACTION = 'wmw_listmonk_signup_submit';
	private const NONCE_NAME = 'wmw_listmonk_signup_nonce';
	private const SUBMISSION_TOKEN_NAME = 'wmw_listmonk_submission_token';
	private const SUBMISSION_TOKEN_PREFIX = 'wmw_listmonk_submission_token_';
	private const SUBMISSION_TOKEN_CLAIM_PREFIX = 'wmw_listmonk_submission_token_claim_';
	private const CLEAR_LOGS_ACTION = 'wmw_listmonk_signup_clear_logs';
	private const RESULT_QUERY_ARG = 'wmw_listmonk_signup_result';
	private const RESULT_TRANSIENT_PREFIX = 'wmw_listmonk_signup_result_';
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
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_clear_logs' ] );
		add_action( 'admin_post_nopriv_' . self::SUBMISSION_ACTION, [ $this, 'handle_submission' ] );
		add_action( 'admin_post_' . self::SUBMISSION_ACTION, [ $this, 'handle_submission' ] );
		add_shortcode( 'listmonk_signup', [ $this, 'render_shortcode' ] );
	}

	public static function activate(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	private static function defaults(): array {
		return [
			'base_url'        => 'https://newsletter.wirmachen.wien',
			'api_token'       => '',
			'list_ids'        => '3',
			'success_message' => 'Vielen Dank! Bitte prüfe dein E-Mail-Postfach und bestätige deine Anmeldung.',
			'error_message'   => 'Die Anmeldung konnte leider nicht abgeschlossen werden. Bitte versuche es später erneut.',
			'consent_text'    => 'Ich möchte den Newsletter von Wir machen Wien abonnieren und akzeptiere, dass meine Angaben zur Zusendung des Newsletters verarbeitet werden. Hinweise findest du in unserer <a href="https://wirmachen.wien/datenschutz">Datenschutzerklärung</a>. Ich kann mich jederzeit wieder abmelden.',
			'debug_logging'   => '0',
		];
	}

	private function districts(): array {
		return [
			'1010' => '1., Innere Stadt',
			'1020' => '2., Leopoldstadt',
			'1030' => '3., Landstraße',
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
			'1180' => '18., Währing',
			'1190' => '19., Döbling',
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
			'Listmonk Signup',
			'Listmonk Signup',
			'manage_options',
			'wmw-listmonk-signup',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'wmw_listmonk_signup',
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
				'wmw_listmonk_base_url_https',
				'Listmonk base URL must start with https://.',
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
				'wmw_listmonk_api_token_required',
				'Listmonk API credential is required and must use api_user:token format.',
				'error'
			);
		} elseif ( ! $this->api_token_has_valid_format( $api_token ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'wmw_listmonk_api_token_format',
				'Listmonk API credential must use api_user:token format.',
				'error'
			);
			$api_token = ! empty( $previous['api_token'] ) && $this->api_token_has_valid_format( $previous['api_token'] ) ? $previous['api_token'] : '';
		}

		$list_ids = isset( $input['list_ids'] ) ? sanitize_textarea_field( (string) $input['list_ids'] ) : '';
		$list_ids = implode( "\n", $this->parse_list_ids( $list_ids ) );
		if ( '' === $list_ids ) {
			add_settings_error(
				self::OPTION_NAME,
				'wmw_listmonk_list_ids_required',
				'At least one numeric Listmonk list ID is required.',
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
		if ( empty( $_POST['wmw_listmonk_clear_logs'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::CLEAR_LOGS_ACTION );
		delete_option( self::LOG_OPTION_NAME );
		delete_option( self::API_FAILURE_OPTION_NAME );
		wp_safe_redirect( add_query_arg( 'wmw_listmonk_logs_cleared', '1', menu_page_url( 'wmw-listmonk-signup', false ) ) );
		$this->terminate_request();
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings();
		$logs     = $this->logs();
		$failures = $this->api_failures();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( ! empty( $_GET['wmw_listmonk_logs_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Listmonk Signup Logs und API-Hinweise wurden gelöscht.</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $failures ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Listmonk Signup:</strong> Mindestens eine Listmonk API-Anfrage konnte nicht abgeschlossen werden.</p>
					<ul>
						<?php foreach ( array_reverse( $failures ) as $failure ) : ?>
							<li><?php echo esc_html( $failure['time'] ?? '' ); ?>: <?php echo esc_html( $failure['message'] ?? '' ); ?> <code><?php echo esc_html( wp_json_encode( $failure['context'] ?? [] ) ); ?></code></li>
						<?php endforeach; ?>
					</ul>
					<p>Bitte Listmonk API-Zugang und Logs prüfen. Die Hinweise werden mit „Logs löschen“ entfernt.</p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'wmw_listmonk_signup' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wmw-listmonk-base-url">Listmonk Base URL</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[base_url]" id="wmw-listmonk-base-url" type="url" class="regular-text" value="<?php echo esc_attr( $settings['base_url'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="wmw-listmonk-api-token">Listmonk API User + Token</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_token]" id="wmw-listmonk-api-token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $settings['api_token'] ) ? 'api_user:token' : 'Gespeichert - leer lassen, um beizubehalten' ); ?>">
							<p class="description">Erforderlich. Listmonk erwartet <code>api_user:token</code>, z. B. <code>newsletter_api:abc123...</code>. Die Anmeldung verwendet die authentifizierte Subscriber API, damit Attribute direkt gespeichert werden.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wmw-listmonk-list-ids">Listen-IDs</label></th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[list_ids]" id="wmw-listmonk-list-ids" class="large-text code" rows="4" required><?php echo esc_textarea( $settings['list_ids'] ); ?></textarea>
							<p class="description">Eine numerische Listen-ID pro Zeile oder kommagetrennt. Standard: 3.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wmw-listmonk-success-message">Erfolgsmeldung</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[success_message]" id="wmw-listmonk-success-message" type="text" class="large-text" value="<?php echo esc_attr( $settings['success_message'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wmw-listmonk-error-message">Fehlermeldung</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[error_message]" id="wmw-listmonk-error-message" type="text" class="large-text" value="<?php echo esc_attr( $settings['error_message'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wmw-listmonk-consent-text">Consent-Text</label></th>
						<td>
							<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[consent_text]" id="wmw-listmonk-consent-text" class="large-text" rows="5"><?php echo esc_textarea( $settings['consent_text'] ); ?></textarea>
							<p class="description">Sicheres HTML wie Links ist erlaubt.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Temporäres Debug Logging</th>
						<td>
							<label><input name="<?php echo esc_attr( self::OPTION_NAME ); ?>[debug_logging]" type="checkbox" value="1" <?php checked( $settings['debug_logging'], '1' ); ?>> Listmonk API Debug Logs speichern</label>
							<p class="description">Nur kurz zum Testen aktivieren. Es werden keine API Tokens gespeichert, aber technische API-Antworten und E-Mail-Adressen können in gekürzter Form sichtbar sein.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Debug Logs</h2>
			<p>Die letzten <?php echo esc_html( (string) self::MAX_LOG_ENTRIES ); ?> Einträge aus dem temporären Plugin-Logging.</p>
			<?php if ( empty( $logs ) ) : ?>
				<p><em>Keine Logs vorhanden.</em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Zeit</th>
							<th>Nachricht</th>
							<th>Kontext</th>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=wmw-listmonk-signup' ) ); ?>" style="margin-top:1rem;">
					<?php wp_nonce_field( self::CLEAR_LOGS_ACTION ); ?>
					<button class="button" type="submit" name="wmw_listmonk_clear_logs" value="1">Logs löschen</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_submission(): void {
		$redirect_url = $this->submission_redirect_url();
		$values       = $this->sanitize_frontend_values( wp_unslash( $_POST ) );

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.' ), $values );
		}

		if ( ! empty( $_POST['website'] ) ) {
			$this->redirect_with_result( $redirect_url, $this->success_result() );
		}

		if ( ! $this->consume_submission_token() ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.' ), $values );
		}

		if ( empty( $values['email'] ) || ! is_email( $values['email'] ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( 'Bitte gib eine gültige E-Mail-Adresse ein.' ), $values );
		}

		if ( empty( $values['consent'] ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( 'Bitte bestätige, dass du den Newsletter abonnieren möchtest.' ), $values );
		}

		if ( $this->is_rate_limited( $values['email'] ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( 'Bitte warte kurz, bevor du es noch einmal versuchst.' ), $values );
		}

		$settings = $this->settings();
		$list_ids = $this->parse_list_ids( $settings['list_ids'] );

		if ( empty( $settings['base_url'] ) || empty( $settings['api_token'] ) || ! $this->api_token_has_valid_format( $settings['api_token'] ) || empty( $list_ids ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( $settings['error_message'] ), $values );
		}

		$request_id = wp_generate_uuid4();
		$result     = $this->subscribe_via_subscribers_endpoint( $settings, $list_ids, $values, $request_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_result( $redirect_url, $this->error_result( $settings['error_message'] ), $values );
		}

		$this->redirect_with_result( $redirect_url, $this->success_result( $settings['success_message'] ) );
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
		<form class="wmw-listmonk-signup" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php if ( ! empty( $submission_result['message'] ) ) : ?>
				<div class="wmw-listmonk-signup__message wmw-listmonk-signup__message--<?php echo esc_attr( $submission_result['type'] ); ?>" role="status">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<section class="wmw-listmonk-signup__step">
				<h2><span>1</span>E-Mail</h2>
				<p class="wmw-listmonk-signup__field">
					<label for="wmw-listmonk-email">E-Mail-Adresse *</label>
					<input id="wmw-listmonk-email" name="email" type="email" value="<?php echo esc_attr( $values['email'] ); ?>" autocomplete="email" required>
				</p>
			</section>

			<section class="wmw-listmonk-signup__step wmw-listmonk-signup__details">
				<h2><span>2</span>Optional: Wie sollen wir dich ansprechen?</h2>

				<div class="wmw-listmonk-signup__grid">
					<p class="wmw-listmonk-signup__field">
						<label for="wmw-listmonk-anrede">Anrede</label>
						<input id="wmw-listmonk-anrede" name="anrede" type="text" value="<?php echo esc_attr( $values['anrede'] ); ?>" placeholder="Liebe" autocomplete="honorific-prefix">
					</p>

					<p class="wmw-listmonk-signup__field">
						<label for="wmw-listmonk-vorname">Vorname</label>
						<input id="wmw-listmonk-vorname" name="vorname" type="text" value="<?php echo esc_attr( $values['vorname'] ); ?>" autocomplete="given-name">
					</p>

					<p class="wmw-listmonk-signup__field">
						<label for="wmw-listmonk-nachname">Nachname</label>
						<input id="wmw-listmonk-nachname" name="nachname" type="text" value="<?php echo esc_attr( $values['nachname'] ); ?>" autocomplete="family-name">
					</p>
				</div>
			</section>

			<fieldset class="wmw-listmonk-signup__step wmw-listmonk-signup__districts">
				<legend><span>3</span>Optional: Welche Bezirke interessieren dich?</legend>
				<p class="wmw-listmonk-signup__hint">Wenn du einen oder mehrere Bezirke auswählst, stimmst du zu, dass wir deine E-Mail-Adresse verwenden dürfen, um dir in Zukunft Informationen zu lokalen Initiativen in diesen Bezirken zu schicken.</p>
				<div class="wmw-listmonk-signup__district-grid">
					<?php foreach ( $districts as $district_id => $district_label ) : ?>
						<label class="wmw-listmonk-signup__district">
							<input name="bezirke[]" type="checkbox" value="<?php echo esc_attr( $district_id ); ?>" <?php checked( in_array( $district_id, $values['bezirke'], true ) ); ?>>
							<span><?php echo esc_html( $district_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<section class="wmw-listmonk-signup__step">
				<h2><span>4</span>Zustimmung</h2>
				<p class="wmw-listmonk-signup__field wmw-listmonk-signup__field--consent">
					<label>
						<input name="consent" type="checkbox" value="1" required>
						<span><?php echo wp_kses_post( $settings['consent_text'] ); ?></span>
					</label>
				</p>
			</section>

			<p class="wmw-listmonk-signup__field wmw-listmonk-signup__field--hp" aria-hidden="true">
				<label for="wmw-listmonk-website">Website</label>
				<input id="wmw-listmonk-website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
			</p>

			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::SUBMISSION_TOKEN_NAME ); ?>" value="<?php echo esc_attr( $submission_token ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SUBMISSION_ACTION ); ?>">
			<input type="hidden" name="return_to" value="<?php echo esc_url( get_permalink() ); ?>">
			<input type="hidden" name="wmw_listmonk_signup_submit" value="1">
			<section class="wmw-listmonk-signup__step wmw-listmonk-signup__step--submit">
				<h2><span>5</span>Jetzt abschicken!</h2>
				<button type="submit">Newsletter abonnieren</button>
			</section>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	private function enqueue_shortcode_assets(): void {
		wp_enqueue_style(
			'wmw-listmonk-signup',
			plugins_url( 'assets/listmonk-signup.css', __FILE__ ),
			[],
			'1.0.0'
		);
	}

	private function submission_redirect_url(): string {
		$return_to = '';

		if ( isset( $_POST['return_to'] ) ) {
			$return_to = esc_url_raw( wp_unslash( $_POST['return_to'] ) );
		}

		if ( '' === $return_to ) {
			$return_to = wp_get_referer() ?: home_url( '/' );
		}

		$return_to = wp_validate_redirect( $return_to, home_url( '/' ) );

		return remove_query_arg( self::RESULT_QUERY_ARG, $return_to );
	}

	private function redirect_with_result( string $redirect_url, array $result, array $values = [] ): void {
		$token = wp_generate_uuid4();

		set_transient(
			self::RESULT_TRANSIENT_PREFIX . $token,
			[
				'result' => $result,
				'values' => $values,
			],
			self::RESULT_TTL_SECONDS
		);

		wp_safe_redirect( add_query_arg( self::RESULT_QUERY_ARG, rawurlencode( $token ), $redirect_url ) );
		$this->terminate_request();
	}

	private function terminate_request(): void {
		if ( apply_filters( 'wmw_listmonk_signup_should_exit', true ) ) {
			exit;
		}

		throw new RuntimeException( 'WMW Listmonk Signup request terminated.' );
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

	private function consume_submission_token(): bool {
		if ( empty( $_POST[ self::SUBMISSION_TOKEN_NAME ] ) ) {
			return false;
		}

		$token = sanitize_key( wp_unslash( $_POST[ self::SUBMISSION_TOKEN_NAME ] ) );
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
				'Listmonk Subscriber API fehlgeschlagen: ' . $response->get_error_message(),
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
			$this->record_api_failure(
				'Listmonk Subscriber API hat einen Fehlerstatus zurückgegeben.',
				[
					'email'      => $values['email'],
					'http_code'  => $code,
					'request_id' => $request_id,
					'body'       => $this->debug_body_snippet( wp_remote_retrieve_body( $response ) ),
				]
			);
			return new WP_Error( 'wmw_listmonk_subscriber_api_failed', 'Listmonk subscriber API failed.' );
		}

		return true;
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
			$anrede = 'Liebe';
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

		error_log( '[WMW Listmonk Signup] ' . $message );
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
		$email_ip_key       = 'wmw_listmonk_signup_email_ip_' . hash( 'sha256', $client_ip . '|' . strtolower( $email ) );
		$ip_key             = 'wmw_listmonk_signup_ip_' . hash( 'sha256', $client_ip );
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
		];
	}

	private function error_result( string $message ): array {
		return [
			'type'    => 'error',
			'message' => $message,
		];
	}
}

register_activation_hook( __FILE__, [ 'WMW_Listmonk_Signup', 'activate' ] );
WMW_Listmonk_Signup::instance();
