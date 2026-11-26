/*
 * The landing page's moving parts, of which there is now one.
 *
 * Everything the page says is in the HTML. Nothing here writes content, so
 * with JavaScript off the page is whole rather than an empty frame - which is
 * also why the one piece below checks for its own markup and returns if it is
 * missing.
 */

const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)');

/**
 * Starts everything the page can run, and returns a teardown.
 *
 * @returns {() => void}
 */
export function initExperience() {
    const teardowns = [theReveals()];

    return () => teardowns.forEach((stop) => stop());
}

/**
 * Sections arrive as they are reached.
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
