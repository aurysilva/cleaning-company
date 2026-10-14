/**
 * Front-end motion: scroll reveals, counters, header state, hero parallax, the mobile CTA bar,
 * back-to-top button and card carousels.
 * Everything is progressive: without this script all content is visible and static.
 */
(() => {
	const root = document.documentElement;
	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* Header state + mobile CTA ------------------------------------------ */

	const mobileCta = document.querySelector('.cc-mobile-cta');
	const hero = document.querySelector('.cc-hero, .cc-page-hero');
	let ticking = false;

	const toTop = document.createElement('button');
	toTop.type = 'button';
	toTop.className = 'cc-to-top';
	toTop.setAttribute('aria-label', 'Back to top');
	toTop.addEventListener('click', () => {
		window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
		document.querySelector('#brx-header a')?.focus({ preventScroll: true });
	});
	document.body.appendChild(toTop);

	const onScroll = () => {
		const y = window.scrollY;
		root.classList.toggle('cc-scrolled', y > 24);

		const scrollable = document.documentElement.scrollHeight - window.innerHeight;
		toTop.classList.toggle('is-visible', y > Math.min(600, window.innerHeight));
		toTop.style.setProperty('--cc-scroll', scrollable > 0 ? (y / scrollable).toFixed(3) : '0');

		if (mobileCta) {
			const threshold = hero ? hero.offsetTop + hero.offsetHeight - 80 : 400;
			const nearFooter = window.innerHeight + y > document.body.scrollHeight - 120;
			mobileCta.classList.toggle('is-visible', y > threshold && !nearFooter);
		}

		if (!reduceMotion) {
			parallax();
		}
		ticking = false;
	};

	window.addEventListener('scroll', () => {
		if (!ticking) {
			ticking = true;
			requestAnimationFrame(onScroll);
		}
	}, { passive: true });

	/* Parallax on background photos -------------------------------------- */

	const covers = Array.from(document.querySelectorAll('.cc-cover'));

	function parallax() {
		const vh = window.innerHeight;
		covers.forEach((cover) => {
			const rect = cover.getBoundingClientRect();
			if (rect.bottom < 0 || rect.top > vh) {
				return;
			}
			// -15% of the cover height across the time it is on screen
			const progress = (vh - rect.top) / (vh + rect.height);
			cover.style.setProperty('--cc-parallax', `${(-progress * rect.height * 0.15).toFixed(1)}px`);
		});
	}

	onScroll();

	/* Scroll reveals ------------------------------------------------------ */

	const revealSelector = [
		'.cc-section-head',
		'.cc-card',
		'.cc-feature',
		'.cc-steps__item',
		'.cc-area-card',
		'.cc-promo',
		'.cc-review',
		'.cc-checklist__room',
		'.cc-stat',
		'.cc-faq__item',
		'.cc-collage',
		'.cc-audience__item',
		'.cc-guarantee',
		'.cc-not-included',
		'.cc-addons__item',
		'.cc-cta__inner > *',
		'.cc-panel',
		'.cc-marquee',
		'.cc-prose > *',
	].join(',');

	if (!reduceMotion && 'IntersectionObserver' in window) {
		const targets = Array.from(document.querySelectorAll(revealSelector)).filter(
			(el) => !el.closest('.cc-hero, .cc-page-hero, #brx-header') && el.getBoundingClientRect().top > window.innerHeight * 0.92
		);

		// Stagger siblings that appear together
		const groups = new Map();
		targets.forEach((el) => {
			const siblings = groups.get(el.parentElement) || [];
			siblings.push(el);
			groups.set(el.parentElement, siblings);
		});
		groups.forEach((siblings) => {
			siblings.forEach((el, i) => el.style.setProperty('--cc-i', String(Math.min(i % 4, 4))));
		});

		const observer = new IntersectionObserver((entries) => {
			entries.forEach((entry) => {
				if (!entry.isIntersecting) {
					return;
				}
				const el = entry.target;
				observer.unobserve(el);
				el.classList.add('is-visible');
				// Hand transitions back to the component (hover effects) once revealed
				const delay = parseFloat(getComputedStyle(el).transitionDelay) * 1000 || 0;
				setTimeout(() => {
					el.classList.remove('cc-reveal', 'is-visible');
					el.style.removeProperty('--cc-i');
				}, delay + 900);
			});
		}, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

		targets.forEach((el) => {
			el.classList.add('cc-reveal');
			observer.observe(el);
		});
	}

	/* Counters ------------------------------------------------------------ */

	const counters = document.querySelectorAll('[data-count]');

	const runCounter = (el) => {
		const target = parseFloat(el.dataset.count);
		const decimals = parseInt(el.dataset.decimals || '0', 10);
		if (Number.isNaN(target)) {
			return;
		}
		const duration = 1400;
		const start = performance.now();
		const step = (now) => {
			const t = Math.min(1, (now - start) / duration);
			const eased = 1 - Math.pow(1 - t, 3);
			el.textContent = (target * eased).toFixed(decimals);
			if (t < 1) {
				requestAnimationFrame(step);
			}
		};
		requestAnimationFrame(step);
	};

	if (!reduceMotion && 'IntersectionObserver' in window) {
		const counterObserver = new IntersectionObserver((entries) => {
			entries.forEach((entry) => {
				if (entry.isIntersecting) {
					counterObserver.unobserve(entry.target);
					runCounter(entry.target);
				}
			});
		}, { threshold: 0.6 });
		counters.forEach((el) => counterObserver.observe(el));
	}

	/* Carousels ----------------------------------------------------------- */

	document.querySelectorAll('[data-cc-carousel]').forEach((carousel) => {
		const track = carousel.querySelector('.cc-carousel__track');
		const bar = carousel.querySelector('.cc-carousel__progress span');
		const buttons = carousel.querySelectorAll('.cc-carousel__btn');
		if (!track) {
			return;
		}

		const update = () => {
			const max = track.scrollWidth - track.clientWidth;
			carousel.classList.toggle('is-static', max <= 2);
			buttons.forEach((btn) => {
				btn.disabled = btn.dataset.dir === '-1' ? track.scrollLeft <= 2 : track.scrollLeft >= max - 2;
			});
			if (bar) {
				bar.style.width = `${Math.min(100, (track.clientWidth / track.scrollWidth) * 100)}%`;
				bar.style.transform = `translateX(${(track.scrollLeft / track.clientWidth) * 100}%)`;
			}
		};

		// One "page" = as many whole cards as fit in the container
		const pageWidth = () => {
			const card = track.firstElementChild;
			if (!card) {
				return track.clientWidth;
			}
			const step = card.getBoundingClientRect().width + (parseFloat(getComputedStyle(track).columnGap) || 0);
			return step * Math.max(1, Math.floor((carousel.clientWidth + 1) / step));
		};

		buttons.forEach((btn) => {
			btn.addEventListener('click', () => {
				track.scrollBy({ left: pageWidth() * Number(btn.dataset.dir), behavior: reduceMotion ? 'auto' : 'smooth' });
			});
		});

		let frame = 0;
		track.addEventListener('scroll', () => {
			cancelAnimationFrame(frame);
			frame = requestAnimationFrame(update);
		}, { passive: true });
		window.addEventListener('resize', update);
		update();

		// Drag to scroll with a mouse (touch and trackpads scroll natively)
		let startX = 0;
		let startLeft = 0;
		let dragged = false;

		track.addEventListener('pointerdown', (e) => {
			if (e.pointerType !== 'mouse' || e.button !== 0) {
				return;
			}
			startX = e.clientX;
			startLeft = track.scrollLeft;
			dragged = false;

			const move = (ev) => {
				const dx = ev.clientX - startX;
				if (!dragged && Math.abs(dx) > 6) {
					dragged = true;
					track.classList.add('is-dragging');
				}
				if (dragged) {
					track.scrollLeft = startLeft - dx;
				}
			};
			const up = () => {
				window.removeEventListener('pointermove', move);
				window.removeEventListener('pointerup', up);
				track.classList.remove('is-dragging');
			};
			window.addEventListener('pointermove', move);
			window.addEventListener('pointerup', up);
		});

		// A drag shouldn't count as a click on the card underneath
		track.addEventListener('click', (e) => {
			if (dragged) {
				e.preventDefault();
				e.stopPropagation();
				dragged = false;
			}
		}, true);
		track.addEventListener('dragstart', (e) => e.preventDefault());
	});

	/* Guide contents list: mark the section being read ------------------- */

	const tocLinks = [...document.querySelectorAll('.cc-toc a[href^="#"]')];
	const headings = tocLinks.map((a) => document.getElementById(decodeURIComponent(a.hash.slice(1)))).filter(Boolean);

	if (headings.length && 'IntersectionObserver' in window) {
		const visible = new Set();
		const observer = new IntersectionObserver((entries) => {
			entries.forEach((entry) => (entry.isIntersecting ? visible.add(entry.target) : visible.delete(entry.target)));
			const current = headings.find((h) => visible.has(h))
				|| [...headings].reverse().find((h) => h.getBoundingClientRect().top < 0);
			tocLinks.forEach((a) => a.classList.toggle('is-active', !!current && a.hash === '#' + current.id));
		}, { rootMargin: '-120px 0px -55% 0px' });
		headings.forEach((h) => observer.observe(h));
	}

	/* "Do we cover you?" town / postcode finder -------------------------- */

	document.querySelectorAll('[data-cc-area-finder]').forEach((finder) => {
		const input = finder.querySelector('.cc-area-finder__input');
		const result = finder.querySelector('.cc-area-finder__result');
		let areas = [];
		try {
			areas = JSON.parse(finder.querySelector('.cc-area-finder__data').textContent);
		} catch (e) {
			return;
		}
		if (!input || !result) {
			return;
		}

		const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
		let matches = [];

		const search = (raw) => {
			const compact = raw.toUpperCase().replace(/\s+/g, '');
			if (/^[A-Z]{1,2}\d/.test(compact)) {
				// Postcode district: the part before the space, or a full postcode minus its last 3 characters
				const parts = raw.trim().toUpperCase().split(/\s+/);
				const district = parts.length > 1 ? parts[0] : (compact.length >= 5 ? compact.slice(0, -3) : compact);
				return areas.filter((a) => a.codes.includes(district)).map((area) => ({ area }));
			}
			const q = raw.trim().toLowerCase();
			const found = [];
			areas.forEach((area) => {
				if (area.name.toLowerCase().includes(q)) {
					found.push({ area, rank: area.name.toLowerCase().startsWith(q) ? 0 : 1 });
					return;
				}
				const place = area.places.find((p) => p.toLowerCase().includes(q));
				if (place) {
					found.push({ area, place, rank: place.toLowerCase().startsWith(q) ? 2 : 3 });
				}
			});
			return found.sort((a, b) => a.rank - b.rank).slice(0, 5);
		};

		input.addEventListener('input', () => {
			const raw = input.value.trim();
			matches = raw.length >= 2 ? search(raw) : [];
			if (raw.length < 2) {
				result.innerHTML = '';
			} else if (matches.length) {
				result.innerHTML = '<p class="cc-area-finder__yes">Yes, we cover that area</p><ul class="cc-area-finder__matches">'
					+ matches.map((m) => `<li><a href="${esc(m.area.url)}">${m.place ? `${esc(m.place)} <span>covered by our ${esc(m.area.name)} team</span>` : `Cleaners in ${esc(m.area.name)}`}</a></li>`).join('')
					+ '</ul>';
			} else {
				result.innerHTML = `<p class="cc-area-finder__no">We don't list “${esc(raw)}” yet, but we may still be able to help.</p>`
					+ `<a class="cc-area-finder__ask" href="${esc(finder.dataset.contact || '/contact/')}">Ask us about your area</a>`;
			}
		});

		finder.querySelector('form')?.addEventListener('submit', (e) => {
			e.preventDefault();
			if (matches.length === 1) {
				window.location.href = matches[0].area.url;
			}
		});
	});
})();
