(function () {
	'use strict';

	function payloadFromForm(form) {
		var payload = {};
		var data = new FormData(form);

		data.forEach(function (value, name) {
			if (name.slice(-2) === '[]') {
				name = name.slice(0, -2);
				if (!Array.isArray(payload[name])) {
					payload[name] = [];
				}
				payload[name].push(value);
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

	function showInlineError(form) {
		var message = form.querySelector('.listmonk-signup__message');
		if (!message) {
			message = document.createElement('div');
			message.className = 'listmonk-signup__message listmonk-signup__message--error';
			message.setAttribute('role', 'status');
			form.insertBefore(message, form.firstChild);
		}
		message.textContent = window.listmonkSignup && window.listmonkSignup.errorMessage ? window.listmonkSignup.errorMessage : 'The subscription could not be completed. Please try again later.';
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.classList || !form.classList.contains('listmonk-signup')) {
			return;
		}

		event.preventDefault();
		if (!form.reportValidity()) {
			return;
		}

		fetch(form.getAttribute('data-listmonk-rest-url'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json'
			},
			body: JSON.stringify(payloadFromForm(form))
		})
			.then(function (response) {
				return response.json().catch(function () {
					return null;
				}).then(function (data) {
					if (data && data.redirect_url) {
						return data;
					}

					if (!response.ok) {
						throw new Error('Submission failed.');
					}

					return data;
				});
			})
			.then(function (data) {
				if (!data || !data.redirect_url) {
					throw new Error('Missing redirect URL.');
				}
				window.location.href = data.redirect_url;
			})
			.catch(function () {
				showInlineError(form);
			});
	});
}());
