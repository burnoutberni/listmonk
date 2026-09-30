(() => {
	'use strict';

	function payloadFromForm(form) {
		const payload = {};
		const data = new FormData(form);

		data.forEach((value, name) => {
			if (name.slice(-2) === '[]') {
				const fieldName = name.slice(0, -2);
				if (!Array.isArray(payload[fieldName])) {
					payload[fieldName] = [];
				}
				payload[fieldName].push(value);
				return;
			}

			if (Object.prototype.hasOwnProperty.call(payload, name)) {
				if (!Array.isArray(payload[name])) {
					payload[name] = [payload[name]];
				}
				payload[name].push(value);
				return;
			}

			payload[name] = value;
		});

		return payload;
	}

	function showInlineError(form, text) {
		let message = form.querySelector('.listmonk-signup__message');
		if (!message) {
			message = document.createElement('div');
			message.setAttribute('role', 'status');
			form.insertBefore(message, form.firstChild);
		}
		message.className = 'listmonk-signup__message listmonk-signup__message--error';
		message.textContent = text || (window.listmonkSignup && window.listmonkSignup.errorMessage ? window.listmonkSignup.errorMessage : 'The subscription could not be completed. Please try again later.');
	}

	document.addEventListener('submit', (event) => {
		const form = event.target;
		if (!form || !form.classList || !form.classList.contains('listmonk-signup')) {
			return;
		}

		event.preventDefault();
		if (form.listmonkSubmissionPending) {
			return;
		}

		if (!form.reportValidity()) {
			return;
		}

		const submitButton = form.querySelector('[type="submit"]');
		form.listmonkSubmissionPending = true;
		if (submitButton) {
			submitButton.disabled = true;
		}

		fetch(form.getAttribute('data-listmonk-rest-url'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-WP-Nonce': (window.listmonkSignup && window.listmonkSignup.restNonce) || ''
			},
			body: JSON.stringify(payloadFromForm(form))
		})
			.then((response) => {
				return response.json().catch(() => {
					return null;
				}).then((data) => {
					if (!response.ok) {
						if (data && (data.error_code === 'invalid_nonce' || data.error_code === 'invalid_submission_token')) {
							const responseError = new Error('Submission failed.');
							responseError.userMessage = data.message;
							throw responseError;
						}

						if (data && data.redirect_url) {
							return data;
						}

						throw new Error('Submission failed.');
					}

					if (data && data.redirect_url) {
						return data;
					}

					return data;
				});
			})
			.then((data) => {
				if (!data || !data.redirect_url) {
					throw new Error('Missing redirect URL.');
				}
				window.location.href = data.redirect_url;
			})
			.catch((error) => {
				form.listmonkSubmissionPending = false;
				if (submitButton) {
					submitButton.disabled = false;
				}
				showInlineError(form, error && error.userMessage);
			});
	});
})();
