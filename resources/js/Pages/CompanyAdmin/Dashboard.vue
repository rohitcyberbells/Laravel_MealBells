<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    company: Object,
    tiffinService: Object,
    weeklyMenu: Object,
    todayOverride: Object,
    todayMeal: Object,
    notifications: Array,
    // Both were computed by the controller and read by nothing, so the
    // dashboard ran CalculateExpectedMeals eight times a load for a forecast
    // it threw away.
    todayStats: { type: Object, default: null },
    forecast: { type: Array, default: () => [] },
});

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Parsed as a plain calendar date: `new Date('2026-10-08')` is UTC midnight,
// which in a behind-UTC timezone renders as the previous day.
const dayLabel = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return WEEKDAY[new Date(y, m - 1, d).getDay()];
};

const dayNumber = (iso) => Number(iso.split('-')[2]);

// A locked day reports what the kitchen was actually told; an open one is still
// an estimate.
const expectedFor = (day) => (day.is_locked ? day.adjusted_total : day.final_expected_count);

const forecastDays = computed(() => props.forecast.filter((d) => d.is_meal_day));

const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

const getPlannedMeal = (day) => {
    if (!props.weeklyMenu?.items) return null;
    return props.weeklyMenu.items.find(i => i.day_of_week.toLowerCase() === day.toLowerCase());
};

</script>

