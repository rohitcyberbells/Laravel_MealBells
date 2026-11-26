/**
 * How a skip got recorded, said in words rather than in the engine's.
 *
 * The database stores 'recurring', 'wfh', 'hr' and so on, and two screens had
 * been printing one or other of those straight out: the daily roster rendered
 * the column raw, so a company admin read "Not eating · recurring" and
 * "Not eating · wfh", and the dashboard strip had its own copy of this map
 * that said "Recurring skip" - our word, not theirs.
 *
 * Nobody outside this codebase calls it recurring. What it means to the person
 * reading the screen is that this employee is never in on that weekday, so
 * that is what it says.
 *
 * @type {Record<string, string>}
 */
export const SKIP_SOURCE_LABELS = {
    leave: 'On leave',
    wfh: 'Working from home',
    hr: 'Marked by HR',
    self: 'Skipped themselves',
    link: 'Skipped by email link',
    recurring: 'Skips this day every week',
};

/**
 * The label for a source, falling back to something readable.
 *
 * A source the map has not caught up with is spaced out rather than printed as
 * a database value - 'half_day' reads as "half day", not as a column.
 *
 * @param {string|null|undefined} source
 * @returns {string}
 */
export function skipSourceLabel(source) {
    if (! source) {
        return 'Not eating';
    }

    return SKIP_SOURCE_LABELS[source] ?? String(source).replace(/_/g, ' ');
}
