(() => {
	'use strict';
	const page = document.querySelector('.wpb-admin');
	if (!page) {
		return;
	}

	page.addEventListener('click', (event) => {
		const trigger = event.target.closest('[data-wpb-dialog]');
		const dismiss = event.target.closest('[data-wpb-dismiss]');
		if (trigger) {
			const dialog = document.getElementById(trigger.dataset.wpbDialog);
			if (dialog) {
				dialog.showModal();
				dialog.dispatchEvent(new Event('wpb:open'));
			}
		}
		if (dismiss) {
			dismiss.closest('dialog').close();
		}
	});

	for (const toggle of page.querySelectorAll('[data-wpb-hours]')) {
		const input = toggle.parentElement.querySelector('input');
		let previous = input.value || '09:00-12:00, 13:00-18:00';
		function update() {
			const open = Boolean(input.value.trim());
			toggle.setAttribute('aria-pressed', String(open));
			toggle.textContent = open ? '營業' : '店休';
		}
		input.addEventListener('input', update);
		toggle.addEventListener('click', () => {
			if (input.value.trim()) {
				previous = input.value;
				input.value = '';
			} else {
				input.value = previous;
			}
			update();
		});
	}

	const closureText = page.querySelector('[data-wpb-closure-text]');
	if (closureText) {
		const list = page.querySelector('[data-wpb-closures]');
		const date = page.querySelector('[data-wpb-closure-date]');
		const dates = () => Array.from(new Set(closureText.value.trim().split(/[\s,，]+/u).filter(Boolean))).sort();
		function render() {
			list.replaceChildren();
			for (const day of dates()) {
				const item = document.createElement('li');
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.textContent = '×';
				remove.setAttribute('aria-label', `移除 ${day} 公休`);
				remove.addEventListener('click', () => {
					closureText.value = dates().filter((value) => value !== day).join('\n');
					render();
					date.focus();
				});
				item.append(day, remove);
				list.append(item);
			}
		}
		page.querySelector('[data-wpb-closure-add]').addEventListener('click', () => {
			if (!date.value || !date.validity.valid) {
				date.focus();
				return;
			}
			closureText.value = Array.from(new Set([...dates(), date.value])).sort().join('\n');
			date.value = '';
			render();
		});
		closureText.addEventListener('input', render);
		render();
	}

	const availability = page.querySelector('[data-wpb-availability]');
	if (availability) {
		const { mode, from, to, hours } = availability.elements;
		const label = availability.querySelector('[data-wpb-block-hours]');
		const syncMode = () => {
			const block = mode.value === 'block';
			label.hidden = !block;
			hours.disabled = !block;
			hours.required = block;
		};
		mode.addEventListener('change', syncMode);
		from.addEventListener('change', () => {
			if (!to.value || to.value < from.value) to.value = from.value;
			to.min = from.value;
		});
		syncMode();
	}

	for (const region of page.querySelectorAll('[data-wpb-scroll]')) {
		const hint = region.previousElementSibling;
		const reflect = () => {
			const max = region.scrollWidth - region.clientWidth;
			const offset = region.scrollLeft;
			hint.hidden = max <= 2;
			hint.toggleAttribute('data-end', max > 2 && offset >= max - 2);
			hint.firstChild.textContent = offset >= max - 2 ? '已到最右側，可向左滑動 ' : '可向右滑動查看其餘內容 ';
			hint.lastElementChild.textContent = offset >= max - 2 ? '←' : '→';
		};
		region.addEventListener('scroll', reflect, { passive: true });
		const observer = new ResizeObserver(reflect);
		observer.observe(region);
		observer.observe(region.firstElementChild);
		reflect();
	}

	const copy = page.querySelector('[data-wpb-copy]');
	copy?.addEventListener('click', async () => {
		try {
			await navigator.clipboard.writeText('[wp_booking]');
			copy.textContent = '已複製';
		} catch {
			const range = document.createRange();
			range.selectNodeContents(copy.parentElement.querySelector('code'));
			window.getSelection().removeAllRanges();
			window.getSelection().addRange(range);
			copy.textContent = '已選取短代碼';
		}
	});
})();
