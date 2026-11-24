<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    tiffinService: Object,
    preparationData: Object,
    selectedDate: String,
    todayOverride: Object,
    todayMenu: String,
    /** False when no company has been paired with this kitchen yet. */
    isPaired: { type: Boolean, default: true },
});

const currentDate = ref(props.selectedDate || new Date().toISOString().split('T')[0]);

const changeDate = (newDate) => {
    currentDate.value = newDate;
    router.get('/tiffin-admin/preparation', { date: newDate }, { preserveState: true });
};

/**
 * The count's status arrives as the database enum. It was printed uppercased
 * and straight through, so the table read AUTO_CONFIRMED at the kitchen.
 */
const statusLabel = (status) => ({
    auto_confirmed: 'Auto-confirmed',
    confirmed: 'Confirmed',
}[status] ?? String(status).replace(/_/g, ' '));

</script>

<template>
    <AppLayout>
    <div class="relative z-10 p-4 sm:p-6 space-y-5">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">Preparation</h1>
                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                    How many tiffins to cook, and for whom.
                </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <label for="prep-date" class="text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft">Date</label>
                <input
                    id="prep-date"
                    type="date"
                    v-model="currentDate"
                    @change="changeDate(currentDate)"
                    class="h-10 px-3 bg-mb-card border border-mb-field rounded-lg text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                />
            </div>
        </header>

        <main class="space-y-5">
            <!--
                The kitchen has one question, and this is it. It used to sit
                small in the corner of the date-picker row, which made choosing
                a date look like the page's main job.
            -->
            <section v-if="preparationData" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft">
                            Tiffins to cook
                        </p>
                        <p class="mt-2 text-6xl sm:text-7xl font-light leading-none tracking-tight text-mb-text-strong tabular-nums">
                            {{ preparationData.summary.total_meals }}
                            <span class="text-2xl font-normal text-mb-text-muted dark:text-mb-text-soft">meals</span>
                        </p>
                    </div>

                    <span v-if="preparationData.summary.is_estimate" class="inline-flex items-center gap-2 text-sm font-semibold text-mb-amber whitespace-nowrap">
                        <span class="w-2 h-2 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                        Live estimate — still moving
                    </span>
                    <span v-else class="inline-flex items-center gap-2 text-sm font-semibold text-mb-emerald whitespace-nowrap">
                        <span class="w-2 h-2 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                        Locked — this is the number
                    </span>
                </div>
            </section>

            <!--
                The override used to sit in a tinted box inside the card. One
                card, one menu: the override replaces the menu rather than
                decorating it, so it is said in the heading instead.
            -->
            <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-mb-text-strong">Menu</h2>
                    <span v-if="todayOverride" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber">
                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                        Emergency override — cook this, not the published menu
                    </span>
                </div>

                <p v-if="todayOverride" class="mt-3 text-xl font-medium text-mb-text-strong">
                    {{ todayOverride.meal_description }}
                </p>
                <p v-else-if="todayMenu" class="mt-3 text-xl font-medium text-mb-text-strong">
                    {{ todayMenu }}
                </p>
                <p v-else class="mt-3 text-sm text-mb-text-muted dark:text-mb-text-soft">
                    No menu published for this date yet.
                </p>

                <p v-if="todayOverride?.reason" class="mt-2 text-xs text-mb-text-muted dark:text-mb-text-soft">
                    Reason: {{ todayOverride.reason }}
                </p>
            </section>

            <!--
                The kitchen reads this to load the vans: one row per company,
                and the only number that goes on the box is "To cook".
            -->
            <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <h2 class="text-sm font-semibold text-mb-text-strong mb-4">By company</h2>

                <!--
                    Two states that used to share one sentence joined by "or",
                    which left the reader unable to tell a half-finished
                    account from an ordinary Sunday. Setup is somebody's job;
                    a day off is nobody's.
                -->
                <div v-if="!isPaired" class="py-14 max-w-md">
                    <h3 class="text-base font-semibold text-mb-text-strong">
                        This kitchen is not connected to a company yet
                    </h3>
                    <p class="mt-2 text-sm leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
                        Counts appear here once MealBells pairs you with the company you
                        cook for. That is set up at our end — there is nothing for you to
                        do, and you will be told when it is live.
                    </p>
                    <p class="mt-5 text-sm text-mb-text-muted dark:text-mb-text-soft">
                        In the meantime you can
                        <Link href="/tiffin-admin/dashboard" class="font-semibold text-mb-emerald underline underline-offset-4">
                            set this week's menu</Link>, which will be waiting when the
                        first count arrives.
                    </p>
                </div>

                <div v-else-if="!preparationData || preparationData.companies.length === 0" class="py-14 max-w-md">
                    <h3 class="text-base font-semibold text-mb-text-strong">No meals on this date</h3>
                    <p class="mt-2 text-sm leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
                        Nothing to cook — the company you serve does not take meals on this
                        day. Pick another date above to see its count.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-mb-line text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft">
                                <th class="py-2 pr-3 font-semibold">Company</th>
                                <th class="py-2 px-3 font-semibold text-right">Eligible</th>
                                <th class="py-2 px-3 font-semibold text-right">Skips</th>
                                <th class="py-2 px-3 font-semibold text-right">Extra</th>
                                <th class="py-2 px-3 font-semibold text-right">To cook</th>
                                <th class="py-2 pl-3 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line/60 text-sm">
                            <tr v-for="comp in preparationData.companies" :key="comp.company_id" class="hover:bg-mb-raised/30">
                                <td class="py-3 pr-3 font-medium text-mb-text-strong">
                                    {{ comp.company_name }}
                                </td>
                                <td class="py-3 px-3 text-right tabular-nums text-mb-text-soft">{{ comp.base_eligible_count }}</td>
                                <td class="py-3 px-3 text-right tabular-nums text-mb-rose dark:text-mb-rose-soft">&minus;{{ comp.skip_count }}</td>
                                <td class="py-3 px-3 text-right tabular-nums text-mb-emerald">+{{ comp.extra_count }}</td>
                                <td class="py-3 px-3 text-right">
                                    <span class="text-lg font-semibold tabular-nums text-mb-text-strong">{{ comp.adjusted_total }}</span>
                                    <span v-if="comp.late_changes.length > 0" class="block text-xs font-normal text-mb-text-muted dark:text-mb-text-soft">
                                        was {{ comp.final_expected_count }}
                                    </span>
                                </td>
                                <td class="py-3 pl-3">
                                    <span v-if="!comp.is_meal_day" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft whitespace-nowrap">
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-text-faint" aria-hidden="true"></span>
                                        No meal day
                                    </span>
                                    <span v-else-if="comp.is_locked" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-emerald whitespace-nowrap">
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                                        {{ statusLabel(comp.status) }}
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber whitespace-nowrap">
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                        Estimate
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>
