<script setup>
import { skipSourceLabel } from '../../../skipSources.js';
import AppLayout from '../../../Layouts/AppLayout.vue';
import SelectField from '../../../Layouts/SelectField.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    date: String,
    is_meal_day: Boolean,
    count: Object,
    status: String,
    cutoff_time: String,
    seconds_left: Number,
    skips: Array,
    extra_meals: Array,
    employees_for_search: Array,
    snapshot: { type: Object, default: null },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

// Per-employee outcome of the last bulk skip. Flash data, so it clears itself.
const bulkSummary = computed(() => page.props.flash?.bulkSummary ?? null);
const dismissedBulk = ref(false);

const employeeLabel = (id) => {
    const match = props.employees_for_search.find((e) => e.id === id);

    return match ? `${match.name} (${match.employee_code})` : `Employee #${id}`;
};

const bulkApplied = computed(() => {
    const s = bulkSummary.value;

    return s ? s.created_count + s.reactivated_count : 0;
});

// Everything the engine did not newly apply, with the reason it gave.
const bulkNotApplied = computed(() => {
    const s = bulkSummary.value;

    if (!s) return [];

    return (s.results ?? [])
        .filter((r) => !['created', 'reactivated'].includes(r.status))
        .map((r) => ({
            who: employeeLabel(r.employee_id),
            date: r.date,
            // A refusal carries a reason; an already-skipped day does not.
            why: r.reason ?? r.status.replace(/_/g, ' '),
        }));
});

const isLocked = computed(() => props.status === 'locked');

/* ---------- status badge ---------- */

const statusLabel = computed(() => {
    if (props.snapshot?.reviewed_at) return 'Reviewed';
    if (isLocked.value) return 'Locked';
    return 'Draft';
});

// A colour for the word and a colour for the dot beside it. Filled capsules
// before, which read as louder than the heading they sat next to.
const statusTone = computed(() => ({
    Reviewed: { text: 'text-mb-emerald', dot: 'bg-mb-emerald' },
    Locked: { text: 'text-mb-indigo', dot: 'bg-mb-indigo' },
    Draft: { text: 'text-mb-text-muted dark:text-mb-text-soft', dot: 'bg-mb-text-faint' },
}[statusLabel.value]));

/* ---------- cutoff countdown ---------- */

// Floored once, here. The server sends a float (Carbon's diff carries
// fractional seconds), and `float % 60` produced "18:51:7.715735999998287" on
// screen - the hours and minutes were fine because they go through Math.floor.
const remaining = ref(Math.max(0, Math.floor(props.seconds_left ?? 0)));

const ticker = setInterval(() => {
    if (remaining.value > 0) remaining.value -= 1;
}, 1000);

onUnmounted(() => clearInterval(ticker));

