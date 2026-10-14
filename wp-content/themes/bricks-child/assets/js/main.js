/**
 * Front-end motion: scroll reveals, counters, header state, hero parallax and the mobile CTA bar.
 * Everything is progressive: without this script all content is visible and static.
 */
(() => {
	const root = document.documentElement;
	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* Header state + mobile CTA ------------------------------------------ */

	const mobileCta = document.querySelector('.cc-mobile-cta');
	const hero = document.querySelector('.cc-hero, .cc-page-hero');
	let ticking = false;

	const onScroll = () => {
		const y = window.scrollY;
		root.classList.toggle('cc-scrolled', y > 24);

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
})();
