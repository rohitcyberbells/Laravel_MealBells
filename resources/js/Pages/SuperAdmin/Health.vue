<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || page.props.user);

const props = defineProps({
    scheduler: {
        type: Object,
        required: true,
    },
    failed_jobs_count: {
        type: Number,
        default: 0,
    },
    snapshots_last_24h: {
        type: Number,
        default: 0,
    },
    missing_snapshots_today: {
        type: Array,
        default: () => [],
    },
    unconfigured_companies: {
        type: Array,
        default: () => [],
    },
    hrms: {
        type: Object,
        default: () => ({
            today: { failed: 0, blocked: 0, stale: 0 },
            last_7_days: { failed: 0, blocked: 0, stale: 0 },
            total_events: 0,
            last_event_at: null,
            stale_after_minutes: 10,
            stuck_count: 0,
            stuck_events: [],
            abandoned_count: 0,
        }),
    },
});

const hrmsMetrics = computed(() => [
    { label: 'Failed today', value: props.hrms.today.failed, tone: 'text-red-400' },
    { label: 'Blocked today', value: props.hrms.today.blocked, tone: 'text-amber-400' },
    { label: 'Stale today', value: props.hrms.today.stale, tone: 'text-slate-300' },
    { label: 'Failed 7d', value: props.hrms.last_7_days.failed, tone: 'text-red-400' },
    { label: 'Blocked 7d', value: props.hrms.last_7_days.blocked, tone: 'text-amber-400' },
    { label: 'Stale 7d', value: props.hrms.last_7_days.stale, tone: 'text-slate-300' },
]);

</script>

