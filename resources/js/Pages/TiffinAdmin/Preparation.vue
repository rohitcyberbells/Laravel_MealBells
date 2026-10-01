<script setup>
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

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-white p-6">
        <!-- Header -->
        <header class="max-w-7xl mx-auto flex justify-between items-center pb-6 border-b border-slate-800 mb-8">
            <div>
                <div class="flex items-center space-x-3">
                    <h1 class="text-3xl font-bold text-amber-400">{{ tiffinService?.name || 'Tiffin Vendor Dashboard' }}</h1>
                    <span class="bg-amber-500/20 text-amber-300 border border-amber-500/30 text-xs px-3 py-1 rounded-full font-semibold">
                        Vendor Preparation View
                    </span>
                </div>
                <p class="text-slate-400 text-sm mt-1">Real-time & Locked Demand Planning Engine</p>
            </div>
            <div class="flex items-center space-x-4">
                <a href="/tiffin-admin/dashboard" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-sm px-4 py-2 rounded-lg transition font-semibold">
                    ← Back to Menu Builder
                </a>
                <button @click="logout" class="bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/40 text-sm px-4 py-2 rounded-lg transition">
                    Logout
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- 📅 Date Navigation & Overall Summary Bar -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl flex flex-wrap justify-between items-center gap-4">
                <div class="flex items-center space-x-4">
                    <label class="text-xs uppercase font-bold text-slate-400">Select Date:</label>
                    <input type="date" v-model="currentDate" @change="changeDate(currentDate)" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                </div>

                <div v-if="preparationData" class="flex items-center space-x-6">
                    <div class="text-right">
                        <p class="text-xs uppercase font-semibold text-slate-400">Total Expected Meals</p>
                        <p class="text-3xl font-extrabold text-emerald-400">{{ preparationData.summary.total_meals }} <span class="text-xs font-normal text-slate-400">meals</span></p>
                    </div>
                    <span v-if="preparationData.summary.is_estimate" class="bg-amber-500/20 text-amber-300 border border-amber-500/40 text-xs font-semibold px-3 py-1.5 rounded-full">
                        ⚡ Live Estimate
                    </span>
                    <span v-else class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs font-semibold px-3 py-1.5 rounded-full">
                        🔒 Locked Count
                    </span>
                </div>
            </div>

            <!-- 🍲 Today's Planned Menu Card -->
            <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                <h2 class="text-lg font-bold text-white mb-2 flex items-center gap-2">
                    🍲 Menu for {{ currentDate }}
                </h2>
                <div v-if="todayOverride" class="bg-amber-950/40 border border-amber-500/40 rounded-lg p-4">
                    <span class="bg-amber-500 text-slate-950 text-xs font-bold px-2 py-0.5 rounded">Emergency Override Active</span>
                    <p class="text-amber-200 font-semibold text-base mt-2">{{ todayOverride.meal_description }}</p>
                    <p v-if="todayOverride.reason" class="text-xs text-amber-400/80 mt-1">Reason: {{ todayOverride.reason }}</p>
                </div>
                <div v-else-if="todayMenu" class="bg-slate-950 border border-slate-800 rounded-lg p-4">
                    <p class="text-slate-200 font-medium text-base">{{ todayMenu }}</p>
                </div>
                <div v-else class="text-slate-500 text-sm italic">
                    No menu published for this date yet.
                </div>
            </section>

            <!-- 🏢 Assigned Companies Preparation Table -->
            <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                <h2 class="text-xl font-bold text-white mb-4">🏢 Company Preparation Breakdown</h2>

                <div v-if="!preparationData || preparationData.companies.length === 0" class="text-center py-12 text-slate-500">
                    Abhi koi company assigned nahi hai ya is date par active nahi hai.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800 text-slate-400 text-xs uppercase tracking-wider">
                                <th class="p-3">Company Name</th>
                                <th class="p-3">Base Eligible</th>
                                <th class="p-3">Skips</th>
                                <th class="p-3">Extra Meals</th>
                                <th class="p-3">Expected Count</th>
                                <th class="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-sm">
                            <tr v-for="comp in preparationData.companies" :key="comp.company_id" class="hover:bg-slate-800/30">
                                <td class="p-3 font-semibold text-white">
                                    {{ comp.company_name }}
                                </td>
                                <td class="p-3 text-slate-300 font-medium">{{ comp.base_eligible_count }}</td>
                                <td class="p-3 text-red-400 font-medium">-{{ comp.skip_count }}</td>
                                <td class="p-3 text-emerald-400 font-medium">+{{ comp.extra_count }}</td>
                                <td class="p-3 font-extrabold text-amber-400 text-base">
                                    {{ comp.adjusted_total }}
                                    <span v-if="comp.late_changes.length > 0" class="text-xs font-normal text-amber-300 block">
                                        (Original: {{ comp.final_expected_count }})
                                    </span>
                                </td>
                                <td class="p-3">
                                    <span v-if="!comp.is_meal_day" class="bg-slate-800 text-slate-400 border border-slate-700 text-xs px-2.5 py-1 rounded-full font-semibold">
                                        Non-Meal Day
                                    </span>
                                    <span v-else-if="comp.is_locked" class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs px-2.5 py-1 rounded-full font-semibold">
                                        {{ comp.status.toUpperCase() }}
                                    </span>
                                    <span v-else class="bg-amber-500/20 text-amber-300 border border-amber-500/40 text-xs px-2.5 py-1 rounded-full font-semibold">
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
</template>
