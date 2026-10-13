<script setup>
import { computed, ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
                <div>
                    <h1 class="text-2xl font-black text-white flex items-center gap-2">
                        📅 Company Calendar & Holidays
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Override default working days with company holidays or compensatory working days
                    </p>
                </div>

                <div class="flex items-center space-x-3">
                    <label class="text-xs font-semibold text-slate-400">Select Month:</label>
                    <input
                        v-model="selectedMonth"
                        type="month"
                        @change="changeMonth"
                        class="px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                    />
                </div>
            </div>

            <!-- Set Calendar Day Form Card -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-slate-200">➕ Add / Update Calendar Override</h3>
                <form @submit.prevent="saveDay" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Date</label>
                        <input
                            v-model="form.date"
                            type="date"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Type</label>
                        <select
                            v-model="form.type"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        >
                            <option value="holiday">🔴 Company Holiday</option>
                            <option value="working_day">🟢 Working Day (Compensatory)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Note / Reason</label>
                        <input
                            v-model="form.note"
                            type="text"
                            placeholder="e.g. Diwali, Independence Day"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        />
                    </div>
                    <div>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-bold text-xs rounded-xl shadow-md transition disabled:opacity-50 cursor-pointer"
                        >
                            Save Override
                        </button>
                    </div>
                </form>
            </div>

            <!-- Mark a whole range at once. The single form above is one day;
                 a festival week or a shutdown is the common case and was
                 fifteen clicks. -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-200">🗓️ Mark a range of days</h3>
                    <p class="text-xs text-slate-400 mt-1">
                        Every day between the two dates, inclusive — a shutdown week, a festival, or a
                        run of compensatory working days.
                    </p>
                </div>

                <form @submit.prevent="saveBulk" class="grid grid-cols-1 sm:grid-cols-5 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">From</label>
                        <input
                            v-model="bulkStart"
                            type="date"
                            :min="today"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">To</label>
                        <input
                            v-model="bulkEnd"
                            type="date"
                            :min="bulkStart || today"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Type</label>
                        <select
                            v-model="bulkForm.type"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        >
                            <option value="holiday">🔴 Company Holiday</option>
                            <option value="working_day">🟢 Working Day (Compensatory)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Note / Reason</label>
                        <input
                            v-model="bulkForm.note"
                            type="text"
                            placeholder="e.g. Diwali week"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        />
                    </div>
                    <div>
                        <button
                            type="submit"
                            :disabled="!canSubmitBulk"
                            class="w-full px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-bold text-xs rounded-xl shadow-md transition disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                        >
                            {{ bulkForm.processing ? 'Saving…' : `Mark ${bulkEditable || ''} ${bulkEditable === 1 ? 'day' : 'days'}`.trim() }}
                        </button>
                    </div>
                </form>

                <!-- Said before the click, not after. The server passes over
                     days it can no longer edit, so a range of ten reporting
                     "Updated 3 days" would read as a failure. -->
                <div v-if="bulkDates.length" class="text-xs space-y-1">
                    <p v-if="bulkTooLong" class="text-red-300 font-semibold">
                        That range is longer than {{ MAX_RANGE_DAYS }} days. Narrow it — this writes one
                        row per day, and a mistyped year should not become thousands of them.
                    </p>

                    <template v-else>
                        <p class="text-slate-300">
                            <span class="font-semibold text-white">{{ bulkEditable }}</span>
                            {{ bulkEditable === 1 ? 'day' : 'days' }} will be marked as
                            {{ bulkForm.type === 'holiday' ? 'a company holiday' : 'a working day' }}.
                        </p>
                        <p v-if="bulkSkipped" class="text-amber-400">
                            {{ bulkSkipped }} {{ bulkSkipped === 1 ? 'day is' : 'days are' }} already past and
                            will be left alone — a day that has been counted cannot be rewritten.
                        </p>
                        <p v-if="bulkEditable === 0" class="text-amber-400">
                            Nothing in that range can still be edited.
                        </p>
                    </template>
                </div>

                <p v-if="bulkForm.errors.calendar" class="text-xs text-red-300">
                    {{ bulkForm.errors.calendar }}
                </p>
                <p v-if="bulkForm.errors.dates" class="text-xs text-red-300">
                    {{ bulkForm.errors.dates }}
                </p>
            </div>

            <!-- Overrides Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <div class="p-4 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-white">Active Calendar Overrides for {{ month }}</h3>
                    <span class="text-xs text-slate-400">{{ days.length }} Overrides</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-semibold">
                            <tr>
                                <th class="p-3">Date</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Note</th>
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="day in days" :key="day.id" class="hover:bg-slate-800/40">
                                <td class="p-3 font-bold font-mono text-cyan-400">{{ day.date }}</td>
                                <td class="p-3">
                                    <span :class="[
                                        'px-2.5 py-1 rounded-full text-[10px] font-bold border',
                                        day.type === 'holiday' ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                                    ]">
                                        {{ day.type === 'holiday' ? '🔴 Holiday' : '🟢 Special Working Day' }}
                                    </span>
                                </td>
                                <td class="p-3 text-slate-300">{{ day.note || '-' }}</td>
                                <td class="p-3 text-right">
                                    <button
                                        @click="removeDay(day.id)"
                                        class="text-red-400 hover:text-red-300 font-semibold cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!days || !days.length">
                                <td colspan="4" class="p-8 text-center text-slate-500">
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
