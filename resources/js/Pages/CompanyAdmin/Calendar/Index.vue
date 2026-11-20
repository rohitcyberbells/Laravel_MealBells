<script setup>
import { computed, ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import SelectField from '../../../Layouts/SelectField.vue';

// Both forms set the same two kinds of day, so they read from one list.
const calendarTypes = [
    { value: 'holiday', label: 'Company holiday' },
    { value: 'working_day', label: 'Working day' },
];

// A mistyped year must not post thousands of writes in one request.
const MAX_RANGE_DAYS = 366;

const props = defineProps({
    month: String,
    days: Array,
    meal_days: Array,
    today: String,
});

const form = useForm({
    date: '',
    type: 'holiday',
    note: '',
});

// A separate form object, so a failed range does not put its errors on the
// single-day form and leave the wrong field looking wrong.
const bulkForm = useForm({
    dates: [],
    type: 'holiday',
    note: '',
});

const bulkStart = ref('');
const bulkEnd = ref('');

/*
 * Every date in the chosen range, inclusive.
 *
 * Built from the yyyy-mm-dd strings with Date.UTC rather than new Date(string)
 * in local time: a range crossing a daylight-saving change would otherwise skip
 * or repeat a day, and India not having DST is no reason to write it wrongly.
 */
const bulkDates = computed(() => {
    if (!bulkStart.value || !bulkEnd.value || bulkEnd.value < bulkStart.value) {
        return [];
    }

    const dates = [];
    const [ys, ms, ds] = bulkStart.value.split('-').map(Number);
    const cursor = new Date(Date.UTC(ys, ms - 1, ds));
    const last = bulkEnd.value;

    while (dates.length <= MAX_RANGE_DAYS) {
        const iso = cursor.toISOString().slice(0, 10);
        dates.push(iso);

        if (iso >= last) {
            break;
        }

        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return dates;
});


// Days the server will pass over: it refuses anything already past, and the
// range form would otherwise report "Updated 3 days" after you picked ten.
const bulkSkipped = computed(
    () => bulkDates.value.filter((date) => date < props.today).length,
);

const bulkEditable = computed(() => bulkDates.value.length - bulkSkipped.value);

const bulkTooLong = computed(() => bulkDates.value.length > MAX_RANGE_DAYS);

const canSubmitBulk = computed(
    () => bulkEditable.value > 0 && !bulkTooLong.value && !bulkForm.processing,
);

const selectedMonth = ref(props.month);

const weekdaysMap = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};

const changeMonth = () => {
    router.get('/company-admin/calendar', { month: selectedMonth.value }, { preserveState: true });
};

const saveDay = () => {
    form.post('/company-admin/calendar', {
        onSuccess: () => {
            form.reset('date', 'note');
        },
    });
};

const saveBulk = () => {
    // Only the days the server can act on. Sending the past ones as well would
    // work - it skips them - but then the count it reports back would not match
    // what this screen said it was about to do.
    bulkForm
        .transform((data) => ({
            ...data,
            dates: bulkDates.value.filter((date) => date >= props.today),
        }))
        .post('/company-admin/calendar/bulk', {
            onSuccess: () => {
                bulkStart.value = '';
                bulkEnd.value = '';
                bulkForm.reset('note');
            },
        });
};

const removeDay = (dayId) => {
    router.delete(`/company-admin/calendar/${dayId}`);
};
</script>

<template>
    <AppLayout>
        <div class="p-4 sm:p-6 space-y-5">
            <!-- A page heading, not a card: it holds a title and one control. -->
            <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">Calendar</h1>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                        Holidays and compensatory working days that override the normal week.
                    </p>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <label for="calendar-month" class="text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft">Month</label>
                    <input
                        id="calendar-month"
                        v-model="selectedMonth"
                        type="month"
                        @change="changeMonth"
                        class="h-10 px-3 bg-mb-card border border-mb-field rounded-lg text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-emerald"
                    />
                </div>
            </header>

            <!--
                One day and a run of days are the same job at two lengths, so
                they sit side by side rather than one under the other. The
                split waits for xl: at lg each of these forms would be holding
                four fields in half a page.
            -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 items-start">

                <!-- Set Calendar Day Form Card -->
                <div class="min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 space-y-4">
                    <div>
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">One day</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Add a single override, or change one that is already set.
                        </p>
                    </div>

                    <form @submit.prevent="saveDay" class="flex flex-wrap items-end gap-3">
                        <div class="w-40 shrink-0">
                            <label for="day-date" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Date</label>
                            <input
                                id="day-date"
                                v-model="form.date"
                                type="date"
                                required
                                class="w-full h-10 px-3 bg-mb-inset border border-mb-field rounded-lg text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-emerald"
                            />
                        </div>
                        <div class="w-44 shrink-0">
                            <label for="day-type" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Type</label>
                            <SelectField id="day-type" v-model="form.type" :options="calendarTypes" aria-label="Override type" />
                        </div>
                        <div class="grow basis-48 min-w-0">
                            <label for="day-note" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Note</label>
                            <input
                                id="day-note"
                                v-model="form.note"
                                type="text"
                                placeholder="Diwali, Independence Day"
                                class="w-full h-10 px-3 bg-mb-inset border border-mb-field rounded-lg text-mb-text-strong text-sm placeholder:text-mb-text-muted focus:outline-none focus:border-mb-tint-emerald"
                            />
                        </div>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="h-10 px-4 shrink-0 rounded-xl bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 text-mb-on-fill text-sm font-semibold disabled:opacity-40 cursor-pointer transition"
                        >
                            Save
                        </button>
                    </form>
                </div>

            <!-- Mark a whole range at once. The single form above is one day;
                 a festival week or a shutdown is the common case and was
                 fifteen clicks. -->
            <div class="min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 space-y-4">
                <div>
                    <h2 class="text-base font-bold tracking-tight text-mb-text-strong">A range of days</h2>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                        Every day between the two dates, inclusive — a shutdown week, a festival, or a
                        run of compensatory working days.
                    </p>
                </div>

                <form @submit.prevent="saveBulk" class="flex flex-wrap items-end gap-3">
                    <div class="w-36 shrink-0">
                        <label for="range-from" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">From</label>
                        <input
                            id="range-from"
                            v-model="bulkStart"
                            type="date"
                            :min="today"
                            required
                            class="w-full h-10 px-3 bg-mb-inset border border-mb-field rounded-lg text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-emerald"
                        />
                    </div>
                    <div class="w-36 shrink-0">
                        <label for="range-to" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">To</label>
                        <input
                            id="range-to"
                            v-model="bulkEnd"
                            type="date"
                            :min="bulkStart || today"
                            required
                            class="w-full h-10 px-3 bg-mb-inset border border-mb-field rounded-lg text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-emerald"
                        />
                    </div>
                    <div class="w-44 shrink-0">
                        <label for="range-type" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Type</label>
                        <SelectField id="range-type" v-model="bulkForm.type" :options="calendarTypes" aria-label="Override type for the range" />
                    </div>
                    <div class="grow basis-40 min-w-0">
                        <label for="range-note" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Note</label>
                        <input
                            id="range-note"
                            v-model="bulkForm.note"
                            type="text"
                            placeholder="Diwali week"
                            class="w-full h-10 px-3 bg-mb-inset border border-mb-field rounded-lg text-mb-text-strong text-sm placeholder:text-mb-text-muted focus:outline-none focus:border-mb-tint-emerald"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="!canSubmitBulk"
                        class="h-10 px-4 shrink-0 rounded-xl bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 text-mb-on-fill text-sm font-semibold disabled:opacity-40 disabled:cursor-default cursor-pointer transition"
                    >
                        {{ bulkForm.processing ? 'Saving…' : `Mark ${bulkEditable || ''} ${bulkEditable === 1 ? 'day' : 'days'}`.trim() }}
                    </button>
                </form>

                <!-- Said before the click, not after. The server passes over
                     days it can no longer edit, so a range of ten reporting
                     "Updated 3 days" would read as a failure. -->
                <div v-if="bulkDates.length" class="text-xs space-y-1">
                    <p v-if="bulkTooLong" class="text-mb-rose-soft font-semibold">
                        That range is longer than {{ MAX_RANGE_DAYS }} days. Narrow it — this writes one
                        row per day, and a mistyped year should not become thousands of them.
                    </p>

                    <template v-else>
                        <p class="text-mb-text-soft">
                            <span class="font-semibold text-mb-text-strong">{{ bulkEditable }}</span>
                            {{ bulkEditable === 1 ? 'day' : 'days' }} will be marked as
                            {{ bulkForm.type === 'holiday' ? 'a company holiday' : 'a working day' }}.
                        </p>
                        <p v-if="bulkSkipped" class="text-mb-amber">
                            {{ bulkSkipped }} {{ bulkSkipped === 1 ? 'day is' : 'days are' }} already past and
                            will be left alone — a day that has been counted cannot be rewritten.
                        </p>
                        <p v-if="bulkEditable === 0" class="text-mb-amber">
                            Nothing in that range can still be edited.
                        </p>
                    </template>
                </div>

                <p v-if="bulkForm.errors.calendar" class="text-xs text-mb-rose-soft">
                    {{ bulkForm.errors.calendar }}
                </p>
                <p v-if="bulkForm.errors.dates" class="text-xs text-mb-rose-soft">
                    {{ bulkForm.errors.dates }}
                </p>
                </div>
            </div>

            <!-- Overrides Table -->
            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-mb-line flex justify-between items-center">
                    <h3 class="text-sm font-bold text-mb-text-strong">Active Calendar Overrides for {{ month }}</h3>
                    <span class="text-xs text-mb-text-muted dark:text-mb-text-soft">{{ days.length }} Overrides</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-mb-inset border-b border-mb-line text-mb-text-muted dark:text-mb-text-soft uppercase font-semibold">
                            <tr>
                                <th class="p-3">Date</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Note</th>
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line/60">
                            <tr v-for="day in days" :key="day.id" class="hover:bg-mb-raised/40">
                                <td class="p-3 font-bold font-mono text-mb-cyan">{{ day.date }}</td>
                                <td class="p-3">
                                    <span :class="[
                                        'inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap',
                                        day.type === 'holiday' ? 'text-mb-rose dark:text-mb-rose-soft' : 'text-mb-emerald'
                                    ]">
                                        <span
                                            :class="['w-1.5 h-1.5 shrink-0 rounded-full', day.type === 'holiday' ? 'bg-mb-rose' : 'bg-mb-emerald']"
                                            aria-hidden="true"
                                        ></span>
                                        {{ day.type === 'holiday' ? 'Holiday' : 'Special working day' }}
                                    </span>
                                </td>
                                <td class="p-3 text-mb-text-soft">{{ day.note || '-' }}</td>
                                <td class="p-3 text-right">
                                    <button
                                        @click="removeDay(day.id)"
                                        class="text-mb-rose dark:text-mb-rose-soft hover:text-mb-rose-soft font-semibold cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!days || !days.length">
                                <td colspan="4" class="p-8 text-center text-mb-text-faint dark:text-mb-text-soft">
                                    No custom calendar overrides configured for this month.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
