/* =========================================================================
   Equality Reservation Form — front-end behaviour
   Vanilla JS, no jQuery dependency. Supports multiple forms on one page.
   ========================================================================= */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function recaptchaConfig() {
		return (window.ERF_DATA && window.ERF_DATA.recaptcha) || { mode: 'off' };
	}

	/**
	 * Google's api.js is loaded async, so it may land before or after this file.
	 * Poll for it rather than relying on an onload callback that would have to
	 * exist before the script tag runs.
	 */
	function whenRecaptchaReady(onReady, onFail) {
		var tries = 0;
		(function poll() {
			if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') {
				window.grecaptcha.ready(onReady);
				return;
			}
			if (++tries > 100) { // ~10 seconds.
				if (onFail) { onFail(); }
				return;
			}
			setTimeout(poll, 100);
		}());
	}

	ready(function () {
		var wraps = document.querySelectorAll('.erf-wrap');
		Array.prototype.forEach.call(wraps, initForm);
	});

	function initForm(wrap) {
		var form = wrap.querySelector('.erf-form');
		if (!form || form.dataset.erfInit) {
			return;
		}
		form.dataset.erfInit = '1';

		var steps = Array.prototype.slice.call(form.querySelectorAll('.erf-step'));
		var dots = Array.prototype.slice.call(wrap.querySelectorAll('.erf-dot'));
		var fill = wrap.querySelector('.erf-steps-fill');
		var stepper = wrap.querySelector('.erf-steps');
		var success = wrap.querySelector('.erf-success');
		var msgBox = form.querySelector('.erf-formmsg');
		var total = steps.length;
		var current = 0;

		var captcha = recaptchaConfig();
		var captchaBox = form.querySelector('.erf-recaptcha');
		var widgetId = null;

		// v2: render this form's own widget so several forms can coexist.
		if (captcha.mode === 'v2' && captchaBox) {
			whenRecaptchaReady(function () {
				try {
					widgetId = window.grecaptcha.render(captchaBox, {
						sitekey: captcha.site_key,
						callback: function () { clearError(captchaBox); },
						'expired-callback': function () {
							setError(captchaBox, 'Su verificación expiró. Marque la casilla de nuevo.');
						}
					});
				} catch (e) {
					showMessage('No se pudo cargar la verificación antispam. Recargue la página.');
				}
			}, function () {
				showMessage('No se pudo cargar la verificación antispam. Verifique su conexión y recargue la página.');
			});
		}

		function captchaToken() {
			if (captcha.mode === 'v2') {
				return (widgetId !== null && window.grecaptcha) ? window.grecaptcha.getResponse(widgetId) : '';
			}
			return '';
		}

		function resetCaptcha() {
			if (captcha.mode === 'v2' && widgetId !== null && window.grecaptcha) {
				window.grecaptcha.reset(widgetId);
			}
		}

		/**
		 * Resolve with the token to send. v3 mints one per submit because tokens
		 * expire after two minutes; v2 reuses whatever the visitor already solved.
		 */
		function getToken() {
			return new Promise(function (resolve, reject) {
				if (captcha.mode === 'v3') {
					whenRecaptchaReady(function () {
						window.grecaptcha
							.execute(captcha.site_key, { action: captcha.action || 'reservation_form' })
							.then(resolve, function () {
								reject(new Error('execute failed'));
							});
					}, function () {
						reject(new Error('api unavailable'));
					});
					return;
				}
				resolve(captchaToken());
			});
		}

		function showStep(index, focusFirst) {
			index = Math.max(0, Math.min(index, total - 1));
			steps.forEach(function (step, i) {
				step.classList.toggle('is-active', i === index);
			});
			dots.forEach(function (dot, i) {
				dot.classList.toggle('is-active', i === index);
				dot.classList.toggle('is-done', i < index);
			});
			if (fill) {
				var pct = total > 1 ? (index / (total - 1)) * 100 : 0;
				fill.style.width = pct + '%';
			}
			current = index;

			if (focusFirst) {
				var firstField = steps[index].querySelector('input:not([type=hidden]), select, textarea');
				if (firstField) {
					try { firstField.focus({ preventScroll: true }); } catch (e) { firstField.focus(); }
				}
			}
		}

		function fieldsIn(step) {
			return Array.prototype.slice
				.call(step.querySelectorAll('input, select, textarea'))
				.filter(function (f) {
					return f.name !== 'erf_website' && f.name !== 'g-recaptcha-response';
				});
		}

		function validateStep(index) {
			var ok = true;
			fieldsIn(steps[index]).forEach(function (field) {
				clearError(field);
				if (!field.checkValidity()) {
					ok = false;
					setError(field, friendlyMessage(field));
				}
			});
			return ok;
		}

		function friendlyMessage(field) {
			if (field.validity.valueMissing) {
				if (field.type === 'checkbox') {
					return 'Debe aceptar los términos y condiciones.';
				}
				if (field.tagName === 'SELECT') {
					return 'Por favor, seleccione una opción.';
				}
				return 'Este campo es obligatorio.';
			}
			if (field.validity.typeMismatch && field.type === 'email') {
				return 'Por favor, ingrese un correo electrónico válido.';
			}
			if (field.validity.rangeUnderflow) {
				if (field.type === 'date') {
					return field.name === 'dropoff_date'
						? 'La fecha de devolución no puede ser anterior a la fecha de recogida.'
						: 'Por favor, elija una fecha no anterior a hoy.';
				}
				return 'Valor demasiado bajo (mínimo ' + field.min + ').';
			}
			if (field.validity.rangeOverflow) {
				return 'Valor demasiado alto (máximo ' + field.max + ').';
			}
			return field.validationMessage || 'Por favor, revise este campo.';
		}

		function setError(field, message) {
			var holder = field.closest('.erf-field') || field.parentElement;
			var slot = holder ? holder.querySelector('.erf-error') : null;
			field.classList.add('has-error');
			if (slot) {
				slot.textContent = message;
				slot.classList.add('is-visible');
			}
		}

		function clearError(field) {
			var holder = field.closest('.erf-field') || field.parentElement;
			var slot = holder ? holder.querySelector('.erf-error') : null;
			field.classList.remove('has-error');
			if (slot) {
				slot.textContent = '';
				slot.classList.remove('is-visible');
			}
		}

		function showMessage(text) {
			if (!msgBox) { return; }
			msgBox.textContent = text;
			msgBox.className = 'erf-formmsg erf-formmsg--error';
			msgBox.hidden = false;
		}

		function hideMessage() {
			if (!msgBox) { return; }
			msgBox.hidden = true;
		}

		// Keep the drop-off date at or after the pick-up date.
		var pickupField = form.querySelector('[name="date"]');
		var dropoffField = form.querySelector('[name="dropoff_date"]');

		if (pickupField && dropoffField) {
			pickupField.addEventListener('change', function () {
				if (!pickupField.value) {
					return;
				}
				dropoffField.min = pickupField.value;
				// Drop a now-impossible choice rather than leaving it looking valid.
				if (dropoffField.value && dropoffField.value < pickupField.value) {
					dropoffField.value = '';
					clearError(dropoffField);
				}
			});
		}

		// Clear a field's error as soon as the user edits it.
		form.addEventListener('input', function (e) {
			if (e.target.classList && e.target.classList.contains('has-error')) {
				clearError(e.target);
			}
		});
		form.addEventListener('change', function (e) {
			if (e.target.classList && e.target.classList.contains('has-error')) {
				clearError(e.target);
			}
		});

		// Next / Previous navigation.
		form.addEventListener('click', function (e) {
			var nextBtn = e.target.closest('.erf-next');
			var prevBtn = e.target.closest('.erf-prev');
			if (nextBtn) {
				e.preventDefault();
				hideMessage();
				if (validateStep(current)) {
					showStep(current + 1, true);
				}
			} else if (prevBtn) {
				e.preventDefault();
				hideMessage();
				showStep(current - 1, true);
			}
		});

		// Final submit.
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideMessage();
			if (!validateStep(current)) {
				return;
			}
			// v2 only: the widget is not a form control, so check it by hand.
			if (captcha.mode === 'v2' && captchaBox && !captchaToken()) {
				setError(captchaBox, 'Confirme que no es un robot.');
				return;
			}
			submitForm();
		});

		function submitForm() {
			var cfg = window.ERF_DATA || {};
			if (!cfg.ajax_url) {
				showMessage('Error de configuración: no se puede enviar el formulario.');
				return;
			}

			var submitBtn = form.querySelector('.erf-submit');

			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.classList.add('is-loading');
			}

			function releaseButton() {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.classList.remove('is-loading');
				}
			}

			getToken().then(function (token) {
				var data = new FormData(form);
				data.append('action', 'erf_submit');
				data.append('nonce', cfg.nonce || '');
				// FormData already carries the v2 textarea; set() keeps v3 and v2
				// on one code path without duplicating the key.
				if (token) {
					data.set('g-recaptcha-response', token);
				}

				return fetch(cfg.ajax_url, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
					.then(function (response) {
						return response.json().catch(function () {
							return { success: false, data: { message: 'Respuesta inesperada del servidor.' } };
						});
					})
					.then(function (result) {
						if (result && result.success) {
							window.location.href = '/thank-you';

							handleSuccess();

						} else {
							var payload = (result && result.data) || {};
							if (payload.errors) {
								applyServerErrors(payload.errors);
								// A used or rejected token can never pass again.
								if (payload.errors.recaptcha) {
									resetCaptcha();
								}
							}
							showMessage(payload.message || 'Algo salió mal. Inténtelo de nuevo.');
						}
					})
					.catch(function () {
						showMessage('Error de red. Verifique su conexión e inténtelo de nuevo.');
					});
			}, function () {
				resetCaptcha();
				showMessage('No se pudo completar la verificación antispam. Recargue la página e inténtelo de nuevo.');
			})
			.then(releaseButton);
		}

		function applyServerErrors(errors) {
			Object.keys(errors).forEach(function (name) {
				// The v2 widget has no matching [name], so aim at its container.
				var field = (name === 'recaptcha')
					? captchaBox
					: form.querySelector('[name="' + name + '"]');
				if (field) {
					setError(field, errors[name]);
				}
			});
			// Jump to the earliest step that now shows an error.
			for (var i = 0; i < steps.length; i++) {
				if (steps[i].querySelector('.has-error')) {
					showStep(i, true);
					break;
				}
			}
		}

		function handleSuccess() {
			form.style.display = 'none';
			if (stepper) { stepper.style.display = 'none'; }
			if (success) {
				success.hidden = false;
				success.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.location.href = '/thank-you';
			}
		}

		// Start on step one.
		showStep(0, false);
	}
})();
