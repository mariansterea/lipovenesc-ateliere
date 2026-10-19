/* Lipovenesc Evenimente – calendar, filters, step-by-step sign-up, payment hand-off */
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
		if (state === 'cancelled') return 'Anulat';
		if (state === 'past') return 'A avut loc';
		if (state === 'closed') return 'Înscrieri închise';
		if (state === 'full' || left < 1) return 'Complet';
		if (left <= 3) return left === 1 ? 'Ultimul loc' : 'Ultimele ' + left + ' locuri';
		return left + ' locuri libere';
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function App(app) {
		var api = app.getAttribute('data-api');
		var waitlistOn = app.getAttribute('data-waitlist') === '1';
		var gdpr = app.getAttribute('data-gdpr') || '';
		var events = [];
		try { events = JSON.parse(app.getAttribute('data-calendar') || '[]'); } catch (e) { events = []; }
		var filter = { cat: '', day: '' };

		[].forEach.call(app.querySelectorAll('.lat-card'), function (c) {
			if (!c.querySelector('.lat-img')) c.classList.add('no-img');
		});

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
			var list = app.querySelector('.lat-list');
			if (!list) return;
			var note = app.querySelector('.lat-filter-note');
			if (filter.day) {
				if (!note) {
					note = h('p', { class: 'lat-filter-note' });
					list.before(note);
				}
				note.innerHTML = '';
				var d = new Date(filter.day + 'T12:00:00');
				note.appendChild(document.createTextNode('Evenimente pe ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' '));
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
			var start = (new Date(view.y, view.m, 1).getDay() + 6) % 7;
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

		/* ---------- Live places (the page may be cached) ---------- */

		function updateCard(card, state, left) {
			card.setAttribute('data-state', state);
			card.setAttribute('data-left', left);
			card.className = card.className.replace(/lat-card--(open|full|closed|past|cancelled)/, 'lat-card--' + state);
			var cap = parseInt(card.getAttribute('data-capacity'), 10) || 1;
			var txt = card.querySelector('.lat-places-text');
			if (txt) txt.textContent = placesText(state, left);
			var st = card.querySelector('.lat-state');
			if (st) st.className = 'lat-state lat-state--' + state;
			var bar = card.querySelector('.lat-bar span');
			if (bar) bar.style.width = Math.min(100, Math.round((cap - left) / cap * 100)) + '%';
			var note = card.querySelector('.lat-places-note');
			if (note) note.textContent = (cap - left) + ' din ' + cap + ' locuri ocupate';
			var btn = card.querySelector('.lat-open');
			var canWait = state === 'full' && waitlistOn;
			if (btn) {
				btn.hidden = !(state === 'open' || canWait);
				btn.textContent = state === 'open' ? (card.getAttribute('data-cta') || 'Rezervă') : 'Listă de așteptare';
			}
		}

		var ids = [].map.call(app.querySelectorAll('.lat-card'), function (c) { return c.getAttribute('data-id'); });
		if (ids.length) {
			fetch(api + '/places?ids=' + ids.join(','), { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data) return;
					[].forEach.call(app.querySelectorAll('.lat-card'), function (card) {
						var d = data[card.getAttribute('data-id')];
						if (d) updateCard(card, d.state, d.left);
					});
				})
				.catch(function () {});
		}

		/* ---------- Sign-up form, three steps ---------- */

		function stepper(name, label, hint, value, min) {
			var input = h('input', { type: 'number', name: name, min: String(min), value: String(value), inputmode: 'numeric', 'aria-label': label });
			function change(delta) {
				var v = Math.max(min, (parseInt(input.value, 10) || 0) + delta);
				input.value = v;
				input.dispatchEvent(new Event('input', { bubbles: true }));
			}
			return h('div', { class: 'lat-stepper' }, [
				h('span', { class: 'lat-stepper-label' }, [label, hint ? h('small', { text: hint }) : null]),
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
			var buy = card.getAttribute('data-mode') === 'buy';
			var cta = card.getAttribute('data-cta') || (buy ? 'Cumpără bilet' : 'Rezervă');
			var pa = num(card.getAttribute('data-price-adult'));
			var pc = num(card.getAttribute('data-price-child'));
			if (pc === null) pc = pa;
			var paid = (pa !== null && pa > 0) || (pc !== null && pc > 0);

			var err = h('p', { class: 'lat-error', role: 'alert', hidden: true });
			var total = h('p', { class: 'lat-total' });
			var waitBox = h('p', { class: 'lat-wait', hidden: true });
			var summary = h('div', { class: 'lat-summary' });
			var ages = field('Vârstele copiilor', h('input', { name: 'ages', placeholder: 'Ex: 5 și 8 ani' }));
			var submit = h('button', { type: 'submit', class: 'lat-btn lat-submit', text: cta });

			var stepsBar = h('ol', { class: 'lat-steps' }, [
				h('li', { class: 'is-on' }, ['1 ', h('span', { text: 'Participanți' })]),
				h('li', {}, ['2 ', h('span', { text: 'Date de contact' })]),
				h('li', {}, ['3 ', h('span', { text: buy ? 'Plată' : 'Confirmare' })])
			]);

			var step1 = h('div', { class: 'lat-step' }, [
				h('h5', { class: 'lat-step-title', text: 'Câte persoane vin?' }),
				h('div', { class: 'lat-steppers' }, [
					stepper('adults', 'Adulți', pa !== null && paid ? (pa > 0 ? money(pa) + ' / adult' : 'gratuit') : '', onlyKids ? 1 : 1, 0),
					stepper('children', 'Copii', pc !== null && paid ? (pc > 0 ? money(pc) + ' / copil' : 'gratuit') : '', onlyKids ? 1 : 0, 0)
				]),
				ages,
				total,
				waitBox,
				h('div', { class: 'lat-nav' }, [h('span'), h('button', { type: 'button', class: 'lat-btn', text: 'Continuă', onclick: function () { if (validPeople()) go(2); } })])
			]);

			var step2 = h('div', { class: 'lat-step', hidden: true }, [
				h('h5', { class: 'lat-step-title', text: 'Pe cine așteptăm?' }),
				h('div', { class: 'lat-grid' }, [
					field('Nume și prenume *', h('input', { name: 'name', required: true, autocomplete: 'name' })),
					field('Telefon *', h('input', { name: 'phone', type: 'tel', required: true, autocomplete: 'tel', inputmode: 'tel' })),
					field('Email *', h('input', { name: 'email', type: 'email', required: true, autocomplete: 'email' }))
				]),
				field('Observații (opțional)', h('textarea', { name: 'notes', rows: 2, placeholder: 'Alergii, nevoi speciale, întrebări' })),
				h('label', { class: 'lat-hp', 'aria-hidden': 'true' }, ['Website', h('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })]),
				h('div', { class: 'lat-nav' }, [
					h('button', { type: 'button', class: 'lat-btn lat-btn--ghost', text: 'Înapoi', onclick: function () { go(1); } }),
					h('button', { type: 'button', class: 'lat-btn', text: 'Continuă', onclick: function () { if (validContact()) go(3); } })
				])
			]);

			var step3 = h('div', { class: 'lat-step', hidden: true }, [
				h('h5', { class: 'lat-step-title', text: buy ? 'Verificați și plătiți' : 'Verificați și confirmați' }),
				summary,
				h('label', { class: 'lat-consent' }, [h('input', { type: 'checkbox', name: 'consent', value: '1' }), h('span', { html: gdpr })]),
				h('div', { class: 'lat-nav' }, [
					h('button', { type: 'button', class: 'lat-btn lat-btn--ghost', text: 'Înapoi', onclick: function () { go(2); } }),
					submit
				]),
				buy ? h('p', { class: 'lat-secure', text: 'Plata se face securizat pe pagina următoare (card bancar). Locurile sunt ținute până finalizați plata.' }) : h('p', { class: 'lat-secure', text: paid ? 'Plata se face la fermă, în ziua evenimentului.' : 'Participarea este gratuită; vă rugăm să anulați dacă nu mai ajungeți.' })
			]);

			var form = h('form', { class: 'lat-form', novalidate: true }, [stepsBar, step1, step2, step3, err]);
			var el = form.elements;
			var steps = [step1, step2, step3];

			function go(n) {
				err.hidden = true;
				steps.forEach(function (s, i) { s.hidden = i !== n - 1; });
				[].forEach.call(stepsBar.children, function (li, i) {
					li.classList.toggle('is-on', i === n - 1);
					li.classList.toggle('is-done', i < n - 1);
				});
				if (n === 3) renderSummary();
				form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				var focus = steps[n - 1].querySelector('input:not([type=hidden]):not([type=checkbox])');
				if (focus && n === 2) focus.focus({ preventScroll: true });
			}

			function counts() {
				return {
					adults: Math.max(0, parseInt(el.namedItem('adults').value, 10) || 0),
					children: Math.max(0, parseInt(el.namedItem('children').value, 10) || 0)
				};
			}

			function fail(msg) {
				err.textContent = msg;
				err.hidden = false;
				err.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}

			function waiting() {
				var c = counts();
				var need = onlyKids ? c.children : c.adults + c.children;
				var left = parseInt(card.getAttribute('data-left'), 10) || 0;
				return need > left;
			}

			function amount() {
				var c = counts();
				return (pa || 0) * c.adults + (pc || 0) * c.children;
			}

			function refresh() {
				var c = counts();
				ages.hidden = c.children < 1;
				var left = parseInt(card.getAttribute('data-left'), 10) || 0;
				if (paid) {
					var t = amount();
					total.innerHTML = '';
					total.appendChild(document.createTextNode('Total: ' + (t > 0 ? money(t) : 'gratuit')));
					total.appendChild(h('small', { text: buy ? 'plată online' : 'plata la fermă' }));
					total.hidden = false;
				} else {
					total.hidden = true;
				}
				var w = waiting();
				waitBox.hidden = !w;
				waitBox.textContent = left > 0
					? 'Mai ' + (left === 1 ? 'este un singur loc liber' : 'sunt doar ' + left + ' locuri libere') + '.' + (waitlistOn ? ' Dacă trimiteți așa, intrați pe lista de așteptare și vă anunțăm dacă se eliberează locuri.' : ' Reduceți numărul de persoane.')
					: (waitlistOn ? 'Evenimentul este complet. Vă trecem pe lista de așteptare și vă anunțăm dacă se eliberează locuri.' : 'Evenimentul este complet.');
				submit.textContent = w ? 'Intră pe lista de așteptare' : (buy && amount() > 0 ? 'Mergi la plată' : cta);
				submit.disabled = w && !waitlistOn;
				form.setAttribute('data-wait', w ? '1' : '');
			}
			form.addEventListener('input', refresh);

			function validPeople() {
				var c = counts();
				if (c.adults + c.children < 1) return fail('Alegeți câte persoane vin.'), false;
				if (c.adults + c.children > max) return fail('Pe o înscriere pot fi maximum ' + max + ' persoane. Pentru grupuri mai mari, sunați-ne.'), false;
				if (onlyKids && c.children < 1) return fail('Evenimentul este pentru copii: adăugați cel puțin un copil.'), false;
				if (waiting() && !waitlistOn) return fail('Nu mai sunt suficiente locuri.'), false;
				return true;
			}

			function validContact() {
				var name = el.namedItem('name').value.trim();
				var phone = el.namedItem('phone').value.trim();
				var email = el.namedItem('email').value.trim();
				if (name.length < 3) return fail('Scrieți numele complet.'), false;
				if (phone.replace(/\D/g, '').length < 9) return fail('Numărul de telefon nu pare corect.'), false;
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return fail('Adresa de email nu pare corectă.'), false;
				return true;
			}

			function renderSummary() {
				var c = counts();
				var rows = [
					['Eveniment', card.getAttribute('data-title')],
					['Când', (card.querySelector('.lat-when') || {}).textContent || ''],
					['Participanți', c.adults + (c.adults === 1 ? ' adult' : ' adulți') + (c.children ? ', ' + c.children + (c.children === 1 ? ' copil' : ' copii') : '')],
					['Nume', el.namedItem('name').value.trim()],
					['Contact', el.namedItem('phone').value.trim() + ' · ' + el.namedItem('email').value.trim()]
				];
				if (paid) rows.push(['Total', (amount() > 0 ? money(amount()) : 'gratuit') + (buy ? ' (online)' : ' (la fermă)')]);
				if (waiting()) rows.push(['Status', 'listă de așteptare']);
				summary.innerHTML = '';
				var dl = h('dl');
				rows.forEach(function (r) { dl.appendChild(h('dt', { text: r[0] })); dl.appendChild(h('dd', { text: r[1] })); });
				summary.appendChild(dl);
			}

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!validPeople() || !validContact()) return;
				if (!el.namedItem('consent').checked) return fail('Bifați acordul pentru prelucrarea datelor.');
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
					consent: '1',
					website: el.namedItem('website').value,
					waitlist: form.getAttribute('data-wait') === '1' ? '1' : ''
				};
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
						if (res.pay_url) {
							submit.textContent = 'Vă ducem la plată…';
							window.location.href = res.pay_url;
							return;
						}
						var s = res.summary || {};
						var st = res.left > 0 ? 'open' : (card.getAttribute('data-state') === 'closed' ? 'closed' : 'full');
						updateCard(card, st, res.left || 0);
						slot.innerHTML = '';
						var box = h('div', { class: 'lat-success' }, [
							h('strong', { text: res.status === 'waitlist' ? 'Sunteți pe lista de așteptare.' : 'Rezervare confirmată! Vă așteptăm.' }),
							h('p', { text: (s.when || '') + ' · ' + (s.people || '') + (s.total ? ' · ' + s.total : '') }),
							h('p', { class: 'lat-muted', text: 'Am trimis toate detaliile pe email' + (res.ticket ? ', împreună cu biletul.' : '.') })
						]);
						if (res.ticket) box.appendChild(h('a', { class: 'lat-btn', href: res.ticket, text: 'Vezi biletul' }));
						slot.appendChild(box);
						var btn = card.querySelector('.lat-open');
						if (btn) btn.hidden = true;
					})
					.catch(function (ex) {
						if (typeof ex.left === 'number') {
							updateCard(card, ex.left > 0 ? 'open' : 'full', ex.left);
							refresh();
							go(1);
						}
						submit.disabled = false;
						refresh();
						fail(ex.message);
					});
			});

			slot.appendChild(form);
			refresh();
			form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		app.addEventListener('click', function (e) {
			var btn = e.target.closest('.lat-open');
			if (btn) openForm(btn.closest('.lat-card'));
			var copy = e.target.closest('.lat-copy');
			if (copy && navigator.clipboard) {
				navigator.clipboard.writeText(copy.getAttribute('data-url')).then(function () {
					var t = copy.textContent;
					copy.textContent = 'Link copiat ✓';
					setTimeout(function () { copy.textContent = t; }, 1800);
				});
			}
		});

		renderCal();
		applyFilter();

		// Deep link: #eveniment-123 opens that card's form.
		if (location.hash && /^#eveniment-\d+$/.test(location.hash)) {
			var target = app.querySelector(location.hash);
			if (target && target.querySelector('.lat-open') && !target.querySelector('.lat-open').hidden) openForm(target);
		}
	}

	function ready() {
		[].forEach.call(document.querySelectorAll('.lat-app'), App);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
	else ready();
})();
