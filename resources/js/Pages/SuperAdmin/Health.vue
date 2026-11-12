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
    failed_jobs: {
        type: Object,
        default: () => ({ count: 0, by_queue: [], retry_command: 'php artisan queue:retry all' }),
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
    backup: {
        type: Object,
        default: () => ({ ever_run: false, ok: false, is_stale: true }),
    },
    hrms_pulls: {
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
    { label: 'Failed today', value: props.hrms.today.failed, tone: 'text-mb-rose' },
    { label: 'Blocked today', value: props.hrms.today.blocked, tone: 'text-mb-amber' },
    { label: 'Stale today', value: props.hrms.today.stale, tone: 'text-mb-text-soft' },
    { label: 'Failed 7d', value: props.hrms.last_7_days.failed, tone: 'text-mb-rose' },
    { label: 'Blocked 7d', value: props.hrms.last_7_days.blocked, tone: 'text-mb-amber' },
    { label: 'Stale 7d', value: props.hrms.last_7_days.stale, tone: 'text-mb-text-soft' },
]);

const stalePulls = computed(() => props.hrms_pulls.filter((p) => p.is_stale));

// 'suspicious' means the run finished but a safety guard stopped it acting, so
// the data is stale rather than wrong - amber, not red.
const pullTone = (pull) => {
    if (pull.status === 'ok') return 'bg-mb-tint-emerald/20 text-mb-emerald';
    if (pull.status === 'suspicious') return 'bg-mb-tint-amber/20 text-mb-amber';
    if (pull.status === 'failed') return 'bg-mb-tint-rose/20 text-mb-rose';
    return 'bg-mb-chip text-mb-text-soft';
};

</script>

