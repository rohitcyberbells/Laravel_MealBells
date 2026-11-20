<script setup>
import SelectField from '../../Layouts/SelectField.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

const props = defineProps({
    today: Object,
    next_7_days: Array,
    my_skips: Array,
    my_recurring_rules: Array,
    active_tiffin_assigned: Boolean,
    month_summary: Object,
});

const form = useForm({
    date: '',
    reason: '',
});

const rangeForm = useForm({
    from_date: '',
    to_date: '',
    reason: '',
});

const recurringForm = useForm({
    weekday: 1,
    starts_on: '',
    ends_on: '',
});

const weekdaysMap = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};

const submitSkip = () => {
    form.post('/employee/skips', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

// Only the meal days inside the range are skipped, so a weekend or a holiday
// in the middle is left alone rather than rejecting the whole request.
const submitRange = () => {
    rangeForm.post('/employee/skips/range', {
        preserveScroll: true,
        onSuccess: () => rangeForm.reset(),
    });
};

const submitRecurring = () => {
    recurringForm.post('/employee/recurring-skips', {
        preserveScroll: true,
        onSuccess: () => recurringForm.reset(),
    });
};

// The three ways to skip used to be three stacked forms, one of them behind a
// text toggle. They are the same decision at different lengths, so they share
// one card and one switch.
const skipMode = ref('day');

const skipModes = [
    { key: 'day', label: 'One day' },
    { key: 'range', label: 'Date range' },
    { key: 'weekly', label: 'Every week' },
];

// The state is sent explicitly, because the endpoint requires it: a bare patch
// failed validation, so pausing a rule never actually did anything.
const toggleRule = (rule) => {
    router.patch(`/employee/recurring-skips/${rule.id}`, { active: !rule.active }, { preserveScroll: true });
};

const deleteRule = (ruleId) => {
    router.delete(`/employee/recurring-skips/${ruleId}`, { preserveScroll: true });
};

// today.seconds_left was already supplied by the controller and never used.
// Floored once, here: the server's value is a float, and `float % 60` renders
// the seconds with their fraction attached.
const remaining = ref(Math.max(0, Math.floor(props.today.seconds_left ?? 0)));

const ticker = setInterval(() => {
    if (remaining.value > 0) remaining.value -= 1;
}, 1000);

onUnmounted(() => clearInterval(ticker));

const pad = (value) => String(value).padStart(2, '0');

// Split, because the hero prints the seconds smaller than the hours: one
// string would have to be sliced in the template to do that.
const countdownParts = computed(() => {
    const h = Math.floor(remaining.value / 3600);
    const m = Math.floor((remaining.value % 3600) / 60);
    const s = Math.floor(remaining.value % 60);

    return { hm: `${pad(h)}:${pad(m)}`, s: pad(s) };
});

const countdownWords = computed(() => {
    const h = Math.floor(remaining.value / 3600);
    const m = Math.floor((remaining.value % 3600) / 60);

    return h > 0 ? `${h}h ${m}m` : `${m}m`;
});

/** A 'YYYY-MM-DD' or ISO timestamp rendered in the reader's locale. */
const formatDate = (date, options) =>
    new Date(`${String(date).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, options);

const heroDate = computed(() => formatDate(props.today.date, { weekday: 'long', day: 'numeric', month: 'long' }));

const chipDate = computed(() => formatDate(props.today.date, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }));

const mealDayCount = computed(() => (props.next_7_days ?? []).filter((day) => day.is_meal_day).length);

const weekRange = computed(() => {
    const days = props.next_7_days ?? [];

    if (! days.length) {
        return '';
    }

    const options = { day: 'numeric', month: 'short' };

    return `${formatDate(days[0].date, options)} – ${formatDate(days[days.length - 1].date, options)}`;
});

const weekTaking = computed(() =>
    (props.next_7_days ?? []).filter((day) => day.is_meal_day && day.status !== 'skipped').length
);

const weekSkipped = computed(() =>
    (props.next_7_days ?? []).filter((day) => day.is_meal_day && day.status === 'skipped').length
);

const activeRuleCount = computed(() => (props.my_recurring_rules ?? []).filter((rule) => rule.active).length);

// my_skips carries every uncancelled skip, past ones included. The panel is
// about what is still ahead, so the past is filtered out here rather than
// narrowing the query, which other parts of the page may yet want.
const upcomingSkips = computed(() =>
    (props.my_skips ?? [])
        .filter((skip) => String(skip.date).slice(0, 10) >= props.today.date)
        .slice(0, 6)
);

// Status reads as a coloured dot and a word rather than a filled capsule. A
// row can carry a title, a note, a status and a button at once, and the
// capsules competed with all of them without saying anything extra.
const toneText = {
    emerald: 'text-mb-emerald',
    rose: 'text-mb-rose dark:text-mb-rose-soft',
    amber: 'text-mb-amber',
    slate: 'text-mb-text-soft',
    muted: 'text-mb-text-muted dark:text-mb-text-soft',
};

// The dot takes the same shade as the word beside it, not the fill shade. The
// fills are marks meant to sit on a surface, and the light amber one measured
// 2.13:1 against a card - below the 3:1 that 1.4.11 asks of anything carrying
// meaning. One rule, and every dot clears it in both themes.
const toneDot = {
    emerald: 'bg-mb-emerald',
    rose: 'bg-mb-rose',
    amber: 'bg-mb-amber',
    slate: 'bg-mb-text-muted',
    muted: 'bg-mb-text-faint',
};

const dayTag = (day) => {
    if (! day.is_meal_day) {
        return { label: 'No meal', tone: 'muted' };
    }

    if (day.status !== 'skipped') {
        return { label: 'Taking', tone: 'emerald' };
    }

    if (day.skip_source === 'recurring') {
        return { label: 'Skipped · repeating', tone: 'amber' };
    }

    if (day.skip_source === 'self') {
        return { label: 'Skipped', tone: 'rose' };
    }

    return { label: 'Skipped · HR', tone: 'slate' };
};

const dayTitle = (day) => {
    if (! day.is_meal_day) {
        return day.calendar_day?.type === 'holiday' ? 'Company holiday' : 'No meal served';
    }

    return day.meal || 'Menu not published yet';
};

const dayNote = (day) => {
    if (! day.is_meal_day) {
        return day.calendar_day?.note || 'Nothing to decide';
    }

    if (day.date === props.today.date) {
        return remaining.value > 0
            ? `Today · change until ${props.today.cutoff_time}`
            : 'Today · the cutoff has passed';
    }

    if (day.status === 'skipped') {
        if (day.skip_source === 'recurring') return 'From one of your repeating rules';
        if (day.skip_source === 'self') return 'You opted out of this one';

        return 'Set by your HR team';
    }

    return day.has_override ? 'A one-off change from the kitchen' : "From this week's menu";
};

const skipLabel = (source) => (source === 'self' ? 'you' : source);

const skipTone = (source) => (source === 'recurring' ? 'amber' : source === 'self' ? 'slate' : 'muted');

// Everything that has to be true for a meal to arrive today, each shown with
// its own answer. These were three clauses in one grey paragraph before, and
// a false one read exactly like a true one.
const checks = computed(() => [
    {
        label: 'Tiffin service assigned',
        note: props.active_tiffin_assigned
            ? 'Your company has an active service'
            : 'No service is delivering yet',
        ok: props.active_tiffin_assigned,
    },
    {
        label: 'Today is a meal day',
        note: props.today.is_meal_day
            ? 'Not a weekend or a holiday'
            : props.today.calendar_day?.note || 'No meal is served today',
        ok: Boolean(props.today.is_meal_day),
    },
    {
        label: 'You can still change it',
        note: remaining.value > 0
            ? `${countdownWords.value} left before the count is sent`
            : `Cutoff ${props.today.cutoff_time} has passed`,
        ok: remaining.value > 0 && ! props.today.locked,
    },
]);

const allChecksOk = computed(() => checks.value.every((check) => check.ok));

// One tap per day in the list: skip it, or take it back when the engine allows.
const toggleDay = (day) => {
    if (day.status === 'skipped') {
        if (day.can_cancel) router.delete(`/employee/skips/${day.skip_id}`, { preserveScroll: true });
        return;
    }

    router.post('/employee/skips', { date: day.date }, { preserveScroll: true });
};

const cancelSkip = (skipId) => {
    router.delete(`/employee/skips/${skipId}`, { preserveScroll: true });
};

const weekdayOptions = Object.entries(weekdaysMap).map(([number, label]) => ({
    value: Number(number),
    label,
}));
</script>

<template>
    <AppLayout>
        <!--
            Frosted cards need something behind them to frost. The page colour
            is flat, so three very soft tinted blobs sit under everything:
            blurred far past recognition, they read as ambient light rather
            than shapes. Fixed, so they stay put while the page scrolls, and
            built from the tint tokens, so they follow the theme.
        -->
        <!--
            relative z-10, because the blob layer above is positioned: a
            negative z-index would have put it behind the shell's own
            background, where nothing would ever see it.
        -->
        <div class="relative z-10 p-4 sm:p-6 space-y-5">

            <!-- Page header -->
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">My Meals</h1>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">One decision a day, before the cutoff.</p>
                </div>

                <span class="inline-flex items-center gap-2 h-10 px-3.5 rounded-xl bg-mb-card/70 backdrop-blur-xl border border-mb-line text-xs font-medium text-mb-text-soft">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="16" rx="3" />
                        <path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18" />
                    </svg>
                    {{ chipDate }}
                </span>
            </header>

            <div class="flex flex-wrap items-start gap-5">

                <!-- ── Main column ── -->
                <div class="grow basis-[34rem] min-w-0 space-y-5">

                    <!-- Hero: the countdown is the most urgent thing here, so it is the biggest -->
                    <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 sm:p-7">
                        <div class="flex flex-wrap items-start justify-between gap-6">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft">
                                    {{ heroDate }}
                                </p>

                                <p v-if="remaining > 0" class="mt-2.5 text-6xl sm:text-7xl font-light leading-none tracking-tight text-mb-text-strong tabular-nums">
                                    {{ countdownParts.hm }}<span class="text-3xl font-normal text-mb-text-muted dark:text-mb-text-soft">:{{ countdownParts.s }}</span>
                                </p>
                                <p v-else class="mt-2.5 text-4xl sm:text-5xl font-light leading-tight tracking-tight text-mb-text-strong">
                                    Today is fixed
                                </p>

                                <p class="mt-3 text-sm text-mb-text-soft">
                                    <template v-if="remaining > 0">
                                        left to change today's meal · cutoff
                                        <strong class="font-semibold text-mb-text-strong">{{ today.cutoff_time }}</strong>
                                    </template>
                                    <template v-else>
                                        Cutoff {{ today.cutoff_time }} has passed — today's count is with the kitchen.
                                    </template>
                                </p>
                            </div>

                            <div class="flex flex-col items-start sm:items-end gap-3">
                                <span
                                    :class="[
                                        'inline-flex items-center gap-2 text-sm font-semibold',
                                        today.status === 'skipped' ? toneText.rose : toneText.emerald,
                                    ]"
                                >
                                    <svg v-if="today.status === 'skipped'" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9.5 18 20 7" />
                                    </svg>
                                    {{ today.status === 'skipped' ? 'You are skipping today' : 'You are receiving today' }}
                                </span>

                                <button
                                    v-if="today.can_cancel"
                                    @click="cancelSkip(today.skip_id)"
                                    class="h-10 px-5 rounded-xl bg-mb-text-strong text-mb-page text-xs font-semibold cursor-pointer transition hover:opacity-90"
                                >
                                    Take today back
                                </button>
                                <button
                                    v-else-if="today.status !== 'skipped' && today.is_meal_day && remaining > 0"
                                    @click="toggleDay({ date: today.date, status: 'take' })"
                                    class="h-10 px-5 rounded-xl bg-mb-text-strong text-mb-page text-xs font-semibold cursor-pointer transition hover:opacity-90"
                                >
                                    Skip today
                                </button>
                            </div>
                        </div>

                        <div v-if="today.meal" class="mt-6 pt-5 border-t border-mb-line flex flex-wrap items-center gap-3.5">
                            <div class="w-10 h-10 shrink-0 rounded-xl bg-mb-raised flex items-center justify-center text-mb-text-soft">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 11h18M5 11a7 7 0 0 1 14 0M4 15h16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" />
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft">On the menu</p>
                                <p class="text-lg font-semibold tracking-tight text-mb-text-strong mt-0.5">{{ today.meal }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- Summary tiles -->
                    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                        <div class="bg-mb-fill-emerald text-mb-on-fill-soft border border-transparent dark:border-mb-tint-emerald/50 rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <div class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="M12 7v5l3 2" />
                                </svg>
                                <span class="text-xs font-semibold">Cutoff</span>
                            </div>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-on-fill">{{ today.cutoff_time }}</p>
                            <p class="text-[11px] leading-snug">After this, today's count goes to the kitchen.</p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <div class="flex items-center gap-1.5 text-mb-text-soft">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9.5 18 20 7" />
                                </svg>
                                <span class="text-xs font-semibold">Taken</span>
                            </div>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">
                                {{ month_summary?.taken ?? 0 }}<span class="text-base font-normal text-mb-text-muted dark:text-mb-text-soft"> meals</span>
                            </p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">
                                Meals you took in {{ month_summary?.label ?? 'this month' }} so far.
                            </p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <div class="flex items-center gap-1.5 text-mb-text-soft">
                                <svg class="w-3.5 h-3.5 text-mb-amber" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="M8 12h8" />
                                </svg>
                                <span class="text-xs font-semibold">Skipped</span>
                            </div>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">
                                {{ month_summary?.skipped ?? 0 }}<span class="text-base font-normal text-mb-text-muted dark:text-mb-text-soft"> days</span>
                            </p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Days you opted out of this month.</p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <div class="flex items-center gap-1.5 text-mb-text-soft">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />
                                </svg>
                                <span class="text-xs font-semibold">Repeating</span>
                            </div>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">
                                {{ activeRuleCount }}<span class="text-base font-normal text-mb-text-muted dark:text-mb-text-soft"> rules</span>
                            </p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Weekdays you skip automatically.</p>
                        </div>
                    </section>

                    <!-- The week ahead -->
                    <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 pb-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div>
                                <h2 class="text-base font-bold tracking-tight text-mb-text-strong">The week ahead</h2>
                                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                    {{ weekRange }} · {{ mealDayCount }} meal {{ mealDayCount === 1 ? 'day' : 'days' }}
                                </p>
                            </div>

                            <!-- The weekday pills that sat here only repeated the
                                 date each row already prints. These are counts
                                 the list cannot be read off at a glance. -->
                            <div class="flex items-center gap-4 text-xs font-medium text-mb-text-soft">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                                    {{ weekTaking }} taking
                                </span>
                                <span v-if="weekSkipped" class="inline-flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-mb-rose" aria-hidden="true"></span>
                                    {{ weekSkipped }} skipped
                                </span>
                            </div>
                        </div>

                        <ul class="mt-4">
                            <li
                                v-for="day in next_7_days"
                                :key="day.date"
                                :class="[
                                    'flex flex-wrap items-center gap-3.5 py-3.5 border-t border-mb-line first:border-t-0',
                                    day.is_meal_day ? '' : 'opacity-70',
                                ]"
                            >
                                <div class="w-14 shrink-0 text-center">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-mb-text-muted dark:text-mb-text-soft">{{ day.day_name.slice(0, 3) }}</p>
                                    <p class="text-xl font-semibold text-mb-text-strong mt-0.5">{{ day.date.slice(8, 10) }}</p>
                                </div>

                                <div class="grow basis-48 min-w-0">
                                    <p
                                        :class="[
                                            'text-sm font-semibold',
                                            day.status === 'skipped' ? 'text-mb-text-muted dark:text-mb-text-soft line-through' : 'text-mb-text-strong',
                                        ]"
                                    >
                                        {{ dayTitle(day) }}
                                    </p>
                                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">{{ dayNote(day) }}</p>
                                </div>

                                <span :class="['inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap', toneText[dayTag(day).tone]]">
                                    <span :class="['w-1.5 h-1.5 rounded-full shrink-0', toneDot[dayTag(day).tone]]" aria-hidden="true"></span>
                                    {{ dayTag(day).label }}
                                </span>

                                <template v-if="day.is_meal_day">
                                    <button
                                        v-if="day.status !== 'skipped'"
                                        @click="toggleDay(day)"
                                        class="h-9 px-4 rounded-xl bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition"
                                    >
                                        Skip
                                    </button>
                                    <button
                                        v-else-if="day.can_cancel"
                                        @click="toggleDay(day)"
                                        class="h-9 px-4 rounded-xl bg-mb-text-strong text-mb-page text-xs font-semibold cursor-pointer transition hover:opacity-90"
                                    >
                                        Take it back
                                    </button>
                                    <!-- HR and system skips are not the employee's to withdraw -->
                                    <span v-else class="text-[11px] italic text-mb-text-faint dark:text-mb-text-soft">Ask HR</span>
                                </template>
                            </li>
                        </ul>
                    </section>

                    <!-- Plan a skip: one card, three lengths -->
                    <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Plan a skip</h2>
                                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">A single day, a stretch of days, or every week.</p>
                            </div>

                            <div class="inline-flex gap-1 p-1 rounded-xl bg-mb-inset border border-mb-line">
                                <button
                                    v-for="mode in skipModes"
                                    :key="mode.key"
                                    type="button"
                                    @click="skipMode = mode.key"
                                    :aria-pressed="skipMode === mode.key"
                                    :class="[
                                        'h-8 px-4 rounded-lg text-xs font-semibold cursor-pointer transition',
                                        skipMode === mode.key
                                            ? 'bg-mb-card border border-mb-field text-mb-text-strong'
                                            : 'text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong',
                                    ]"
                                >
                                    {{ mode.label }}
                                </button>
                            </div>
                        </div>

                        <!-- One day -->
                        <form v-if="skipMode === 'day'" @submit.prevent="submitSkip" class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3.5 items-end">
                            <div class="space-y-1.5">
                                <label for="skip-date" class="block text-[11px] font-semibold text-mb-text-soft">Date</label>
                                <input
                                    id="skip-date"
                                    v-model="form.date"
                                    type="date"
                                    required
                                    class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label for="skip-reason" class="block text-[11px] font-semibold text-mb-text-soft">
                                    Reason <span class="font-normal text-mb-text-muted dark:text-mb-text-soft">(optional)</span>
                                </label>
                                <input
                                    id="skip-reason"
                                    v-model="form.reason"
                                    type="text"
                                    placeholder="Working from home"
                                    class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong placeholder:text-mb-text-faint dark:placeholder:text-mb-text-soft focus:outline-none focus:border-mb-tint-emerald"
                                />
                            </div>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="h-11 rounded-xl bg-mb-fill-emerald text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 text-sm font-semibold disabled:opacity-40 cursor-pointer transition hover:opacity-90"
                            >
                                Skip that day
                            </button>

                            <p v-if="form.errors.date" class="sm:col-span-3 text-xs text-mb-rose">{{ form.errors.date }}</p>
                            <p v-if="form.errors.skip" class="sm:col-span-3 text-xs text-mb-rose">{{ form.errors.skip }}</p>
                        </form>

                        <!-- Date range -->
                        <form v-else-if="skipMode === 'range'" @submit.prevent="submitRange" class="mt-5 space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3.5 items-end">
                                <div class="space-y-1.5">
                                    <label for="range-from" class="block text-[11px] font-semibold text-mb-text-soft">From</label>
                                    <input
                                        id="range-from"
                                        v-model="rangeForm.from_date"
                                        type="date"
                                        required
                                        class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label for="range-to" class="block text-[11px] font-semibold text-mb-text-soft">To</label>
                                    <input
                                        id="range-to"
                                        v-model="rangeForm.to_date"
                                        type="date"
                                        required
                                        class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label for="range-reason" class="block text-[11px] font-semibold text-mb-text-soft">
                                        Reason <span class="font-normal text-mb-text-muted dark:text-mb-text-soft">(optional)</span>
                                    </label>
                                    <input
                                        id="range-reason"
                                        v-model="rangeForm.reason"
                                        type="text"
                                        placeholder="Annual leave"
                                        class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong placeholder:text-mb-text-faint dark:placeholder:text-mb-text-soft focus:outline-none focus:border-mb-tint-emerald"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="rangeForm.processing"
                                    class="h-11 rounded-xl bg-mb-fill-emerald text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 text-sm font-semibold disabled:opacity-40 cursor-pointer transition hover:opacity-90"
                                >
                                    Skip the range
                                </button>
                            </div>

                            <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft">
                                Weekends and holidays inside the range are left alone. Up to 31 days at a time.
                            </p>

                            <p v-if="rangeForm.errors.from_date" class="text-xs text-mb-rose">{{ rangeForm.errors.from_date }}</p>
                            <p v-if="rangeForm.errors.to_date" class="text-xs text-mb-rose">{{ rangeForm.errors.to_date }}</p>
                            <p v-if="rangeForm.errors.skip" class="text-xs text-mb-rose">{{ rangeForm.errors.skip }}</p>
                        </form>

                        <!-- Every week -->
                        <form v-else @submit.prevent="submitRecurring" class="mt-5 space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3.5 items-end">
                                <div class="space-y-1.5">
                                    <label for="rule-weekday" class="block text-[11px] font-semibold text-mb-text-soft">Weekday</label>
                                    <SelectField
                                        id="rule-weekday"
                                        v-model="recurringForm.weekday"
                                        :options="weekdayOptions"
                                        size="lg"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label for="rule-starts" class="block text-[11px] font-semibold text-mb-text-soft">Starts on</label>
                                    <input
                                        id="rule-starts"
                                        v-model="recurringForm.starts_on"
                                        type="date"
                                        required
                                        class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label for="rule-ends" class="block text-[11px] font-semibold text-mb-text-soft">
                                        Ends on <span class="font-normal text-mb-text-muted dark:text-mb-text-soft">(optional)</span>
                                    </label>
                                    <input
                                        id="rule-ends"
                                        v-model="recurringForm.ends_on"
                                        type="date"
                                        class="w-full h-11 px-3 rounded-xl bg-mb-inset border border-mb-field text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="recurringForm.processing"
                                    class="h-11 rounded-xl bg-mb-fill-emerald text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 text-sm font-semibold disabled:opacity-40 cursor-pointer transition hover:opacity-90"
                                >
                                    Add the rule
                                </button>
                            </div>

                            <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft">
                                Every matching weekday is skipped from the start date until you pause or delete the rule.
                            </p>

                            <p v-if="recurringForm.errors.weekday" class="text-xs text-mb-rose">{{ recurringForm.errors.weekday }}</p>
                            <p v-if="recurringForm.errors.starts_on" class="text-xs text-mb-rose">{{ recurringForm.errors.starts_on }}</p>
                        </form>
                    </section>
                </div>

                <!-- ── Side column ── -->
                <div class="grow basis-80 min-w-0 space-y-5">

                    <!-- Everything that has to be true for a meal to arrive -->
                    <section class="bg-mb-fill-emerald text-mb-on-fill-soft border border-transparent dark:border-mb-tint-emerald/50 rounded-2xl p-6">
                        <h2 class="text-base font-bold text-mb-on-fill">
                            {{ allChecksOk ? 'Everything checks out' : 'One thing needs attention' }}
                        </h2>
                        <p class="text-xs mt-1">What has to be true for you to eat today.</p>

                        <ul class="mt-4 space-y-2.5">
                            <li
                                v-for="check in checks"
                                :key="check.label"
                                class="flex items-center gap-3 p-3 rounded-2xl bg-white/12"
                            >
                                <div class="grow min-w-0">
                                    <p class="text-[13px] font-semibold text-mb-on-fill">{{ check.label }}</p>
                                    <p class="text-[11px] mt-0.5">{{ check.note }}</p>
                                </div>

                                <svg v-if="check.ok" class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" role="img" aria-label="Yes">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.5 3 3 5-6.5" />
                                </svg>
                                <svg v-else class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" role="img" aria-label="No">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="m9 9 6 6M15 9l-6 6" />
                                </svg>
                            </li>
                        </ul>
                    </section>

                    <!-- Skips still ahead -->
                    <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Skips coming up</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">Days you are already opted out of.</p>

                        <ul class="mt-4 divide-y divide-mb-line">
                            <li v-for="skip in upcomingSkips" :key="skip.id" class="flex items-center gap-3 py-3">
                                <div class="grow min-w-0">
                                    <p class="text-[13px] font-semibold text-mb-text-strong">
                                        {{ formatDate(skip.date, { weekday: 'short', day: 'numeric', month: 'short' }) }}
                                    </p>
                                    <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-0.5 truncate">
                                        {{ skip.reason || 'No reason given' }}
                                    </p>
                                </div>

                                <span :class="['text-[11px] font-semibold whitespace-nowrap', toneText[skipTone(skip.source)]]">
                                    {{ skipLabel(skip.source) }}
                                </span>

                                <button
                                    v-if="['self', 'recurring'].includes(skip.source)"
                                    @click="cancelSkip(skip.id)"
                                    :aria-label="`Cancel the skip on ${skip.date}`"
                                    class="w-8 h-8 shrink-0 rounded-lg border border-mb-field text-mb-text-muted dark:text-mb-text-soft hover:text-mb-rose hover:border-mb-tint-rose/40 flex items-center justify-center cursor-pointer transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                                    </svg>
                                </button>
                            </li>
                        </ul>

                        <p v-if="! upcomingSkips.length" class="text-xs italic text-mb-text-faint dark:text-mb-text-soft mt-3">
                            Nothing coming up — you are eating in every meal day ahead.
                        </p>
                    </section>

                    <!-- Recurring rules -->
                    <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Repeating skips</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">Weekdays you never eat in.</p>

                        <ul class="mt-4 space-y-2.5">
                            <li
                                v-for="rule in my_recurring_rules"
                                :key="rule.id"
                                class="p-3.5 rounded-2xl bg-mb-inset border border-mb-line"
                            >
                                <div class="flex items-center justify-between gap-2.5">
                                    <p :class="['text-[13px] font-semibold', rule.active ? 'text-mb-text-strong' : 'text-mb-text-muted dark:text-mb-text-soft']">
                                        Every {{ weekdaysMap[rule.weekday] }}
                                    </p>
                                    <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold', rule.active ? toneText.emerald : toneText.muted]">
                                        <span :class="['w-1.5 h-1.5 rounded-full shrink-0', rule.active ? toneDot.emerald : toneDot.muted]" aria-hidden="true"></span>
                                        {{ rule.active ? 'Active' : 'Paused' }}
                                    </span>
                                </div>

                                <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-1.5">
                                    From {{ formatDate(rule.starts_on, { day: 'numeric', month: 'short', year: 'numeric' }) }}
                                    <template v-if="rule.ends_on">
                                        to {{ formatDate(rule.ends_on, { day: 'numeric', month: 'short', year: 'numeric' }) }}
                                    </template>
                                    <template v-else>· no end date</template>
                                </p>

                                <div class="flex gap-2 mt-3">
                                    <button
                                        @click="toggleRule(rule)"
                                        class="h-8 px-3 rounded-lg bg-mb-card border border-mb-field text-[11px] font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition"
                                    >
                                        {{ rule.active ? 'Pause' : 'Resume' }}
                                    </button>
                                    <button
                                        @click="deleteRule(rule.id)"
                                        class="h-8 px-3 rounded-lg bg-mb-card border border-mb-tint-rose/40 text-[11px] font-semibold text-mb-rose hover:bg-mb-tint-rose/10 cursor-pointer transition"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </li>
                        </ul>

                        <p v-if="! my_recurring_rules || ! my_recurring_rules.length" class="text-xs italic text-mb-text-faint dark:text-mb-text-soft mt-3">
                            No repeating skips yet.
                        </p>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
