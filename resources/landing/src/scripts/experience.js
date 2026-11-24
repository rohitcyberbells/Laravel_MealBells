/*
 * The landing page's moving parts, of which there are deliberately few.
 *
 * It is set as a magazine feature, and an editorial page is won on its
 * setting rather than on things sliding into view. So: paragraphs arrive on
 * the way down, the plot fills once, and the diorama loads when it is nearly
 * on screen. Nothing else.
 *
 * Everything the page says is in the HTML. Nothing here writes content, so
 * with JavaScript off the page is the whole article rather than an empty
 * frame - which is also why each piece below checks for its own markup and
 * returns if it is missing.
 */

const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

/**
 * Starts everything the page can run, and returns a teardown.
 *
 * @returns {() => void}
 */
export function initExperience() {
    const teardowns = [theReveals(), thePlot()];

    return () => teardowns.forEach((stop) => stop());
}

/* ------------------------------------------------------------- the reveals */

/**
 * Paragraphs and figures arrive as they are reached.
 *
 * One observer for all of them rather than one each, and every element is
 * unobserved once seen: a reveal that can play twice plays backwards when
 * somebody scrolls up.
 *
 * @returns {() => void}
 */
function theReveals() {
    const items = [...document.querySelectorAll('.rise')];

    if (items.length === 0) {
        return () => {};
    }

    if (REDUCED.matches) {
        items.forEach((item) => item.classList.add('is-in'));

        return () => {};
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -10% 0px' },
    );

    items.forEach((item) => observer.observe(item));

    return () => observer.disconnect();
}

/* ---------------------------------------------------------------- the plot */

/**
 * The hundred and twenty-seven marks fill in a wipe rather than all at once.
 *
 * The delay is written per mark here instead of in the stylesheet because
 * there are a hundred and twenty-seven of them and the count comes from the
 * markup: a CSS rule would have to name every nth-child.
 *
 * @returns {() => void}
 */
function thePlot() {
    const plot = document.querySelector('[data-plot]');

    if (!plot) {
        return () => {};
    }

    if (REDUCED.matches) {
        plot.classList.add('is-in');

        return () => {};
    }

    [...plot.children].forEach((mark, i) => {
        mark.style.transitionDelay = `${Math.min(i * 6, 900)}ms`;
    });

    const observer = new IntersectionObserver(
        ([entry]) => {
            if (entry.isIntersecting) {
                observer.disconnect();
                plot.classList.add('is-in');
            }
        },
        { threshold: 0.2 },
    );

    observer.observe(plot);

    return () => observer.disconnect();
}
