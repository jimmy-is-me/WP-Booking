(() => {
	'use strict';

	function mount(root) {
		const message = root.querySelector('[data-wpb-manage-message]');
		const summary = root.querySelector('[data-wpb-manage-summary]');
		const move = root.querySelector('[data-wpb-move]');
		const cancel = root.querySelector('[data-wpb-cancel]');
		const date = move.elements.new_date;
		const slot = move.elements.new_slot;
		const submit = move.querySelector('[type="submit"]');
		const confirmBox = cancel.querySelector('[data-wpb-cancel-confirm]');
		const cancelButton = cancel.querySelector('[data-wpb-cancel-submit]');
		const id = Number(root.dataset.wpbId);
		const credentials = id ? { id } : { key: window.location.hash.slice(1) };
		let revision, slotRequest;

		async function request(action, data = {}, signal) {
			const headers = { 'Content-Type': 'application/json', 'X-WPB-Request': '1' };
			if (root.dataset.wpbNonce) {
				headers['X-WP-Nonce'] = root.dataset.wpbNonce;
			}
			const response = await fetch(root.dataset.wpbManage, {
				method: 'POST', cache: 'no-store', headers, signal,
				body: JSON.stringify({ ...credentials, ...data, action }),
			});
			const body = await response.json().catch(() => null);
			if (!response.ok || !body) {
				throw new Error(body?.message || '目前無法連線，請稍後再試。');
			}
			return body;
		}

		async function load() {
			slotRequest?.abort();
			message.textContent = '載入預約資料…';
			move.hidden = true;
			cancel.hidden = true;
			summary.hidden = true;
			confirmBox.hidden = true;
			cancelButton.disabled = false;
			date.disabled = false;
			move.reset();
			slot.replaceChildren(new Option('請先選日期', ''));
			slot.disabled = true;
			submit.disabled = true;
			try {
				const data = await request('details');
				revision = data.revision;
				summary.textContent = [data.reference, data.service, data.time, data.status].join(' · ');
				summary.hidden = Boolean(id);
				date.min = data.dates.min;
				date.max = data.dates.max;
				move.hidden = !data.move;
				cancel.hidden = !data.cancel;
				message.textContent = data.move || data.cancel ? '' : '目前已超過可自行變更的期限，或店家未開放這項操作。';
			} catch (error) {
				message.textContent = error.message;
			}
		}

		async function loadSlots() {
			slotRequest?.abort();
			slot.disabled = true;
			submit.disabled = true;
			slot.replaceChildren(new Option('請選擇時段', ''));
			if (!date.value || !date.validity.valid) {
				return;
			}
			const controller = new AbortController();
			slotRequest = controller;
			message.textContent = '查詢可預約時段…';
			try {
				const data = await request('slots', { date: date.value }, controller.signal);
				if (controller.signal.aborted) {
					return;
				}
				for (const entry of data.slots) {
					slot.add(new Option(entry.label, entry.start));
				}
				slot.disabled = !data.slots.length;
				message.textContent = data.slots.length ? '' : '這天沒有可改期的時段。';
			} catch (error) {
				if (!controller.signal.aborted) {
					message.textContent = error.message;
				}
			}
		}

		async function finish(action, data) {
			slotRequest?.abort();
			const controls = [date, slot, submit, cancelButton];
			const disabled = controls.map((control) => control.disabled);
			controls.forEach((control) => { control.disabled = true; });
			message.textContent = '正在處理…';
			try {
				const result = await request(action, data);
				if (id) {
					window.location.reload();
					return;
				}
				move.hidden = true;
				cancel.hidden = true;
				summary.hidden = true;
				message.textContent = [result.message, result.time].filter(Boolean).join(' ');
			} catch (error) {
				message.textContent = error.message;
				controls.forEach((control, index) => { control.disabled = disabled[index]; });
				if (action === 'move') {
					const refresh = document.createElement('button');
					refresh.type = 'button';
					refresh.textContent = '重查時段';
					refresh.addEventListener('click', loadSlots, { once: true });
					message.append(' ', refresh);
				}
			}
		}

		date.addEventListener('change', loadSlots);
		slot.addEventListener('change', () => { submit.disabled = !slot.value; });
		move.addEventListener('submit', (event) => {
			event.preventDefault();
			if (slot.value) {
				finish('move', { starts_at: Number(slot.value), revision });
			}
		});
		cancel.querySelector('[data-wpb-cancel-toggle]').addEventListener('click', () => {
			confirmBox.hidden = false;
			cancelButton.focus();
		});
		cancel.querySelector('[data-wpb-cancel-back]').addEventListener('click', () => {
			confirmBox.hidden = true;
			cancel.querySelector('[data-wpb-cancel-toggle]').focus();
		});
		cancelButton.addEventListener('click', () => finish('cancel'));

		const details = root.closest('details');
		if (!id) {
			window.addEventListener('hashchange', () => {
				credentials.key = window.location.hash.slice(1);
				load();
			});
		}
		if (details && !details.open) {
			details.addEventListener('toggle', () => {
				if (details.open) {
					load();
				}
			});
		} else {
			load();
		}
	}

	document.querySelectorAll('[data-wpb-manage]').forEach(mount);
})();
