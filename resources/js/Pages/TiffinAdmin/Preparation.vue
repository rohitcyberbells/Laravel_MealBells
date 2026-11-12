<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    tiffinService: Object,
    preparationData: Object,
    selectedDate: String,
    todayOverride: Object,
    todayMenu: String,
});

const currentDate = ref(props.selectedDate || new Date().toISOString().split('T')[0]);

const changeDate = (newDate) => {
    currentDate.value = newDate;
    router.get('/tiffin-admin/preparation', { date: newDate }, { preserveState: true });
};

</script>

<template>
    <AppLayout>
    <div class="p-6">
        <!-- Header -->

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- 📅 Date Navigation & Overall Summary Bar -->
            <div class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl flex flex-wrap justify-between items-center gap-4">
                <div class="flex items-center space-x-4">
                    <label class="text-xs uppercase font-bold text-mb-text-muted">Select Date:</label>
                    <input type="date" v-model="currentDate" @change="changeDate(currentDate)" class="bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-amber" />
                </div>

                <div v-if="preparationData" class="flex items-center space-x-6">
                    <div class="text-right">
                        <p class="text-xs uppercase font-semibold text-mb-text-muted">Total Expected Meals</p>
                        <p class="text-3xl font-extrabold text-mb-emerald">{{ preparationData.summary.total_meals }} <span class="text-xs font-normal text-mb-text-muted">meals</span></p>
                    </div>
                    <span v-if="preparationData.summary.is_estimate" class="bg-mb-tint-amber/20 text-mb-amber-soft border border-mb-tint-amber/40 text-xs font-semibold px-3 py-1.5 rounded-full">
                        ⚡ Live Estimate
                    </span>
                    <span v-else class="bg-mb-tint-emerald/20 text-mb-emerald-soft border border-mb-tint-emerald/40 text-xs font-semibold px-3 py-1.5 rounded-full">
                        🔒 Locked Count
                    </span>
                </div>
            </div>

            <!-- 🍲 Today's Planned Menu Card -->
            <section class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl">
                <h2 class="text-lg font-bold text-mb-text-strong mb-2 flex items-center gap-2">
                    🍲 Menu for {{ currentDate }}
                </h2>
                <div v-if="todayOverride" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-lg p-4">
                    <span class="bg-mb-tint-amber text-mb-on-accent text-xs font-bold px-2 py-0.5 rounded">Emergency Override Active</span>
                    <p class="text-mb-amber-soft font-semibold text-base mt-2">{{ todayOverride.meal_description }}</p>
                    <p v-if="todayOverride.reason" class="text-xs text-mb-amber/80 mt-1">Reason: {{ todayOverride.reason }}</p>
                </div>
                <div v-else-if="todayMenu" class="bg-mb-inset border border-mb-line rounded-lg p-4">
                    <p class="text-mb-text font-medium text-base">{{ todayMenu }}</p>
                </div>
                <div v-else class="text-mb-text-faint text-sm italic">
                    No menu published for this date yet.
                </div>
            </section>

            <!-- 🏢 Assigned Companies Preparation Table -->
            <section class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl">
                <h2 class="text-xl font-bold text-mb-text-strong mb-4">🏢 Company Preparation Breakdown</h2>

                <div v-if="!preparationData || preparationData.companies.length === 0" class="text-center py-12 text-mb-text-faint">
                    Abhi koi company assigned nahi hai ya is date par active nahi hai.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-mb-line text-mb-text-muted text-xs uppercase tracking-wider">
                                <th class="p-3">Company Name</th>
                                <th class="p-3">Base Eligible</th>
                                <th class="p-3">Skips</th>
                                <th class="p-3">Extra Meals</th>
                                <th class="p-3">Expected Count</th>
                                <th class="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line/60 text-sm">
                            <tr v-for="comp in preparationData.companies" :key="comp.company_id" class="hover:bg-mb-raised/30">
                                <td class="p-3 font-semibold text-mb-text-strong">
                                    {{ comp.company_name }}
                                </td>
                                <td class="p-3 text-mb-text-soft font-medium">{{ comp.base_eligible_count }}</td>
                                <td class="p-3 text-mb-rose font-medium">-{{ comp.skip_count }}</td>
                                <td class="p-3 text-mb-emerald font-medium">+{{ comp.extra_count }}</td>
                                <td class="p-3 font-extrabold text-mb-amber text-base">
                                    {{ comp.adjusted_total }}
                                    <span v-if="comp.late_changes.length > 0" class="text-xs font-normal text-mb-amber-soft block">
                                        (Original: {{ comp.final_expected_count }})
                                    </span>
                                </td>
                                <td class="p-3">
                                    <span v-if="!comp.is_meal_day" class="bg-mb-raised text-mb-text-muted border border-mb-line-mid text-xs px-2.5 py-1 rounded-full font-semibold">
                                        Non-Meal Day
                                    </span>
                                    <span v-else-if="comp.is_locked" class="bg-mb-tint-emerald/20 text-mb-emerald-soft border border-mb-tint-emerald/40 text-xs px-2.5 py-1 rounded-full font-semibold">
                                        {{ comp.status.toUpperCase() }}
                                    </span>
                                    <span v-else class="bg-mb-tint-amber/20 text-mb-amber-soft border border-mb-tint-amber/40 text-xs px-2.5 py-1 rounded-full font-semibold">
                                        ESTIMATE
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
