/* Photo Booking – admin helpers: customer search, free-slot finder. */
(function () {
	'use strict';

	var A = window.PBAdmin || {};

	function get(path) {
		return fetch(A.rest + path, { credentials: 'same-origin', headers: { 'X-WP-Nonce': A.nonce } })
			.then(function (r) { return r.json(); });
	}

	function debounce(fn, ms) {
		var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); };
	}

	// Customer search (new appointment, deliveries).
	function initCustomerSearch(input) {
		var scope = input.closest('form') || document;
		var list = scope.querySelector('.pb-customer-results');
		var hidden = scope.querySelector('input[name="customer_id"]');
		var selected = scope.querySelector('.pb-customer-selected');
		input.addEventListener('input', debounce(function () {
			var q = input.value.trim();
			if (hidden && hidden.value && selected && !selected.hidden) { hidden.value = ''; selected.hidden = true; }
			if (q.length < 2) { list.innerHTML = ''; return; }
			get('/admin/customers?q=' + encodeURIComponent(q)).then(function (rows) {
				list.innerHTML = '';
				(rows || []).forEach(function (c) {
					var li = document.createElement('li');
					var b = document.createElement('button');
					b.type = 'button';
					b.textContent = c.name + ' – ' + c.email + (c.phone ? ' – ' + c.phone : '');
					b.addEventListener('click', function () {
						hidden.value = c.id;
						input.value = c.name;
						list.innerHTML = '';
						if (selected) { selected.hidden = false; selected.textContent = '✓ ' + c.name + ' – ' + c.email; }
						var appt = scope.querySelector('input[name="appointment_id"]');
						if (appt) { appt.value = ''; }
					});
					li.appendChild(b);
					list.appendChild(li);
				});
				if (!rows || !rows.length) { list.innerHTML = '<li class="pb-muted">Nessun cliente trovato</li>'; }
			});
		}, 250));
	}

	// Free-slot finder.
	function initFinder(box) {
		var dateInput = box.querySelector('.pb-finder-date');
		var out = box.querySelector('.pb-finder-slots');
		var target = box.getAttribute('data-target');
		var form = box.closest('form');

		function product() {
			var sel = box.getAttribute('data-product-from');
			if (sel) { var s = form.querySelector(sel); return s ? s.value : ''; }
			return box.getAttribute('data-product');
		}

		function load() {
			if (!dateInput.value) { out.innerHTML = ''; return; }
			out.textContent = '…';
			var q = '/admin/slots?product=' + encodeURIComponent(product()) + '&date=' + encodeURIComponent(dateInput.value) + (box.getAttribute('data-exclude') ? '&exclude=' + box.getAttribute('data-exclude') : '');
			get(q).then(function (res) {
				out.innerHTML = '';
				var slots = (res && res.slots) || [];
				if (!slots.length) { out.textContent = A.i18n.noSlots; return; }
				slots.forEach(function (s) {
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'button button-small';
					b.textContent = s.time;
					b.addEventListener('click', function () {
						var val = s.start.substr(0, 16).replace(' ', 'T');
						if (target === 'propose') {
							var inputs = form.querySelectorAll('input[data-propose]');
							var empty = Array.prototype.filter.call(inputs, function (i) { return !i.value; })[0] || inputs[inputs.length - 1];
							empty.value = val;
						} else if (target === 'move') {
							form.querySelector('input[data-move]').value = val;
						} else if (target === 'new') {
							form.querySelector('input[data-new-time]').value = s.time;
						}
						b.classList.add('button-primary');
					});
					out.appendChild(b);
				});
			}).catch(function () { out.textContent = A.i18n.error; });
		}
		dateInput.addEventListener('change', load);
		var ps = box.getAttribute('data-product-from') && form.querySelector(box.getAttribute('data-product-from'));
		if (ps) { ps.addEventListener('change', load); }
		if (dateInput.value) { load(); }
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.pb-customer-search'), initCustomerSearch);
		Array.prototype.forEach.call(document.querySelectorAll('.pb-slot-finder'), initFinder);
	});
})();
