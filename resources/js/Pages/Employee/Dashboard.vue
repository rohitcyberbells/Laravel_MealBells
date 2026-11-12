<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

const props = defineProps({
    today: Object,
    next_7_days: Array,
    my_skips: Array,
    my_recurring_rules: Array,
    active_tiffin_assigned: Boolean,
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

// A single date is the common case, so the range form starts closed.
const showRangeForm = ref(false);

const submitRecurring = () => {
    recurringForm.post('/employee/recurring-skips', {
        onSuccess: () => recurringForm.reset(),
    });
};

// The state is sent explicitly, because the endpoint requires it: a bare patch
// failed validation, so pausing a rule never actually did anything.
const toggleRule = (rule) => {
    router.patch(`/employee/recurring-skips/${rule.id}`, { active: !rule.active }, { preserveScroll: true });
};

const deleteRule = (ruleId) => {
    router.delete(`/employee/recurring-skips/${ruleId}`);
};

// today.seconds_left was already supplied by the controller and never used.
// Floored once, here: the server's value is a float, and `float % 60` renders
// the seconds with their fraction attached.
const remaining = ref(Math.max(0, Math.floor(props.today.seconds_left ?? 0)));

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

// One tap per day in the strip: skip it, or take it back when the engine allows.
const toggleDay = (day) => {
    if (day.status === 'skipped') {
        if (day.can_cancel) router.delete(`/employee/skips/${day.skip_id}`, { preserveScroll: true });
        return;
    }
    router.post('/employee/skips', { date: day.date }, { preserveScroll: true });
};

const cancelSkip = (skipId) => {
    router.delete(`/employee/skips/${skipId}`);
};

</script>

<template>
    <AppLayout>
    <div class="p-4 sm:p-6 space-y-6">
        <!-- Top Navbar -->

        <main class="max-w-5xl mx-auto space-y-6">
            <!-- Today's Status Card -->
            <div class="bg-mb-card border border-mb-line p-6 rounded-2xl shadow-xl space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-mb-cyan">Today's Preference ({{ today.date }})</h2>
                    <span :class="[
                        'px-3 py-1 rounded-full text-xs font-bold border',
                        today.status === 'skipped' ? 'bg-mb-tint-rose/20 text-mb-rose-soft border-mb-tint-rose/30' : 'bg-mb-tint-emerald/20 text-mb-emerald-soft border-mb-tint-emerald/30'
                    ]">
                        {{ today.status === 'skipped' ? '🚫 Skipped' : '🍽️ Receiving Meal' }}
                    </span>
                </div>

                <p class="text-xs text-mb-text-muted">
                    Cutoff {{ today.cutoff_time }}
                    <span v-if="countdown" class="text-mb-cyan font-mono font-semibold">· {{ countdown }} left to change today</span>
                    <span v-else class="text-mb-amber font-semibold">· cutoff passed, today is fixed</span>
                </p>

                <p v-if="today.meal" class="text-sm font-semibold text-mb-text-strong">
                    Scheduled Meal: <span class="text-mb-emerald-soft">{{ today.meal }}</span>
                </p>

                <div v-if="today.can_cancel" class="pt-2">
                    <button @click="cancelSkip(today.skip_id)" class="px-4 py-2 bg-mb-tint-rose/20 hover:bg-mb-tint-rose/30 text-mb-rose-soft border border-mb-tint-rose/40 rounded-xl text-xs font-bold transition cursor-pointer">
                        Cancel Skip for Today
                    </button>
                </div>
            </div>

            <!-- This week at a glance. next_7_days was already supplied and
                 rendered nowhere. -->
            <div class="bg-mb-card border border-mb-line p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-mb-text-strong">📅 The week ahead</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
                    <div
                        v-for="day in next_7_days"
                        :key="day.date"
                        :class="[
                            'p-3 rounded-xl border text-center space-y-1',
                            !day.is_meal_day
                                ? 'bg-mb-inset border-mb-line opacity-60'
                                : day.status === 'skipped'
                                    ? 'bg-mb-tint-rose/10 border-mb-tint-rose/30'
                                    : 'bg-mb-tint-emerald/10 border-mb-tint-emerald/30'
                        ]"
                    >
                        <p class="text-[11px] font-bold uppercase text-mb-text-muted">{{ day.day_name.slice(0, 3) }}</p>
                        <p class="text-xs font-mono text-mb-text-faint">{{ day.date.slice(5) }}</p>

                        <template v-if="day.is_meal_day">
                            <p :class="['text-[11px] font-bold', day.status === 'skipped' ? 'text-mb-rose-soft' : 'text-mb-emerald-soft']">
                                {{ day.status === 'skipped' ? 'Skipped' : 'Taking' }}
                            </p>
                            <p v-if="day.skip_source" class="text-[10px] text-mb-text-faint capitalize">{{ day.skip_source }}</p>

                            <button
                                v-if="day.status !== 'skipped'"
                                @click="toggleDay(day)"
                                class="w-full px-2 py-1 rounded-lg text-[10px] font-bold text-mb-amber-soft bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 border border-mb-tint-amber/30 cursor-pointer"
                            >
                                Skip
                            </button>
                            <button
                                v-else-if="day.can_cancel"
                                @click="toggleDay(day)"
                                class="w-full px-2 py-1 rounded-lg text-[10px] font-bold text-mb-emerald-soft bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 border border-mb-tint-emerald/30 cursor-pointer"
                            >
                                Take it back
                            </button>
                            <!-- HR and system skips are not the employee's to withdraw -->
                            <p v-else class="text-[10px] text-mb-text-faint italic">Ask HR</p>
                        </template>
                        <p v-else class="text-[10px] text-mb-text-faint italic">
                            {{ day.calendar_day?.type === 'holiday' ? 'Holiday' : 'No meal' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Single Skip Quick Form -->
            <div class="bg-mb-card border border-mb-line p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-mb-text-strong">➕ Quick Skip Single Meal</h3>
                <form @submit.prevent="submitSkip" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-mb-text-muted mb-1">Date</label>
                        <input v-model="form.date" type="date" class="w-full px-3 py-2 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-mb-text-muted mb-1">Reason (Optional)</label>
                        <input v-model="form.reason" type="text" placeholder="e.g. Personal Leave" class="w-full px-3 py-2 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" />
                    </div>
                    <div>
                        <button type="submit" :disabled="form.processing" class="w-full px-4 py-2 bg-mb-tint-cyan hover:bg-mb-cyan text-mb-on-accent font-bold text-xs rounded-xl transition cursor-pointer">
                            Skip Meal
                        </button>
                    </div>
                </form>

                <p v-if="form.errors.skip" class="text-xs text-mb-rose">{{ form.errors.skip }}</p>

                <div class="pt-2 border-t border-mb-line">
                    <button
                        @click="showRangeForm = !showRangeForm"
                        class="text-xs font-semibold text-mb-cyan hover:text-mb-cyan-soft cursor-pointer"
                    >
                        {{ showRangeForm ? '− Hide date range' : '📅 Away for several days? Skip a date range' }}
                    </button>
                </div>

                <form v-if="showRangeForm" @submit.prevent="submitRange" class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-semibold text-mb-text-muted mb-1">From</label>
                            <input v-model="rangeForm.from_date" type="date" required class="w-full px-3 py-2 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-mb-text-muted mb-1">To</label>
                            <input v-model="rangeForm.to_date" type="date" required class="w-full px-3 py-2 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-mb-text-muted mb-1">Reason (Optional)</label>
                            <input v-model="rangeForm.reason" type="text" placeholder="e.g. Annual Leave" class="w-full px-3 py-2 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" />
                        </div>
                        <div>
                            <button type="submit" :disabled="rangeForm.processing" class="w-full px-4 py-2 bg-mb-tint-cyan hover:bg-mb-cyan disabled:opacity-40 text-mb-on-accent font-bold text-xs rounded-xl transition cursor-pointer">
                                Skip Range
                            </button>
                        </div>
                    </div>

                    <p class="text-[10px] text-mb-text-faint">
                        Only meal days in the range are skipped — weekends and holidays are left alone. Up to 31 days at a time.
                    </p>

                    <p v-if="rangeForm.errors.from_date" class="text-xs text-mb-rose">{{ rangeForm.errors.from_date }}</p>
                    <p v-if="rangeForm.errors.to_date" class="text-xs text-mb-rose">{{ rangeForm.errors.to_date }}</p>
                    <p v-if="rangeForm.errors.skip" class="text-xs text-mb-rose">{{ rangeForm.errors.skip }}</p>
                </form>
            </div>

            <!-- 🔄 Recurring Skip Rules Section -->
            <div class="bg-mb-card border border-mb-line p-6 rounded-2xl shadow-xl space-y-6">
                <h3 class="text-base font-bold text-mb-amber flex items-center gap-2">
                    🔄 My Recurring Skip Rules
                </h3>

                <!-- Form to Add Rule -->
                <form @submit.prevent="submitRecurring" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end bg-mb-inset p-4 rounded-xl border border-mb-line">
                    <div>
                        <label class="block text-xs font-semibold text-mb-text-muted mb-1">Weekday</label>
                        <select v-model="recurringForm.weekday" class="w-full px-3 py-2 bg-mb-card border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan">
                            <option v-for="(name, num) in weekdaysMap" :key="num" :value="Number(num)">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-mb-text-muted mb-1">Starts On</label>
                        <input v-model="recurringForm.starts_on" type="date" class="w-full px-3 py-2 bg-mb-card border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-mb-text-muted mb-1">Ends On (Optional)</label>
                        <input v-model="recurringForm.ends_on" type="date" class="w-full px-3 py-2 bg-mb-card border border-mb-line rounded-xl text-mb-text-strong text-xs focus:outline-none focus:border-mb-tint-cyan" />
                    </div>
                    <div>
                        <button type="submit" :disabled="recurringForm.processing" class="w-full px-4 py-2 bg-mb-tint-amber hover:bg-mb-amber text-mb-on-accent font-bold text-xs rounded-xl transition cursor-pointer">
                            Add Rule
                        </button>
                    </div>
                </form>

                <!-- Rules List -->
                <div class="space-y-3">
                    <div v-for="rule in my_recurring_rules" :key="rule.id" class="flex items-center justify-between p-3.5 bg-mb-inset border border-mb-line rounded-xl">
                        <div>
                            <span class="font-bold text-mb-text-strong text-sm">Every {{ weekdaysMap[rule.weekday] }}</span>
                            <span class="text-xs text-mb-text-muted ml-2">(Starts: {{ rule.starts_on }} {{ rule.ends_on ? 'Ends: ' + rule.ends_on : 'Indefinite' }})</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button @click="toggleRule(rule)" :title="rule.active ? 'Pause this rule' : 'Resume this rule'" :class="['px-3 py-1 rounded-lg text-xs font-semibold border cursor-pointer', rule.active ? 'bg-mb-tint-emerald/20 text-mb-emerald-soft border-mb-tint-emerald/30' : 'bg-mb-raised text-mb-text-muted border-mb-line-mid']">
                                {{ rule.active ? 'Active' : 'Paused' }}
                            </button>
                            <button @click="deleteRule(rule.id)" class="px-3 py-1 bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border border-mb-tint-rose/30 rounded-lg text-xs font-semibold">
                                Delete
                            </button>
                        </div>
                    </div>

                    <p v-if="!my_recurring_rules || !my_recurring_rules.length" class="text-xs text-mb-text-faint italic">
                        No recurring skip rules active.
                    </p>
                </div>
            </div>
        </main>
    </div>
    </AppLayout>
</template>
