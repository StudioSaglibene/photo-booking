/* Photo Booking – date & time picker (vanilla JS, no dependencies). */
(function () {
	'use strict';

	var cfg = window.PB || {};
	var t = cfg.i18n || {};

	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') { n.textContent = attrs[k]; }
				else if (k === 'class') { n.className = attrs[k]; }
				else { n.setAttribute(k, attrs[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c) { n.appendChild(c); } });
		return n;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function api(path, params) {
		var qs = Object.keys(params).filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
		return fetch(cfg.rest + path + '?' + qs, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); });
	}

	function formatDay(dateStr) {
		var p = dateStr.split('-');
		var d = new Date(+p[0], +p[1] - 1, +p[2]);
		var names = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
		return names[d.getDay()] + ' ' + (+p[2]) + ' ' + (t.months ? t.months[+p[1] - 1].toLowerCase() : p[1]);
	}

	function Picker(root, opts) {
		this.root = root;
		this.opts = opts || {};
		var first = (this.opts.firstMonth || '').split('-');
		var now = new Date();
		this.year = first.length === 2 ? +first[0] : now.getFullYear();
		this.month = first.length === 2 ? +first[1] - 1 : now.getMonth();
		this.selectedDate = null;
		this.selectedStart = this.opts.selected || null;
		if (this.selectedStart) {
			var sd = this.selectedStart.split(' ')[0].split('-');
			this.year = +sd[0]; this.month = +sd[1] - 1; this.selectedDate = this.selectedStart.split(' ')[0];
		}
		this.days = [];
		this.render();
		this.loadMonth();
	}

	Picker.prototype.render = function () {
		var self = this;
		this.root.innerHTML = '';
		this.prevBtn = el('button', { type: 'button', class: 'pb-cal-prev', 'aria-label': t.prev || 'Prev', text: '‹' });
		this.nextBtn = el('button', { type: 'button', class: 'pb-cal-next', 'aria-label': t.next || 'Next', text: '›' });
		this.title = el('div', { class: 'pb-cal-title' });
		this.grid = el('div', { class: 'pb-cal-grid', role: 'grid' });
		this.msg = el('p', { class: 'pb-cal-msg' });
		this.slotsBox = el('div', { class: 'pb-slots' });
		this.slotsTitle = el('p', { class: 'pb-slots-title' });
		var head = el('div', { class: 'pb-cal-head' }, [this.prevBtn, this.title, this.nextBtn]);
		var cal = el('div', { class: 'pb-cal' }, [head, this.grid, this.msg]);
		var right = el('div', { class: 'pb-slots-wrap' }, [this.slotsTitle, this.slotsBox]);
		this.root.appendChild(el('div', { class: 'pb-picker-inner' }, [cal, right]));
		this.prevBtn.addEventListener('click', function () { self.shift(-1); });
		this.nextBtn.addEventListener('click', function () { self.shift(1); });
	};

	Picker.prototype.shift = function (d) {
		this.month += d;
		if (this.month < 0) { this.month = 11; this.year--; }
		if (this.month > 11) { this.month = 0; this.year++; }
		this.loadMonth();
	};

	Picker.prototype.loadMonth = function () {
		var self = this;
		var key = this.year + '-' + pad(this.month + 1);
		this.title.textContent = (t.months ? t.months[this.month] : key) + ' ' + this.year;
		var now = new Date();
		this.prevBtn.disabled = (this.year < now.getFullYear()) || (this.year === now.getFullYear() && this.month <= now.getMonth());
		this.grid.innerHTML = '';
		this.msg.textContent = t.loading || '…';
		var req = this.req = api('/days', { product: this.opts.productId, month: key, exclude: this.opts.exclude });
		req.then(function (res) {
			if (req !== self.req) { return; }
			self.days = res.days || [];
			self.msg.textContent = self.days.length ? '' : (t.noDays || '');
			self.drawGrid();
			if (self.selectedDate && self.days.indexOf(self.selectedDate) !== -1) {
				self.loadSlots(self.selectedDate);
			} else if (!self.selectedDate) {
				self.slotsTitle.textContent = '';
				self.slotsBox.innerHTML = '';
			}
		}).catch(function () { self.msg.textContent = t.error || 'Error'; });
	};

	Picker.prototype.drawGrid = function () {
		var self = this;
		this.grid.innerHTML = '';
		(t.days || ['L', 'M', 'M', 'G', 'V', 'S', 'D']).forEach(function (d) {
			self.grid.appendChild(el('span', { class: 'pb-cal-dow', text: d }));
		});
		var first = new Date(this.year, this.month, 1);
		var offset = (first.getDay() + 6) % 7;
		var total = new Date(this.year, this.month + 1, 0).getDate();
		for (var i = 0; i < offset; i++) { this.grid.appendChild(el('span', { class: 'pb-cal-empty' })); }
		for (var d = 1; d <= total; d++) {
			var ds = this.year + '-' + pad(this.month + 1) + '-' + pad(d);
			var available = this.days.indexOf(ds) !== -1;
			var b = el('button', { type: 'button', class: 'pb-cal-day' + (available ? ' is-available' : '') + (ds === this.selectedDate ? ' is-selected' : ''), 'data-date': ds, text: String(d) });
			if (!available) { b.disabled = true; }
			b.addEventListener('click', (function (date) { return function () { self.pickDate(date); }; })(ds));
			this.grid.appendChild(b);
		}
	};

	Picker.prototype.pickDate = function (date) {
		this.selectedDate = date;
		this.selectedStart = null;
		if (this.opts.onSelect) { this.opts.onSelect(null); }
		this.drawGrid();
		this.loadSlots(date);
	};

	Picker.prototype.loadSlots = function (date) {
		var self = this;
		this.slotsTitle.textContent = formatDay(date);
		this.slotsBox.innerHTML = '';
		this.slotsBox.appendChild(el('p', { class: 'pb-cal-msg', text: t.loading || '…' }));
		var req = this.sreq = api('/slots', { product: this.opts.productId, date: date, exclude: this.opts.exclude });
		req.then(function (res) {
			if (req !== self.sreq) { return; }
			self.slotsBox.innerHTML = '';
			var slots = res.slots || [];
			if (!slots.length) { self.slotsBox.appendChild(el('p', { class: 'pb-cal-msg', text: t.noSlots || '' })); return; }
			slots.forEach(function (s) {
				var b = el('button', { type: 'button', class: 'pb-slot' + (s.start === self.selectedStart ? ' is-selected' : ''), 'data-start': s.start, text: s.time });
				b.addEventListener('click', function () {
					self.selectedStart = s.start;
					Array.prototype.forEach.call(self.slotsBox.querySelectorAll('.pb-slot'), function (x) { x.classList.remove('is-selected'); });
					b.classList.add('is-selected');
					if (self.opts.onSelect) { self.opts.onSelect(s, formatDay(date)); }
				});
				self.slotsBox.appendChild(b);
			});
		}).catch(function () { self.slotsBox.innerHTML = ''; self.slotsBox.appendChild(el('p', { class: 'pb-cal-msg', text: t.error || 'Error' })); });
	};

	window.PBPicker = {
		create: function (root, opts) { return new Picker(root, opts); },
		formatDay: formatDay
	};

	// Auto-mount pickers declared in markup (customer area: reschedule).
	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-pb-picker]'), function (root) {
			var form = root.closest('form');
			var input = form ? form.querySelector('input[name="start"]') : null;
			var submit = form ? form.querySelector('[type="submit"]') : null;
			if (submit) { submit.disabled = true; }
			new Picker(root, {
				productId: root.getAttribute('data-product'),
				exclude: root.getAttribute('data-exclude'),
				firstMonth: root.getAttribute('data-first'),
				onSelect: function (slot) {
					if (input) { input.value = slot ? slot.start : ''; }
					if (submit) { submit.disabled = !slot; }
				}
			});
		});
	});
})();
