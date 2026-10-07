/* Photo Booking – step-by-step booking form. */
(function () {
	'use strict';

	var cfg = window.PB || {};
	var t = cfg.i18n || {};

	function money(v) {
		var n = Number(v || 0);
		return n.toLocaleString('it-IT', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }) + ' ' + (cfg.currency || '€');
	}

	function init(form) {
		var steps = Array.prototype.slice.call(form.querySelectorAll('.pb-step'));
		var progress = Array.prototype.slice.call(form.querySelectorAll('.pb-progress li'));
		var back = form.querySelector('.pb-back');
		var next = form.querySelector('.pb-next');
		var startInput = form.querySelector('input[name="start"]');
		var pickerRoot = form.querySelector('.pb-picker');
		var picker = null;
		var pickerFor = null;
		var whenLabel = '';
		var current = 1;

		form.classList.add('is-js');

		function selectedPackage() {
			var r = form.querySelector('input[name="product_id"]:checked');
			return r ? r.closest('.pb-package') : null;
		}

		function ensurePicker() {
			var pkg = selectedPackage();
			if (!pkg) { return; }
			var id = pkg.getAttribute('data-id');
			if (pickerFor === id) { return; }
			var keep = pickerFor === null && startInput.value ? startInput.value : null;
			if (!keep) { startInput.value = ''; whenLabel = ''; }
			pickerFor = id;
			picker = window.PBPicker.create(pickerRoot, {
				productId: id,
				firstMonth: pkg.getAttribute('data-first'),
				selected: keep,
				onSelect: function (slot, dayLabel) {
					startInput.value = slot ? slot.start : '';
					whenLabel = slot ? (dayLabel + ', ore ' + slot.time) : '';
					updateNav();
					if (slot) { setTimeout(function () { go(3); }, 250); }
				}
			});
			if (keep) {
				var p = keep.split(' ');
				whenLabel = window.PBPicker.formatDay(p[0]) + ', ore ' + p[1].substr(0, 5);
			}
		}

		function valid(step) {
			if (step === 1) { return !!selectedPackage(); }
			if (step === 2) { return !!startInput.value; }
			if (step === 3) {
				var ok = true;
				['first_name', 'last_name', 'email', 'phone'].forEach(function (n) {
					var f = form.querySelector('[name="' + n + '"]');
					if (!f.value.trim() || (f.type === 'email' && !/^\S+@\S+\.\S+$/.test(f.value))) { ok = false; f.classList.add('is-invalid'); }
					else { f.classList.remove('is-invalid'); }
				});
				return ok;
			}
			return true;
		}

		function fillSummary() {
			var pkg = selectedPackage();
			if (!pkg) { return; }
			var price = parseFloat(pkg.getAttribute('data-price'));
			var deposit = parseFloat(pkg.getAttribute('data-deposit'));
			form.querySelector('[data-summary="package"]').textContent = pkg.getAttribute('data-name') + ' · ' + pkg.getAttribute('data-duration') + ' min';
			form.querySelector('[data-summary="when"]').textContent = whenLabel || '—';
			form.querySelector('[data-summary="total"]').textContent = money(price);
			form.querySelector('[data-amount="deposit"]').textContent = money(deposit);
			form.querySelector('[data-amount="full"]').textContent = money(price);
			var depMode = form.querySelector('.pb-mode[data-mode="deposit"]');
			if (deposit >= price) {
				depMode.style.display = 'none';
				form.querySelector('input[name="mode"][value="full"]').checked = true;
			} else {
				depMode.style.display = '';
				if (!form.querySelector('input[name="mode"]:checked')) { form.querySelector('input[name="mode"][value="deposit"]').checked = true; }
			}
		}

		function updateNav() {
			back.style.visibility = current > 1 ? 'visible' : 'hidden';
			next.style.display = current < steps.length ? '' : 'none';
			next.disabled = !valid(current) && current !== 3;
		}

		function go(n) {
			if (n > current) {
				for (var s = current; s < n; s++) {
					if (!valid(s)) {
						var box = steps[s - 1];
						var warn = box.querySelector('.pb-step-warn') || box.appendChild(Object.assign(document.createElement('p'), { className: 'pb-step-warn' }));
						warn.textContent = t.required || '';
						n = s;
						break;
					}
				}
			}
			current = n;
			steps.forEach(function (el) {
				var i = +el.getAttribute('data-step');
				el.hidden = i !== current;
				var w = el.querySelector('.pb-step-warn'); if (w && i !== current) { w.remove(); }
			});
			progress.forEach(function (li) {
				var i = +li.getAttribute('data-step');
				li.classList.toggle('is-current', i === current);
				li.classList.toggle('is-done', i < current);
			});
			if (current === 2) { ensurePicker(); }
			if (current === 4) { fillSummary(); }
			updateNav();
			var top = form.getBoundingClientRect().top + window.pageYOffset - 80;
			if (window.pageYOffset > top) { window.scrollTo({ top: top, behavior: 'smooth' }); }
		}

		progress.forEach(function (li) {
			li.addEventListener('click', function () {
				var i = +li.getAttribute('data-step');
				if (i < current) { go(i); }
			});
		});

		form.addEventListener('change', function (e) {
			if (e.target.name === 'product_id') {
				Array.prototype.forEach.call(form.querySelectorAll('.pb-package'), function (p) { p.classList.toggle('is-selected', p.contains(e.target)); });
				if (pickerFor !== e.target.value) { startInput.value = ''; }
				updateNav();
				setTimeout(function () { go(2); }, 200);
			}
		});
		form.addEventListener('input', function () { if (current === 3) { updateNav(); } });
		back.addEventListener('click', function () { go(Math.max(1, current - 1)); });
		next.addEventListener('click', function () { go(current + 1); });

		form.addEventListener('submit', function (e) {
			for (var s = 1; s <= 3; s++) { if (!valid(s)) { e.preventDefault(); go(s); return; } }
			var acc = form.querySelector('input[name="accept_policy"]');
			if (!acc.checked) { e.preventDefault(); acc.closest('.pb-check').classList.add('is-invalid'); return; }
			form.querySelector('.pb-submit').disabled = true;
			form.classList.add('is-loading');
		});

		var sel = selectedPackage();
		if (sel) { sel.classList.add('is-selected'); }
		// Resume where the user was (e.g. after a server-side validation error).
		if (sel && startInput.value) { ensurePicker(); go(form.querySelector('.pb-errors') ? 3 : 4); }
		else if (sel) { go(2); }
		else { go(1); }
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('form.pb-booking'), init);
	});
})();
