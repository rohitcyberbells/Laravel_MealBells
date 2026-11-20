<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import MealFlowStrip from './Dashboard/MealFlowStrip.vue';
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

// Whether a day moved off its base at all, in either direction.
const dayDelta = (day) => (day.skip_count ?? 0) + (day.extra_count ?? 0) > 0;

const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

const getPlannedMeal = (day) => {
    if (!props.weeklyMenu?.items) return null;
    return props.weeklyMenu.items.find(i => i.day_of_week.toLowerCase() === day.toLowerCase());
};

</script>

<template>
    <AppLayout>
    <!--
        No page heading above the city. The company name is in the sidebar, the
        tiffin service is named in the summary line under the scene, and an
        unpaired company gets the banner below - so a title bar here would only
        push the one thing worth looking at off the top of the screen.
    -->
    <div class="relative z-10 min-h-screen p-4 sm:p-6">
        <main class="space-y-5">
            <!-- Unassigned Banner -->
            <section v-if="!tiffinService" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-2xl p-6">
                <h2 class="text-base font-bold text-mb-amber">Account pending pairing</h2>
                <p class="text-sm text-mb-text-soft mt-1 max-w-lg">
                    Your company is not yet paired with a tiffin service, so no meals are being counted.
                    Ask the super admin to pair your account.
                </p>
            </section>

            <template v-else>
                <!--
                    A fixed four-to-one split, so a grid rather than the
                    grow/basis pattern the rest of the app uses: those share
                    leftover space, which gives a ratio that drifts with the
                    window. Five columns divide exactly, and the gap comes out
                    of the grid rather than either child. Below lg they stack.
                -->
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-stretch">
                    <div :class="['min-w-0', notifications && notifications.length ? 'lg:col-span-4' : 'lg:col-span-5']">
                        <MealFlowStrip
                            v-if="todayStats"
                            data-diff-ignore
                            :company="company"
                            :tiffin-service="tiffinService"
                            :today-stats="todayStats"
                            :today-meal="todayMeal"
                            :expected="expectedFor(todayStats)"
                        />
                    </div>

                    <!--
                        A feed, not a stack of boxes. Each entry used to be its
                        own bordered card inside this card, so a list of three
                        notices drew four nested rectangles. min-h-0 on the
                        column and the list is what lets the list scroll inside
                        the stretched card instead of pushing it taller than
                        the scene beside it.
                    -->
                    <aside
                        v-if="notifications && notifications.length"
                        class="lg:col-span-1 min-w-0 min-h-0 flex flex-col bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-5"
                    >
                        <div class="flex items-center justify-between gap-2 shrink-0">
                            <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Recent alerts</h2>
                            <span class="text-[11px] font-semibold text-mb-text-muted dark:text-mb-text-soft shrink-0">
                                {{ notifications.length }}
                            </span>
                        </div>

                        <ul class="mt-2 min-h-0 overflow-y-auto divide-y divide-mb-line">
                            <li v-for="notif in notifications" :key="notif.id" class="py-2.5">
                                <div class="flex items-start gap-2">
                                    <span class="w-1.5 h-1.5 shrink-0 mt-1.5 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                    <p class="grow min-w-0 text-[13px] font-semibold text-mb-text-strong">{{ notif.data.title }}</p>
                                    <time
                                        class="text-[11px] text-mb-text-muted dark:text-mb-text-soft whitespace-nowrap shrink-0 tabular-nums"
                                        :datetime="notif.created_at"
                                    >
                                        {{ new Date(notif.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                    </time>
                                </div>
                                <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-0.5 pl-3.5">{{ notif.data.message }}</p>
                                <p v-if="notif.data.reason" class="text-[11px] text-mb-amber mt-0.5 pl-3.5">Reason: {{ notif.data.reason }}</p>
                            </li>
                        </ul>
                    </aside>
                </div>

                <!-- Today's count and the week ahead -->
                <section v-if="todayStats" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                    <div class="flex items-start justify-between flex-wrap gap-3 mb-5">
                        <div>
                            <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Meal count</h2>
                            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                Everyone eligible is counted unless a skip says otherwise.
                            </p>
                        </div>
                        <span
                            :class="[
                                'inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap',
                                todayStats.is_locked ? 'text-mb-text-muted dark:text-mb-text-soft' : 'text-mb-emerald'
                            ]"
                        >
                            <span
                                :class="['w-1.5 h-1.5 shrink-0 rounded-full', todayStats.is_locked ? 'bg-mb-text-faint' : 'bg-mb-emerald']"
                                aria-hidden="true"
                            ></span>
                            {{ todayStats.is_locked ? 'Locked · sent to kitchen' : 'Open · still an estimate' }}
                        </span>
                    </div>

                    <p v-if="!todayStats.is_meal_day" class="text-sm text-mb-text-muted dark:text-mb-text-soft">
                        No meal today — it is not a meal day for your company.
                    </p>

                    <!--
                        Four tiles rather than five boxes joined by − + =. The
                        arithmetic is still there, as the caption under the
                        total, which is the one number the kitchen acts on.
                    -->
                    <section v-else class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                        <div class="bg-mb-fill-emerald text-mb-on-fill-soft border border-transparent dark:border-mb-tint-emerald/50 rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <span class="text-xs font-semibold">Expected</span>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-on-fill">{{ expectedFor(todayStats) }}</p>
                            <p class="text-[11px] leading-snug tabular-nums">
                                {{ todayStats.base_eligible_count }} − {{ todayStats.skip_count }} + {{ todayStats.extra_count }}
                            </p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <span class="text-xs font-semibold text-mb-text-soft">Eligible</span>
                            <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">{{ todayStats.base_eligible_count }}</p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Employees who get a meal by default.</p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <span class="text-xs font-semibold text-mb-text-soft">Skips</span>
                            <p class="text-4xl font-light leading-none tracking-tight" :class="todayStats.skip_count ? 'text-mb-amber' : 'text-mb-text-strong'">
                                {{ todayStats.skip_count }}
                            </p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Opted out today, for any reason.</p>
                        </div>

                        <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                            <span class="text-xs font-semibold text-mb-text-soft">Extra</span>
                            <p class="text-4xl font-light leading-none tracking-tight" :class="todayStats.extra_count ? 'text-mb-cyan' : 'text-mb-text-strong'">
                                {{ todayStats.extra_count }}
                            </p>
                            <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Guests and visitors added on.</p>
                        </div>
                    </section>

                    <!-- One bordered strip with dividers, not a box per day -->
                    <div v-if="forecastDays.length" class="mt-6 pt-5 border-t border-mb-line">
                        <p class="text-[11px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Next meal days</p>

                        <ul class="mt-3 flex flex-wrap sm:flex-nowrap sm:divide-x divide-mb-line">
                            <li
                                v-for="day in forecastDays"
                                :key="day.date"
                                :title="`${day.date} · ${day.base_eligible_count} − ${day.skip_count} + ${day.extra_count} = ${expectedFor(day)}`"
                                class="grow basis-20 px-3 py-1 first:pl-0"
                            >
                                <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">
                                    {{ dayLabel(day.date) }} {{ dayNumber(day.date) }}
                                </span>
                                <span class="block text-2xl font-light tracking-tight text-mb-text-strong mt-1">{{ expectedFor(day) }}</span>
                                <!-- Both adjustments, not just skips: showing
                                     "−4" beside 30 against a base of 28 reads as
                                     arithmetic that does not add up. -->
                                <span v-if="dayDelta(day)" class="block text-[11px] whitespace-nowrap mt-0.5 tabular-nums">
                                    <span v-if="day.skip_count" class="text-mb-amber">−{{ day.skip_count }}</span>
                                    <span v-if="day.skip_count && day.extra_count" class="text-mb-text-muted dark:text-mb-text-soft"> </span>
                                    <span v-if="day.extra_count" class="text-mb-cyan">+{{ day.extra_count }}</span>
                                </span>
                                <span v-else class="block text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-0.5">full</span>
                            </li>
                        </ul>

                        <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft mt-3">
                            Non-meal days are left out. Figures move until each day's cutoff.
                        </p>
                    </div>
                </section>

            <!--
                Today's meal and the week it comes from, as a pair. An even
                split, so a grid again rather than grow/basis - and
                items-stretch, so the two cards share a bottom edge instead of
                one hanging short of the other.
            -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">

                <!-- Today's meal. The card is the box; the meal needs no second one. -->
                <section class="min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                    <div class="flex justify-between items-center flex-wrap gap-3">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Today's meal</h2>
                        <span v-if="todayMeal?.is_override" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber whitespace-nowrap">
                            <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                            Kitchen override active
                        </span>
                    </div>

                    <template v-if="todayMeal">
                        <p class="text-xl font-semibold tracking-tight text-mb-text-strong mt-3">{{ todayMeal.meal_description }}</p>
                        <p v-if="todayMeal.reason" class="text-xs text-mb-amber mt-2">
                            <strong class="font-semibold">Note from the kitchen:</strong> {{ todayMeal.reason }}
                        </p>
                    </template>

                    <p v-else class="text-sm text-mb-text-muted dark:text-mb-text-soft mt-3">
                        No meal published for today yet.
                    </p>
                </section>

                <!-- Published weekly menu, Monday to Friday -->
                <section class="min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                    <div class="flex justify-between items-start flex-wrap gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Published weekly menu</h2>
                            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                From <span class="font-semibold text-mb-emerald">{{ tiffinService.name }}</span>
                            </p>
                        </div>
                        <p v-if="tiffinService.contact_phone" class="text-xs text-mb-text-muted dark:text-mb-text-soft shrink-0">
                            Kitchen · <span class="font-semibold text-mb-text-strong">{{ tiffinService.contact_phone }}</span>
                        </p>
                    </div>

                    <!--
                        One row with dividers between the days, rather than five
                        bordered boxes sitting inside this one. The row waits
                        for xl rather than md now: this card is half the page,
                        so at md five columns would be under 100px each.
                    -->
                    <ul
                        v-if="weeklyMenu?.items?.length"
                        class="mt-4 flex flex-col xl:flex-row xl:divide-x divide-y xl:divide-y-0 divide-mb-line"
                    >
                        <li v-for="day in daysOfWeek" :key="day" class="grow basis-0 min-w-0 py-3 xl:py-0 xl:px-3 xl:first:pl-0 xl:last:pr-0">
                            <h3 class="text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">{{ day }}</h3>
                            <p class="text-sm text-mb-text-strong mt-1">
                                {{ getPlannedMeal(day)?.meal_description || 'Nothing scheduled' }}
                            </p>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-mb-text-muted dark:text-mb-text-soft mt-3">
                        The tiffin service has not published the weekly menu yet.
                    </p>
                </section>
            </div>
            </template>
        </main>
    </div>
    </AppLayout>
</template>