<template>
    <AppLayout>
    <div>
        <!-- Top Navigation -->

        <!-- Main Body -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-white">🩺 Platform System Health</h1>
                <p class="text-sm text-slate-400">Real-time status of scheduler heartbeat, queue worker failures, and snapshot locks</p>
            </div>

            <!-- Health Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Scheduler Heartbeat Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase text-slate-400">Scheduler Status</span>
                        <span
                            :class="[
                                'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                                scheduler.is_stale
                                    ? 'bg-red-500/20 text-red-400 border border-red-500/40 animate-pulse'
                                    : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40'
                            ]"
                        >
                            {{ scheduler.is_stale ? '⚠️ STALE (>3m)' : '✅ HEALTHY' }}
                        </span>
                    </div>
                    <div class="text-3xl font-extrabold text-white">
                        {{ scheduler.minutes_ago !== null ? `${scheduler.minutes_ago} min ago` : 'Never' }}
                    </div>
                    <p class="text-xs text-slate-400">
                        Cache Heartbeat: {{ scheduler.last_run_timestamp ? new Date(scheduler.last_run_timestamp * 1000).toLocaleString() : 'No heartbeat recorded' }}
                    </p>
                </div>

                <!-- Queue Failed Jobs Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-slate-400">Queue Failed Jobs</span>
                    <div class="text-3xl font-extrabold text-white">
                        <span :class="failed_jobs_count > 0 ? 'text-red-400' : 'text-emerald-400'">
                            {{ failed_jobs_count }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">
                        Jobs in `failed_jobs` table requiring investigation
                    </p>
                </div>

                <!-- Locked Snapshots Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-slate-400">Snapshots Locked (Last 24h)</span>
                    <div class="text-3xl font-extrabold text-indigo-400">
                        {{ snapshots_last_24h }}
                    </div>
                    <p class="text-xs text-slate-400">
                        Confirmed meal count records locked across all companies
                    </p>
                </div>
            </div>

            <!-- Paired but never configured: the cutoff job skips these entirely -->
            <div v-if="unconfigured_companies.length > 0" class="p-5 rounded-2xl bg-amber-500/10 border border-amber-500/40">
                <h2 class="text-sm font-bold text-amber-300">
                    ⚠️ {{ unconfigured_companies.length }} paired company(ies) have no settings yet
                </h2>
                <p class="text-xs text-amber-200/80 mt-1">
                    The cutoff job skips a company with no settings row, so it will never produce a snapshot.
                    Set a cutoff time, timezone and meal days for:
                    <span class="font-semibold">{{ unconfigured_companies.map(c => c.company_name).join(', ') }}</span>
                </p>
            </div>

            <!-- HRMS Webhook Health -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-5">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-200">🔗 HRMS Webhook Events</h2>
                        <p class="text-xs text-slate-400">
                            Last event:
                            <span class="font-mono text-slate-300">{{ hrms.last_event_at || 'none yet' }}</span>
                            · {{ hrms.total_events }} total
                        </p>
                    </div>
                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                            hrms.stuck_count > 0
                                ? 'bg-amber-500/20 text-amber-400 border border-amber-500/40 animate-pulse'
                                : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40'
                        ]"
                    >
                        {{ hrms.stuck_count > 0 ? `⚠️ ${hrms.stuck_count} STUCK` : '✅ NONE STUCK' }}
                    </span>
                </div>

                <div v-if="hrms.stuck_count > 0" class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30">
                    <p class="text-sm font-semibold text-amber-300">
                        {{ hrms.stuck_count }} event(s) accepted but never processed for over {{ hrms.stale_after_minutes }} minutes.
                    </p>
                    <p class="text-xs text-amber-200/80 mt-1">
                        Usually the queue worker is not running. Check `php artisan queue:work`.
                    </p>
                </div>

                <div v-if="hrms.abandoned_count > 0" class="p-4 rounded-xl bg-red-500/10 border border-red-500/30">
                    <p class="text-sm font-semibold text-red-300">
                        {{ hrms.abandoned_count }} failed event(s) gave up after the retry limit and need a human.
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div v-for="metric in hrmsMetrics" :key="metric.label" class="p-4 rounded-xl bg-slate-800/50 border border-slate-800">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">{{ metric.label }}</span>
                        <div class="text-2xl font-extrabold mt-1" :class="metric.value > 0 ? metric.tone : 'text-slate-500'">
                            {{ metric.value }}
                        </div>
                    </div>
                </div>

                <div v-if="hrms.stuck_events.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Event ID</th>
                                <th class="py-3 px-4">Company</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Received</th>
                                <th class="py-3 px-4">Waiting</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="event in hrms.stuck_events" :key="event.id" class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 font-mono text-slate-400">{{ event.external_event_id }}</td>
                                <td class="py-3 px-4 font-semibold text-slate-200">{{ event.company_name || '—' }}</td>
                                <td class="py-3 px-4 text-slate-400">{{ event.event_type }}</td>
                                <td class="py-3 px-4 font-mono text-slate-400">{{ event.created_at }}</td>
                                <td class="py-3 px-4 text-amber-400 font-bold">{{ event.minutes_waiting }} min</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Missing Snapshots Table -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl">
                <h2 class="text-base font-bold text-slate-200 mb-4">Missing Today Snapshots (Cutoff Passed)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Company ID</th>
                                <th class="py-3 px-4">Company Name</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Cutoff Time</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="item in missing_snapshots_today" :key="item.company_id" class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 font-mono text-slate-400">#{{ item.company_id }}</td>
                                <td class="py-3 px-4 font-semibold text-slate-200">{{ item.company_name }}</td>
                                <td class="py-3 px-4 font-mono text-slate-300">{{ item.date }}</td>
                                <td class="py-3 px-4 text-slate-400">{{ item.cutoff_time }}</td>
                                <td class="py-3 px-4 text-red-400 font-bold">⚠️ MISSING SNAPSHOT</td>
                            </tr>
                            <tr v-if="missing_snapshots_today.length === 0">
                                <td colspan="5" class="py-6 text-center text-emerald-400 font-medium">
                                    All companies with passed cutoff have locked snapshots for today!
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    </AppLayout>
</template>
