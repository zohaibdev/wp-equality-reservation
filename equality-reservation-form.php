<?php
/**
 * Plugin Name:       Equality Reservation Form
 * Plugin URI:        https://example.com/
 * Description:       A multi-step "Reservation Request" form embedded inline via the [reservation_form] shortcode (no popup). Submissions are sent over AJAX and emailed to the address set under Settings → Reservation Form.
 * Version:           1.0.3
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       equality-reservation-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct file access.
}

define( 'ERF_VERSION', '1.0.5' );
define( 'ERF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERF_URL', plugin_dir_url( __FILE__ ) );
define( 'ERF_OPTION', 'erf_settings' );

/* -------------------------------------------------------------------------
 * Settings helpers
 * ---------------------------------------------------------------------- */

/**
 * Return saved settings merged with sensible defaults.
 *
 * @return array
 */
function erf_get_settings() {
	$defaults = array(
		'recipient'         => get_option( 'admin_email' ),
		'subject'           => 'New Reservation Request',
		'car_types'         => "Sedan\nSUV\nVan\nMinibus\nLuxury\nTruck",
		'license_types'     => "Full License\nProvisional License\nInternational License\nCommercial (CDL)",
		'recaptcha_mode'    => 'off',
		'recaptcha_site'    => '',
		'recaptcha_secret'  => '',
		'recaptcha_score'   => '0.5',
	);

	$saved = get_option( ERF_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return wp_parse_args( $saved, $defaults );
}

/**
 * Turn a textarea (one item per line) into a clean array.
 *
 * @param string $text Raw textarea value.
 * @return array
 */
function erf_lines_to_array( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	$lines = array_map( 'trim', $lines );

	return array_values( array_filter( $lines, 'strlen' ) );
}

/**
 * Check a value is a real calendar date in YYYY-MM-DD form.
 *
 * Rejects impossible dates such as 2026-02-31, which a bare format check
 * would happily accept.
 *
 * @param string $value Raw date string.
 * @return bool
 */
function erf_is_valid_date( $value ) {
	$parts = date_parse_from_format( 'Y-m-d', (string) $value );

	if ( ! empty( $parts['error_count'] ) || ! empty( $parts['warning_count'] ) ) {
		return false;
	}

	return checkdate( (int) $parts['month'], (int) $parts['day'], (int) $parts['year'] );
}

/* -------------------------------------------------------------------------
 * Google reCAPTCHA
 * ---------------------------------------------------------------------- */

/**
 * Which reCAPTCHA flavour is actually usable right now.
 *
 * A mode is only honoured when both keys are present, so a half-finished
 * configuration can never silently wave every submission through.
 *
 * @return string 'off', 'v2' or 'v3'.
 */
function erf_recaptcha_mode() {
	$settings = erf_get_settings();
	$mode     = isset( $settings['recaptcha_mode'] ) ? $settings['recaptcha_mode'] : 'off';

	if ( ! in_array( $mode, array( 'v2', 'v3' ), true ) ) {
		return 'off';
	}
	if ( '' === trim( (string) $settings['recaptcha_site'] ) || '' === trim( (string) $settings['recaptcha_secret'] ) ) {
		return 'off';
	}

	return $mode;
}

/**
 * The minimum v3 score a submission must reach to be accepted.
 *
 * @return float
 */
function erf_recaptcha_score_threshold() {
	$settings  = erf_get_settings();
	$threshold = isset( $settings['recaptcha_score'] ) ? (float) $settings['recaptcha_score'] : 0.5;
	$threshold = min( 1.0, max( 0.0, $threshold ) );

	/**
	 * Filter the v3 score threshold (0.0 = almost certainly a bot, 1.0 = almost
	 * certainly human). Google's suggested starting point is 0.5.
	 */
	return (float) apply_filters( 'erf_recaptcha_score_threshold', $threshold );
}

/**
 * Ask Google to verify a token.
 *
 * @param string $token    The g-recaptcha-response value from the browser.
 * @param string $expected The v3 action name we expect the token to carry.
 * @return true|string True when the token is good, otherwise a visitor-facing message.
 */
function erf_verify_recaptcha( $token, $expected = 'reservation_form' ) {
	$mode = erf_recaptcha_mode();
	if ( 'off' === $mode ) {
		return true;
	}

	if ( '' === $token ) {
		return 'v2' === $mode
			? 'Confirme que no es un robot.'
			: 'No pudimos verificar que usted es humano. Recargue la página e inténtelo de nuevo.';
	}

	$settings = erf_get_settings();
	$body     = array(
		'secret'   => trim( (string) $settings['recaptcha_secret'] ),
		'response' => $token,
	);

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		$body['remoteip'] = $ip;
	}

	$response = wp_remote_post(
		'https://www.google.com/recaptcha/api/siteverify',
		array(
			'timeout' => 10,
			'body'    => $body,
		)
	);

	// Google unreachable: fail closed, but tell the visitor it is worth retrying.
	if ( is_wp_error( $response ) ) {
		return 'No pudimos conectar con el servicio de verificación antispam. Inténtelo de nuevo en unos momentos.';
	}

	$result = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $result ) ) {
		return 'No pudimos conectar con el servicio de verificación antispam. Inténtelo de nuevo en unos momentos.';
	}

	if ( empty( $result['success'] ) ) {
		$codes = isset( $result['error-codes'] ) ? (array) $result['error-codes'] : array();

		// An expired or already-used token just needs another attempt.
		if ( array_intersect( $codes, array( 'timeout-or-duplicate' ) ) ) {
			return 'Su verificación expiró. Inténtelo de nuevo.';
		}
		// A bad secret is the site owner's problem, not the visitor's.
		if ( array_intersect( $codes, array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
			return 'La verificación antispam está mal configurada en este sitio. Por favor, contáctenos directamente.';
		}

		return 'v2' === $mode
			? 'La verificación falló. Marque nuevamente la casilla “No soy un robot”.'
			: 'Su envío fue marcado como tráfico automatizado.';
	}

	if ( 'v3' === $mode ) {
		// Make sure the token was minted for this form, not lifted from elsewhere.
		if ( isset( $result['action'] ) && $expected !== $result['action'] ) {
			return 'No se pudo verificar su envío. Recargue la página e inténtelo de nuevo.';
		}
		$score = isset( $result['score'] ) ? (float) $result['score'] : 0.0;
		if ( $score < erf_recaptcha_score_threshold() ) {
			return 'Su envío fue marcado como tráfico automatizado. Contáctenos directamente si esto es un error.';
		}
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

/**
 * Register (but do not enqueue) the front-end CSS/JS.
 * They are enqueued on demand from the shortcode so they only load on pages
 * that actually use the form.
 */
function erf_register_assets() {
	wp_register_style(
		'erf-style',
		ERF_URL . 'assets/reservation-form.css',
		array(),
		ERF_VERSION
	);

	wp_register_script(
		'erf-script',
		ERF_URL . 'assets/reservation-form.js',
		array(),
		time(),
		true
	);

	$mode     = erf_recaptcha_mode();
	$settings = erf_get_settings();
	$site_key = trim( (string) $settings['recaptcha_site'] );

	if ( 'off' !== $mode ) {
		// v2 is rendered explicitly so each form on the page gets its own widget.
		$src = ( 'v3' === $mode )
			? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key )
			: 'https://www.google.com/recaptcha/api.js?render=explicit';

		wp_register_script( 'erf-recaptcha', $src, array(), null, true );
	}

	wp_localize_script(
		'erf-script',
		'ERF_DATA',
		array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'erf_submit_nonce' ),
			'recaptcha' => array(
				'mode'     => $mode,
				'site_key' => ( 'off' === $mode ) ? '' : $site_key,
				'action'   => 'reservation_form',
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'erf_register_assets' );

/**
 * Google's API script must not block rendering.
 *
 * @param string $tag    The full script tag.
 * @param string $handle Script handle.
 * @return string
 */
function erf_recaptcha_script_tag( $tag, $handle ) {
	if ( 'erf-recaptcha' !== $handle || false !== strpos( $tag, ' async' ) ) {
		return $tag;
	}

	return str_replace( ' src=', ' async defer src=', $tag );
}
add_filter( 'script_loader_tag', 'erf_recaptcha_script_tag', 10, 2 );

/* -------------------------------------------------------------------------
 * Shortcode: [reservation_form]
 * ---------------------------------------------------------------------- */

/**
 * Render the multi-step reservation form.
 *
 * Accepted attributes:
 *   title      - Heading text.
 *   subtitle   - Sub-heading text.
 *   bg         - Optional background image URL for the card.
 *   terms_url  - URL the "terms & conditions" link points to.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function erf_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'     => 'Reservation Request',
			'subtitle'  => "Let's start with your basic information",
			'bg'        => '',
			'terms_url' => '#',
		),
		$atts,
		'reservation_form'
	);

	// Load assets only where the shortcode is used.
	wp_enqueue_style( 'erf-style' );
	wp_enqueue_script( 'erf-script' );

	$recaptcha_mode = erf_recaptcha_mode();
	if ( 'off' !== $recaptcha_mode ) {
		wp_enqueue_script( 'erf-recaptcha' );
	}

	$settings      = erf_get_settings();
	$car_types     = erf_lines_to_array( $settings['car_types'] );
	$license_types = erf_lines_to_array( $settings['license_types'] );

	$today = current_time( 'Y-m-d' );

	// Unique per instance so labels stay linked when several forms share a page.
	static $instance = 0;
	$instance++;
	$uid = 'erf-' . $instance;

	$terms_url  = esc_url( $atts['terms_url'] );
	$card_style = '';
	if ( ! empty( $atts['bg'] ) ) {
		$card_style = sprintf( " style=\"--erf-bg-image:url('%s');\"", esc_url( $atts['bg'] ) );
	}

	// Inline arrow icons (kept tiny and dependency-free).
	$icon_next = '<svg class="erf-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
	$icon_prev = '<svg class="erf-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';

	ob_start();
	?>
	<div class="erf-wrap">
		<div class="erf-card-d"<?php echo $card_style; // Safe: URL escaped above. ?>>
			<div class="erf-inner-d">
				<div class="erf-steps" aria-hidden="true">
					<div class="erf-steps-line"><span class="erf-steps-fill"></span></div>
					<div class="erf-dot is-active" data-dot="1"><span>01</span></div>
					<div class="erf-dot" data-dot="2"><span>02</span></div>
					<div class="erf-dot" data-dot="3"><span>03</span></div>
				</div>

				<form class="erf-form" novalidate>
					<div class="erf-hp" aria-hidden="true">
						<label>Deje este campo vacío
							<input type="text" name="erf_website" tabindex="-1" autocomplete="off">
						</label>
					</div>

					<!-- Step 1 : basic information -->
					<div class="erf-step is-active" data-step="1">
						<div class="erf-field">
							<input type="text" name="full_name" placeholder="Escriba su nombre completo" autocomplete="name" pattern="[A-Za-z]+(?:[ '\-][A-Za-z]+)*" minlength="2" maxlength="80" title="Use solo letras, espacios, apóstrofes o guiones." oninput="this.value=this.value.replace(/[^A-Za-z '\-]/g,'')" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<input type="email" name="email" placeholder="Su correo electrónico" autocomplete="email" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<input type="tel" name="phone" placeholder="Su número de teléfono" autocomplete="tel" inputmode="tel" pattern="\+?[0-9()\-\s]{7,20}" minlength="7" maxlength="20" title="Use de 7 a 20 caracteres con dígitos y, opcionalmente, +, espacios, paréntesis o guiones." oninput="this.value=this.value.replace(/[^0-9()+\-\s]/g,'')" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-actions erf-actions--end">
							<button type="button" class="erf-btn erf-btn--light erf-next">Siguiente <?php echo $icon_next; ?></button>
						</div>
					</div>

					<!-- Step 2 : trip details -->
					<div class="erf-step" data-step="2">
						<div class="erf-field">
							<label class="erf-label" for="<?php echo esc_attr( $uid ); ?>-date">Fecha de recogida</label>
							<input type="date" id="<?php echo esc_attr( $uid ); ?>-date" name="date" min="<?php echo esc_attr( $today ); ?>" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<label class="erf-label" for="<?php echo esc_attr( $uid ); ?>-dropoff">Fecha de devolución</label>
							<input type="date" id="<?php echo esc_attr( $uid ); ?>-dropoff" name="dropoff_date" min="<?php echo esc_attr( $today ); ?>" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<select name="car_type" required>
								<option value="" selected disabled>Seleccione el tipo de vehículo</option>
								<?php foreach ( $car_types as $car ) : ?>
									<option value="<?php echo esc_attr( $car ); ?>"><?php echo esc_html( $car ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<input type="number" name="passengers" min="1" max="7" step="1" placeholder="No. de pasajeros" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-actions">
							<button type="button" class="erf-btn erf-btn--ghost erf-prev"><?php echo $icon_prev; ?> Anterior</button>
							<button type="button" class="erf-btn erf-btn--light erf-next">Siguiente <?php echo $icon_next; ?></button>
						</div>
					</div>

					<!-- Step 3 : driver details -->
					<div class="erf-step" data-step="3">
						<div class="erf-field">
							<input type="number" name="age" min="18" max="100" step="1" placeholder="Su edad" required>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field">
							<select name="license_type" required>
								<option value="" selected disabled>Tipo de licencia de conducir</option>
								<?php foreach ( $license_types as $license ) : ?>
									<option value="<?php echo esc_attr( $license ); ?>"><?php echo esc_html( $license ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="erf-error"></span>
						</div>
						<div class="erf-field erf-field--agree">
							<label class="erf-checkbox">
								<input type="checkbox" name="agree" value="1" required>
								<span class="erf-checkbox-box" aria-hidden="true"></span>
								<span class="erf-checkbox-text">Acepto los <a href="<?php echo $terms_url; // Escaped above. ?>" target="_blank" rel="noopener noreferrer">términos y condiciones</a></span>
							</label>
							<span class="erf-error"></span>
						</div>
						<?php if ( 'v2' === $recaptcha_mode ) : ?>
							<div class="erf-field erf-field--recaptcha">
								<div class="erf-recaptcha"></div>
								<span class="erf-error"></span>
							</div>
						<?php endif; ?>
						<div class="erf-actions">
							<button type="button" class="erf-btn erf-btn--ghost erf-prev"><?php echo $icon_prev; ?> Anterior</button>
							<button type="submit" class="erf-btn erf-btn--light erf-submit">
								<span class="erf-btn-label">Enviar Solicitud <?php echo $icon_next; ?></span>
								<span class="erf-spinner" aria-hidden="true"></span>
							</button>
						</div>
					</div>

					<div class="erf-formmsg" role="alert" hidden></div>
				</form>

				<div class="erf-success" hidden>
					<div class="erf-success-icon" aria-hidden="true">
						<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
					</div>
					<h3 class="erf-success-title">¡Gracias!</h3>
					<p class="erf-success-text">Hemos recibido su solicitud de reservación. Nuestro equipo se pondrá en contacto con usted en breve.</p>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'reservation_form', 'erf_shortcode' );

/* -------------------------------------------------------------------------
 * AJAX submission handler
 * ---------------------------------------------------------------------- */

/**
 * Process the AJAX form submission and email the result.
 */
function erf_handle_submit() {
	// 1. Verify the nonce.
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'erf_submit_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'Error de seguridad. Actualice la página e inténtelo de nuevo.' ), 403 );
	}

	// 2. Honeypot: a real user never fills this hidden field.
	if ( ! empty( $_POST['erf_website'] ) ) {
		wp_send_json_error( array( 'message' => 'Su envío fue marcado como spam.' ), 400 );
	}

	// 3. Collect and sanitize each field.
	$full_name  = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
	$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
	$dropoff    = isset( $_POST['dropoff_date'] ) ? sanitize_text_field( wp_unslash( $_POST['dropoff_date'] ) ) : '';
	$car_type   = isset( $_POST['car_type'] ) ? sanitize_text_field( wp_unslash( $_POST['car_type'] ) ) : '';
	$passengers = isset( $_POST['passengers'] ) ? sanitize_text_field( wp_unslash( $_POST['passengers'] ) ) : '';
	$age        = isset( $_POST['age'] ) ? sanitize_text_field( wp_unslash( $_POST['age'] ) ) : '';
	$license    = isset( $_POST['license_type'] ) ? sanitize_text_field( wp_unslash( $_POST['license_type'] ) ) : '';
	$agree      = ! empty( $_POST['agree'] );

	// 4. Server-side validation (mirrors the front-end rules).
	$errors = array();

	if ( '' === $full_name ) {
		$errors['full_name'] = 'Por favor, ingrese su nombre completo.';
	} elseif ( ! preg_match( "/^[A-Za-z]+(?:[ '\\-][A-Za-z]+)*$/", $full_name ) || strlen( $full_name ) < 2 || strlen( $full_name ) > 80 ) {
		$errors['full_name'] = 'El nombre solo puede contener letras, espacios, apóstrofes y guiones (2-80 caracteres).';
	}
	if ( '' === $email || ! is_email( $email ) ) {
		$errors['email'] = 'Por favor, ingrese un correo electrónico válido.';
	}
	if ( '' === $phone ) {
		$errors['phone'] = 'Por favor, ingrese su número de teléfono.';
	} else {
		$phone_digits = preg_replace( '/\D+/', '', $phone );
		if ( ! preg_match( '/^\+?[0-9()\-\s]{7,20}$/', $phone ) || strlen( $phone_digits ) < 7 || strlen( $phone_digits ) > 15 ) {
			$errors['phone'] = 'Por favor, ingrese un número de teléfono válido.';
		}
	}
	if ( '' === $date ) {
		$errors['date'] = 'Por favor, seleccione una fecha de recogida.';
	} elseif ( ! erf_is_valid_date( $date ) ) {
		$errors['date'] = 'Por favor, ingrese una fecha de recogida válida.';
	}

	if ( '' === $dropoff ) {
		$errors['dropoff_date'] = 'Por favor, seleccione una fecha de devolución.';
	} elseif ( ! erf_is_valid_date( $dropoff ) ) {
		$errors['dropoff_date'] = 'Por favor, ingrese una fecha de devolución válida.';
	} elseif ( empty( $errors['date'] ) && $dropoff < $date ) {
		// Y-m-d sorts correctly as a plain string, so no date maths needed.
		$errors['dropoff_date'] = 'La fecha de devolución no puede ser anterior a la fecha de recogida.';
	}
	if ( '' === $car_type ) {
		$errors['car_type'] = 'Por favor, seleccione un tipo de vehículo.';
	}
	if ( '' === $passengers ) {
		$errors['passengers'] = 'Por favor, ingrese el número de pasajeros.';
	}
	if ( '' === $age ) {
		$errors['age'] = 'Por favor, ingrese su edad.';
	}
	if ( '' === $license ) {
		$errors['license_type'] = 'Por favor, seleccione un tipo de licencia.';
	}
	if ( ! $agree ) {
		$errors['agree'] = 'Debe aceptar los términos y condiciones.';
	}

	if ( ! empty( $errors ) ) {
		wp_send_json_error(
			array(
				'message' => 'Por favor, corrija los campos resaltados.',
				'errors'  => $errors,
			),
			422
		);
	}

	// 5. Verify reCAPTCHA last, so invalid submissions never cost an API call.
	$token    = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
	$verified = erf_verify_recaptcha( $token );
	if ( true !== $verified ) {
		wp_send_json_error(
			array(
				'message' => $verified,
				'errors'  => array( 'recaptcha' => $verified ),
			),
			403
		);
	}

	// 6. Work out the recipient.
	$settings  = erf_get_settings();
	$recipient = trim( (string) $settings['recipient'] );
	if ( '' === $recipient ) {
		$recipient = get_option( 'admin_email' );
	}
	/**
	 * Filter the address(es) reservation submissions are sent to.
	 * Return a string (optionally comma separated) or an array of emails.
	 */
	$recipient = apply_filters( 'erf_recipient_email', $recipient );

	$subject = ! empty( $settings['subject'] ) ? $settings['subject'] : 'New Reservation Request';
	$subject = apply_filters( 'erf_email_subject', $subject );

	// 7. Build a tidy HTML email body.
	$rows = array(
		'Full Name'         => $full_name,
		'Email'             => $email,
		'Phone'             => $phone,
		'Pick-up Date'      => $date,
		'Drop-off Date'     => $dropoff,
		'Car Type'          => $car_type,
		'No. of Passengers' => $passengers,
		'Age'               => $age,
		'License Type'      => $license,
		'Agreed to Terms'   => $agree ? 'Yes' : 'No',
	);

	$body  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#222;">';
	$body .= '<h2 style="margin:0 0 16px;font-size:20px;">New Reservation Request</h2>';
	$body .= '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
	$i = 0;
	foreach ( $rows as $label => $value ) {
		$bg    = ( 0 === $i % 2 ) ? '#f7f7f7' : '#ffffff';
		$body .= '<tr>';
		$body .= '<td style="padding:10px 14px;background:' . $bg . ';border:1px solid #eee;font-weight:bold;width:38%;">' . esc_html( $label ) . '</td>';
		$body .= '<td style="padding:10px 14px;background:' . $bg . ';border:1px solid #eee;">' . nl2br( esc_html( $value ) ) . '</td>';
		$body .= '</tr>';
		$i++;
	}
	$body .= '</table>';
	$body .= '<p style="margin-top:18px;font-size:12px;color:#888;">Sent from ' . esc_html( get_bloginfo( 'name' ) ) . ' on ' . esc_html( current_time( 'F j, Y \a\t g:i a' ) ) . '</p>';
	$body .= '</div>';

	// 8. Headers: HTML email, with Reply-To set to the visitor.
	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		sprintf( 'Reply-To: %s <%s>', $full_name, $email ),
	);

	$sent = wp_mail( $recipient, $subject, $body, $headers );

	if ( $sent ) {
		wp_send_json_success( array( 'message' => 'Su solicitud de reservación ha sido enviada con éxito.' ) );
	}

	wp_send_json_error( array( 'message' => 'Lo sentimos, su solicitud no pudo enviarse por correo en este momento. Inténtelo de nuevo más tarde.' ), 500 );
}
add_action( 'wp_ajax_erf_submit', 'erf_handle_submit' );
add_action( 'wp_ajax_nopriv_erf_submit', 'erf_handle_submit' );

