<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    range: {
        type: Number,
        default: 7,
    },
    report: {
        type: Object,
        required: true,
    },
});

const changeRange = (newRange) => {
    router.get('/company-admin/reports/adoption', { range: newRange }, { preserveState: true });
};
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <!-- Header & Range Selection -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">📈 Employee Adoption Report</h1>
                    <p class="text-sm text-slate-400">
                        Aggregate skip channels and employee portal usage metrics ({{ report.from_date }} to {{ report.to_date }})
                    </p>
                </div>

                <div class="inline-flex rounded-xl bg-slate-900 p-1 border border-slate-800">
                    <button
                        @click="changeRange(7)"
                        :class="[
                            'px-4 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                            range === 7
                                ? 'bg-cyan-500 text-slate-950 shadow-md'
                                : 'text-slate-400 hover:text-white'
                        ]"
                    >
                        Last 7 Days
                    </button>
                    <button
                        @click="changeRange(30)"
                        :class="[
                            'px-4 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                            range === 30
                                ? 'bg-cyan-500 text-slate-950 shadow-md'
                                : 'text-slate-400 hover:text-white'
                        ]"
                    >
                        Last 30 Days
                    </button>
                </div>
            </div>

            <!-- Top Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Large Self Service Metric -->
                <div class="p-6 rounded-2xl bg-gradient-to-br from-cyan-950/40 via-slate-900 to-slate-900 border border-cyan-500/30 shadow-xl relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 text-7xl opacity-10">🚀</div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-cyan-400">Self-Service Skip Rate</span>
                    <div class="text-4xl font-extrabold text-white mt-2">
                        <!-- Null means there were no skips to divide by, so there is
                             no rate yet. Rendering it raw printed a bare "%". -->
                        <template v-if="report.self_service_pct !== null">{{ report.self_service_pct }}%</template>
                        <span v-else class="text-slate-500">—</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">
                        <template v-if="report.self_service_pct !== null">
                            (Self + Recurring) / Total Skips ({{ report.total_active_skips }} total skips)
                        </template>
                        <template v-else>
                            No skips recorded in this period yet
                        </template>
                    </p>
                </div>

                <!-- Logins Overview -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl">
                    <span class="text-xs font-semibold uppercase tracking-wider text-emerald-400">Portal Logins Summary</span>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between border-b border-slate-800/80 pb-1.5">
                            <span class="text-slate-400">Total Active Employees:</span>
                            <span class="font-bold text-slate-200">{{ report.employees.total_active }}</span>
                        </div>
                        <div class="flex justify-between border-b border-slate-800/80 pb-1.5">
                            <span class="text-slate-400">Login Accounts Created:</span>
                            <span class="font-bold text-slate-200">{{ report.employees.logins_created }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Logged in At Least Once:</span>
                            <span class="font-bold text-emerald-400">{{ report.employees.logged_in_at_least_once }}</span>
                        </div>
                    </div>
                </div>

                <!-- HR Workload Alert Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl">
                    <span class="text-xs font-semibold uppercase tracking-wider text-amber-400">HR Manual Burden</span>
                    <div class="text-3xl font-extrabold text-white mt-2">
                        {{ report.days_with_20_plus_hr_skips }}
                    </div>
                    <p class="text-xs text-slate-400 mt-2">
                        Days with ≥ 20 HR manual skips in selected period
                    </p>
                </div>
            </div>

            <!-- Skip Breakdown by Source -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl">
                <h2 class="text-base font-bold text-slate-200 mb-4">Channel Breakdown (Active Skips)</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center">
                        <span class="text-xs text-slate-400 block font-medium">Self Portal</span>
                        <span class="text-xl font-bold text-cyan-400 mt-1 block">{{ report.skips_by_source.self }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center">
                        <span class="text-xs text-slate-400 block font-medium">Recurring</span>
                        <span class="text-xl font-bold text-emerald-400 mt-1 block">{{ report.skips_by_source.recurring }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center">
                        <span class="text-xs text-slate-400 block font-medium">HR Manual</span>
                        <span class="text-xl font-bold text-amber-400 mt-1 block">{{ report.skips_by_source.hr }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center">
                        <span class="text-xs text-slate-400 block font-medium">Leave CSV</span>
                        <span class="text-xl font-bold text-purple-400 mt-1 block">{{ report.skips_by_source.leave }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center">
                        <span class="text-xs text-slate-400 block font-medium">WFH CSV</span>
                        <span class="text-xl font-bold text-indigo-400 mt-1 block">{{ report.skips_by_source.wfh }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-slate-700/50 text-center opacity-60">
                        <span class="text-xs text-slate-400 block font-medium">Link (Reserved)</span>
                        <span class="text-xl font-bold text-slate-400 mt-1 block">{{ report.skips_by_source.link }}</span>
                    </div>
                </div>
            </div>

            <!-- Daily Series Table -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl overflow-hidden">
                <h2 class="text-base font-bold text-slate-200 mb-4">Daily Series (Working Meal Days)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] tracking-wider font-semibold">
                            <tr>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">HR Manual Skips</th>
                                <th class="py-3 px-4">Self-Service Skips (Self + Recurring)</th>
                                <th class="py-3 px-4">Total Skips</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="day in report.daily_series" :key="day.date" class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 font-mono text-slate-200">{{ day.date }}</td>
                                <td class="py-3 px-4">
                                    <span :class="day.manual_skips >= 20 ? 'text-amber-400 font-bold' : 'text-slate-300'">
                                        {{ day.manual_skips }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-cyan-400 font-semibold">{{ day.self_service_skips }}</td>
                                <td class="py-3 px-4 text-white font-bold">{{ day.total_skips }}</td>
                            </tr>
                            <tr v-if="report.daily_series.length === 0">
                                <td colspan="4" class="py-6 text-center text-slate-500">
                                    No meal days found in the selected date range.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
