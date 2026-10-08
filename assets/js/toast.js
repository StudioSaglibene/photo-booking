/* Photo Booking – toast: avvisi leggeri in basso a destra. window.pbToast(testo, tipo, durata) con tipo success|error|info. */
(function () {
	'use strict';
	var host = null;
	function ensure() {
		if (!host) {
			host = document.createElement('div');
			host.className = 'pb-toasts';
			host.setAttribute('aria-live', 'polite');
			document.body.appendChild(host);
		}
		return host;
	}
	window.pbToast = function (text, type, ms) {
		if (!text) { return; }
		var t = document.createElement('div');
		t.className = 'pb-toast is-' + (type || 'info');
		var icon = document.createElement('span');
		icon.className = 'pb-toast-icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = type === 'success' ? '✓' : (type === 'error' ? '!' : 'i');
		var body = document.createElement('div');
		body.className = 'pb-toast-body';
		if (typeof text === 'string' && /<[a-z][\s\S]*>/i.test(text)) { body.innerHTML = text; } else { body.textContent = text; }
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'pb-toast-close';
		close.setAttribute('aria-label', 'Chiudi');
		close.textContent = '×';
		t.appendChild(icon); t.appendChild(body); t.appendChild(close);
		ensure().appendChild(t);
		requestAnimationFrame(function () { t.classList.add('is-in'); });
		var timer = setTimeout(hide, ms || (type === 'error' ? 9000 : 5500));
		function hide() { clearTimeout(timer); t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 350); }
		close.addEventListener('click', hide);
		return hide;
	};
	// WooCommerce notices (area privata, checkout) become toasts; errors stay visible also in the page.
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.woocommerce-message, .woocommerce-info, .woocommerce-error').forEach(function (n) {
			if (n.closest('.woocommerce-form-login-toggle, .pb-login-notices, form.checkout')) { return; }
			var type = n.classList.contains('woocommerce-error') ? 'error' : (n.classList.contains('woocommerce-message') ? 'success' : 'info');
			var items = n.matches('ul') ? Array.prototype.slice.call(n.querySelectorAll('li')) : [n];
			items.forEach(function (li) { window.pbToast(li.innerHTML.trim(), type); });
			if (type !== 'error') { n.classList.add('pb-toasted'); }
		});
	});
	// Checkout: WooCommerce injects errors via AJAX.
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error', function () {
			var box = document.querySelector('form.checkout .woocommerce-error');
			if (box) { Array.prototype.forEach.call(box.querySelectorAll('li'), function (li) { window.pbToast(li.innerHTML.trim(), 'error'); }); }
		});
	}
})();
