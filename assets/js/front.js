/* Lipovenesc Ateliere – calendar, filters, sign-up form */
(function () {
	'use strict';

	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
	var WEEK = ['L', 'Ma', 'Mi', 'J', 'V', 'S', 'D'];

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'text') el.textContent = v;
			else if (k === 'html') el.innerHTML = v; // Only server-filtered HTML (wp_kses_post).
			else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
			else el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}

	function num(v) {
		v = String(v || '').replace(',', '.').trim();
		return v !== '' && !isNaN(v) ? parseFloat(v) : null;
	}

	function money(v) {
		var s = (Math.round(v * 100) / 100).toFixed(v % 1 ? 2 : 0).replace('.', ',');
		return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' lei';
	}

	function placesText(state, left) {
		if (state === 'closed') return 'Înscrieri închise';
		if (state === 'full' || left < 1) return 'Complet';
		return left === 1 ? 'Ultimul loc liber' : left + ' locuri libere';
	}

	function App(app) {
		var api = app.getAttribute('data-api');
		var waitlistOn = app.getAttribute('data-waitlist') === '1';
		var gdpr = app.getAttribute('data-gdpr') || '';
		var events = [];
		try { events = JSON.parse(app.getAttribute('data-calendar') || '[]'); } catch (e) { events = []; }
		var filter = { cat: '', day: '' };

		/* ---------- Filtering ---------- */

		function applyFilter() {
			var cards = app.querySelectorAll('.lat-card');
			var visibleMonths = {};
			[].forEach.call(cards, function (c) {
				var okCat = !filter.cat || (' ' + c.getAttribute('data-cats') + ' ').indexOf(' ' + filter.cat + ' ') !== -1;
				var okDay = !filter.day || c.getAttribute('data-date') === filter.day;
				c.hidden = !(okCat && okDay);
				if (!c.hidden) visibleMonths[c.getAttribute('data-date').slice(0, 7)] = true;
			});
			[].forEach.call(app.querySelectorAll('.lat-month'), function (m) {
				m.hidden = !visibleMonths[m.getAttribute('data-month')];
			});
			var note = app.querySelector('.lat-filter-note');
			if (filter.day) {
				if (!note) {
					note = h('p', { class: 'lat-filter-note' });
					app.querySelector('.lat-list').before(note);
				}
				note.innerHTML = '';
				var d = new Date(filter.day + 'T12:00:00');
				note.appendChild(document.createTextNode('Ateliere pe ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' '));
				note.appendChild(h('button', { type: 'button', class: 'lat-link', text: 'Arată toate', onclick: function () { filter.day = ''; renderCal(); applyFilter(); } }));
			} else if (note) {
				note.remove();
			}
		}

		[].forEach.call(app.querySelectorAll('.lat-chip'), function (chip) {
			chip.addEventListener('click', function () {
				filter.cat = chip.getAttribute('data-cat');
				[].forEach.call(app.querySelectorAll('.lat-chip'), function (c) { c.classList.toggle('is-on', c === chip); });
				applyFilter();
			});
		});

		/* ---------- Month calendar ---------- */

		var calEl = app.querySelector('.lat-cal');
		var byDay = {};
		events.forEach(function (e) { (byDay[e.date] = byDay[e.date] || []).push(e); });
		var first = events.length ? new Date(events[0].date + 'T12:00:00') : new Date();
		var view = { y: first.getFullYear(), m: first.getMonth() };

		function pad(n) { return (n < 10 ? '0' : '') + n; }

		function renderCal() {
			if (!calEl) return;
			calEl.innerHTML = '';
			var head = h('div', { class: 'lat-cal-head' }, [
				h('button', { type: 'button', class: 'lat-cal-nav', 'aria-label': 'Luna anterioară', text: '‹', onclick: function () { if (--view.m < 0) { view.m = 11; view.y--; } renderCal(); } }),
				h('strong', { text: MONTHS[view.m].charAt(0).toUpperCase() + MONTHS[view.m].slice(1) + ' ' + view.y }),
				h('button', { type: 'button', class: 'lat-cal-nav', 'aria-label': 'Luna următoare', text: '›', onclick: function () { if (++view.m > 11) { view.m = 0; view.y++; } renderCal(); } })
			]);
			var grid = h('div', { class: 'lat-cal-grid' });
			WEEK.forEach(function (w) { grid.appendChild(h('span', { class: 'lat-cal-wd', text: w })); });
			var start = (new Date(view.y, view.m, 1).getDay() + 6) % 7; // Monday first.
			var days = new Date(view.y, view.m + 1, 0).getDate();
			for (var i = 0; i < start; i++) grid.appendChild(h('span'));
			for (var d = 1; d <= days; d++) {
				var key = view.y + '-' + pad(view.m + 1) + '-' + pad(d);
				var evs = byDay[key];
				if (evs) {
					grid.appendChild(h('button', {
						type: 'button',
						class: 'lat-cal-day has-ev' + (filter.day === key ? ' is-on' : ''),
						title: evs.map(function (e) { return e.title; }).join(', '),
						'aria-label': d + ' ' + MONTHS[view.m] + ': ' + evs.map(function (e) { return e.title; }).join(', '),
						'data-day': key,
						text: String(d),
						onclick: function () {
							var k = this.getAttribute('data-day');
							filter.day = filter.day === k ? '' : k;
							renderCal();
							applyFilter();
							var target = app.querySelector('.lat-card:not([hidden])');
							if (filter.day && target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					}));
				} else {
					grid.appendChild(h('span', { class: 'lat-cal-day', text: String(d) }));
				}
			}
			calEl.appendChild(head);
			calEl.appendChild(grid);
		}

		/* ---------- Live places (page may be cached) ---------- */

		function updateCard(card, state, left) {
			card.setAttribute('data-state', state);
			card.setAttribute('data-left', left);
			card.className = card.className.replace(/lat-card--\w+/, 'lat-card--' + state);
			var cap = parseInt(card.getAttribute('data-capacity'), 10) || 1;
			card.querySelector('.lat-places-text').textContent = placesText(state, left);
			card.querySelector('.lat-bar span').style.width = Math.min(100, Math.round((cap - left) / cap * 100)) + '%';
			var btn = card.querySelector('.lat-open');
			var canWait = state === 'full' && waitlistOn;
			if (btn) {
				btn.hidden = !(state === 'open' || canWait);
				btn.textContent = state === 'open' ? 'Înscrie-te' : 'Listă de așteptare';
			}
		}

		fetch(api + '/places', { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) return;
				[].forEach.call(app.querySelectorAll('.lat-card'), function (card) {
					var d = data[card.getAttribute('data-id')];
					if (d) updateCard(card, d.state, d.left);
				});
			})
			.catch(function () {});

		/* ---------- Sign-up form ---------- */

		function stepper(name, label, value, min) {
			var input = h('input', { type: 'number', name: name, min: String(min), value: String(value), inputmode: 'numeric', 'aria-label': label });
			function change(delta) {
				var v = Math.max(min, (parseInt(input.value, 10) || 0) + delta);
				input.value = v;
				input.dispatchEvent(new Event('input', { bubbles: true }));
			}
			return h('div', { class: 'lat-stepper' }, [
				h('span', { class: 'lat-stepper-label', text: label }),
				h('button', { type: 'button', 'aria-label': 'Mai puțini ' + label.toLowerCase(), text: '−', onclick: function () { change(-1); } }),
				input,
				h('button', { type: 'button', 'aria-label': 'Mai mulți ' + label.toLowerCase(), text: '+', onclick: function () { change(1); } })
			]);
		}

		function field(label, input) {
			return h('label', { class: 'lat-field' }, [h('span', { text: label }), input]);
		}

		function openForm(card) {
			var slot = card.querySelector('.lat-form-slot');
			if (slot.firstChild) {
				slot.innerHTML = '';
				return;
			}
			var onlyKids = card.getAttribute('data-count') === 'children';
			var max = parseInt(card.getAttribute('data-max'), 10) || 10;
			var pa = num(card.getAttribute('data-price-adult'));
			var pc = num(card.getAttribute('data-price-child'));
			if (pc === null) pc = pa;

			var err = h('p', { class: 'lat-error', role: 'alert', hidden: true });
			var total = h('p', { class: 'lat-total' });
			var waitBox = h('p', { class: 'lat-wait', hidden: true });
			var ages = field('Vârstele copiilor', h('input', { name: 'ages', placeholder: 'Ex: 5 și 8 ani' }));
			var submit = h('button', { type: 'submit', class: 'lat-btn lat-submit', text: 'Trimite înscrierea' });

			var form = h('form', { class: 'lat-form', novalidate: true }, [
				h('div', { class: 'lat-steppers' }, [stepper('adults', 'Adulți', onlyKids ? 1 : 1, 0), stepper('children', 'Copii', onlyKids ? 1 : 0, 0)]),
				ages,
				h('div', { class: 'lat-grid' }, [
					field('Nume și prenume *', h('input', { name: 'name', required: true, autocomplete: 'name' })),
					field('Telefon *', h('input', { name: 'phone', type: 'tel', required: true, autocomplete: 'tel', inputmode: 'tel' })),
					field('Email *', h('input', { name: 'email', type: 'email', required: true, autocomplete: 'email' }))
				]),
				field('Observații (opțional)', h('textarea', { name: 'notes', rows: 2, placeholder: 'Alergii, nevoi speciale, întrebări' })),
				h('label', { class: 'lat-hp', 'aria-hidden': 'true' }, ['Website', h('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })]),
				h('label', { class: 'lat-consent' }, [h('input', { type: 'checkbox', name: 'consent', value: '1' }), h('span', { html: gdpr })]),
				total,
				waitBox,
				err,
				submit
			]);
			var el = form.elements;

			function counts() {
				return {
					adults: Math.max(0, parseInt(el.namedItem('adults').value, 10) || 0),
					children: Math.max(0, parseInt(el.namedItem('children').value, 10) || 0)
				};
			}

			function refresh() {
				var c = counts();
				ages.hidden = c.children < 1;
				var need = onlyKids ? c.children : c.adults + c.children;
				var left = parseInt(card.getAttribute('data-left'), 10) || 0;
				var parts = [];
				if (pa !== null || pc !== null) {
					var t = (pa || 0) * c.adults + (pc || 0) * c.children;
					parts.push('Total estimat: ' + (t > 0 ? money(t) : 'gratuit') + ' (plata la fața locului)');
				}
				total.textContent = parts.join('');
				total.hidden = !parts.length;
				var waiting = need > left;
				waitBox.hidden = !waiting;
				waitBox.textContent = left > 0
					? 'Mai ' + (left === 1 ? 'este un singur loc liber' : 'sunt doar ' + left + ' locuri libere') + '.' + (waitlistOn ? ' Dacă trimiteți așa, intrați pe lista de așteptare și vă anunțăm dacă se eliberează locuri.' : ' Reduceți numărul de persoane.')
					: (waitlistOn ? 'Atelierul este complet. Vă trecem pe lista de așteptare și vă anunțăm dacă se eliberează locuri.' : 'Atelierul este complet.');
				submit.textContent = waiting ? 'Intră pe lista de așteptare' : 'Trimite înscrierea';
				submit.disabled = waiting && !waitlistOn;
				form.setAttribute('data-wait', waiting ? '1' : '');
			}
			form.addEventListener('input', refresh);

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var c = counts();
				var data = {
					workshop: card.getAttribute('data-id'),
					adults: c.adults,
					children: c.children,
					ages: el.namedItem('ages').value.trim(),
					name: el.namedItem('name').value.trim(),
					phone: el.namedItem('phone').value.trim(),
					email: el.namedItem('email').value.trim(),
					notes: el.namedItem('notes').value.trim(),
					consent: el.namedItem('consent').checked ? '1' : '',
					website: el.namedItem('website').value,
					waitlist: form.getAttribute('data-wait') === '1' ? '1' : ''
				};
				function fail(msg) {
					err.textContent = msg;
					err.hidden = false;
					err.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
				if (c.adults + c.children < 1) return fail('Alegeți câte persoane vin.');
				if (c.adults + c.children > max) return fail('Pe o înscriere pot fi maximum ' + max + ' persoane. Pentru grupuri mai mari, contactați-ne.');
				if (onlyKids && c.children < 1) return fail('Atelierul este pentru copii: adăugați cel puțin un copil.');
				if (data.name.length < 3) return fail('Scrieți numele complet.');
				if (data.phone.replace(/\D/g, '').length < 9) return fail('Numărul de telefon nu pare corect.');
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) return fail('Adresa de email nu pare corectă.');
				if (!data.consent) return fail('Bifați acordul pentru prelucrarea datelor.');

				err.hidden = true;
				submit.disabled = true;
				submit.textContent = 'Se trimite…';
				fetch(api + '/register', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify(data)
				})
					.then(function (r) {
						return r.json().catch(function () { return {}; }).then(function (d) {
							if (!r.ok) {
								var ex = new Error((d && d.message) || 'A apărut o eroare. Încercați din nou.');
								ex.left = d && d.data && d.data.left;
								throw ex;
							}
							return d;
						});
					})
					.then(function (res) {
						var s = res.summary || {};
						var st = res.left > 0 ? 'open' : (card.getAttribute('data-state') === 'closed' ? 'closed' : 'full');
						updateCard(card, st, res.left || 0);
						slot.innerHTML = '';
						slot.appendChild(h('div', { class: 'lat-success' }, [
							h('strong', { text: res.status === 'waitlist' ? 'Sunteți pe lista de așteptare.' : 'Înscriere confirmată! Vă așteptăm.' }),
							h('p', { text: (s.when || '') + ' · ' + (s.people || '') + (s.total ? ' · ' + s.total : '') }),
							h('p', { class: 'lat-muted', text: 'Am trimis toate detaliile pe email.' })
						]));
						var btn = card.querySelector('.lat-open');
						if (btn) btn.hidden = true;
					})
					.catch(function (ex) {
						if (typeof ex.left === 'number') {
							updateCard(card, ex.left > 0 ? 'open' : 'full', ex.left);
							refresh();
						}
						submit.disabled = false;
						submit.textContent = form.getAttribute('data-wait') === '1' ? 'Intră pe lista de așteptare' : 'Trimite înscrierea';
						fail(ex.message);
					});
			});

			slot.appendChild(form);
			refresh();
			el.namedItem('name').focus({ preventScroll: true });
			form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		app.addEventListener('click', function (e) {
			var btn = e.target.closest('.lat-open');
			if (btn) openForm(btn.closest('.lat-card'));
		});

		renderCal();
		applyFilter();
	}

	function ready() {
		[].forEach.call(document.querySelectorAll('.lat-app'), App);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
	else ready();
})();