<template>
    <AppLayout>
    <div>
        <!-- Top Navigation -->

        <!-- Main Body -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-mb-text-strong">🩺 Platform System Health</h1>
                <p class="text-sm text-mb-text-muted">Real-time status of scheduler heartbeat, queue worker failures, and snapshot locks</p>
            </div>

            <!-- Health Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Scheduler Heartbeat Card -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase text-mb-text-muted">Scheduler Status</span>
                        <span
                            :class="[
                                'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                                scheduler.is_stale
                                    ? 'bg-mb-tint-rose/20 text-mb-rose border border-mb-tint-rose/40 animate-pulse'
                                    : 'bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/40'
                            ]"
                        >
                            {{ scheduler.is_stale ? '⚠️ STALE (>3m)' : '✅ HEALTHY' }}
                        </span>
                    </div>
                    <div class="text-3xl font-extrabold text-mb-text-strong">
                        {{ scheduler.minutes_ago !== null ? `${scheduler.minutes_ago} min ago` : 'Never' }}
                    </div>
                    <p class="text-xs text-mb-text-muted">
                        Cache Heartbeat: {{ scheduler.last_run_timestamp ? new Date(scheduler.last_run_timestamp * 1000).toLocaleString() : 'No heartbeat recorded' }}
                    </p>
                </div>

                <!-- Queue Failed Jobs Card -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-mb-text-muted">Queue Failed Jobs</span>
                    <div class="text-3xl font-extrabold text-mb-text-strong">
                        <span :class="failed_jobs_count > 0 ? 'text-mb-rose' : 'text-mb-emerald'">
                            {{ failed_jobs_count }}
                        </span>
                    </div>
                    <p class="text-xs text-mb-text-muted">
                        Jobs in `failed_jobs` table requiring investigation
                    </p>

                    <!-- A count alone is not actionable: the oldest one says
                         whether this is a burst from minutes ago or something
                         that has been sitting for a week. -->
                    <template v-if="failed_jobs.count > 0">
                        <p class="text-[11px] text-mb-text-faint">
                            Oldest <span class="font-mono text-mb-text-muted">{{ failed_jobs.oldest_at }}</span>
                        </p>
                        <div v-if="failed_jobs.by_queue?.length" class="space-y-1">
                            <p v-for="q in failed_jobs.by_queue" :key="q.queue" class="text-[11px] text-mb-text-muted">
                                <span class="font-mono">{{ q.queue }}</span> — {{ q.total }}
                            </p>
                        </div>
                        <!-- Printed rather than offered as a button: retrying
                             blindly can re-apply work someone should look at. -->
                        <code class="block bg-mb-inset border border-mb-line rounded px-2 py-1 text-[11px] text-mb-amber-soft select-all">
                            {{ failed_jobs.retry_command }}
                        </code>
                        <p class="text-[10px] text-mb-text-faint">
                            Inspect first with <span class="font-mono">php artisan queue:failed</span>.
                        </p>
                    </template>
                </div>

                <!-- Locked Snapshots Card -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-mb-text-muted">Snapshots Locked (Last 24h)</span>
                    <div class="text-3xl font-extrabold text-mb-indigo">
                        {{ snapshots_last_24h }}
                    </div>
                    <p class="text-xs text-mb-text-muted">
                        Confirmed meal count records locked across all companies
                    </p>
                </div>
            </div>

            <!-- Paired but never configured: the cutoff job skips these entirely -->
            <div v-if="unconfigured_companies.length > 0" class="p-5 rounded-2xl bg-mb-tint-amber/10 border border-mb-tint-amber/40">
                <h2 class="text-sm font-bold text-mb-amber-soft">
                    ⚠️ {{ unconfigured_companies.length }} paired company(ies) have no settings yet
                </h2>
                <p class="text-xs text-mb-amber-soft/80 mt-1">
                    The cutoff job skips a company with no settings row, so it will never produce a snapshot.
                    Set a cutoff time, timezone and meal days for:
                    <span class="font-semibold">{{ unconfigured_companies.map(c => c.company_name).join(', ') }}</span>
                </p>
            </div>

            <!-- A backup that silently stopped is only discovered when
                 someone needs it, which is the worst possible moment. -->
            <div
                :class="[
                    'p-6 rounded-2xl border shadow-xl space-y-3',
                    backup.ok && !backup.is_stale
                        ? 'bg-mb-card border-mb-line'
                        : 'bg-mb-tint-rose/10 border-mb-tint-rose/40'
                ]"
            >
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-base font-bold text-mb-text">💾 Database backup</h2>
                        <p class="text-xs text-mb-text-muted">
                            Taken nightly. Local copies only — an off-host copy is a server-side job.
                        </p>
                    </div>
                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border',
                            backup.ok && !backup.is_stale
                                ? 'bg-mb-tint-emerald/20 text-mb-emerald border-mb-tint-emerald/40'
                                : 'bg-mb-tint-rose/20 text-mb-rose border-mb-tint-rose/40 animate-pulse'
                        ]"
                    >
                        {{ !backup.ever_run ? '⚠️ never run' : (!backup.ok ? '❌ failed' : (backup.is_stale ? '⚠️ stale' : '✅ ok')) }}
                    </span>
                </div>

                <p v-if="backup.ever_run" class="text-xs text-mb-text-muted">
                    Last run <span class="font-mono text-mb-text-soft">{{ backup.at }}</span>
                    <span v-if="backup.hours_ago !== null"> ({{ backup.hours_ago }}h ago)</span>
                    <span v-if="backup.size_kb"> · {{ backup.size_kb }} KB</span>
                </p>

                <p v-if="backup.error" class="text-xs text-mb-rose-soft">{{ backup.error }}</p>

                <p v-if="!backup.ok || backup.is_stale" class="text-xs text-mb-rose-soft/90">
                    Nothing here is recoverable without one. See
                    <span class="font-mono">docs/backup-restore.md</span>, and check the scheduler.
                </p>
            </div>

            <!-- HRMS Pull Health: companies we poll rather than receive from -->
            <div v-if="hrms_pulls.length" class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl space-y-5">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-base font-bold text-mb-text">⬇️ HRMS Pulls</h2>
                        <p class="text-xs text-mb-text-muted">
                            Companies whose HRMS has no webhooks, so leave is polled instead.
                        </p>
                    </div>
                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                            stalePulls.length > 0
                                ? 'bg-mb-tint-amber/20 text-mb-amber border border-mb-tint-amber/40 animate-pulse'
                                : 'bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/40'
                        ]"
                    >
                        {{ stalePulls.length > 0 ? `⚠️ ${stalePulls.length} NOT RUNNING` : '✅ ALL RUNNING' }}
                    </span>
                </div>

                <!-- A pull that stopped looks exactly like a quiet day of no
                     leave, so staleness is called out rather than inferred. -->
                <div v-if="stalePulls.length > 0" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30">
                    <p class="text-sm font-semibold text-mb-amber-soft">
                        {{ stalePulls.length }} company pull(s) have not run recently.
                    </p>
                    <p class="text-xs text-mb-amber-soft/80 mt-1">
                        Leave approved in the HR system is not reaching the count. Check the scheduler and the vendor credentials.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Company</th>
                                <th class="py-3 px-4">Source</th>
                                <th class="py-3 px-4">Last run</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4 text-right">Applied</th>
                                <th class="py-3 px-4 text-right">Cancelled</th>
                                <th class="py-3 px-4 text-right">Ignored</th>
                                <th class="py-3 px-4 text-right">Unknown</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <template v-for="pull in hrms_pulls" :key="pull.company_id">
                                <tr class="hover:bg-mb-raised/30">
                                    <td class="py-3 px-4 font-semibold text-mb-text">
                                        {{ pull.company_name }}
                                        <span class="font-mono text-[11px] text-mb-text-faint">{{ pull.company_code }}</span>
                                    </td>
                                    <td class="py-3 px-4 text-mb-text-muted">{{ pull.adapter || '—' }}</td>
                                    <td class="py-3 px-4 font-mono text-xs" :class="pull.is_stale ? 'text-mb-amber' : 'text-mb-text-muted'">
                                        {{ pull.last_pull_at || 'never' }}
                                        <span v-if="pull.minutes_ago !== null" class="text-mb-text-faint">({{ pull.minutes_ago }} min ago)</span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span :class="['px-2 py-0.5 rounded text-[11px] font-bold uppercase', pullTone(pull)]">
                                            {{ pull.status || 'never run' }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-bold text-mb-text">{{ pull.applied ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right font-bold text-mb-text">{{ pull.cancelled ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-mb-text-muted">{{ pull.ignored ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right" :class="pull.unknown_employee > 0 ? 'text-mb-amber font-bold' : 'text-mb-text-faint'">
                                        {{ pull.unknown_employee ?? '—' }}
                                    </td>
                                </tr>
                                <tr v-if="pull.error || pull.warnings.length" class="bg-mb-inset/40">
                                    <td colspan="8" class="py-2 px-4 text-xs space-y-1">
                                        <p v-if="pull.error" class="text-mb-rose-soft">{{ pull.error }}</p>
                                        <p v-for="warning in pull.warnings" :key="warning" class="text-mb-amber-soft">{{ warning }}</p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- HRMS Webhook Health -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl space-y-5">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-base font-bold text-mb-text">🔗 HRMS Webhook Events</h2>
                        <p class="text-xs text-mb-text-muted">
                            Last event:
                            <span class="font-mono text-mb-text-soft">{{ hrms.last_event_at || 'none yet' }}</span>
                            · {{ hrms.total_events }} total
                        </p>
                    </div>
                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                            hrms.stuck_count > 0
                                ? 'bg-mb-tint-amber/20 text-mb-amber border border-mb-tint-amber/40 animate-pulse'
                                : 'bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/40'
                        ]"
                    >
                        {{ hrms.stuck_count > 0 ? `⚠️ ${hrms.stuck_count} STUCK` : '✅ NONE STUCK' }}
                    </span>
                </div>

                <div v-if="hrms.stuck_count > 0" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30">
                    <p class="text-sm font-semibold text-mb-amber-soft">
                        {{ hrms.stuck_count }} event(s) accepted but never processed for over {{ hrms.stale_after_minutes }} minutes.
                    </p>
                    <p class="text-xs text-mb-amber-soft/80 mt-1">
                        Usually the queue worker is not running. Check `php artisan queue:work`.
                    </p>
                </div>

                <div v-if="hrms.abandoned_count > 0" class="p-4 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30">
                    <p class="text-sm font-semibold text-mb-rose-soft">
                        {{ hrms.abandoned_count }} failed event(s) gave up after the retry limit and need a human.
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div v-for="metric in hrmsMetrics" :key="metric.label" class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line">
                        <span class="text-[11px] font-semibold uppercase text-mb-text-muted">{{ metric.label }}</span>
                        <div class="text-2xl font-extrabold mt-1" :class="metric.value > 0 ? metric.tone : 'text-mb-text-faint'">
                            {{ metric.value }}
                        </div>
                    </div>
                </div>

                <div v-if="hrms.stuck_events.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Event ID</th>
                                <th class="py-3 px-4">Company</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Received</th>
                                <th class="py-3 px-4">Waiting</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="event in hrms.stuck_events" :key="event.id" class="hover:bg-mb-raised/30">
                                <td class="py-3 px-4 font-mono text-mb-text-muted">{{ event.external_event_id }}</td>
                                <td class="py-3 px-4 font-semibold text-mb-text">{{ event.company_name || '—' }}</td>
                                <td class="py-3 px-4 text-mb-text-muted">{{ event.event_type }}</td>
                                <td class="py-3 px-4 font-mono text-mb-text-muted">{{ event.created_at }}</td>
                                <td class="py-3 px-4 text-mb-amber font-bold">{{ event.minutes_waiting }} min</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Missing Snapshots Table -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line shadow-xl">
                <h2 class="text-base font-bold text-mb-text mb-4">Missing Today Snapshots (Cutoff Passed)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Company ID</th>
                                <th class="py-3 px-4">Company Name</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Cutoff Time</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="item in missing_snapshots_today" :key="item.company_id" class="hover:bg-mb-raised/30">
                                <td class="py-3 px-4 font-mono text-mb-text-muted">#{{ item.company_id }}</td>
                                <td class="py-3 px-4 font-semibold text-mb-text">{{ item.company_name }}</td>
                                <td class="py-3 px-4 font-mono text-mb-text-soft">{{ item.date }}</td>
                                <td class="py-3 px-4 text-mb-text-muted">{{ item.cutoff_time }}</td>
                                <td class="py-3 px-4 text-mb-rose font-bold">⚠️ MISSING SNAPSHOT</td>
                            </tr>
                            <tr v-if="missing_snapshots_today.length === 0">
                                <td colspan="5" class="py-6 text-center text-mb-emerald font-medium">
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
