/* Photo Booking – flusso "Prenota": servizio → pacchetto ed extra → data → profilo, tutto nella stessa pagina. */
(function () {
	'use strict';

	var cfg = window.PB || {};
	var STORE = 'pbFlowState';

	function $(root, sel) { return root.querySelector(sel); }
	function $$(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

	function money(v) {
		var n = Number(v || 0);
		return n.toLocaleString('it-IT', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }) + ' ' + (cfg.currency || '€');
	}

	function init(form) {
		var steps = $$(form, '.pb-flow-step');
		var input = {
			product: $(form, 'input[name="product_id"]'),
			tier: $(form, 'input[name="tier"]'),
			start: $(form, 'input[name="start"]')
		};
		var state = { service: null, tier: null, when: '', whenShort: '', whenLong: '' };
		var picker = null;
		var pickerFor = null;
		var current = 1;

		function service(id) { return $(form, '.pb-service[data-id="' + id + '"]'); }
		function hasTiers() { return state.service && +state.service.getAttribute('data-tiers') > 0; }
		function fill(name, text) { $$(form, '[data-fill="' + name + '"]').forEach(function (n) { n.textContent = text; }); }
		function checkedExtras() {
			return $$(form, '.pb-extra input:checked').filter(function (c) { return !c.disabled; });
		}

		function total() {
			if (!state.service) { return 0; }
			if (!state.tier) { return parseFloat(state.service.getAttribute('data-price')); }
			return checkedExtras().reduce(function (sum, c) { return sum + parseFloat(c.getAttribute('data-price')); }, parseFloat(state.tier.getAttribute('data-price')));
		}

		/* Same rule as the server: package deposit if lower than the package price, otherwise everything. */
		function deposit() {
			var t = total();
			var p = parseFloat(state.service.getAttribute('data-price'));
			var d = parseFloat(state.service.getAttribute('data-deposit'));
			return Math.min(d < p ? d : t, t);
		}

		function go(n, opts) {
			opts = opts || {};
			current = n;
			steps.forEach(function (s) {
				var on = +s.getAttribute('data-step') === n;
				s.hidden = !on;
				s.classList.toggle('is-entering', on && !opts.instant);
			});
			if (n === 3) { ensurePicker(); }
			if (n === 4) { fillProfile(); }
			refresh();
			if (!opts.noScroll) {
				var top = form.getBoundingClientRect().top + window.pageYOffset - (parseInt(getComputedStyle(form).scrollMarginTop, 10) || 0);
				window.scrollTo({ top: top, behavior: opts.instant ? 'auto' : 'smooth' });
			}
		}

		/* Step 1 */
		function chooseService(btn) {
			if (btn.disabled) { return; }
			if (!state.service || state.service !== btn) {
				state.service = btn;
				input.product.value = btn.getAttribute('data-id');
				selectTier(null);
				input.start.value = '';
				state.when = state.whenShort = state.whenLong = '';
			}
			fill('service', btn.getAttribute('data-name'));
			var intro = $(form, '[data-fill="excerpt"]');
			if (intro) { intro.textContent = btn.getAttribute('data-excerpt') || ''; intro.hidden = !intro.textContent; }
			$$(form, '.pb-tiers').forEach(function (g) { g.hidden = g.getAttribute('data-for') !== btn.getAttribute('data-id'); });
			go(hasTiers() ? 2 : 3);
		}

		/* Step 2 */
		function selectTier(tierBtn) {
			state.tier = tierBtn;
			input.tier.value = tierBtn ? tierBtn.getAttribute('data-key') : '';
			$$(form, '.pb-tier').forEach(function (t) { t.setAttribute('aria-pressed', t === tierBtn ? 'true' : 'false'); });
			var key = tierBtn ? input.product.value + ':' + tierBtn.getAttribute('data-key') : '';
			$$(form, '.pb-extras').forEach(function (box) {
				var on = box.getAttribute('data-for') === key;
				box.hidden = !on;
				$$(box, 'input').forEach(function (c) { c.disabled = !on; if (!on) { c.checked = false; } });
			});
			refresh();
		}

		/* Step 3 */
		function ensurePicker() {
			var id = input.product.value;
			if (!id || pickerFor === id) {
				if (picker && state.tier) { picker.opts.priceLabel = money(total()); }
				return;
			}
			var keep = input.start.value || null;
			pickerFor = id;
			picker = window.PBPicker.create($(form, '.pb-picker'), {
				productId: id,
				firstMonth: state.service.getAttribute('data-first'),
				selected: keep,
				adjacent: true,
				placeholder: 'Seleziona prima una data dal calendario.',
				priceLabel: money(total()),
				onSelect: function (slot, dayLabel) {
					input.start.value = slot ? slot.start : '';
					setWhen(slot ? slot.start : '');
					refresh();
				}
			});
		}

		function setWhen(start) {
			if (!start) { state.when = state.whenShort = state.whenLong = ''; return; }
			var p = start.split(' ');
			var d = p[0].split('-');
			var time = p[1].substr(0, 5);
			var day = window.PBPicker.formatDay(p[0]);
			var month = day.split(' ').slice(2).join(' ');
			state.whenShort = (+d[2]) + ' ' + month + ' - ' + time;
			state.whenLong = day + ' ' + d[0] + '·' + time;
		}

		/* Step 4 */
		function fillProfile() {
			var name = state.service.getAttribute('data-name') + (state.tier ? ' · ' + state.tier.getAttribute('data-name') : '');
			var recap = $(form, '[data-fill="recap"]');
			recap.innerHTML = '';
			[name].concat(state.whenLong.split('·')).forEach(function (part, i) {
				if (i) { recap.appendChild(Object.assign(document.createElement('i'), { textContent: '·' })); }
				recap.appendChild(document.createTextNode(part));
			});

			var lines = $(form, '[data-fill="lines"]');
			lines.innerHTML = '';
			function line(label, value, cls) {
				var row = document.createElement('div');
				row.className = 'pb-pay-line' + (cls ? ' ' + cls : '');
				row.appendChild(Object.assign(document.createElement('span'), { textContent: label }));
				row.appendChild(Object.assign(document.createElement('span'), { textContent: value }));
				lines.appendChild(row);
			}
			if (state.tier) {
				line(name, money(state.tier.getAttribute('data-price')));
				checkedExtras().forEach(function (c) { line('+ ' + c.getAttribute('data-name'), money(c.getAttribute('data-price'))); });
			} else {
				line(name, money(total()));
			}
			line('Totale', money(total()), 'is-total');

			var dep = deposit();
			var depMode = $(form, '.pb-mode[data-mode="deposit"]');
			$(form, '[data-amount="deposit"]').textContent = money(dep);
			$(form, '[data-amount="full"]').textContent = money(total());
			if (dep >= total()) {
				depMode.hidden = true;
				$(form, 'input[name="mode"][value="full"]').checked = true;
			} else {
				depMode.hidden = false;
				if (!$(form, 'input[name="mode"]:checked')) { $(form, 'input[name="mode"][value="deposit"]').checked = true; }
			}
		}

		function profileValid(mark) {
			var ok = true;
			$$(form, '.pb-fields input').forEach(function (f) {
				var v = f.value.trim();
				var bad = !v ||
					(f.type === 'email' && !/^\S+@\S+\.\S+$/.test(v)) ||
					(f.type === 'tel' && v.replace(/\D/g, '').length < 6) ||
					(f.type === 'password' && f.value.length < 8);
				if (bad) { ok = false; }
				if (mark) { f.classList.toggle('is-invalid', bad); }
			});
			var acc = $(form, 'input[name="accept_policy"]');
			if (!acc.checked) { ok = false; }
			if (mark) { acc.closest('.pb-check').classList.toggle('is-invalid', !acc.checked); }
			return ok;
		}

		function refresh() {
			save();
			var t2 = $(form, '[data-step="2"] [data-next]');
			t2.disabled = !state.tier;
			fill('tier-total', state.tier ? state.tier.getAttribute('data-name') + ' · ' + money(total()) : '');
			fill('service-tier', state.service ? state.service.getAttribute('data-name') + (state.tier ? ' · ' + state.tier.getAttribute('data-name') : '') : '');
			$(form, '[data-step="3"] [data-next]').disabled = !input.start.value;
			fill('when-short', state.whenShort);
			$(form, '.pb-submit').disabled = !profileValid(false);
		}

		function save() {
			try {
				sessionStorage.setItem(STORE, JSON.stringify({
					t: Date.now(),
					step: current,
					product: input.product.value,
					tier: input.tier.value,
					extras: checkedExtras().map(function (c) { return c.value; }),
					start: input.start.value,
					fields: ['first_name', 'last_name', 'phone', 'email'].reduce(function (o, n) { var f = $(form, '[name="' + n + '"]'); if (f) { o[n] = f.value; } return o; }, {})
				}));
			} catch (e) { /* storage unavailable: the customer just starts over */ }
		}

		function restore(data) {
			var btn = data.product && service(data.product);
			if (!btn) { return false; }
			state.service = btn;
			input.product.value = data.product;
			fill('service', btn.getAttribute('data-name'));
			var intro2 = $(form, '[data-fill="excerpt"]');
			if (intro2) { intro2.textContent = btn.getAttribute('data-excerpt') || ''; intro2.hidden = !intro2.textContent; }
			$$(form, '.pb-tiers').forEach(function (g) { g.hidden = g.getAttribute('data-for') !== data.product; });
			var tier = data.tier && $(form, '.pb-tiers[data-for="' + data.product + '"] .pb-tier[data-key="' + data.tier + '"]');
			selectTier(tier || null);
			(data.extras || []).forEach(function (k) {
				var c = $(form, '.pb-extra input[value="' + k + '"]:not(:disabled)');
				if (c) { c.checked = true; }
			});
			Object.keys(data.fields || {}).forEach(function (n) { var f = $(form, '[name="' + n + '"]'); if (f && !f.value) { f.value = data.fields[n]; } });
			if (hasTiers() && !tier) { go(2, { instant: true }); return true; }
			if (data.start) {
				input.start.value = data.start;
				setWhen(data.start);
				ensurePicker();
			}
			var target = data.start ? 4 : 3;
			if (data.step && data.step >= 2 && data.step < target) { target = data.step; }
			go(target, { instant: true });
			return true;
		}

		/* Events */
		form.addEventListener('click', function (e) {
			var s = e.target.closest('.pb-service');
			if (s) { chooseService(s); return; }
			var t = e.target.closest('.pb-tier');
			if (t) {
				selectTier(t);
				// Scroll down to the extras of this sub-package, or to "Continua" when it has none.
				var target = $(form, '.pb-extras:not([hidden])') || $(form, '[data-step="2"] .pb-continue');
				setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
				return;
			}
			var nx = e.target.closest('[data-next]');
			if (nx && !nx.disabled) { go(+nx.getAttribute('data-next')); return; }
			var bk = e.target.closest('[data-go]');
			if (bk) {
				var to = bk.getAttribute('data-go');
				go(to === 'back' ? (hasTiers() ? 2 : 1) : +to);
				return;
			}
			if (e.target.closest('.pb-login-link')) { save(); }
		});
		form.addEventListener('change', function (e) {
			if (e.target.closest('.pb-extra')) {
				if (picker) { picker.opts.priceLabel = money(total()); }
			}
			refresh();
		});
		form.addEventListener('input', refresh);
		form.addEventListener('submit', function (e) {
			if (!profileValid(true)) { e.preventDefault(); if (window.pbToast) { window.pbToast('Controlla i campi evidenziati: nome, cognome, telefono, email e accettazione delle condizioni.', 'error'); } return; }
			form.classList.add('is-loading');
		});
		$(form, '.pb-profile').addEventListener('focusout', function (e) {
			var f = e.target;
			if (f.matches('.pb-fields input') && f.value) { f.classList.toggle('is-invalid', !f.checkValidity()); }
		});

		/* Initial state: after a server error, after login, or a package passed in the URL. */
		var stored = null;
		try { stored = JSON.parse(sessionStorage.getItem(STORE) || 'null'); } catch (e) { stored = null; }
		if (stored && Date.now() - stored.t > 30 * 60 * 1000) { stored = null; }

		var restored = false;
		if (form.getAttribute('data-has-errors') === '1') {
			restored = restore({
				product: form.getAttribute('data-selected'),
				tier: form.getAttribute('data-tier'),
				extras: (form.getAttribute('data-extras') || '').split(',').filter(Boolean),
				start: form.getAttribute('data-start')
			});
			var errs = $(form, '.pb-flow-errors');
			if (errs && window.pbToast) { Array.prototype.forEach.call(errs.querySelectorAll('p'), function (p) { window.pbToast(p.innerHTML, 'error'); }); }
			var target = $(form, '[data-step="' + current + '"] .pb-flow-wrap');
			if (errs && target && current !== 1) { target.insertBefore(errs, target.firstChild); errs.style.margin = '0 0 40px'; }
		} else if (stored) {
			restored = restore(stored);
		}
		if (!restored && form.getAttribute('data-selected') !== '0' && service(form.getAttribute('data-selected'))) {
			chooseService(service(form.getAttribute('data-selected')));
			restored = true;
		}
		if (!restored) { go(1, { instant: true, noScroll: true }); }
		refresh();
	}

	document.addEventListener('DOMContentLoaded', function () {
		$$(document, 'form.pb-flow').forEach(init);
	});
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) { window.location.reload(); }
	});
})();
