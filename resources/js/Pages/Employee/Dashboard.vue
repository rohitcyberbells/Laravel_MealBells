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

const submitRecurring = () => {
    recurringForm.post('/employee/recurring-skips', {
        onSuccess: () => recurringForm.reset(),
    });
};

const toggleRule = (ruleId) => {
    router.patch(`/employee/recurring-skips/${ruleId}`);
};

const deleteRule = (ruleId) => {
    router.delete(`/employee/recurring-skips/${ruleId}`);
};

// today.seconds_left was already supplied by the controller and never used.
const remaining = ref(props.today.seconds_left ?? 0);

const ticker = setInterval(() => {
    if (remaining.value > 0) remaining.value -= 1;
}, 1000);

onUnmounted(() => clearInterval(ticker));

const countdown = computed(() => {
    if (remaining.value <= 0) return null;
    const h = Math.floor(remaining.value / 3600);
    const m = Math.floor((remaining.value % 3600) / 60);
    const s = remaining.value % 60;
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
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-cyan-400">Today's Preference ({{ today.date }})</h2>
                    <span :class="[
                        'px-3 py-1 rounded-full text-xs font-bold border',
                        today.status === 'skipped' ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                    ]">
                        {{ today.status === 'skipped' ? '🚫 Skipped' : '🍽️ Receiving Meal' }}
                    </span>
                </div>

                <p class="text-xs text-slate-400">
                    Cutoff {{ today.cutoff_time }}
                    <span v-if="countdown" class="text-cyan-400 font-mono font-semibold">· {{ countdown }} left to change today</span>
                    <span v-else class="text-amber-400 font-semibold">· cutoff passed, today is fixed</span>
                </p>

                <p v-if="today.meal" class="text-sm font-semibold text-white">
                    Scheduled Meal: <span class="text-emerald-300">{{ today.meal }}</span>
                </p>

                <div v-if="today.can_cancel" class="pt-2">
                    <button @click="cancelSkip(today.skip_id)" class="px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/40 rounded-xl text-xs font-bold transition cursor-pointer">
                        Cancel Skip for Today
                    </button>
                </div>
            </div>

            <!-- This week at a glance. next_7_days was already supplied and
                 rendered nowhere. -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-white">📅 The week ahead</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
                    <div
                        v-for="day in next_7_days"
                        :key="day.date"
                        :class="[
                            'p-3 rounded-xl border text-center space-y-1',
                            !day.is_meal_day
                                ? 'bg-slate-950 border-slate-800 opacity-60'
                                : day.status === 'skipped'
                                    ? 'bg-red-500/10 border-red-500/30'
                                    : 'bg-emerald-500/10 border-emerald-500/30'
                        ]"
                    >
                        <p class="text-[11px] font-bold uppercase text-slate-400">{{ day.day_name.slice(0, 3) }}</p>
                        <p class="text-xs font-mono text-slate-500">{{ day.date.slice(5) }}</p>

                        <template v-if="day.is_meal_day">
                            <p :class="['text-[11px] font-bold', day.status === 'skipped' ? 'text-red-300' : 'text-emerald-300']">
                                {{ day.status === 'skipped' ? 'Skipped' : 'Taking' }}
                            </p>
                            <p v-if="day.skip_source" class="text-[10px] text-slate-500 capitalize">{{ day.skip_source }}</p>

                            <button
                                v-if="day.status !== 'skipped'"
                                @click="toggleDay(day)"
                                class="w-full px-2 py-1 rounded-lg text-[10px] font-bold text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 cursor-pointer"
                            >
                                Skip
                            </button>
                            <button
                                v-else-if="day.can_cancel"
                                @click="toggleDay(day)"
                                class="w-full px-2 py-1 rounded-lg text-[10px] font-bold text-emerald-300 bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 cursor-pointer"
                            >
                                Take it back
                            </button>
                            <!-- HR and system skips are not the employee's to withdraw -->
                            <p v-else class="text-[10px] text-slate-500 italic">Ask HR</p>
                        </template>
                        <p v-else class="text-[10px] text-slate-500 italic">
                            {{ day.calendar_day?.type === 'holiday' ? 'Holiday' : 'No meal' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Single Skip Quick Form -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-white">➕ Quick Skip Single Meal</h3>
                <form @submit.prevent="submitSkip" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Date</label>
                        <input v-model="form.date" type="date" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Reason (Optional)</label>
                        <input v-model="form.reason" type="text" placeholder="e.g. Personal Leave" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" />
                    </div>
                    <div>
                        <button type="submit" :disabled="form.processing" class="w-full px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-bold text-xs rounded-xl transition cursor-pointer">
                            Skip Meal
                        </button>
                    </div>
                </form>
            </div>

            <!-- 🔄 Recurring Skip Rules Section -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-6">
                <h3 class="text-base font-bold text-amber-400 flex items-center gap-2">
                    🔄 My Recurring Skip Rules
                </h3>

                <!-- Form to Add Rule -->
                <form @submit.prevent="submitRecurring" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Weekday</label>
                        <select v-model="recurringForm.weekday" class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500">
                            <option v-for="(name, num) in weekdaysMap" :key="num" :value="Number(num)">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Starts On</label>
                        <input v-model="recurringForm.starts_on" type="date" class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Ends On (Optional)</label>
                        <input v-model="recurringForm.ends_on" type="date" class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" />
                    </div>
                    <div>
                        <button type="submit" :disabled="recurringForm.processing" class="w-full px-4 py-2 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs rounded-xl transition cursor-pointer">
                            Add Rule
                        </button>
                    </div>
                </form>

                <!-- Rules List -->
                <div class="space-y-3">
                    <div v-for="rule in my_recurring_rules" :key="rule.id" class="flex items-center justify-between p-3.5 bg-slate-950 border border-slate-800 rounded-xl">
                        <div>
                            <span class="font-bold text-white text-sm">Every {{ weekdaysMap[rule.weekday] }}</span>
                            <span class="text-xs text-slate-400 ml-2">(Starts: {{ rule.starts_on }} {{ rule.ends_on ? 'Ends: ' + rule.ends_on : 'Indefinite' }})</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button @click="toggleRule(rule.id)" :class="['px-3 py-1 rounded-lg text-xs font-semibold border', rule.active ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700']">
                                {{ rule.active ? 'Active' : 'Paused' }}
                            </button>
                            <button @click="deleteRule(rule.id)" class="px-3 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-lg text-xs font-semibold">
                                Delete
                            </button>
                        </div>
                    </div>

                    <p v-if="!my_recurring_rules || !my_recurring_rules.length" class="text-xs text-slate-500 italic">
                        No recurring skip rules active.
                    </p>
                </div>
            </div>
        </main>
    </div>
    </AppLayout>
</template>
