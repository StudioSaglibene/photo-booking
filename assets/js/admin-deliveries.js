/* Photo Booking – direct multipart upload browser → Cloudflare R2 (presigned URLs from WordPress). */
(function () {
	'use strict';

	var A = window.PBAdmin || {};
	var CONCURRENCY = 3;
	var MAX_RETRIES = 5;

	function api(path, body) {
		return fetch(A.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify(body || {})
		}).then(function (r) {
			return r.json().then(function (j) { if (!r.ok) { throw new Error(j && j.message ? j.message : 'HTTP ' + r.status); } return j; });
		});
	}

	function fmtBytes(b) {
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
		return b.toFixed(i ? 1 : 0) + ' ' + u[i];
	}

	function putPart(url, blob, onProgress, xhrs) {
		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();
			xhrs.push(xhr);
			xhr.open('PUT', url);
			xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(e.loaded); } };
			xhr.onload = function () {
				if (xhr.status >= 200 && xhr.status < 300) {
					var etag = xhr.getResponseHeader('ETag');
					if (!etag) { reject(new Error('ETag mancante: controlla la configurazione CORS del bucket (ExposeHeaders: ETag).')); return; }
					resolve(etag);
				} else { reject(new Error('HTTP ' + xhr.status)); }
			};
			xhr.onerror = function () { reject(new Error('Errore di rete (CORS o connessione).')); };
			xhr.onabort = function () { reject(new Error('abort')); };
			xhr.send(blob);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('pb-upload-form');
		if (!form) { return; }
		var wrap = form.querySelector('.pb-progress-wrap');
		var bar = form.querySelector('.pb-progress-bar span');
		var text = form.querySelector('.pb-progress-text');
		var cancelBtn = form.querySelector('.pb-upload-cancel');
		var submit = form.querySelector('[type="submit"]');
		var state = null;

		function leaveGuard(e) { e.preventDefault(); e.returnValue = A.i18n.confirmLeave; return A.i18n.confirmLeave; }

		function finish(msg, ok) {
			window.removeEventListener('beforeunload', leaveGuard);
			cancelBtn.hidden = true;
			submit.disabled = false;
			text.textContent = msg;
			wrap.classList.toggle('is-error', !ok);
			wrap.classList.toggle('is-done', !!ok);
			if (ok) { setTimeout(function () { window.location.reload(); }, 1500); }
			state = null;
		}

		cancelBtn.addEventListener('click', function () {
			if (!state) { return; }
			state.cancelled = true;
			state.xhrs.forEach(function (x) { try { x.abort(); } catch (e) {} });
			if (state.id) { api('/admin/deliveries/' + state.id + '/abort'); }
			finish('Caricamento interrotto.', false);
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var file = form.querySelector('input[type="file"]').files[0];
			var customer = form.querySelector('input[name="customer_id"]').value;
			if (!customer) { alert('Seleziona un cliente.'); return; }
			if (!file) { alert('Seleziona il file ZIP.'); return; }

			state = { cancelled: false, xhrs: [], id: null };
			var st = state;
			submit.disabled = true;
			cancelBtn.hidden = false;
			wrap.hidden = false;
			wrap.classList.remove('is-error', 'is-done');
			bar.style.width = '0%';
			text.textContent = 'Preparazione…';
			window.addEventListener('beforeunload', leaveGuard);

			var started = Date.now();
			api('/admin/deliveries', {
				customer_id: customer,
				appointment_id: form.querySelector('input[name="appointment_id"]').value,
				title: form.querySelector('input[name="title"]').value,
				filename: file.name,
				size: file.size,
				days: form.querySelector('input[name="days"]').value,
				notify: form.querySelector('input[name="notify"]').checked
			}).then(function (init) {
				st.id = init.id;
				var partSize = init.part_size, total = init.parts;
				var loaded = {}, etags = {}, urls = {}, next = 1, active = 0, done = 0, failed = false;

				function progress() {
					var sum = 0; Object.keys(loaded).forEach(function (k) { sum += loaded[k]; });
					var pct = Math.min(100, sum / file.size * 100);
					bar.style.width = pct.toFixed(1) + '%';
					var secs = (Date.now() - started) / 1000;
					var speed = sum / Math.max(secs, 1);
					var eta = speed > 0 ? Math.round((file.size - sum) / speed) : 0;
					text.textContent = pct.toFixed(1) + '% · ' + fmtBytes(sum) + ' / ' + fmtBytes(file.size) + ' · ' + fmtBytes(speed) + '/s' + (eta ? ' · ~' + Math.ceil(eta / 60) + ' min' : '');
				}

				function presign(from) {
					var nums = [];
					for (var i = from; i <= Math.min(total, from + 19); i++) { if (!urls[i]) { nums.push(i); } }
					if (!nums.length) { return Promise.resolve(); }
					return api('/admin/deliveries/' + st.id + '/parts', { parts: nums }).then(function (r) {
						Object.keys(r.urls).forEach(function (k) { urls[k] = r.urls[k]; });
					});
				}

				return new Promise(function (resolve, reject) {
					function upload(n, attempt) {
						var blob = file.slice((n - 1) * partSize, Math.min(file.size, n * partSize));
						return presign(n).then(function () {
							return putPart(urls[n], blob, function (l) { loaded[n] = l; progress(); }, st.xhrs);
						}).catch(function (err) {
							if (st.cancelled) { throw err; }
							if (attempt < MAX_RETRIES) {
								loaded[n] = 0; delete urls[n];
								return new Promise(function (r) { setTimeout(r, 1000 * Math.pow(2, attempt)); }).then(function () { return upload(n, attempt + 1); });
							}
							throw err;
						});
					}
					function pump() {
						if (failed || st.cancelled) { return; }
						if (done === total) { resolve(etags); return; }
						while (active < CONCURRENCY && next <= total) {
							(function (n) {
								active++;
								upload(n, 0).then(function (etag) {
									etags[n] = etag; loaded[n] = Math.min(partSize, file.size - (n - 1) * partSize); active--; done++; progress(); pump();
								}).catch(function (err) { failed = true; reject(err); });
							})(next++);
						}
					}
					pump();
				});
			}).then(function (etags) {
				if (st.cancelled) { return; }
				text.textContent = 'Finalizzazione…';
				var parts = Object.keys(etags).map(function (n) { return { n: +n, etag: etags[n] }; });
				return api('/admin/deliveries/' + st.id + '/complete', { parts: parts }).then(function () {
					bar.style.width = '100%';
					finish('✓ Caricamento completato.', true);
				});
			}).catch(function (err) {
				if (st.cancelled) { return; }
				if (st.id) { api('/admin/deliveries/' + st.id + '/abort'); }
				finish('Errore: ' + err.message, false);
			});
		});
	});
})();
