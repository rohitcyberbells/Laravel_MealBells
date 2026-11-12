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
                    <h1 class="text-2xl font-bold text-mb-text-strong tracking-tight">📈 Employee Adoption Report</h1>
                    <p class="text-sm text-mb-text-muted">
                        Aggregate skip channels and employee portal usage metrics ({{ report.from_date }} to {{ report.to_date }})
                    </p>
                </div>

                <div class="inline-flex rounded-xl bg-mb-card p-1 border border-mb-line">
                    <button
                        @click="changeRange(7)"
                        :class="[
                            'px-4 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                            range === 7
                                ? 'bg-mb-tint-cyan text-mb-on-accent shadow-md'
                                : 'text-mb-text-muted hover:text-mb-text-strong'
                        ]"
                    >
                        Last 7 Days
                    </button>
                    <button
                        @click="changeRange(30)"
                        :class="[
                            'px-4 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer',
                            range === 30
                                ? 'bg-mb-tint-cyan text-mb-on-accent shadow-md'
                                : 'text-mb-text-muted hover:text-mb-text-strong'
                        ]"
                    >
                        Last 30 Days
                    </button>
                </div>
            </div>

            <!-- Top Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Large Self Service Metric -->
                <div class="p-6 rounded-2xl bg-gradient-to-br from-mb-tint-cyan/10 via-mb-card to-mb-card border border-mb-tint-cyan/30 shadow-xl relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 text-7xl opacity-10">🚀</div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-mb-cyan">Self-Service Skip Rate</span>
                    <div class="text-4xl font-extrabold text-mb-text-strong mt-2">
                        <!-- Null means there were no skips to divide by, so there is
                             no rate yet. Rendering it raw printed a bare "%". -->
                        <template v-if="report.self_service_pct !== null">{{ report.self_service_pct }}%</template>
                        <span v-else class="text-mb-text-faint">—</span>
                    </div>
                    <p class="text-xs text-mb-text-muted mt-2">
                        <template v-if="report.self_service_pct !== null">
                            (Self + Recurring) / Total Skips ({{ report.total_active_skips }} total skips)
                        </template>
                        <template v-else>
                            No skips recorded in this period yet
                        </template>
                    </p>
                </div>

                <!-- Logins Overview -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl">
                    <span class="text-xs font-semibold uppercase tracking-wider text-mb-emerald">Portal Logins Summary</span>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between border-b border-mb-line/80 pb-1.5">
                            <span class="text-mb-text-muted">Total Active Employees:</span>
                            <span class="font-bold text-mb-text">{{ report.employees.total_active }}</span>
                        </div>
                        <div class="flex justify-between border-b border-mb-line/80 pb-1.5">
                            <span class="text-mb-text-muted">Login Accounts Created:</span>
                            <span class="font-bold text-mb-text">{{ report.employees.logins_created }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-mb-text-muted">Logged in At Least Once:</span>
                            <span class="font-bold text-mb-emerald">{{ report.employees.logged_in_at_least_once }}</span>
                        </div>
                    </div>
                </div>

                <!-- HR Workload Alert Card -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl">
                    <span class="text-xs font-semibold uppercase tracking-wider text-mb-amber">HR Manual Burden</span>
                    <div class="text-3xl font-extrabold text-mb-text-strong mt-2">
                        {{ report.days_with_20_plus_hr_skips }}
                    </div>
                    <p class="text-xs text-mb-text-muted mt-2">
                        Days with ≥ 20 HR manual skips in selected period
                    </p>
                </div>
            </div>

            <!-- Skip Breakdown by Source -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl">
                <h2 class="text-base font-bold text-mb-text mb-4">Channel Breakdown (Active Skips)</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center">
                        <span class="text-xs text-mb-text-muted block font-medium">Self Portal</span>
                        <span class="text-xl font-bold text-mb-cyan mt-1 block">{{ report.skips_by_source.self }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center">
                        <span class="text-xs text-mb-text-muted block font-medium">Recurring</span>
                        <span class="text-xl font-bold text-mb-emerald mt-1 block">{{ report.skips_by_source.recurring }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center">
                        <span class="text-xs text-mb-text-muted block font-medium">HR Manual</span>
                        <span class="text-xl font-bold text-mb-amber mt-1 block">{{ report.skips_by_source.hr }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center">
                        <span class="text-xs text-mb-text-muted block font-medium">Leave CSV</span>
                        <span class="text-xl font-bold text-purple-400 mt-1 block">{{ report.skips_by_source.leave }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center">
                        <span class="text-xs text-mb-text-muted block font-medium">WFH CSV</span>
                        <span class="text-xl font-bold text-mb-indigo mt-1 block">{{ report.skips_by_source.wfh }}</span>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line-mid/50 text-center opacity-60">
                        <span class="text-xs text-mb-text-muted block font-medium">Link (Reserved)</span>
                        <span class="text-xl font-bold text-mb-text-muted mt-1 block">{{ report.skips_by_source.link }}</span>
                    </div>
                </div>
            </div>

            <!-- Daily Series Table -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl overflow-hidden">
                <h2 class="text-base font-bold text-mb-text mb-4">Daily Series (Working Meal Days)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] tracking-wider font-semibold">
                            <tr>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">HR Manual Skips</th>
                                <th class="py-3 px-4">Self-Service Skips (Self + Recurring)</th>
                                <th class="py-3 px-4">Total Skips</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="day in report.daily_series" :key="day.date" class="hover:bg-mb-raised/30">
                                <td class="py-3 px-4 font-mono text-mb-text">{{ day.date }}</td>
                                <td class="py-3 px-4">
                                    <span :class="day.manual_skips >= 20 ? 'text-mb-amber font-bold' : 'text-mb-text-soft'">
                                        {{ day.manual_skips }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-mb-cyan font-semibold">{{ day.self_service_skips }}</td>
                                <td class="py-3 px-4 text-mb-text-strong font-bold">{{ day.total_skips }}</td>
                            </tr>
                            <tr v-if="report.daily_series.length === 0">
                                <td colspan="4" class="py-6 text-center text-mb-text-faint">
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