<template>
    <AppLayout>
    <div class="p-6">
        <div class="max-w-7xl mx-auto pb-6 border-b border-slate-800 mb-8">
            <div>
                <div class="flex items-center space-x-3">
                    <h1 class="text-3xl font-bold text-cyan-400">{{ company?.name || 'Company Dashboard' }}</h1>
                    <span v-if="tiffinService" class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-xs px-3 py-1 rounded-full font-semibold">
                        Assigned Tiffin: {{ tiffinService.name }}
                    </span>
                    <span v-else class="bg-amber-500/20 text-amber-300 border border-amber-500/30 text-xs px-3 py-1 rounded-full font-semibold">
                        No Tiffin Service Assigned Yet
                    </span>
                </div>
                <p class="text-slate-400 text-sm mt-1">Employee Meal Portal & Daily Menu Overview</p>
            </div>
        </div>

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- Unassigned Banner -->
            <section v-if="!tiffinService" class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-6 text-center">
                <h2 class="text-xl font-bold text-amber-400 mb-2">Notice: Account Pending Pairing</h2>
                <p class="text-slate-300 text-sm max-w-lg mx-auto">
                    Your company is not yet paired with an assigned tiffin service. Please contact the Super Admin to pair your account.
                </p>
            </section>

            <template v-else>
                <!-- 🍽️ Today's count and the week ahead -->
                <section v-if="todayStats" class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <div class="flex items-start justify-between flex-wrap gap-3 mb-5">
                        <div>
                            <h2 class="text-xl font-bold text-cyan-400">🍽️ Meal count</h2>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Everyone eligible is counted unless a skip says otherwise.
                            </p>
                        </div>
                        <span
                            :class="[
                                'px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border',
                                todayStats.is_locked
                                    ? 'bg-slate-700/40 text-slate-300 border-slate-600'
                                    : 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                            ]"
                        >
                            {{ todayStats.is_locked ? 'Locked · sent to kitchen' : 'Open · still an estimate' }}
                        </span>
                    </div>

                    <div v-if="!todayStats.is_meal_day" class="text-sm text-slate-400 bg-slate-950 border border-slate-800 rounded-xl p-4">
                        No meal today — it is not a meal day for your company.
                    </div>

                    <template v-else>
                        <!-- Expected = base − skips + extra, the same arithmetic
                             the engine locks at cutoff. -->
                        <div class="flex flex-wrap items-end gap-3 text-center">
                            <div class="bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 min-w-[6rem]">
                                <span class="text-[10px] uppercase font-semibold text-slate-400">Eligible</span>
                                <div class="text-2xl font-extrabold text-slate-200">{{ todayStats.base_eligible_count }}</div>
                            </div>
                            <span class="text-xl text-slate-500 pb-3">−</span>
                            <div class="bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 min-w-[6rem]">
                                <span class="text-[10px] uppercase font-semibold text-slate-400">Skips</span>
                                <div class="text-2xl font-extrabold text-amber-400">{{ todayStats.skip_count }}</div>
                            </div>
                            <span class="text-xl text-slate-500 pb-3">+</span>
                            <div class="bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 min-w-[6rem]">
                                <span class="text-[10px] uppercase font-semibold text-slate-400">Extra</span>
                                <div class="text-2xl font-extrabold text-cyan-300">{{ todayStats.extra_count }}</div>
                            </div>
                            <span class="text-xl text-slate-500 pb-3">=</span>
                            <div class="bg-cyan-500/10 border border-cyan-500/40 rounded-xl px-5 py-3 min-w-[7rem]">
                                <span class="text-[10px] uppercase font-semibold text-cyan-300">Expected</span>
                                <div class="text-3xl font-extrabold text-cyan-300">{{ expectedFor(todayStats) }}</div>
                            </div>
                        </div>
                    </template>

                    <div v-if="forecastDays.length" class="mt-6 pt-5 border-t border-slate-800">
                        <p class="text-[11px] uppercase font-semibold text-slate-400 mb-3">Next meal days</p>
                        <div class="flex flex-wrap gap-2">
                            <div
                                v-for="day in forecastDays"
                                :key="day.date"
                                :title="`${day.date} · ${day.base_eligible_count} eligible − ${day.skip_count} skips + ${day.extra_count} extra`"
                                class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-center min-w-[4.5rem]"
                            >
                                <span class="block text-[10px] uppercase font-semibold text-slate-500">
                                    {{ dayLabel(day.date) }} {{ dayNumber(day.date) }}
                                </span>
                                <span class="block text-lg font-extrabold text-slate-200">{{ expectedFor(day) }}</span>
                                <span v-if="day.skip_count" class="block text-[10px] text-amber-400">−{{ day.skip_count }}</span>
                                <span v-else class="block text-[10px] text-slate-600">full</span>
                            </div>
                        </div>
                        <p class="text-[10px] text-slate-500 mt-2">
                            Non-meal days are left out. Figures move until each day's cutoff.
                        </p>
                    </div>
                </section>

                <!-- 🔔 Recent Notifications Feed -->
                <section v-if="notifications && notifications.length" class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <h2 class="text-xl font-bold text-amber-400 flex items-center gap-2 mb-4">
                        🔔 Recent Alerts & Notifications
                    </h2>
                    <div class="space-y-3">
                        <div v-for="notif in notifications" :key="notif.id" class="bg-slate-950 border border-slate-800 rounded-lg p-4 flex items-start justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-white">{{ notif.data.title }}</h3>
                                <p class="text-xs text-slate-300 mt-1">{{ notif.data.message }}</p>
                                <p v-if="notif.data.reason" class="text-xs text-amber-300 mt-1">Reason: {{ notif.data.reason }}</p>
                            </div>
                            <span class="text-[10px] text-slate-500 whitespace-nowrap ml-4">
                                {{ new Date(notif.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                            </span>
                        </div>
                    </div>
                </section>

                <!-- 🍱 Today's Featured Meal Card -->
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-emerald-400 flex items-center gap-2">
                             Today's Meal
                        </h2>
                        <span v-if="todayMeal?.is_override" class="bg-amber-500/20 text-amber-300 border border-amber-500/40 text-xs font-semibold px-3 py-1 rounded-full">
                             Daily Kitchen Override Active
                        </span>
                    </div>

                    <div v-if="todayMeal" class="bg-slate-950 border border-slate-800 rounded-lg p-5">
                        <p class="text-lg font-bold text-white">{{ todayMeal.meal_description }}</p>
                        <p v-if="todayMeal.reason" class="text-xs text-amber-300 mt-2 bg-amber-500/10 p-2 rounded border border-amber-500/20">
                            <strong>Note from kitchen:</strong> {{ todayMeal.reason }}
                        </p>
                    </div>

                    <div v-else class="bg-slate-950 border border-slate-800 rounded-lg p-5 text-center">
                        <p class="text-slate-400 text-sm italic">No meal schedule published for today yet.</p>
                    </div>
                </section>

                <!--  Published Weekly Menu (Monday - Friday) -->
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-xl font-bold text-white"> Published Weekly Menu</h2>
                            <p class="text-slate-400 text-xs">Official menu provided by <span class="text-emerald-400 font-semibold">{{ tiffinService.name }}</span></p>
                        </div>
                        <div v-if="tiffinService.contact_phone" class="text-xs text-slate-400">
                            Kitchen Contact: <span class="text-slate-200 font-semibold">{{ tiffinService.contact_phone }}</span>
                        </div>
                    </div>

                    <div v-if="weeklyMenu?.items?.length" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        <div v-for="day in daysOfWeek" :key="day" class="bg-slate-950 border border-slate-800 rounded-lg p-4">
                            <h3 class="text-xs uppercase font-bold text-cyan-400 mb-2">{{ day }}</h3>
                            <p class="text-sm text-slate-200">
                                {{ getPlannedMeal(day)?.meal_description || 'No meal scheduled' }}
                            </p>
                        </div>
                    </div>

                    <div v-else class="text-center py-8 bg-slate-950 rounded-lg border border-slate-800">
                        <p class="text-slate-400 text-sm">The tiffin service has not published the weekly menu yet.</p>
                    </div>
                </section>
            </template>
        </main>
    </div>
    </AppLayout>
</template>

