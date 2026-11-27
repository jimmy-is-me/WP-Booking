(() => {
	'use strict';
	const dayLabels = { available: '可預約', closed: '公休', full: '額滿', outside: '未開放' };

	async function request(path, params = {}, options = {}) {
		const url = new URL(path, window.location.href);
		for (const [key, value] of Object.entries(params)) {
			url.searchParams.set(key, value);
		}
		const response = await fetch(url, { cache: 'no-store', ...options });
		const body = await response.json().catch(() => null);
		if (!response.ok || !body) {
			const error = new Error(body?.message || '目前無法連線，請稍後再試。');
			error.code = body?.code;
			throw error;
		}
		return body;
	}

	function monthAt(month, offset = 0) {
		const [year, number] = month.split('-').map(Number);
		return new Date(Date.UTC(year, number - 1 + offset, 1));
	}

	function makeChoice(label, className) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = className;
		button.textContent = label;
		button.setAttribute('aria-pressed', 'false');
		return button;
	}

	function choose(container, selected) {
		for (const button of container.querySelectorAll('[aria-pressed]')) {
			button.setAttribute('aria-pressed', String(button === selected));
		}
	}

	function mount(root) {
		const part = (name) => root.querySelector(`[data-wpb="${name}"]`);
		const form = part('form');
		const services = part('services');
		const days = part('days');
		const slots = part('slots');
		const wizard = part('wizard');
		const submit = part('submit');
		const contactNext = part('contact-next');
		const dateNext = part('date-next');
		let phase = 1;
		let service, defaults, limits, month, date, slot, monthRequest, slotRequest, previousRequest;
		let submitting = false;

		function message(name, value = '') {
			const node = part(name);
			node.textContent = value;
			node.hidden = !value;
		}

		function showPhase(number, focus = true) {
			phase = number;
			for (const panel of root.querySelectorAll('[data-wpb-panel]')) {
				panel.hidden = Number(panel.dataset.wpbPanel) !== number;
				if (!panel.hidden && focus) {
					panel.querySelector('h3').focus();
				}
			}
			for (const item of root.querySelectorAll('[data-wpb-step]')) {
				const value = Number(item.dataset.wpbStep);
				item.classList.toggle('wpb-step-done', value < number);
				if (value === number) {
					item.setAttribute('aria-current', 'step');
				} else {
					item.removeAttribute('aria-current');
				}
			}
		}

		function clearSlots() {
			slotRequest?.abort();
			slot = undefined;
			slots.replaceChildren();
			if (contactNext) contactNext.disabled = true;
			message('date-label', date ? `${date} 可預約時段` : '可預約時段');
			message('slot-message', '請先選擇日期。');
		}

		function serviceCard(entry) {
			const card = makeChoice('', 'wpb-service');
			const icon = document.createElement('span');
			icon.className = 'wpb-service-icon';
			icon.textContent = '◈';
			icon.setAttribute('aria-hidden', 'true');
			const title = document.createElement('strong');
			title.textContent = entry.name;
			const info = document.createElement('span');
			info.className = 'wpb-service-meta';
			info.textContent = `${entry.duration} 分鐘${entry.price ? ` · ${entry.price}` : ''}`;
			card.append(icon, title, info);
			if (entry.description) {
				const desc = document.createElement('span');
				desc.className = 'wpb-service-description';
				desc.textContent = entry.description;
				card.append(desc);
			}
			card.addEventListener('click', () => {
				choose(services, card);
				if (service?.id !== entry.id) {
					monthRequest?.abort();
					service = entry;
					limits = service.dates || defaults;
					month = undefined;
					date = undefined;
					clearSlots();
				}
				dateNext.disabled = false;
			});
			return card;
		}

		async function loadServices(focus = false, notice = '') {
			wizard.hidden = true;
			part('retry').hidden = true;
			message('calendar-message', '正在載入預約服務…');
			try {
				const calendar = await request(root.dataset.wpbCalendar);
				if (!calendar.services.length) {
					message('calendar-message', '目前沒有開放預約的服務。');
					return;
				}
				defaults = calendar.dates;
				service = undefined;
				month = undefined;
				date = undefined;
				clearSlots();
				message('flow-message', notice);
				message('timezone', `網站時區 ${calendar.timezone}`);
				services.replaceChildren(...calendar.services.map(serviceCard));
				dateNext.disabled = true;
				message('calendar-message');
				wizard.hidden = false;
				showPhase(1, focus);
			} catch (error) {
				message('calendar-message', error.message);
				part('retry').hidden = false;
			}
		}

		function renderDays(availability) {
			const first = monthAt(month);
			const last = monthAt(month, 1);
			const fragment = document.createDocumentFragment();
			for (let blank = 0; blank < first.getUTCDay(); blank++) {
				fragment.append(document.createElement('span'));
			}
			for (const day = first; day < last; day.setUTCDate(day.getUTCDate() + 1)) {
				const key = day.toISOString().slice(0, 10);
				const status = availability[key] || 'outside';
				const choice = makeChoice(String(day.getUTCDate()), `wpb-day wpb-${status}`);
				choice.title = dayLabels[status];
				choice.setAttribute('aria-label', `${key} ${dayLabels[status]}`);
				choice.setAttribute('aria-pressed', String(key === date));
				choice.disabled = status !== 'available';
				choice.addEventListener('click', () => {
					date = key;
					choose(days, choice);
					loadSlots();
				});
				fragment.append(choice);
			}
			days.replaceChildren(fragment);
		}

		async function loadMonth() {
			monthRequest?.abort();
			const controller = new AbortController();
			monthRequest = controller;
			const first = monthAt(month);
			message('month-label', `${first.getUTCFullYear()} 年 ${first.getUTCMonth() + 1} 月`);
			part('previous').disabled = month <= limits.min.slice(0, 7);
			part('next').disabled = month >= limits.max.slice(0, 7);
			part('retry-month').hidden = true;
			days.replaceChildren();
			message('month-message', '正在查詢月曆…');
			try {
				const calendar = await request(root.dataset.wpbMonth, { service: service.id, month }, { signal: controller.signal });
				if (controller.signal.aborted) {
					return;
				}
				if (date && calendar.days[date] !== 'available') {
					date = undefined;
					clearSlots();
				}
				renderDays(calendar.days);
				message('month-message', Object.values(calendar.days).includes('available') ? '' : '本月沒有可預約的日期。');
				if (date && !slot) {
					loadSlots();
				}
			} catch (error) {
				if (!controller.signal.aborted) {
					message('month-message', error.message);
					part('retry-month').hidden = false;
				}
			}
		}

		async function loadSlots() {
			clearSlots();
			const controller = new AbortController();
			slotRequest = controller;
			message('slot-message', '正在查詢可預約時段…');
			try {
				const available = await request(root.dataset.wpbSlots, { service: service.id, date }, { signal: controller.signal });
				if (controller.signal.aborted) {
					return;
				}
				message('slot-message', available.slots.length ? '' : '當天已無可預約時段，請選擇其他日期。');
				for (const item of available.slots) {
					if (!contactNext) {
						const label = document.createElement('span');
						label.className = 'wpb-slot wpb-slot-label';
						label.textContent = item.label;
						slots.append(label);
						continue;
					}
					const label = service.capacity_unit === 'people' ? item.label + ' · 剩 ' + item.remaining + ' 人' : item.label;
					const choice = makeChoice(label, 'wpb-slot');
					choice.addEventListener('click', () => {
						slot = item;
						choose(slots, choice);
						contactNext.disabled = false;
						message('flow-message');
					});
					slots.append(choice);
				}
			} catch (error) {
				if (!controller.signal.aborted) {
					message('slot-message', error.message);
					const retry = makeChoice('重新查詢時段', 'wpb-button wpb-secondary');
					retry.removeAttribute('aria-pressed');
					retry.addEventListener('click', loadSlots);
					slots.append(retry);
				}
			}
		}

		dateNext.addEventListener('click', () => {
			showPhase(2);
			if (!month) {
				const initial = root.dataset.wpbDate;
				date = initial && initial >= limits.min && initial <= limits.max ? initial : undefined;
				month = (date || limits.min).slice(0, 7);
				loadMonth();
			}
		});

		function moveMonth(offset) {
			month = monthAt(month, offset).toISOString().slice(0, 7);
			date = undefined;
			clearSlots();
			loadMonth();
		}

		part('previous').addEventListener('click', () => moveMonth(-1));
		part('next').addEventListener('click', () => moveMonth(1));
		part('retry-month').addEventListener('click', loadMonth);
		part('back-service').addEventListener('click', () => showPhase(1));
		part('back-date')?.addEventListener('click', () => showPhase(2));
		contactNext?.addEventListener('click', () => {
			const byPerson = service.capacity_unit === 'people';
			part('party-field').hidden = !byPerson;
			form.elements.party_size.max = byPerson ? slot.remaining : 1;
			form.elements.party_size.value = Math.min(Number(form.elements.party_size.value) || 1, Number(form.elements.party_size.max));
			message('summary-service', service.name);
			message('summary-time', `${date} ${slot.label}`);
			message('summary-duration', `${service.duration} 分鐘`);
			part('summary-price-row').hidden = !service.price;
			message('summary-price', service.price || '');
			message('submit-message');
			showPhase(3);
		});

		form?.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (submitting || phase !== 3 || !slot || !service) {
				return;
			}
			const payload = {
				...Object.fromEntries(new FormData(form)),
				service_id: service.id, starts_at: slot.start,
				expected_duration: service.duration, expected_price: service.price_amount,
				party_size: Number(form.elements.party_size.value),
			};
			const signature = JSON.stringify(payload);
			const controls = Array.from(form.elements).filter((control) => !control.disabled);
			try {
				if (previousRequest?.signature !== signature) {
					const bytes = crypto.getRandomValues(new Uint8Array(16));
					const id = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
					previousRequest = { signature, id };
				}
				submitting = true;
				controls.forEach((control) => { control.disabled = true; });
				form.setAttribute('aria-busy', 'true');
				submit.textContent = '送出中…';
				message('submit-message');
				const headers = { 'Content-Type': 'application/json', 'X-WPB-Request': '1' };
				if (root.dataset.wpbNonce) {
					headers['X-WP-Nonce'] = root.dataset.wpbNonce;
				}
				const confirmation = await request(root.dataset.wpbBookings, {}, {
					method: 'POST', headers, body: JSON.stringify({ ...payload, request_id: previousRequest.id }),
				});
				for (const key of ['reference', 'service', 'time', 'status']) {
					message(key, confirmation[key]);
				}
				part('result-price-row').hidden = !confirmation.price;
				message('result-price', confirmation.price || '');
				const descriptions = {
					待確認: '預約已登記，請等候店家確認。',
					已確認: '預約已登記，請依預約時段出席。',
				};
				message('result-message', descriptions[confirmation.status] || '請查看目前的預約狀態。');
				message('result-party', String(confirmation.party_size));
				root.querySelector('[data-wpb-qr]').dispatchEvent(new CustomEvent('wpb:receipt', { detail: confirmation.receipt }));
				form.reset();
				previousRequest = undefined;
				showPhase(4);
			} catch (error) {
				if (['wpb_unavailable', 'wpb_request_mismatch'].includes(error.code)) {
					previousRequest = undefined;
					clearSlots();
					message('flow-message', error.message);
					showPhase(2);
					loadMonth();
				} else if (error.code === 'wpb_service_changed') {
					previousRequest = undefined;
					loadServices(true, error.message);
				} else {
					message('submit-message', error.message);
				}
			} finally {
				submitting = false;
				form.removeAttribute('aria-busy');
				controls.forEach((control) => { control.disabled = false; });
				submit.textContent = '送出預約';
			}
		});

		part('another')?.addEventListener('click', () => loadServices(true));
		part('retry').addEventListener('click', () => loadServices(true));
		const dialog = root.closest('dialog');
		if (dialog && !dialog.open) {
			dialog.addEventListener('wpb:open', () => loadServices(), { once: true });
		} else {
			loadServices();
		}
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
