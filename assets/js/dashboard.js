/* Evenimente: panou — comutare „De la zero” / „Copie”. */
(function () {
	document.querySelectorAll('.latd-tabs2').forEach(function (tabs) {
		var box = tabs.closest('.latd-create');
		tabs.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-t]');
			if (!b) { return; }
			tabs.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
			box.querySelectorAll('.latd-form').forEach(function (f) { f.hidden = f.getAttribute('data-p') !== b.getAttribute('data-t'); });
		});
	});
	if (location.hash === '#creeaza') {
		var t = document.querySelector('#creeaza input[name="title"]');
		if (t) { setTimeout(function () { t.focus(); }, 150); }
	}
})();
