/* Photo Booking – admin calendar (FullCalendar 6). */
(function () {
	'use strict';

	var A = window.PBAdmin || {};
	var C = window.PBCalendar || {};

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function toInput(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
	function human(d) { return d.toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }); }

	function api(method, path, body) {
		return fetch(A.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) { if (!r.ok) { throw new Error(j && j.message ? j.message : r.status); } return j; });
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var el = document.getElementById('pb-calendar');
		if (!el) { return; }
		if (!window.FullCalendar) {
			document.getElementById('pb-cal-fallback').hidden = false;
			return;
		}

		var showClosed = document.getElementById('pb-show-closed');
		var dlg = document.getElementById('pb-dialog');
		var dTitle = dlg.querySelector('.pb-dialog-title');
		var dRange = dlg.querySelector('.pb-dialog-range');
		var dMsg = dlg.querySelector('.pb-dialog-msg');
		var fStart = dlg.querySelector('[name="start"]');
		var fEnd = dlg.querySelector('[name="end"]');
		var fReason = dlg.querySelector('[name="reason"]');
		var current = null; // { mode: 'select'|'block', start, end, event }

		function buttons(list) {
			Array.prototype.forEach.call(dlg.querySelectorAll('[data-act]'), function (b) {
				b.style.display = list.indexOf(b.getAttribute('data-act')) !== -1 ? '' : 'none';
			});
		}

		function openSelect(start, end) {
			current = { mode: 'select', start: start, end: end };
			dTitle.textContent = 'Intervallo selezionato';
			dRange.textContent = '';
			fStart.value = toInput(start); fEnd.value = toInput(end); fReason.value = '';
			dMsg.textContent = '';
			buttons(['block', 'appointment']);
			dlg.showModal();
		}

		function openBlock(event) {
			current = { mode: 'block', event: event };
			dTitle.textContent = 'Blocco';
			dRange.textContent = '';
			fStart.value = toInput(event.start); fEnd.value = toInput(event.end || event.start);
			fReason.value = event.extendedProps.reason || '';
			dMsg.textContent = '';
			buttons(['save-block', 'delete-block']);
			dlg.showModal();
		}

		var calendar = new FullCalendar.Calendar(el, {
			initialView: window.innerWidth < 782 ? 'listWeek' : 'timeGridWeek',
			locale: {
				code: 'it',
				week: { dow: 1, doy: 4 },
				buttonText: { prev: '‹', next: '›', today: 'Oggi', month: 'Mese', week: 'Settimana', day: 'Giorno', list: 'Elenco' },
				allDayText: 'Giorno',
				moreLinkText: function (n) { return '+ altri ' + n; },
				noEventsText: 'Nessun appuntamento'
			},
			headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
			height: 'auto',
			nowIndicator: true,
			allDaySlot: false,
			slotDuration: '00:15:00',
			slotLabelInterval: '01:00',
			slotMinTime: C.slotMinTime || '08:00:00',
			slotMaxTime: C.slotMaxTime || '20:00:00',
			businessHours: C.businessHours || false,
			slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
			eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
			selectable: true,
			selectMirror: true,
			editable: true,
			eventDurationEditable: false,
			dayMaxEvents: true,
			events: function (info, success, failure) {
				var qs = '?start=' + encodeURIComponent(info.startStr) + '&end=' + encodeURIComponent(info.endStr) + (showClosed.checked ? '&closed=1' : '');
				fetch(A.rest + '/admin/events' + qs, { credentials: 'same-origin', headers: { 'X-WP-Nonce': A.nonce } })
					.then(function (r) { return r.json(); }).then(success).catch(failure);
			},
			eventDidMount: function (info) {
				var p = info.event.extendedProps;
				if (p.kind === 'appointment') {
					info.el.title = info.event.title + '\n' + p.statusLabel + ' · ' + p.payment + (p.balance > 0 ? ' · da saldare ' + p.balance + ' €' : '') + (p.phone ? '\n' + p.phone : '');
				}
			},
			select: function (info) { openSelect(info.start, info.end); calendar.unselect(); },
			eventClick: function (info) {
				if (info.event.extendedProps.kind === 'block') {
					info.jsEvent.preventDefault();
					openBlock(info.event);
				}
			},
			eventAllow: function (span, event) { return event.extendedProps.kind !== 'proposal'; },
			eventDrop: function (info) {
				var ev = info.event;
				if (ev.extendedProps.kind === 'block') {
					api('POST', '/admin/blocks/' + ev.extendedProps.blockId, { start: ev.start.toISOString(), end: ev.end.toISOString() })
						.catch(function (e) { alert(e.message); info.revert(); });
					return;
				}
				if (!window.confirm((A.i18n.confirmMove || '%s').replace('%s', human(ev.start)))) { info.revert(); return; }
				var notify = window.confirm(A.i18n.notifyMove);
				api('POST', '/admin/appointments/' + ev.extendedProps.appointmentId + '/move', { start: ev.start.toISOString(), notify: notify })
					.then(function () { calendar.refetchEvents(); })
					.catch(function (e) {
						if (window.confirm(e.message + '\n\nForzare comunque lo spostamento?')) {
							api('POST', '/admin/appointments/' + ev.extendedProps.appointmentId + '/move', { start: ev.start.toISOString(), notify: notify, force: true })
								.then(function () { calendar.refetchEvents(); })
								.catch(function (e2) { alert(e2.message); info.revert(); });
						} else { info.revert(); }
					});
			}
		});
		calendar.render();
		window.pbCalendar = calendar;
		showClosed.addEventListener('change', function () { calendar.refetchEvents(); });

		document.getElementById('pb-add-block').addEventListener('click', function () {
			var s = new Date(); s.setMinutes(0, 0, 0); s.setHours(s.getHours() + 1);
			var e = new Date(s.getTime() + 3600 * 1000);
			openSelect(s, e);
			buttons(['block']);
		});

		dlg.addEventListener('click', function (e) {
			var act = e.target.getAttribute && e.target.getAttribute('data-act');
			if (!act) { return; }
			var start = new Date(fStart.value), end = new Date(fEnd.value);
			if (act === 'appointment') {
				window.location.href = C.newUrl + '&start=' + encodeURIComponent(fStart.value);
				return;
			}
			if ((act === 'block' || act === 'save-block') && !(end > start)) { dMsg.textContent = 'Intervallo non valido.'; return; }
			var p;
			if (act === 'block') {
				p = api('POST', '/admin/blocks', { start: start.toISOString(), end: end.toISOString(), reason: fReason.value });
			} else if (act === 'save-block') {
				p = api('POST', '/admin/blocks/' + current.event.extendedProps.blockId, { start: start.toISOString(), end: end.toISOString(), reason: fReason.value });
			} else if (act === 'delete-block') {
				p = api('DELETE', '/admin/blocks/' + current.event.extendedProps.blockId);
			}
			if (!p) { return; }
			p.then(function (res) {
				calendar.refetchEvents();
				if (res && res.warning) { alert(res.warning); }
				dlg.close();
			}).catch(function (err) { dMsg.textContent = err.message; });
		});
	});
})();