/* -------------------------------------------------------------------------
 * Admin settings page  (Settings → Reservation Form)
 * ---------------------------------------------------------------------- */

function erf_add_settings_page() {
	add_options_page(
		'Reservation Form',
		'Reservation Form',
		'manage_options',
		'erf-settings',
		'erf_render_settings_page'
	);
}
add_action( 'admin_menu', 'erf_add_settings_page' );

function erf_register_settings() {
	register_setting( 'erf_settings_group', ERF_OPTION, 'erf_sanitize_settings' );
}
add_action( 'admin_init', 'erf_register_settings' );

/**
 * Sanitize the settings form before saving.
 *
 * @param array $input Raw input.
 * @return array
 */
function erf_sanitize_settings( $input ) {
	$out = array();

	// Recipient may be a comma separated list of addresses.
	$emails = array();
	if ( isset( $input['recipient'] ) ) {
		foreach ( explode( ',', (string) $input['recipient'] ) as $candidate ) {
			$clean = sanitize_email( trim( $candidate ) );
			if ( is_email( $clean ) ) {
				$emails[] = $clean;
			}
		}
	}
	$out['recipient']     = implode( ', ', $emails );
	$out['subject']       = isset( $input['subject'] ) ? sanitize_text_field( $input['subject'] ) : '';
	$out['car_types']     = isset( $input['car_types'] ) ? sanitize_textarea_field( $input['car_types'] ) : '';
	$out['license_types'] = isset( $input['license_types'] ) ? sanitize_textarea_field( $input['license_types'] ) : '';

	if ( '' === $out['recipient'] ) {
		add_settings_error( ERF_OPTION, 'erf_recipient', 'Please enter at least one valid recipient email address.', 'error' );
		$out['recipient'] = get_option( 'admin_email' );
	}

	// reCAPTCHA.
	$mode = isset( $input['recaptcha_mode'] ) ? sanitize_text_field( $input['recaptcha_mode'] ) : 'off';
	if ( ! in_array( $mode, array( 'off', 'v2', 'v3' ), true ) ) {
		$mode = 'off';
	}

	$out['recaptcha_site']   = isset( $input['recaptcha_site'] ) ? sanitize_text_field( $input['recaptcha_site'] ) : '';
	$out['recaptcha_secret'] = isset( $input['recaptcha_secret'] ) ? sanitize_text_field( $input['recaptcha_secret'] ) : '';

	// Refuse to enable a mode we cannot actually enforce.
	if ( 'off' !== $mode && ( '' === $out['recaptcha_site'] || '' === $out['recaptcha_secret'] ) ) {
		add_settings_error(
			ERF_OPTION,
			'erf_recaptcha_keys',
			'reCAPTCHA needs both a site key and a secret key. It has been switched off until you supply both.',
			'error'
		);
		$mode = 'off';
	}
	$out['recaptcha_mode'] = $mode;

	$score = isset( $input['recaptcha_score'] ) ? (float) $input['recaptcha_score'] : 0.5;
	if ( $score < 0 || $score > 1 ) {
		add_settings_error( ERF_OPTION, 'erf_recaptcha_score', 'The reCAPTCHA score threshold must be between 0.0 and 1.0. It has been reset to 0.5.', 'error' );
		$score = 0.5;
	}
	$out['recaptcha_score'] = (string) round( $score, 2 );

	return $out;
}

