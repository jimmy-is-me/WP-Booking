(() => {
	'use strict';

	function mount(root) {
		const message = root.querySelector('[data-wpb-manage-message]');
		const summary = root.querySelector('[data-wpb-manage-summary]');
		const move = root.querySelector('[data-wpb-move]');
		const cancel = root.querySelector('[data-wpb-cancel]');
		const date = move.elements.new_date;
		const slot = move.elements.new_slot;
		const slotGrid = move.querySelector('[data-wpb-manage-slots]');
		const submit = move.querySelector('[type="submit"]');
		const confirmBox = cancel.querySelector('[data-wpb-cancel-confirm]');
		const cancelButton = cancel.querySelector('[data-wpb-cancel-submit]');
		const id = Number(root.dataset.wpbId);
		const credentials = id ? { id } : { key: window.location.hash.slice(1) };
		const days = root.querySelector('[data-wpb-days]');
		const previousMonth = root.querySelector('[data-wpb-prev]');
		const nextMonth = root.querySelector('[data-wpb-next]');
		const newTime = root.querySelector('[data-wpb-new-time]');
		const attendance = root.querySelector('[data-wpb-attendance]');
		const calendarButton = root.querySelector('[data-wpb-ics]');
		let revision, slotRequest, monthRequest, limits, month, detailsRequest, calendarData;

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
			monthRequest?.abort();
			detailsRequest?.abort();
			const controller = new AbortController();
			detailsRequest = controller;
			attendance.hidden = calendarButton.hidden = newTime.hidden = true;
			message.textContent = '載入預約資料…';
			move.hidden = true;
			cancel.hidden = true;
			summary.hidden = true;
			confirmBox.hidden = true;
			cancelButton.disabled = false;
			date.disabled = false;
			move.reset();
			slot.value = '';
			slotGrid.textContent = '請先選日期';
			submit.disabled = true;
			try {
				const data = await request('details', {}, controller.signal);
				if (controller.signal.aborted) return;
				revision = data.revision;
				summary.textContent = [data.reference, data.service, data.time, data.status].join(' · ');
				summary.hidden = Boolean(id);
				limits = data.dates;
				month = limits.min.slice(0, 7);
				root.querySelector('[data-wpb-original]').textContent = '原時段：' + data.time;
				calendarData = data;
				attendance.textContent = data.calendar?.attendance || '';
				attendance.hidden = Boolean(id) || !attendance.textContent;
				calendarButton.hidden = Boolean(id) || !data.calendar;
				move.hidden = !data.move;
				cancel.hidden = !data.cancel;
				message.textContent = data.move || data.cancel ? '' : '目前已超過可自行變更的期限，或店家未開放這項操作。';
				if (data.move) loadMonth();
			} catch (error) {
				if (!controller.signal.aborted) message.textContent = error.message;
			}
		}

		function monthStart(offset = 0) {
			const start = new Date(month + '-01T00:00:00Z');
			start.setUTCMonth(start.getUTCMonth() + offset);
			return start;
		}

		async function loadMonth() {
			monthRequest?.abort();
			slotRequest?.abort();
			date.value = '';
			submit.disabled = newTime.hidden = true;
			slot.replaceChildren(new Option('請先選日期', ''));
			days.replaceChildren();
			const controller = new AbortController();
			monthRequest = controller;
			const first = monthStart();
			const last = monthStart(1);
			root.querySelector('[data-wpb-month-label]').textContent = first.getUTCFullYear() + ' 年 ' + (first.getUTCMonth() + 1) + ' 月';
			previousMonth.disabled = month <= limits.min.slice(0, 7);
			nextMonth.disabled = month >= limits.max.slice(0, 7);
			message.textContent = '正在查詢可改期日期…';
			try {
				const result = await request('month', { month }, controller.signal);
				if (controller.signal.aborted) return;
				for (let index = 0; index < first.getUTCDay(); index++) days.append(document.createElement('span'));
				const labels = { available: '可選', closed: '公休', full: '額滿或無其他時段', outside: '不可選' };
				for (const day = first; day < last; day.setUTCDate(day.getUTCDate() + 1)) {
					const key = day.toISOString().slice(0, 10);
					const status = result.days[key] || 'outside';
					const button = document.createElement('button');
					button.type = 'button';
					button.className = 'wpb-day wpb-' + status;
					button.textContent = day.getUTCDate();
					button.disabled = status !== 'available';
					button.setAttribute('aria-label', key + ' ' + labels[status]);
					button.setAttribute('aria-pressed', 'false');
					button.addEventListener('click', () => {
						days.querySelector('[aria-pressed="true"]')?.setAttribute('aria-pressed', 'false');
						button.setAttribute('aria-pressed', 'true');
						date.value = key;
						loadSlots();
					});
					days.append(button);
				}
				message.textContent = Object.values(result.days).includes('available') ? '' : '本月沒有可改期日期。';
			} catch (error) {
				if (controller.signal.aborted) return;
				const retry = document.createElement('button');
				retry.type = 'button';
				retry.textContent = '重新查詢日期';
				retry.addEventListener('click', loadMonth, { once: true });
				message.textContent = error.message;
				message.append(' ', retry);
			}
		}

		async function loadSlots() {
			slotRequest?.abort();
			submit.disabled = newTime.hidden = true;
			slot.value = '';
			slotGrid.replaceChildren();
			if (!date.value) {
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
					const button = document.createElement('button');
					button.type = 'button';
					button.className = 'wpb-slot';
					button.textContent = entry.label;
					button.setAttribute('aria-pressed', 'false');
					button.addEventListener('click', () => {
						slotGrid.querySelector('[aria-pressed="true"]')?.setAttribute('aria-pressed', 'false');
						button.setAttribute('aria-pressed', 'true');
						slot.value = entry.start;
						submit.disabled = newTime.hidden = false;
						newTime.textContent = '新時段：' + date.value + ' ' + entry.label;
					});
					slotGrid.append(button);
				}
				message.textContent = data.slots.length ? '' : '這天沒有可改期的時段。';
			} catch (error) {
				if (!controller.signal.aborted) {
					message.textContent = error.message;
				}
			}
		}

		async function finish(action, data) {
			slotRequest?.abort();
			const controls = [submit, cancelButton, previousMonth, nextMonth, ...days.querySelectorAll('button'), ...slotGrid.querySelectorAll('button')];
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
				summary.hidden = attendance.hidden = calendarButton.hidden = true;
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


		for (const [button, offset] of [[previousMonth, -1], [nextMonth, 1]]) {
			button.addEventListener('click', () => {
				month = monthStart(offset).toISOString().slice(0, 7);
				loadMonth();
			});
		}
		calendarButton.addEventListener('click', () => downloadCalendar(calendarData));
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

	function downloadCalendar({ calendar, reference, service, revision }) {
		const stamp = (seconds) => new Date(seconds * 1000).toISOString().replace(/[-:]|\.\d{3}/g, '');
		const escape = (value) => value.replace(/\\/g, '\\\\').replace(/\r?\n/g, '\\n').replace(/[,;]/g, (character) => '\\' + character).replace(/\r/g, '');
		const lines = [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WUMETAX//WP Booking//ZH',
			'BEGIN:VEVENT', 'UID:' + reference + '@' + location.hostname, 'SEQUENCE:' + revision,
			'DTSTAMP:' + stamp(Date.now() / 1000),
			'DTSTART:' + stamp(calendar.start), 'DTEND:' + stamp(calendar.end),
			'SUMMARY:' + escape(service),
			'DESCRIPTION:' + escape([reference, calendar.attendance].filter(Boolean).join('\n')),
			'END:VEVENT', 'END:VCALENDAR',
		];
		const encoder = new TextEncoder();
		const folded = lines.map((line) => {
			let result = '';
			let width = 0;
			for (const character of line) {
				const size = encoder.encode(character).length;
				if (width + size > 75) {
					result += '\r\n ';
					width = 1;
				}
				result += character;
				width += size;
			}
			return result;
		});
		const url = URL.createObjectURL(new Blob([folded.join('\r\n') + '\r\n'], { type: 'text/calendar;charset=utf-8' }));
		const link = document.createElement('a');
		link.href = url;
		link.download = reference + '.ics';
		link.click();
		setTimeout(() => URL.revokeObjectURL(url), 30_000);
	}

	document.querySelectorAll('[data-wpb-manage]').forEach(mount);
})();
