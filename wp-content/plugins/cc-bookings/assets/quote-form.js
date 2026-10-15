/**
 * Multi-step quote form ({cc_quote_form}) and the private quote page (Accept & pay, questions).
 * Prices only come from the REST price endpoint; the server re-prices on submit and again at payment.
 */
(() => {
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	const api = async (base, path, { method = 'POST', body = null, form = null } = {}) => {
		const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
		if (form) {
			opts.body = form;
		} else if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		try {
			const res = await fetch(base + path, opts);
			const data = await res.json();
			return { http: res.status, ...data };
		} catch (e) {
			return { ok: false, http: 0, message: 'We couldn\'t connect. Please check your connection and try again.' };
		}
	};

	const scrollIntoViewIfNeeded = (el) => {
		const top = el.getBoundingClientRect().top;
		if (top < 80 || top > window.innerHeight * 0.6) {
			window.scrollTo({ top: window.scrollY + top - 120, behavior: reduceMotion ? 'auto' : 'smooth' });
		}
	};

	/* Quote form ----------------------------------------------------------- */

	document.querySelectorAll('[data-cc-quote]').forEach((root) => {
		let config;
		try {
			config = JSON.parse(root.querySelector('.cc-quote__config').textContent);
		} catch (e) {
			return;
		}
		const form = root.querySelector('form');
		form.hidden = false;
		root.id = root.id || 'quote';

		const STEPS = ['service', 'details', 'extras', 'date', 'contact'];
		const DETAIL_FIELDS = ['size', 'quantities', 'bedrooms', 'bathrooms', 'property_type', 'frequency', 'furnished', 'condition', 'changeover', 'notes', 'parking', 'pets', 'access', 'photos'];
		const services = Object.fromEntries(config.services.map((s) => [s.slug, s]));
		const state = { step: 0, service: null, date: '', slot: '', days: null, preview: null, replaces: null, started: Date.now(), turnstileId: null, previewSeq: 0, showAllDates: false };

		const q = (sel) => form.querySelector(sel);
		const stepEl = (name) => q(`[data-step="${name}"]`);
		const postcode = q('[name="postcode"]');
		const coverage = q('[data-coverage]');
		const askAnyway = q('[data-ask-anyway]');
		const summary = q('[data-summary]');
		const formError = q('[data-form-error]');
		const btnBack = q('[data-back]');
		const btnNext = q('[data-next]');
		const btnSubmit = q('[data-submit]');

		/* Step 1: service + postcode */

		q('[data-services]').innerHTML = config.services.map((s) => `<label class="cc-quote__service"><input type="radio" name="service" value="${esc(s.slug)}"><span>${esc(s.name)}</span></label>`).join('');

		const district = (raw) => {
			const m = raw.toUpperCase().replace(/\s+/g, '').match(/^([A-Z]{1,2}\d[A-Z\d]?)(\d[A-Z]{2})$/);
			return m ? m[1] : '';
		};
		const isCovered = () => config.districts.includes(district(postcode.value));
		const checkPostcode = () => {
			const d = district(postcode.value);
			askAnyway.hidden = !d || isCovered();
			coverage.className = 'cc-quote__coverage' + (d ? (isCovered() ? ' is-yes' : ' is-no') : '');
			coverage.textContent = !d ? '' : isCovered() ? `Great — we cover ${d}.` : `We don't usually cover ${d}, but we may still be able to help.`;
		};
		postcode.addEventListener('input', checkPostcode);

		const isBespokeRoute = () => !!(state.service && (state.service.bespoke || (!isCovered() && askAnyway.querySelector('input').checked) || (state.preview && state.preview.bespoke)));

		/* Step 2: property questions */

		const help = (f) => (f.help ? `<p class="cc-quote__help">${esc(f.help)}</p>` : '');
		const fieldError = '<p class="cc-quote__field-error" hidden></p>';
		const stepper = (name, label, value, min) => `<div class="cc-quote__stepper"><button type="button" data-step-btn="-1" aria-label="Fewer: ${esc(label)}">−</button><input type="number" name="${name}" min="${min}" max="20" value="${value}" inputmode="numeric" aria-label="${esc(label)}"><button type="button" data-step-btn="1" aria-label="More: ${esc(label)}">+</button></div>`;

		const fieldHtml = (f, s) => {
			const id = `cc-q-a-${f.name}`;
			switch (f.type) {
				case 'size':
					return `<div class="cc-quote__field" data-field="size"><label for="${id}">${esc(f.label)}</label><select id="${id}" name="a_size"><option value="">Choose…</option>${s.sizes.map((r, i) => `<option value="${i}">${esc(r.label)}${r.note && !/^from$/i.test(r.note) ? ' — ' + esc(r.note) : ''}</option>`).join('')}</select>${help(f)}${fieldError}</div>`;
				case 'quantities':
					return `<div class="cc-quote__field" data-field="quantities"><p class="cc-quote__label">${esc(f.label)}</p><div class="cc-quote__qty-list">${s.sizes.map((r, i) => `<div class="cc-quote__qty"><span class="cc-quote__qty-label">${esc(r.label)}${r.note ? `<small>${esc(r.note)}</small>` : ''}</span>${stepper(`q_${i}`, r.label, 0, 0)}</div>`).join('')}</div>${fieldError}</div>`;
				case 'select':
					return `<div class="cc-quote__field" data-field="${esc(f.name)}"><p class="cc-quote__label" id="${id}">${esc(f.label)}</p><div class="cc-quote__pills${f.name === 'condition' ? ' cc-quote__pills--stack' : ''}" role="radiogroup" aria-labelledby="${id}">${f.options.map(([v, l]) => `<label class="cc-quote__pill"><input type="radio" name="a_${esc(f.name)}" value="${esc(v)}"><span>${esc(l)}</span></label>`).join('')}</div>${help(f)}${fieldError}</div>`;
				case 'textarea':
					return `<div class="cc-quote__field" data-field="${esc(f.name)}"><label for="${id}">${esc(f.label)}</label><textarea id="${id}" name="a_${esc(f.name)}" rows="4" maxlength="2000" placeholder="${esc(f.placeholder || '')}"></textarea>${help(f)}${fieldError}</div>`;
				case 'photos':
					return `<div class="cc-quote__field" data-field="photos"><label for="${id}">${esc(f.label)}</label><input id="${id}" name="photos" type="file" accept="image/*" multiple>${help(f)}${fieldError}</div>`;
			}
			return '';
		};

		const renderService = () => {
			const s = state.service;
			q('[data-fields]').innerHTML = s.fields.map((f) => fieldHtml(f, s)).join('');
			q('[data-addons]').innerHTML = s.addons.map((a, i) => `<div class="cc-quote__addon"><label class="cc-quote__check"><input type="checkbox" name="addon_${i}" value="1"><span><strong>${esc(a.name)}</strong>${a.description ? `<small>${esc(a.description)}</small>` : ''}${a.quote ? '<small class="cc-quote__tag">Priced separately — we\'ll quote this personally</small>' : ''}</span></label>${a.per ? `<div class="cc-quote__addon-qty" hidden><span>How many?</span>${stepper(`addon_qty_${i}`, a.name, 1, 1)}</div>` : ''}</div>`).join('');
			state.days = null;
			state.date = '';
			state.slot = '';
			state.preview = null;
		};

		const selectService = (slug) => {
			const s = services[slug];
			if (!s || s === state.service) {
				return;
			}
			const keep = collect();
			state.service = s;
			renderService();
			restore({ parking: keep.parking, pets: keep.pets, access: keep.access });
		};

		form.addEventListener('change', (e) => {
			if (e.target.name === 'service') {
				selectService(e.target.value);
				clearError('service');
			}
			if (/^addon_\d+$/.test(e.target.name)) {
				const qty = e.target.closest('.cc-quote__addon').querySelector('.cc-quote__addon-qty');
				if (qty) {
					qty.hidden = !e.target.checked;
				}
			}
			if (e.target.closest('[data-step="details"], [data-step="extras"]')) {
				state.days = null;
				if (state.step >= 2) {
					updatePreview();
				}
			}
			const field = e.target.closest('[data-field]');
			if (field) {
				clearError(field.dataset.field);
			}
		});

		form.addEventListener('click', (e) => {
			const btn = e.target.closest('[data-step-btn]');
			if (!btn) {
				return;
			}
			const input = btn.parentElement.querySelector('input');
			input.value = String(Math.min(20, Math.max(Number(input.min || 0), (parseInt(input.value, 10) || 0) + Number(btn.dataset.stepBtn))));
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});

		const collect = () => {
			const s = state.service;
			const a = {};
			if (!s) {
				return a;
			}
			s.fields.forEach((f) => {
				if (f.type === 'size') {
					const v = q('[name="a_size"]')?.value;
					if (v) {
						a.size = Number(v);
					}
				} else if (f.type === 'quantities') {
					a.quantities = {};
					s.sizes.forEach((r, i) => {
						const n = parseInt(q(`[name="q_${i}"]`)?.value || '0', 10);
						if (n > 0) {
							a.quantities[i] = n;
						}
					});
				} else if (f.type === 'select') {
					const c = q(`[name="a_${f.name}"]:checked`);
					if (c) {
						a[f.name] = c.value;
					}
				} else if (f.type === 'textarea') {
					const v = q(`[name="a_${f.name}"]`)?.value.trim();
					if (v) {
						a[f.name] = v;
					}
				}
			});
			a.addons = {};
			s.addons.forEach((ad, i) => {
				if (q(`[name="addon_${i}"]`)?.checked) {
					a.addons[i] = ad.per ? Math.max(1, parseInt(q(`[name="addon_qty_${i}"]`)?.value || '1', 10)) : 1;
				}
			});
			return a;
		};

		const restore = (a) => {
			Object.entries(a || {}).forEach(([key, value]) => {
				if (value === undefined || value === null) {
					return;
				}
				if (key === 'size') {
					const sel = q('[name="a_size"]');
					if (sel) {
						sel.value = String(value);
					}
				} else if (key === 'quantities') {
					Object.entries(value).forEach(([i, n]) => {
						const input = q(`[name="q_${i}"]`);
						if (input) {
							input.value = String(n);
						}
					});
				} else if (key === 'addons') {
					Object.entries(value).forEach(([i, n]) => {
						const box = q(`[name="addon_${i}"]`);
						if (box) {
							box.checked = true;
							box.dispatchEvent(new Event('change', { bubbles: true }));
						}
						const qty = q(`[name="addon_qty_${i}"]`);
						if (qty) {
							qty.value = String(n);
						}
					});
				} else {
					const radio = q(`[name="a_${key}"][value="${CSS.escape(String(value))}"]`);
					const text = q(`textarea[name="a_${key}"]`);
					if (radio) {
						radio.checked = true;
					} else if (text) {
						text.value = String(value);
					}
				}
			});
		};

		/* Price preview (from step 3) */

		let previewTimer = 0;
		const updatePreview = () => {
			clearTimeout(previewTimer);
			previewTimer = setTimeout(async () => {
				if (!state.service) {
					return;
				}
				const seq = ++state.previewSeq;
				summary.hidden = false;
				summary.classList.add('is-loading');
				const res = await api(config.rest, 'price', { body: { service: state.service.slug, answers: collect(), date: state.date, slot: state.slot, postcode: postcode.value } });
				if (seq !== state.previewSeq) {
					return;
				}
				summary.classList.remove('is-loading');
				if (!res.ok) {
					summary.innerHTML = `<p>${esc(res.message || 'We couldn\'t work out a price just now.')}</p>`;
					return;
				}
				state.preview = res;
				renderSummary();
				updateNav();
			}, 250);
		};

		const renderSummary = () => {
			const p = state.preview;
			if (!p || STEPS[state.step] === 'service' || STEPS[state.step] === 'details') {
				summary.hidden = true;
				return;
			}
			summary.hidden = false;
			if (isBespokeRoute()) {
				summary.innerHTML = '<p class="cc-quote__summary-title">We\'ll price this one personally</p>'
					+ '<p>Send your details and we\'ll email a tailored quote — usually within the hour during opening times.</p>'
					+ (p.reasons && p.reasons.length ? `<ul class="cc-quote__reasons">${p.reasons.map((r) => `<li>${esc(r)}</li>`).join('')}</ul>` : '');
				return;
			}
			summary.innerHTML = '<p class="cc-quote__summary-title">Your price</p>'
				+ `<ul class="cc-quote__lines">${p.items.map((i) => `<li><span>${esc(i.label)}</span><span>${esc(i.amount)}</span></li>`).join('')}</ul>`
				+ `<p class="cc-quote__total"><span>Total</span><strong>${esc(p.total)}</strong></p>`
				+ (p.duration ? `<p class="cc-quote__duration">${esc(p.duration)}</p>` : '')
				+ (!state.date ? '<p class="cc-quote__small">Weekend or short-notice dates may add a small surcharge.</p>' : '');
		};

		/* Step 4: dates and arrival slots */

		const loadDays = async () => {
			const cal = q('[data-calendar]');
			cal.innerHTML = '<p class="cc-quote__lead">Checking availability…</p>';
			const res = await api(config.rest, 'slots', { body: { service: state.service.slug, answers: collect(), postcode: postcode.value } });
			if (!res.ok) {
				cal.innerHTML = `<p class="cc-quote__error">${esc(res.message || 'We couldn\'t load availability.')}</p>`;
				return;
			}
			state.days = res;
			const day = res.dates.find((d) => d.date === state.date);
			if (!day || (!res.preferred && !day.slots[state.slot])) {
				state.date = day ? state.date : '';
				state.slot = '';
			}
			renderDays();
		};

		const renderDays = () => {
			const res = state.days;
			const cal = q('[data-calendar]');
			q('[data-date-lead]').textContent = res.preferred
				? 'Choose a preferred date and time (optional) — we\'ll confirm availability with your quote.'
				: 'Greyed-out dates and times are fully booked.';
			if (!res.dates.length) {
				cal.innerHTML = `<p>We have no online availability at the moment. Please call us${config.phone ? ' on ' + esc(config.phone) : ''}.</p>`;
				return;
			}
			const limit = state.showAllDates ? res.dates.length : 21;
			const days = res.dates.slice(0, limit).map((d) => {
				const open = Object.values(d.slots).some(Boolean);
				const [dow, num, mon] = d.label.split(' ');
				return `<button type="button" class="cc-quote__day${d.weekday >= 6 ? ' is-weekend' : ''}" data-date="${esc(d.date)}" aria-pressed="${d.date === state.date}"${open ? '' : ' disabled'}><span>${esc(dow)}</span><strong>${esc(num)}</strong><span>${esc(mon)}</span></button>`;
			}).join('');
			const more = res.dates.length > limit ? '<button type="button" class="cc-quote__more" data-more-dates>Show later dates</button>' : '';
			const day = res.dates.find((d) => d.date === state.date);
			const times = day
				? `<div class="cc-quote__times" role="group" aria-label="Arrival time on ${esc(day.label)}">${res.slots.map((s) => `<button type="button" class="cc-quote__time" data-slot="${esc(s.key)}" aria-pressed="${s.key === state.slot}"${day.slots[s.key] ? '' : ' disabled'}>${esc(s.label)}${day.slots[s.key] ? '' : '<small>Fully booked</small>'}</button>`).join('')}</div>`
				: '<p class="cc-quote__small">Pick a date to see arrival times.</p>';
			cal.innerHTML = `<div class="cc-quote__days">${days}</div>${more}<p class="cc-quote__label">Arrival time</p>${times}`;
		};

		q('[data-calendar]').addEventListener('click', (e) => {
			const dayBtn = e.target.closest('[data-date]');
			const timeBtn = e.target.closest('[data-slot]');
			if (e.target.closest('[data-more-dates]')) {
				state.showAllDates = true;
				renderDays();
			} else if (dayBtn) {
				state.date = dayBtn.dataset.date;
				state.slot = '';
				renderDays();
				q('.cc-quote__times')?.querySelector('button:not([disabled])')?.focus({ preventScroll: true });
			} else if (timeBtn) {
				state.slot = timeBtn.dataset.slot;
				renderDays();
				clearError('date');
				updatePreview();
			}
		});

		/* Errors */

		const fieldBox = (name) => q(`[data-field="${name}"]`) || (name === 'service' ? q('[data-services]') : null);
		const showError = (name, message) => {
			const box = fieldBox(name);
			const el = q(`[data-error="${name}"]`) || box?.querySelector('.cc-quote__field-error');
			if (el) {
				el.textContent = message;
				el.hidden = false;
			}
			box?.classList.add('has-error');
			return !!el;
		};
		const clearError = (name) => {
			const box = fieldBox(name);
			const el = q(`[data-error="${name}"]`) || box?.querySelector('.cc-quote__field-error');
			if (el) {
				el.hidden = true;
			}
			box?.classList.remove('has-error');
		};
		const clearAll = () => {
			form.querySelectorAll('.cc-quote__field-error').forEach((el) => {
				el.hidden = true;
			});
			form.querySelectorAll('.has-error').forEach((el) => el.classList.remove('has-error'));
			formError.hidden = true;
		};

		const validate = (step) => {
			const errors = {};
			if (step === 'service') {
				if (!state.service) {
					errors.service = 'Please choose a service';
				}
				if (!district(postcode.value)) {
					errors.postcode = 'Please enter your full postcode, e.g. HU5 2AB';
				} else if (!isCovered() && !askAnyway.querySelector('input').checked) {
					errors.postcode = 'We don\'t usually cover this postcode. Tick "Ask us anyway" to continue.';
				}
			} else if (step === 'details') {
				const a = collect();
				state.service.fields.forEach((f) => {
					if (f.type === 'quantities' && !Object.keys(a.quantities || {}).length) {
						errors.quantities = 'Please add at least one item';
					} else if (f.required && f.type !== 'quantities' && f.type !== 'photos' && (a[f.name] === undefined || a[f.name] === '')) {
						errors[f.name] = f.type === 'textarea' ? 'Please tell us a little about the job' : 'Please choose an option';
					}
				});
				const files = q('[name="photos"]')?.files || [];
				if (files.length > config.maxPhotos) {
					errors.photos = `Please choose no more than ${config.maxPhotos} photos`;
				} else if (['poor', 'very_poor'].includes(a.condition) && !files.length) {
					errors.photos = 'Please add a few photos so we can see the condition';
				} else if ([...files].some((f) => f.size > 8 * 1024 * 1024)) {
					errors.photos = 'Each photo must be under 8MB';
				}
			} else if (step === 'date') {
				if (!isBespokeRoute() && (!state.date || !state.slot)) {
					errors.date = 'Please choose a date and arrival time';
				}
			} else if (step === 'contact') {
				const v = (n) => (form.elements[n]?.value || '').trim();
				if (v('name').length < 2) {
					errors.name = 'Please enter your name';
				}
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))) {
					errors.email = 'Please enter a valid email address';
				}
				if (v('phone').replace(/\D/g, '').length < 10) {
					errors.phone = 'Please enter your mobile number';
				}
				if (!v('address_1')) {
					errors.address_1 = 'Please enter the first line of the address';
				}
				if (!v('town')) {
					errors.town = 'Please enter the town';
				}
			}
			Object.entries(errors).forEach(([name, message]) => showError(name, message));
			const first = Object.keys(errors)[0];
			if (first) {
				const box = fieldBox(first);
				(box?.querySelector('input:not([type="hidden"]), select, textarea, button') || box)?.focus?.();
			}
			return !first;
		};

		/* Navigation */

		const skip = (name) => name === 'extras' && state.service && !state.service.addons.length;

		const updateNav = () => {
			const last = state.step === STEPS.length - 1;
			btnBack.hidden = state.step === 0;
			btnNext.hidden = last;
			btnSubmit.hidden = !last;
			btnSubmit.textContent = isBespokeRoute() ? 'Send my request' : 'See my price';
			form.querySelectorAll('[data-progress]').forEach((li) => {
				const i = STEPS.indexOf(li.dataset.progress);
				li.classList.toggle('is-current', i === state.step);
				li.classList.toggle('is-done', i < state.step);
				li.hidden = skip(li.dataset.progress);
			});
			const visible = STEPS.filter((s) => !skip(s));
			const bar = q('.cc-quote__progress-bar span');
			if (bar) {
				bar.style.width = `${((visible.indexOf(STEPS[state.step]) + 1) / visible.length) * 100}%`;
			}
		};

		const show = (index, focus = true) => {
			state.step = index;
			const name = STEPS[index];
			form.querySelectorAll('[data-step]').forEach((el) => {
				el.hidden = el.dataset.step !== name;
			});
			formError.hidden = true;
			if (name === 'extras' || name === 'date' || name === 'contact') {
				updatePreview();
			}
			if (name === 'date' && !state.days) {
				loadDays();
			}
			if (name === 'contact') {
				renderTurnstile();
			}
			renderSummary();
			updateNav();
			if (focus) {
				scrollIntoViewIfNeeded(form);
				stepEl(name).querySelector('.cc-quote__title')?.focus({ preventScroll: true });
			}
		};

		const next = () => {
			clearAll();
			if (!validate(STEPS[state.step])) {
				return;
			}
			let i = state.step + 1;
			while (i < STEPS.length - 1 && skip(STEPS[i])) {
				i++;
			}
			show(i);
		};

		const back = () => {
			let i = state.step - 1;
			while (i > 0 && skip(STEPS[i])) {
				i--;
			}
			show(Math.max(0, i));
		};

		btnNext.addEventListener('click', next);
		btnBack.addEventListener('click', back);
		form.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox' && state.step < STEPS.length - 1) {
				e.preventDefault();
				next();
			}
		});

		/* Turnstile */

		const renderTurnstile = () => {
			if (!config.turnstile || state.turnstileId !== null) {
				return;
			}
			if (!window.turnstile) {
				setTimeout(renderTurnstile, 400);
				return;
			}
			state.turnstileId = window.turnstile.render(q('[data-turnstile]'), { sitekey: config.turnstile, size: 'flexible' });
		};

		/* Submit */

		const errorStep = (field) => {
			if (field === 'service' || field === 'postcode') {
				return 'service';
			}
			if (field === 'date') {
				return 'date';
			}
			return DETAIL_FIELDS.includes(field) ? 'details' : 'contact';
		};

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			if (state.step !== STEPS.length - 1) {
				next();
				return;
			}
			clearAll();
			if (!validate('contact')) {
				return;
			}
			const fd = new FormData();
			fd.append('service', state.service.slug);
			fd.append('postcode', postcode.value);
			if (askAnyway.querySelector('input').checked) {
				fd.append('ask_anyway', '1');
			}
			fd.append('answers', JSON.stringify(collect()));
			fd.append('date', state.date);
			fd.append('slot', state.slot);
			['name', 'email', 'phone', 'address_1', 'address_2', 'town', 'access_notes', 'website'].forEach((n) => fd.append(n, form.elements[n]?.value || ''));
			if (form.elements.marketing?.checked) {
				fd.append('marketing', '1');
			}
			fd.append('elapsed', String(Date.now() - state.started));
			[...(q('[name="photos"]')?.files || [])].forEach((file) => fd.append('photos[]', file));
			if (config.turnstile && window.turnstile && state.turnstileId !== null) {
				fd.append('cf-turnstile-response', window.turnstile.getResponse(state.turnstileId) || '');
			}
			if (state.replaces) {
				fd.append('replaces_ref', state.replaces.ref);
				fd.append('replaces_token', state.replaces.token);
			}

			btnSubmit.disabled = true;
			btnBack.disabled = true;
			const label = btnSubmit.textContent;
			btnSubmit.textContent = 'Sending…';
			const res = await api(config.rest, 'quotes', { form: fd });
			btnSubmit.disabled = false;
			btnBack.disabled = false;
			btnSubmit.textContent = label;

			if (res.ok && res.status === 'quoted' && res.url) {
				btnSubmit.disabled = true;
				btnSubmit.textContent = 'Opening your quote…';
				window.location.href = res.url;
				return;
			}
			if (res.ok) {
				form.querySelectorAll('[data-step]').forEach((el) => {
					el.hidden = el.dataset.step !== 'done';
				});
				q('[data-done-message]').textContent = res.message || '';
				summary.hidden = true;
				q('.cc-quote__nav').hidden = true;
				q('.cc-quote__progress').hidden = true;
				q('.cc-quote__progress-bar').hidden = true;
				scrollIntoViewIfNeeded(form);
				stepEl('done').querySelector('.cc-quote__title')?.focus({ preventScroll: true });
				return;
			}

			if (config.turnstile && window.turnstile && state.turnstileId !== null) {
				window.turnstile.reset(state.turnstileId);
			}
			const errors = res.errors || {};
			const fields = Object.keys(errors);
			if (fields.length) {
				const target = STEPS.indexOf(fields.map(errorStep).sort((a, b) => STEPS.indexOf(a) - STEPS.indexOf(b))[0]);
				if (target !== state.step) {
					show(target);
				}
				if (errors.date) {
					state.days = null;
					loadDays();
				}
				fields.forEach((f) => showError(f, errors[f]));
			}
			formError.textContent = res.message || 'Something went wrong. Please try again.';
			formError.hidden = false;
		});

		/* Prefill: "Change details" link (?quote=&token=) or ?service= from a service page */

		const params = new URLSearchParams(window.location.search);
		const prefill = async () => {
			if (params.get('quote') && params.get('token')) {
				const res = await api(config.rest, `quotes/${encodeURIComponent(params.get('quote'))}/prefill?token=${encodeURIComponent(params.get('token'))}`, { method: 'GET' });
				if (!res.ok || !res.quote) {
					return;
				}
				const d = res.quote;
				state.replaces = { ref: params.get('quote'), token: params.get('token') };
				const radio = q(`[name="service"][value="${CSS.escape(d.service)}"]`);
				if (radio) {
					radio.checked = true;
					selectService(d.service);
				}
				postcode.value = d.postcode || '';
				askAnyway.querySelector('input').checked = !!d.ask_anyway;
				checkPostcode();
				restore(d.answers);
				['name', 'email', 'phone', 'address_1', 'address_2', 'town', 'access_notes'].forEach((n) => {
					if (form.elements[n]) {
						form.elements[n].value = d[n] || '';
					}
				});
				form.elements.marketing.checked = !!d.marketing;
				state.date = d.date || '';
				state.slot = d.slot || '';
				root.scrollIntoView({ behavior: 'auto', block: 'start' });
			} else if (params.get('service') && services[params.get('service')]) {
				const radio = q(`[name="service"][value="${CSS.escape(params.get('service'))}"]`);
				radio.checked = true;
				selectService(params.get('service'));
			}
		};

		show(0, false);
		prefill();
	});

	/* Private quote page ---------------------------------------------------- */

	const base = (window.ccBookings && window.ccBookings.rest) || '/wp-json/cc/v1/';

	document.querySelectorAll('[data-cc-accept]').forEach((form) => {
		const button = form.querySelector('[data-pay]');
		const label = button.textContent;
		const error = form.querySelector('[data-error]');
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const terms = form.elements.terms;
			const early = form.elements.early_start;
			if (!terms.checked || (early && !early.checked)) {
				error.textContent = early ? 'Please tick both boxes above to continue.' : 'Please tick the box above to continue.';
				error.hidden = false;
				return;
			}
			error.hidden = true;
			button.disabled = true;
			button.textContent = 'Please wait…';
			const res = await api(base, `quotes/${encodeURIComponent(form.dataset.ref)}/accept`, { body: { token: form.dataset.token, terms: true, early_start: !!(early && early.checked) } });
			if (res.ok && res.url) {
				window.location.href = res.url;
				return;
			}
			button.disabled = false;
			button.textContent = label;
			if (res.ok && res.mode === 'manual') {
				form.innerHTML = `<p class="cc-private__total-label">Thank you</p><p>${esc(res.message)}</p>`;
				return;
			}
			if (res.reload) {
				window.location.reload();
				return;
			}
			error.textContent = res.message || 'Something went wrong. Please try again.';
			error.hidden = false;
		});
	});

	document.querySelectorAll('[data-cc-question]').forEach((form) => {
		const error = form.querySelector('[data-error]');
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const button = form.querySelector('button');
			button.disabled = true;
			const res = await api(base, `quotes/${encodeURIComponent(form.dataset.ref)}/question`, { body: { token: form.dataset.token, message: form.elements.message.value } });
			button.disabled = false;
			if (res.ok) {
				form.innerHTML = `<p>${esc(res.message)}</p>`;
				return;
			}
			error.textContent = res.message || 'Something went wrong.';
			error.hidden = false;
		});
	});

	// Waiting for Stripe's webhook after the payment redirect
	document.querySelectorAll('[data-cc-refresh]').forEach((el) => {
		const key = 'cc-refresh:' + window.location.pathname;
		const count = parseInt(sessionStorage.getItem(key) || '0', 10);
		if (count < 24) {
			sessionStorage.setItem(key, String(count + 1));
			setTimeout(() => window.location.reload(), Number(el.dataset.ccRefresh || 5) * 1000);
		}
	});
})();
