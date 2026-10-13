/* Cookie consent: technical always on; statistics / marketing only after an explicit choice. */
(function () {
	'use strict';
	var cfg = window.pbCookies || { name: 'pb_consent', days: 180, secure: false };
	var box = document.getElementById('pb-cookie');
	if (!box) { return; }
	var prefs = document.getElementById('pb-cookie-prefs');
	var cats = ['statistics', 'marketing'];
	var listeners = [];

	function readCookie() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + cfg.name + '=([^;]*)'));
		if (!m) { return null; }
		try {
			var v = JSON.parse(decodeURIComponent(m[1]));
			if (!v || !v.c || !v.t) { return null; }
			if (Date.now() - v.t > cfg.days * 864e5) { return null; } // consent expires after 6 months
			return v;
		} catch (e) { return null; }
	}

	function writeCookie(choices) {
		var v = { v: 1, t: Date.now(), c: { necessary: true, statistics: !!choices.statistics, marketing: !!choices.marketing } };
		document.cookie = cfg.name + '=' + encodeURIComponent(JSON.stringify(v)) + '; max-age=' + (cfg.days * 86400) + '; path=/; SameSite=Lax' + (cfg.secure ? '; Secure' : '');
		return v;
	}

	function activate(cat) {
		// Inert <template data-pb-consent> blocks printed by the server, plus any <script type="text/plain" data-pb-consent>.
		document.querySelectorAll('template[data-pb-consent="' + cat + '"]').forEach(function (t) {
			var frag = document.importNode(t.content, true);
			frag.querySelectorAll('script').forEach(function (old) {
				var s = document.createElement('script');
				Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
				s.text = old.text;
				old.parentNode.replaceChild(s, old);
			});
			t.parentNode.insertBefore(frag, t);
			t.parentNode.removeChild(t);
		});
		document.querySelectorAll('script[type="text/plain"][data-pb-consent="' + cat + '"]').forEach(function (old) {
			var s = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (a) { if (a.name !== 'type') { s.setAttribute(a.name, a.value); } });
			s.text = old.text;
			old.parentNode.replaceChild(s, old);
		});
		document.querySelectorAll('[data-pb-src][data-pb-consent="' + cat + '"]').forEach(function (el) {
			el.src = el.getAttribute('data-pb-src');
			el.removeAttribute('data-pb-src');
		});
	}

	function apply(v) {
		if (typeof window.gtag === 'function') {
			window.gtag('consent', 'update', {
				analytics_storage: v.c.statistics ? 'granted' : 'denied',
				ad_storage: v.c.marketing ? 'granted' : 'denied',
				ad_user_data: v.c.marketing ? 'granted' : 'denied',
				ad_personalization: v.c.marketing ? 'granted' : 'denied'
			});
		}
		cats.forEach(function (c) { if (v.c[c]) { activate(c); } });
		listeners.forEach(function (fn) { try { fn(v.c); } catch (e) {} });
		document.dispatchEvent(new CustomEvent('pb:consent', { detail: v.c }));
	}

	function show(openPrefs) {
		var v = readCookie();
		prefs.querySelectorAll('input[name]').forEach(function (i) { i.checked = !!(v && v.c[i.name]); });
		box.hidden = false;
		togglePrefs(!!openPrefs);
		requestAnimationFrame(function () { box.classList.add('is-visible'); });
		var first = box.querySelector('[data-pb-cookie="accept"]');
		if (first) { first.focus({ preventScroll: true }); }
	}

	function hide() {
		box.classList.remove('is-visible');
		setTimeout(function () { box.hidden = true; }, 350);
	}

	function togglePrefs(open) {
		prefs.hidden = !open;
		var btn = box.querySelector('[data-pb-cookie="customize"]');
		if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		box.classList.toggle('is-prefs', open);
	}

	function choose(choices) {
		var v = writeCookie(choices);
		hide();
		apply(v);
	}

	box.addEventListener('click', function (e) {
		var b = e.target.closest('[data-pb-cookie]');
		if (!b) { return; }
		var action = b.getAttribute('data-pb-cookie');
		if (action === 'accept') { choose({ statistics: true, marketing: true }); }
		if (action === 'reject') { choose({ statistics: false, marketing: false }); }
		if (action === 'customize') { togglePrefs(prefs.hidden); }
		if (action === 'save') {
			var c = {};
			prefs.querySelectorAll('input[name]').forEach(function (i) { c[i.name] = i.checked; });
			choose(c);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !box.hidden) { choose({ statistics: false, marketing: false }); }
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a.pb-cookie-open, a[href$="#cookie-preferences"]');
		if (a) { e.preventDefault(); show(true); }
	});

	window.pbConsent = {
		get: function () { var v = readCookie(); return v ? v.c : null; },
		open: function () { show(true); },
		on: function (fn) { listeners.push(fn); var v = readCookie(); if (v) { fn(v.c); } }
	};

	var current = readCookie();
	if (current) {
		apply(current);
	} else {
		setTimeout(function () { show(false); }, 600);
	}
})();