const countdown = computed(() => {
    if (remaining.value <= 0) return null;
    const h = Math.floor(remaining.value / 3600);
    const m = Math.floor((remaining.value % 3600) / 60);
    const s = Math.floor(remaining.value % 60);
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

/* ---------- date navigation ---------- */

const selectedDate = ref(props.date);

const goToDate = () => {
    router.get('/company-admin/daily', { date: selectedDate.value }, { preserveScroll: true });
};

/* ---------- per-employee skip ---------- */

// The skips list carries the employee, so the roster can show each person's
// current state without a second query.
const skipByEmployee = computed(() => {
    const map = {};
    for (const skip of props.skips) {
        if (!skip.cancelled_at) map[skip.employee_id] = skip;
    }
    return map;
});

// Trimmed, so a column of empty subtext does not appear for skips that carry
// no reason at all.
const skipReason = (skip) => (skip?.reason ?? '').trim() || null;

const skipForm = useForm({ employee_id: null, date: props.date, source: 'hr', reason: '' });

const addSkip = (employeeId) => {
    skipForm.employee_id = employeeId;
    skipForm.date = props.date;
    skipForm.post('/company-admin/skips', { preserveScroll: true });
};

const removeSkip = (skipId) => {
    router.delete(`/company-admin/skips/${skipId}`, { preserveScroll: true });
};

/* ---------- bulk skip ---------- */

/*
 * Paged in the browser, not the server: the whole roster already arrives in
 * employees_for_search because the page searches and counts across all of it,
 * so asking for a page at a time would cost a round trip and buy nothing.
 */
const PAGE_SIZE = 15;

const rosterPage = ref(1);

const pageCount = computed(() => Math.max(1, Math.ceil(props.employees_for_search.length / PAGE_SIZE)));

const pagedEmployees = computed(() => {
    const start = (rosterPage.value - 1) * PAGE_SIZE;

    return props.employees_for_search.slice(start, start + PAGE_SIZE);
});

const firstShown = computed(() => (props.employees_for_search.length ? (rosterPage.value - 1) * PAGE_SIZE + 1 : 0));

const lastShown = computed(() => Math.min(rosterPage.value * PAGE_SIZE, props.employees_for_search.length));

// A different date brings a different roster, which can be shorter than the
// page we are standing on.

/*
 * Bulk skip is occasional - a team away for a day, a floor at an offsite - so
 * its checkbox column is off until asked for. It was a column of empty boxes
 * down the whole roster the rest of the time, which read as the table's first
 * column rather than as a tool.
 *
 * Turning it off drops the selection too: leaving ids selected in a hidden
 * column is how you skip the wrong people.
 */
const selecting = ref(false);

const toggleSelecting = () => {
    selecting.value = ! selecting.value;

    if (! selecting.value) {
        selected.value = [];
    }
};

const selected = ref([]);

const toggleSelected = (employeeId) => {
    const at = selected.value.indexOf(employeeId);
    at === -1 ? selected.value.push(employeeId) : selected.value.splice(at, 1);
};

// A different date brings a different roster, which can be shorter than the
// page we are standing on. It can also be a locked day, where every checkbox
// is disabled - and a selection made on another date is not a selection here.
watch(() => props.employees_for_search, () => {
    rosterPage.value = 1;
    selecting.value = false;
    selected.value = [];
});

const bulkForm = useForm({ employee_ids: [], dates: [], source: 'hr', reason: '' });

const bulkSources = [
    { value: 'hr', label: 'Marked by HR' },
    { value: 'leave', label: 'On leave' },
    { value: 'wfh', label: 'Working from home' },
];

const submitBulk = () => {
    bulkForm.employee_ids = [...selected.value];
    bulkForm.dates = [props.date];
    bulkForm.post('/company-admin/skips/bulk', {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
    });
};

/* ---------- extra meals ---------- */

const extraForm = useForm({ date: props.date, quantity: 1, type: 'guest', reason: '' });

const extraTypes = [
    { value: 'guest', label: 'Guest' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'other', label: 'Other' },
];

const submitExtra = () => {
    extraForm.date = props.date;
    extraForm.post('/company-admin/extra-meals', {
        preserveScroll: true,
        onSuccess: () => extraForm.reset('quantity', 'reason'),
    });
};

const cancelExtra = (id) => {
    router.delete(`/company-admin/extra-meals/${id}`, { preserveScroll: true });
};

/* ---------- locked-day actions ---------- */

const lateForm = useForm({ date: props.date, change_quantity: 1, reason: '' });

/**
 * Nudge a stepper's value, keeping it inside its range.
 *
 * A late change is signed - it can add meals or take them away - and typing a
 * minus into a number field is a fiddly way to say "one fewer". The buttons
 * make the direction a click, and the sign visible before you submit.
 */
const step = (form, field, delta, min, max) => {
    const next = Number(form[field] || 0) + delta;
    form[field] = Math.min(max, Math.max(min, next));
};

const submitLateChange = () => {
    lateForm.date = props.date;
    lateForm.post('/company-admin/late-changes', {
        preserveScroll: true,
        onSuccess: () => lateForm.reset('reason'),
    });
};

const acknowledge = () => {
    router.post('/company-admin/daily/acknowledge', { date: props.date }, { preserveScroll: true });
};

const NEUTRAL_TONE = { text: 'text-mb-text-muted dark:text-mb-text-soft', dot: 'bg-mb-text-faint' };

const sourceTone = (source) => ({
    hr: { text: 'text-mb-cyan', dot: 'bg-mb-cyan' },
    leave: { text: 'text-mb-amber', dot: 'bg-mb-amber' },
    wfh: { text: 'text-mb-indigo', dot: 'bg-mb-indigo' },
    self: { text: 'text-mb-emerald', dot: 'bg-mb-emerald' },
    // Purple has no token of its own; it is the one source colour outside the
    // palette, so the shades are pinned per theme rather than left at 300,
    // which fails on a light table row.
    recurring: { text: 'text-purple-700 dark:text-purple-300', dot: 'bg-purple-700 dark:bg-purple-300' },
    link: NEUTRAL_TONE,
}[source] ?? NEUTRAL_TONE);
</script>

<template>
    <AppLayout>
        <div class="p-4 sm:p-6 space-y-5">
            <!-- Header + date picker -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">Daily count</h1>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                        Cutoff {{ cutoff_time }}
                        <span v-if="countdown" class="text-mb-cyan font-mono font-semibold">· {{ countdown }} left</span>
                        <span v-else class="text-mb-amber font-semibold">· cutoff passed</span>
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <div>
                        <label for="index-date" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Date</label>
                        <input id="index-date" v-model="selectedDate" type="date" class="bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <button @click="goToDate" class="px-4 py-2 bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 text-mb-on-fill font-bold rounded-lg text-sm cursor-pointer">
                        Go
                    </button>
                </div>
            </div>

            <!-- Guard errors -->
            <div v-for="(message, key) in errors" :key="key" class="p-3 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-sm text-mb-rose-soft">
                {{ message }}
            </div>

            <div v-if="!is_meal_day" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30 text-sm text-mb-amber-soft">
                This is not a meal day for your company, so no count is produced.
            </div>

            <!-- Outcome of the last bulk skip -->
            <div
                v-if="bulkSummary && !dismissedBulk"
                class="p-4 rounded-2xl bg-mb-card border border-mb-field space-y-3"
            >
                <div class="flex items-start justify-between gap-4">
                    <p class="text-sm font-bold text-mb-text">
                        Bulk skip:
                        <span class="text-mb-emerald">{{ bulkApplied }} applied</span>
                        <span v-if="bulkNotApplied.length" class="text-mb-amber">
                            · {{ bulkNotApplied.length }} not applied
                        </span>
                        <span class="text-mb-text-faint dark:text-mb-text-soft font-normal">
                            (of {{ bulkSummary.total_processed }} attempted)
                        </span>
                    </p>
                    <button
                        @click="dismissedBulk = true"
                        class="text-mb-text-faint dark:text-mb-text-soft hover:text-mb-text-soft text-lg leading-none cursor-pointer"
                        aria-label="Dismiss"
                    >
                        ×
                    </button>
                </div>

                <div v-if="bulkNotApplied.length" class="space-y-1">
                    <div
                        v-for="(row, index) in bulkNotApplied"
                        :key="`${row.who}-${row.date}-${index}`"
                        class="flex flex-wrap items-baseline gap-x-2 text-xs p-2 rounded-lg bg-mb-raised/40"
                    >
                        <span class="font-semibold text-mb-text">{{ row.who }}</span>
                        <span class="font-mono text-mb-text-faint dark:text-mb-text-soft">{{ row.date }}</span>
                        <span class="text-mb-amber">{{ row.why }}</span>
                    </div>
                </div>
            </div>

            <!-- Count card -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-base font-bold text-mb-text">Count for {{ date }}</h2>
                    <div class="flex items-center gap-2">
                        <span :class="['inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap', statusTone.text]">
                            <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', statusTone.dot]" aria-hidden="true"></span>
                            {{ statusLabel }}
                        </span>
                        <span v-if="snapshot?.lock_type" class="text-[11px] text-mb-text-faint dark:text-mb-text-soft font-mono">{{ snapshot.lock_type }}-locked</span>
                    </div>
                </div>

                <!--
                    Five figures separated by dividers rather than five boxes
                    inside this box. The total is the one the kitchen cooks to,
                    so it is the only one that keeps a surface.
                -->
                <ul class="flex flex-wrap sm:flex-nowrap items-stretch sm:divide-x divide-mb-line">
                    <li class="grow basis-24 py-2 pr-4 first:pl-0">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Base eligible</span>
                        <span class="block text-3xl font-light tracking-tight text-mb-text-strong mt-1 tabular-nums">{{ count.base_eligible_count }}</span>
                    </li>
                    <li class="grow basis-24 py-2 px-4">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Skips</span>
                        <span class="block text-3xl font-light tracking-tight mt-1 tabular-nums" :class="count.skip_count ? 'text-mb-amber' : 'text-mb-text-strong'">−{{ count.skip_count }}</span>
                    </li>
                    <li class="grow basis-24 py-2 px-4">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Extra</span>
                        <span class="block text-3xl font-light tracking-tight mt-1 tabular-nums" :class="count.extra_count ? 'text-mb-indigo' : 'text-mb-text-strong'">+{{ count.extra_count }}</span>
                    </li>
                    <li class="grow basis-24 py-2 px-4">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Expected</span>
                        <span class="block text-3xl font-light tracking-tight text-mb-text-strong mt-1 tabular-nums">{{ count.final_expected_count }}</span>
                    </li>
                    <li class="grow basis-28 rounded-2xl bg-mb-fill-emerald text-mb-on-fill-soft border border-transparent dark:border-mb-tint-emerald/50 p-4 sm:ml-4">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider">Adjusted total</span>
                        <span class="block text-3xl font-light tracking-tight text-mb-on-fill mt-1 tabular-nums">{{ count.adjusted_total }}</span>
                    </li>
                </ul>

                <p v-if="snapshot?.reviewed_at" class="text-xs text-mb-emerald">
                    Reviewed by {{ snapshot.reviewed_by }} at {{ snapshot.reviewed_at }}
                </p>
            </div>

            <!--
                Both cards adjust the same day's count, so they sit side by
                side rather than one under the other. A grid, for an exact even
                split: grow+basis shares the leftover space, which let the
                wider form on the right pull itself a few pixels bigger than
                the one on the left. "After cutoff" only exists on a locked
                day, so on every other day the remaining card spans both
                columns rather than holding half a row.
            -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">

                <!-- Locked-day actions -->
                <div v-if="isLocked" class="min-w-0 p-6 rounded-2xl bg-mb-card border border-mb-tint-indigo/30 space-y-4">
                    <div class="flex items-start justify-between flex-wrap gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-bold text-mb-text">After cutoff</h2>
                            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                The count is locked, so meals change through a recorded adjustment rather than a skip.
                            </p>
                        </div>
                        <button
                            v-if="!snapshot?.reviewed_at"
                            @click="acknowledge"
                            class="inline-flex items-center gap-1.5 h-9 px-3 shrink-0 rounded-lg bg-mb-card border border-mb-tint-emerald/50 text-xs font-semibold text-mb-emerald hover:bg-mb-tint-emerald/10 cursor-pointer transition whitespace-nowrap"
                        >
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9.5 18 20 7" />
                            </svg>
                            Review this count
                        </button>
                    </div>

                    <form @submit.prevent="submitLateChange" class="flex flex-wrap items-end gap-3">
                        <!-- A signed count, two digits at most: it takes the
                             width of its content, not a share of the row. Only
                             the free-text field grows. -->
                        <div class="shrink-0">
                            <label for="late-change" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Change</label>
                            <div class="flex items-stretch h-10 rounded-lg border border-mb-field bg-mb-inset overflow-hidden">
                                <button
                                    type="button"
                                    @click="step(lateForm, 'change_quantity', -1, -200, 200)"
                                    aria-label="One meal fewer"
                                    class="w-9 shrink-0 text-mb-text-soft hover:bg-mb-raised cursor-pointer transition-colors"
                                >−</button>
                                <input
                                    id="late-change"
                                    v-model.number="lateForm.change_quantity"
                                    type="number"
                                    required
                                    class="w-12 text-center bg-transparent border-x border-mb-field text-sm font-semibold text-mb-text-strong tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none focus:outline-none"
                                />
                                <button
                                    type="button"
                                    @click="step(lateForm, 'change_quantity', 1, -200, 200)"
                                    aria-label="One meal more"
                                    class="w-9 shrink-0 text-mb-text-soft hover:bg-mb-raised cursor-pointer transition-colors"
                                >+</button>
                            </div>
                        </div>
                        <div class="grow basis-48 min-w-0">
                            <label for="index-reason" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Reason</label>
                            <input id="index-reason" v-model="lateForm.reason" placeholder="Agreed with the vendor" class="w-full h-10 bg-mb-inset border border-mb-field rounded-lg px-3 text-sm text-mb-text-strong" required />
                        </div>
                        <button
                            type="submit"
                            :disabled="lateForm.processing"
                            class="h-10 px-4 shrink-0 rounded-xl bg-mb-text-strong text-mb-page disabled:opacity-40 text-sm font-semibold cursor-pointer transition hover:opacity-90"
                        >
                            Record change
                        </button>
                    </form>

                    <ul v-if="count.changes?.length" class="divide-y divide-mb-line">
                        <li v-for="change in count.changes" :key="change.id" class="flex items-center gap-3 text-xs py-2">
                            <span class="font-mono font-bold shrink-0 tabular-nums" :class="change.change_quantity > 0 ? 'text-mb-emerald' : 'text-mb-rose dark:text-mb-rose-soft'">
                                {{ change.change_quantity > 0 ? '+' : '' }}{{ change.change_quantity }}
                            </span>
                            <span class="grow min-w-0 text-mb-text-soft truncate">{{ change.reason }}</span>
                            <span class="shrink-0 text-mb-text-muted dark:text-mb-text-soft">{{ change.requested_by?.name }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Extra meals -->
                <div :class="['min-w-0 p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4', isLocked ? '' : 'lg:col-span-2']">
                    <h2 class="text-base font-bold text-mb-text">Extra meals</h2>

                    <form @submit.prevent="submitExtra" class="flex flex-wrap items-end gap-3">
                        <div class="w-32 shrink-0">
                            <label for="extra-type" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Type</label>
                            <SelectField id="extra-type" v-model="extraForm.type" :options="extraTypes" aria-label="Extra meal type" />
                        </div>
                        <div class="shrink-0">
                            <label for="extra-quantity" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Quantity</label>
                            <div class="flex items-stretch h-10 rounded-lg border border-mb-field bg-mb-inset overflow-hidden">
                                <button
                                    type="button"
                                    @click="step(extraForm, 'quantity', -1, 1, 100)"
                                    aria-label="One fewer"
                                    class="w-9 shrink-0 text-mb-text-soft hover:bg-mb-raised cursor-pointer transition-colors"
                                >−</button>
                                <input
                                    id="extra-quantity"
                                    v-model.number="extraForm.quantity"
                                    type="number"
                                    min="1"
                                    max="100"
                                    required
                                    class="w-10 text-center bg-transparent border-x border-mb-field text-sm font-semibold text-mb-text-strong tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none focus:outline-none"
                                />
                                <button
                                    type="button"
                                    @click="step(extraForm, 'quantity', 1, 1, 100)"
                                    aria-label="One more"
                                    class="w-9 shrink-0 text-mb-text-soft hover:bg-mb-raised cursor-pointer transition-colors"
                                >+</button>
                            </div>
                        </div>
                        <div class="grow basis-40 min-w-0">
                            <label for="index-reason-2" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Reason</label>
                            <input id="index-reason-2" v-model="extraForm.reason" placeholder="Client visit" class="w-full h-10 bg-mb-inset border border-mb-field rounded-lg px-3 text-sm text-mb-text-strong" />
                        </div>
                        <button
                            type="submit"
                            :disabled="extraForm.processing"
                            class="h-10 px-4 shrink-0 rounded-xl bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 disabled:opacity-40 text-mb-on-fill text-sm font-semibold cursor-pointer transition"
                        >
                            Add extra
                        </button>
                    </form>

                    <ul v-if="extra_meals.length" class="divide-y divide-mb-line">
                        <li v-for="extra in extra_meals" :key="extra.id" class="flex items-center gap-3 text-xs py-2">
                            <span class="font-bold text-mb-indigo font-mono shrink-0 tabular-nums">+{{ extra.quantity }}</span>
                            <span class="capitalize shrink-0 text-mb-text-muted dark:text-mb-text-soft">{{ extra.type }}</span>
                            <span class="grow min-w-0 text-mb-text-soft truncate">{{ extra.reason }}</span>
                            <span v-if="extra.creator" class="shrink-0 text-mb-text-muted dark:text-mb-text-soft hidden sm:inline">{{ extra.creator.name }}</span>
                            <span v-if="extra.cancelled_at" class="shrink-0 text-mb-text-muted dark:text-mb-text-soft italic">cancelled</span>
                            <button
                                v-else
                                @click="cancelExtra(extra.id)"
                                class="h-7 px-2.5 shrink-0 rounded-lg bg-mb-card border border-mb-tint-rose/50 text-[11px] font-semibold text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10 cursor-pointer transition"
                            >
                                Cancel
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-mb-text-muted dark:text-mb-text-soft">No extra meals for this date.</p>
                </div>
            </div>

            <!-- Roster -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-base font-bold text-mb-text">
                        Employees <span class="text-mb-text-faint dark:text-mb-text-soft font-normal">({{ employees_for_search.length }})</span>
                    </h2>

                    <div class="flex items-end gap-2 flex-wrap">
                        <form v-if="selecting && selected.length" @submit.prevent="submitBulk" class="flex items-end gap-2">
                            <div class="w-28">
                                <label for="bulk-source" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Source</label>
                                <SelectField id="bulk-source" v-model="bulkForm.source" :options="bulkSources" aria-label="Bulk skip source" />
                            </div>
                            <button
                                type="submit"
                                :disabled="bulkForm.processing"
                                class="h-10 px-4 rounded-xl bg-mb-tint-amber hover:opacity-90 disabled:opacity-40 text-mb-on-accent text-sm font-semibold cursor-pointer transition"
                            >
                                Skip {{ selected.length }} selected
                            </button>
                        </form>

                        <!--
                            Not offered once the count is locked. Every
                            checkbox is disabled after the cutoff, because a
                            locked day changes through a recorded adjustment
                            rather than a skip - so the button opened a column
                            nothing in it could be clicked.
                        -->
                        <button
                            v-if="!isLocked"
                            type="button"
                            @click="toggleSelecting"
                            :aria-pressed="selecting"
                            class="h-10 px-3.5 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                        >
                            {{ selecting ? 'Done' : 'Select several' }}
                        </button>

                        <p v-else class="text-xs text-mb-text-muted dark:text-mb-text-soft">
                            Locked — change the count above
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted dark:text-mb-text-soft uppercase text-[11px] font-semibold">
                            <tr>
                                <th v-if="selecting" class="py-3 px-4 w-10"></th>
                                <th class="py-3 px-4">Code</th>
                                <th class="py-3 px-4">Name</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="employee in pagedEmployees" :key="employee.id" class="hover:bg-mb-raised/30">
                                <td v-if="selecting" class="py-3 px-4">
                                    <input
                                        type="checkbox"
                                        :checked="selected.includes(employee.id)"
                                        :disabled="!!skipByEmployee[employee.id] || isLocked"
                                        @change="toggleSelected(employee.id)"
                                        :aria-label="`Select ${employee.name}`"
                                        class="accent-mb-tint-cyan cursor-pointer disabled:opacity-30"
                                    />
                                </td>
                                <td class="py-3 px-4 font-mono text-xs text-mb-text-muted dark:text-mb-text-soft">{{ employee.employee_code }}</td>
                                <!--
                                    The skip sits under the name rather than in
                                    a column of its own. Almost everyone is
                                    taking a meal, so that column printed
                                    "Taking meal" down the whole roster and said
                                    nothing; the count card above already gives
                                    the totals. Only the exception is marked.
                                -->
                                <td class="py-3 px-4">
                                    <span class="font-semibold text-mb-text">{{ employee.name }}</span>

                                    <template v-if="skipByEmployee[employee.id]">
                                        <span
                                            :class="['flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap mt-0.5', sourceTone(skipByEmployee[employee.id].source).text]"
                                        >
                                            <span
                                                :class="['w-1.5 h-1.5 shrink-0 rounded-full', sourceTone(skipByEmployee[employee.id].source).dot]"
                                                aria-hidden="true"
                                            ></span>
                                            Not eating · {{ skipSourceLabel(skipByEmployee[employee.id].source) }}
                                        </span>
                                        <!-- The source alone does not say which leave
                                             this was. Our own label only - the vendor's
                                             reason text is never fetched or stored. -->
                                        <span
                                            v-if="skipReason(skipByEmployee[employee.id])"
                                            class="block text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-0.5 truncate max-w-[20rem]"
                                        >
                                            {{ skipReason(skipByEmployee[employee.id]) }}
                                        </span>
                                    </template>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <!-- Locked days are refused by the engine, so the
                                         buttons say so rather than inviting a failure. -->
                                    <span v-if="isLocked" class="text-[11px] text-mb-text-faint dark:text-mb-text-soft">
                                        Count locked — use an after-cutoff change
                                    </span>
                                    <button
                                        v-else-if="skipByEmployee[employee.id]"
                                        @click="removeSkip(skipByEmployee[employee.id].id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-emerald bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 border border-mb-tint-emerald/30 cursor-pointer"
                                    >
                                        Cancel skip
                                    </button>
                                    <button
                                        v-else
                                        @click="addSkip(employee.id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-amber bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 border border-mb-tint-amber/30 cursor-pointer"
                                    >
                                        Mark skip
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!employees_for_search.length">
                                <td :colspan="selecting ? 4 : 3" class="py-6 text-center text-mb-text-muted dark:text-mb-text-soft">
                                    No active, meal-eligible employees yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav
                    v-if="pageCount > 1"
                    class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-mb-line"
                    aria-label="Employee pages"
                >
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft tabular-nums">
                        Showing {{ firstShown }} to {{ lastShown }} of {{ employees_for_search.length }}
                    </p>

                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            :disabled="rosterPage === 1"
                            @click="rosterPage = rosterPage - 1"
                            class="h-8 px-3 inline-flex items-center gap-1 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised disabled:opacity-40 disabled:cursor-default cursor-pointer transition"
                        >
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                            Previous
                        </button>

                        <button
                            v-for="n in pageCount"
                            :key="n"
                            type="button"
                            :aria-current="n === rosterPage ? 'page' : undefined"
                            @click="rosterPage = n"
                            :class="[
                                'w-8 h-8 rounded-lg text-xs font-semibold cursor-pointer transition tabular-nums',
                                n === rosterPage
                                    ? 'bg-mb-text-strong text-mb-page'
                                    : 'bg-mb-card border border-mb-field text-mb-text-soft hover:bg-mb-raised',
                            ]"
                        >
                            {{ n }}
                        </button>

                        <button
                            type="button"
                            :disabled="rosterPage === pageCount"
                            @click="rosterPage = rosterPage + 1"
                            class="h-8 px-3 inline-flex items-center gap-1 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised disabled:opacity-40 disabled:cursor-default cursor-pointer transition"
                        >
                            Next
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                        </button>
                    </div>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
