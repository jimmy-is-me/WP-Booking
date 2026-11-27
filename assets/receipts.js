(() => {
	'use strict';

	async function updateQR(box, url) {
		if (!url) return;
		const canvas = box.querySelector('canvas');
		const links = box.querySelectorAll('a');
		box.hidden = false;
		links[0].href = url;
		try {
			await window.WPBQRCode.toCanvas(canvas, url, { width: 224, margin: 3, errorCorrectionLevel: 'M' });
			canvas.hidden = false;
			links[1].href = canvas.toDataURL('image/png');
			links[1].hidden = false;
		} catch {
			canvas.hidden = links[1].hidden = true;
		}
	}
	for (const box of document.querySelectorAll('[data-wpb-qr]')) {
		updateQR(box, box.dataset.wpbQr);
		box.addEventListener('wpb:receipt', (event) => updateQR(box, event.detail));
	}

	for (const panel of document.querySelectorAll('[data-wpb-receipt-panel]')) {
		const form = panel.querySelector('form');
		const message = panel.querySelector('[data-wpb-receipt-message]');
		const result = panel.querySelector('[data-wpb-receipt-result]');
		const checkIn = panel.querySelector('[data-wpb-scan-in]');
		const token = location.hash.slice(1);
		const headers = { 'Content-Type': 'application/json', 'X-WPB-Request': '1' };
		if (panel.dataset.nonce) headers['X-WP-Nonce'] = panel.dataset.nonce;

		async function read(data, trigger) {
			if (trigger) trigger.disabled = true;
			message.textContent = data.check_in ? '正在報到…' : '查詢中…';
			if (!data.check_in) result.hidden = true;
			try {
				const response = await fetch(panel.dataset.endpoint, {
					method: 'POST', cache: 'no-store', headers, body: JSON.stringify(data),
				});
				const booking = await response.json();
				if (!response.ok) throw new Error(booking.message || '請稍後再試。');
				panel.querySelector('[data-wpb-receipt-summary]').textContent =
					[booking.reference, booking.service, booking.time, booking.party_size + ' 人', booking.status, booking.checked_in ? '已報到' : ''].filter(Boolean).join(' · ');
				for (const [selector, url] of [['[data-wpb-receipt-open]', form ? booking.receipt : ''], ['[data-wpb-receipt-manage]', booking.manage]]) {
					const link = panel.querySelector(selector);
					link.hidden = !url;
					if (url) link.href = url;
				}
				checkIn.hidden = !booking.can_check_in;
				result.hidden = false;
				message.textContent = data.check_in ? '報到成功。' : '';
			} catch (error) {
				message.textContent = error.message || '目前無法查詢，請稍後再試。';
			} finally {
				if (trigger) trigger.disabled = false;
			}
		}

		if (form) {
			form.hidden = false;
			form.addEventListener('submit', (event) => {
				event.preventDefault();
				read(Object.fromEntries(new FormData(form)), event.submitter);
			});
		} else {
			checkIn.addEventListener('click', () => read({ token, check_in: true }, checkIn));
			read({ token });
		}
	}
})();
