(() => {
	'use strict';

	const genericError = '目前無法連線，請稍後再試。';

	async function request(url, options) {
		const response = await fetch(url, { cache: 'no-store', ...options });
		const body = await response.json().catch(() => null);
		if (!response.ok || !body) {
			const error = new Error(body?.message || genericError);
			error.code = body?.code;
			throw error;
		}
		return body;
	}

	function makeRequestId() {
		const bytes = crypto.getRandomValues(new Uint8Array(16));
		return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
	}

	function mount(root) {
		const part = (name) => root.querySelector(`[data-wpb="${name}"]`);
		const form = part('form');
		const service = form.elements.service;
		const date = form.elements.date;
		const slots = part('slots');
		const submit = part('submit');
		const submitMessage = part('submit-message');
		const result = part('result');
		const calendarMessage = part('calendar-message');
		const retry = part('retry');
		let services = [];
		let calendarLoaded = false;
		let submitting = false;
		let slotsRequest;
		let previousRequest;

		function show(node, message) {
			node.textContent = message;
			node.hidden = message === '';
		}

		function showService() {
			const chosen = services.find((entry) => entry.id === service.value);
			show(part('description'), chosen?.description || '');
		}

		async function loadCalendar() {
			calendarLoaded = false;
			form.hidden = true;
			retry.hidden = true;
			show(calendarMessage, '正在載入預約服務…');
			try {
				const calendar = await request(root.dataset.wpbCalendar);
				services = calendar.services;
				if (!services.length) {
					show(calendarMessage, '目前沒有開放預約的服務。');
					return;
				}
				const placeholder = new Option('請選擇服務', '', true, true);
				placeholder.disabled = true;
				service.replaceChildren(placeholder, ...services.map((entry) =>
					new Option(`${entry.name}（${entry.duration} 分鐘）`, entry.id)
				));
				date.min = calendar.dates.min;
				date.max = calendar.dates.max;
				show(part('timezone'), `網站時區 ${calendar.timezone}`);
				showService();
				show(calendarMessage, '');
				show(part('slot-message'), '選擇服務與日期後即可查看時段。');
				form.hidden = false;
				calendarLoaded = true;
			} catch (error) {
				show(calendarMessage, error instanceof SyntaxError ? genericError : error.message);
				retry.hidden = false;
			}
		}

		async function loadSlots() {
			slotsRequest?.abort();
			slots.replaceChildren();
			submit.disabled = true;
			if (!service.value || !date.value || !date.validity.valid) {
				show(part('slot-message'), '選擇有效的服務與日期後即可查看時段。');
				return;
			}
			const url = new URL(root.dataset.wpbSlots, window.location.href);
			url.searchParams.set('service', service.value);
			url.searchParams.set('date', date.value);
			const controller = new AbortController();
			slotsRequest = controller;
			show(part('slot-message'), '正在查詢可預約時段…');
			try {
				const available = await request(url, { signal: controller.signal });
				if (controller.signal.aborted) {
					return;
				}
				show(part('slot-message'), available.slots.length ? '' : '這天沒有可預約的時段，請選擇其他日期。');
				for (const slot of available.slots) {
					const label = document.createElement('label');
					const input = document.createElement('input');
					const text = document.createElement('span');
					input.type = 'radio';
					input.name = 'starts_at';
					input.value = slot.start;
					input.required = true;
					text.textContent = slot.label;
					label.append(input, text);
					slots.append(label);
				}
			} catch (error) {
				if (!controller.signal.aborted) {
					show(part('slot-message'), error.message);
					const button = document.createElement('button');
					button.type = 'button';
					button.className = 'wpb-button wpb-secondary';
					button.textContent = '重新查詢時段';
					button.addEventListener('click', loadSlots);
					slots.append(button);
				}
			}
		}

		form.addEventListener('change', (event) => {
			if (!calendarLoaded || submitting) {
				return;
			}
			show(submitMessage, '');
			if (event.target === service || event.target === date) {
				showService();
				loadSlots();
			} else if (event.target.name === 'starts_at') {
				submit.disabled = false;
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (submitting || !calendarLoaded) {
				return;
			}
			const data = new FormData(form);
			if (!data.has('starts_at')) {
				return;
			}

			const payload = {
				service_id: data.get('service'),
				starts_at: Number(data.get('starts_at')),
				customer_name: data.get('customer_name'),
				customer_email: data.get('customer_email'),
				customer_phone: data.get('customer_phone'),
				note: data.get('note'),
				website: data.get('website')
			};
			const signature = JSON.stringify(payload);
			const inputs = Array.from(form.elements).filter((control) => !control.disabled);
			try {
				if (previousRequest?.signature !== signature) {
					previousRequest = { signature, id: makeRequestId() };
				}
				submitting = true;
				inputs.forEach((control) => { control.disabled = true; });
				submit.textContent = '送出中…';
				form.setAttribute('aria-busy', 'true');
				show(submitMessage, '');
				const confirmation = await request(root.dataset.wpbBookings, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WPB-Request': '1' },
					body: JSON.stringify({ ...payload, request_id: previousRequest.id })
				});
				for (const key of ['reference', 'service', 'time', 'status']) {
					part(key).textContent = confirmation[key];
				}
				form.reset();
				form.hidden = true;
				result.hidden = false;
				previousRequest = undefined;
				result.focus();
			} catch (error) {
				show(submitMessage, error.message);
				if (['wpb_unavailable', 'wpb_request_mismatch'].includes(error.code)) {
					previousRequest = undefined;
					inputs.forEach((control) => { control.disabled = false; });
					loadSlots();
				}
			} finally {
				submitting = false;
				inputs.forEach((control) => { control.disabled = false; });
				submit.disabled = !slots.querySelector('input:checked') || form.hidden;
				submit.textContent = '送出預約';
				form.removeAttribute('aria-busy');
			}
		});

		part('another').addEventListener('click', async () => {
			result.hidden = true;
			slots.replaceChildren();
			await loadCalendar();
			if (calendarLoaded) {
				service.focus();
			}
		});
		retry.addEventListener('click', loadCalendar);
		loadCalendar();
	}

	function init() {
		document.querySelectorAll('.wpb[data-wpb-calendar]').forEach(mount);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