function erf_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = erf_get_settings();
	?>
	<div class="wrap">
		<h1>Reservation Form</h1>
		<p>Place this shortcode in any page or post to display the form:</p>
		<p><code>[reservation_form]</code></p>
		<p style="color:#666;">Optional attributes:
			<code>[reservation_form title="Book Now" subtitle="Tell us about your trip" bg="https://your-site.com/car.jpg" terms_url="/terms"]</code>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'erf_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="erf_recipient">Send submissions to</label></th>
					<td>
						<input type="text" id="erf_recipient" name="<?php echo esc_attr( ERF_OPTION ); ?>[recipient]" value="<?php echo esc_attr( $s['recipient'] ); ?>" class="regular-text">
						<p class="description">The email address that receives submissions. Separate multiple addresses with commas.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="erf_subject">Email subject</label></th>
					<td>
						<input type="text" id="erf_subject" name="<?php echo esc_attr( ERF_OPTION ); ?>[subject]" value="<?php echo esc_attr( $s['subject'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="erf_car_types">Car types</label></th>
					<td>
						<textarea id="erf_car_types" name="<?php echo esc_attr( ERF_OPTION ); ?>[car_types]" rows="6" class="large-text code"><?php echo esc_textarea( $s['car_types'] ); ?></textarea>
						<p class="description">One option per line. These appear in the “Car Type” dropdown.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="erf_license_types">License types</label></th>
					<td>
						<textarea id="erf_license_types" name="<?php echo esc_attr( ERF_OPTION ); ?>[license_types]" rows="5" class="large-text code"><?php echo esc_textarea( $s['license_types'] ); ?></textarea>
						<p class="description">One option per line. These appear in the “Driving License Type” dropdown.</p>
					</td>
				</tr>
			</table>

			<h2>Spam protection</h2>
			<p>Google reCAPTCHA is optional. Get your keys from
				<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">google.com/recaptcha/admin</a> —
				make sure the key type you create there matches the version you pick below, as v2 and v3 keys are not interchangeable.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="erf_recaptcha_mode">reCAPTCHA</label></th>
					<td>
						<select id="erf_recaptcha_mode" name="<?php echo esc_attr( ERF_OPTION ); ?>[recaptcha_mode]">
							<option value="off" <?php selected( $s['recaptcha_mode'], 'off' ); ?>>Off</option>
							<option value="v2" <?php selected( $s['recaptcha_mode'], 'v2' ); ?>>v2 — “I’m not a robot” checkbox</option>
							<option value="v3" <?php selected( $s['recaptcha_mode'], 'v3' ); ?>>v3 — invisible score</option>
						</select>
						<p class="description">v2 adds a checkbox to the last step. v3 is invisible and scores each submission instead.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="erf_recaptcha_site">Site key</label></th>
					<td>
						<input type="text" id="erf_recaptcha_site" name="<?php echo esc_attr( ERF_OPTION ); ?>[recaptcha_site]" value="<?php echo esc_attr( $s['recaptcha_site'] ); ?>" class="regular-text" autocomplete="off">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="erf_recaptcha_secret">Secret key</label></th>
					<td>
						<input type="password" id="erf_recaptcha_secret" name="<?php echo esc_attr( ERF_OPTION ); ?>[recaptcha_secret]" value="<?php echo esc_attr( $s['recaptcha_secret'] ); ?>" class="regular-text" autocomplete="off">
						<p class="description">Kept on your server and never sent to the browser.</p>
					</td>
				</tr>
				<tr class="erf-v3-only">
					<th scope="row"><label for="erf_recaptcha_score">v3 score threshold</label></th>
					<td>
						<input type="number" id="erf_recaptcha_score" name="<?php echo esc_attr( ERF_OPTION ); ?>[recaptcha_score]" value="<?php echo esc_attr( $s['recaptcha_score'] ); ?>" min="0" max="1" step="0.1" class="small-text">
						<p class="description">Submissions scoring below this are rejected. 1.0 is very likely human, 0.0 very likely a bot. Start at 0.5 and adjust if real requests get blocked.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<script>
		( function () {
			var mode = document.getElementById( 'erf_recaptcha_mode' );
			if ( ! mode ) { return; }
			var v3Rows = document.querySelectorAll( '.erf-v3-only' );
			function sync() {
				Array.prototype.forEach.call( v3Rows, function ( row ) {
					row.style.display = ( 'v3' === mode.value ) ? '' : 'none';
				} );
			}
			mode.addEventListener( 'change', sync );
			sync();
		}() );
		</script>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Settings link on the Plugins screen
 * ---------------------------------------------------------------------- */

function erf_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=erf-settings' ) ) . '">Settings</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'erf_plugin_action_links' );
