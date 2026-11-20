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

// A colour for the word and one for the dot beside it. Filled capsules before,
// which on a page that is mostly status read as a row of badges rather than as
// the one thing that needs attention.
const TONES = {
    ok: { text: 'text-mb-emerald', dot: 'bg-mb-emerald' },
    warn: { text: 'text-mb-amber', dot: 'bg-mb-amber' },
    bad: { text: 'text-mb-rose dark:text-mb-rose-soft', dot: 'bg-mb-rose' },
    neutral: { text: 'text-mb-text-muted dark:text-mb-text-soft', dot: 'bg-mb-text-faint' },
};

const stalePulls = computed(() => props.hrms_pulls.filter((p) => p.is_stale));

// Switched off on purpose is not the same as stopped, and must not be reported
// as a fault: see routes/console.php, where the leave pull is paused.
const pausedPulls = computed(() => props.hrms_pulls.filter((p) => p.paused));

const schedulerStatus = computed(() => (props.scheduler.is_stale
    ? { tone: TONES.bad, label: `Stale (>${3} min)` }
    : { tone: TONES.ok, label: 'Healthy' }));

const backupStatus = computed(() => {
    if (! props.backup.ever_run) return { tone: TONES.bad, label: 'Never run' };
    if (! props.backup.ok) return { tone: TONES.bad, label: 'Failed' };
    if (props.backup.is_stale) return { tone: TONES.warn, label: 'Stale' };

    return { tone: TONES.ok, label: 'Up to date' };
});

const pullsStatus = computed(() => {
    if (pausedPulls.value.length) return { tone: TONES.neutral, label: 'Paused' };
    if (stalePulls.value.length) return { tone: TONES.warn, label: `${stalePulls.value.length} not running` };

    return { tone: TONES.ok, label: 'All running' };
});

const webhookStatus = computed(() => (props.hrms.stuck_count > 0
    ? { tone: TONES.warn, label: `${props.hrms.stuck_count} stuck` }
    : { tone: TONES.ok, label: 'None stuck' }));

// 'suspicious' means the run finished but a safety guard stopped it acting, so
// the data is stale rather than wrong - amber, not red.
const pullTone = (pull) => {
    if (pull.paused) return TONES.neutral;
    if (pull.status === 'ok') return TONES.ok;
    if (pull.status === 'suspicious') return TONES.warn;
    if (pull.status === 'failed') return TONES.bad;

    return TONES.neutral;
};

</script>

<template>
    <AppLayout>
        <!--
            The same ambient layer the other dashboards use: three soft tinted
            blobs, blurred past recognition, so the frosted cards have
            something to frost. z-0 rather than a negative index, which would
            put them behind the shell's own background.
        -->
    <div class="relative z-10">
        <main class="p-4 sm:p-6 space-y-5">
            <header>
                <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">System health</h1>
                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                    Scheduler heartbeat, queue failures, backups, and whether leave is reaching the count.
                </p>
            </header>

            <!-- Health Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5">
                <!-- Scheduler Heartbeat Card -->
                <div class="min-w-0 p-5 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-mb-text-soft">Scheduler</span>
                        <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap', schedulerStatus.tone.text]">
                            <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', schedulerStatus.tone.dot]" aria-hidden="true"></span>
                            {{ schedulerStatus.label }}
                        </span>
                    </div>
                    <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">
                        {{ scheduler.minutes_ago !== null ? `${scheduler.minutes_ago} min` : 'Never' }}
                        <span v-if="scheduler.minutes_ago !== null" class="text-base font-normal text-mb-text-muted dark:text-mb-text-soft"> ago</span>
                    </p>
                    <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">
                        {{ scheduler.last_run_timestamp ? new Date(scheduler.last_run_timestamp * 1000).toLocaleString() : 'No heartbeat recorded' }}
                    </p>
                </div>

                <!-- Queue Failed Jobs Card -->
                <div class="min-w-0 p-5 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line space-y-3">
                    <span class="block text-xs font-semibold text-mb-text-soft">Failed jobs</span>
                    <p class="text-4xl font-light leading-none tracking-tight" :class="failed_jobs_count > 0 ? 'text-mb-rose dark:text-mb-rose-soft' : 'text-mb-text-strong'">
                        {{ failed_jobs_count }}
                    </p>
                    <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">
                        Queue jobs that gave up and need a look.
                    </p>

                    <!-- A count alone is not actionable: the oldest one says
                         whether this is a burst from minutes ago or something
                         that has been sitting for a week. -->
                    <template v-if="failed_jobs.count > 0">
                        <p class="text-[11px] text-mb-text-faint">
                            Oldest <span class="font-mono text-mb-text-muted">{{ failed_jobs.oldest_at }}</span>
                        </p>
                        <div v-if="failed_jobs.by_queue?.length" class="space-y-1">
                            <p v-for="q in failed_jobs.by_queue" :key="q.queue" class="text-[11px] text-mb-text-muted dark:text-mb-text-soft">
                                <span class="font-mono">{{ q.queue }}</span> — {{ q.total }}
                            </p>
                        </div>
                        <!-- Printed rather than offered as a button: retrying
                             blindly can re-apply work someone should look at. -->
                        <code class="block bg-mb-inset border border-mb-line rounded-lg px-2 py-1 text-[11px] text-mb-amber select-all">
                            {{ failed_jobs.retry_command }}
                        </code>
                        <p class="text-[11px] text-mb-text-muted dark:text-mb-text-soft">
                            Inspect first with <span class="font-mono">php artisan queue:failed</span>.
                        </p>
                    </template>
                </div>

                <!-- Locked Snapshots Card -->
                <div class="min-w-0 p-5 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line space-y-3">
                    <span class="block text-xs font-semibold text-mb-text-soft">Snapshots locked</span>
                    <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">{{ snapshots_last_24h }}</p>
                    <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">
                        Counts sent to a kitchen in the last 24 hours.
                    </p>
                </div>
            </div>

            <!-- Paired but never configured: the cutoff job skips these entirely -->
            <div v-if="unconfigured_companies.length > 0" class="p-5 rounded-2xl bg-mb-tint-amber/10 border border-mb-tint-amber/40">
                <h2 class="text-sm font-bold text-mb-amber-soft">
                    {{ unconfigured_companies.length }} paired {{ unconfigured_companies.length === 1 ? 'company has' : 'companies have' }} no settings yet
                </h2>
                <p class="text-xs text-mb-amber-soft mt-1">
                    The cutoff job skips a company with no settings row, so it will never produce a snapshot.
                    Set a cutoff time, timezone and meal days for:
                    <span class="font-semibold">{{ unconfigured_companies.map(c => c.company_name).join(', ') }}</span>
                </p>
            </div>

            <!-- A backup that silently stopped is only discovered when
                 someone needs it, which is the worst possible moment. -->
            <div
                :class="[
                    'p-6 rounded-2xl border space-y-3',
                    backup.ok && !backup.is_stale
                        ? 'bg-mb-card/70 backdrop-blur-xl border-mb-line'
                        : 'bg-mb-tint-rose/10 border-mb-tint-rose/40'
                ]"
            >
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Database backup</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Taken nightly. Local copies only — an off-host copy is a server-side job.
                        </p>
                    </div>
                    <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap shrink-0', backupStatus.tone.text]">
                        <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', backupStatus.tone.dot]" aria-hidden="true"></span>
                        {{ backupStatus.label }}
                    </span>
                </div>

                <p v-if="backup.ever_run" class="text-xs text-mb-text-muted dark:text-mb-text-soft">
                    Last run <span class="font-mono text-mb-text-soft">{{ backup.at }}</span>
                    <span v-if="backup.hours_ago !== null"> ({{ backup.hours_ago }}h ago)</span>
                    <span v-if="backup.size_kb"> · {{ backup.size_kb }} KB</span>
                </p>
                <p v-else class="text-xs text-mb-rose dark:text-mb-rose-soft">No backup has ever been recorded.</p>

                <p v-if="backup.error" class="text-xs text-mb-rose dark:text-mb-rose-soft">{{ backup.error }}</p>

                <p v-if="!backup.ok || backup.is_stale" class="text-xs text-mb-rose dark:text-mb-rose-soft">
                    Nothing here is recoverable without one. See
                    <span class="font-mono">docs/backup-restore.md</span>, and check the scheduler.
                </p>
            </div>

            <!-- HRMS Pull Health: companies we poll rather than receive from -->
            <div v-if="hrms_pulls.length" class="p-6 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line space-y-5">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Leave pulls</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Companies whose HR system has no webhooks, so leave is polled instead.
                        </p>
                    </div>
                    <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap shrink-0', pullsStatus.tone.text]">
                        <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', pullsStatus.tone.dot]" aria-hidden="true"></span>
                        {{ pullsStatus.label }}
                    </span>
                </div>

                <!--
                    Paused is reported before stale, and in grey rather than
                    amber, because a job nobody scheduled has not failed. Read
                    from the schedule itself, so this cannot disagree with
                    routes/console.php.
                -->
                <div v-if="pausedPulls.length" class="p-4 rounded-xl bg-mb-inset border border-mb-line">
                    <p class="text-sm font-semibold text-mb-text-strong">
                        The leave pull is switched off.
                    </p>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-1">
                        No entry for it in the schedule, so the timings below are history rather than a fault.
                        Leave is being entered by hand until a vendor login or a webhook arrives.
                        Turn it back on in <span class="font-mono">routes/console.php</span>.
                    </p>
                </div>

                <!-- A pull that stopped looks exactly like a quiet day of no
                     leave, so staleness is called out rather than inferred. -->
                <div v-else-if="stalePulls.length > 0" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30">
                    <p class="text-sm font-semibold text-mb-amber-soft">
                        {{ stalePulls.length }} company {{ stalePulls.length === 1 ? 'pull has' : 'pulls have' }} not run recently.
                    </p>
                    <p class="text-xs text-mb-amber-soft mt-1">
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
                                    <td class="py-3 px-4 text-mb-text-muted dark:text-mb-text-soft capitalize">{{ pull.adapter || '—' }}</td>
                                    <td class="py-3 px-4 font-mono text-xs" :class="pull.is_stale ? 'text-mb-amber' : 'text-mb-text-muted dark:text-mb-text-soft'">
                                        {{ pull.last_pull_at || 'never' }}
                                        <span v-if="pull.minutes_ago !== null" class="text-mb-text-muted dark:text-mb-text-soft">({{ pull.minutes_ago }} min ago)</span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold capitalize whitespace-nowrap', pullTone(pull).text]">
                                            <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', pullTone(pull).dot]" aria-hidden="true"></span>
                                            {{ pull.paused ? 'Paused' : (pull.status || 'never run') }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-semibold text-mb-text-strong tabular-nums">{{ pull.applied ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-mb-text-strong tabular-nums">{{ pull.cancelled ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right text-mb-text-muted dark:text-mb-text-soft tabular-nums">{{ pull.ignored ?? '—' }}</td>
                                    <td class="py-3 px-4 text-right tabular-nums" :class="pull.unknown_employee > 0 ? 'text-mb-amber font-semibold' : 'text-mb-text-muted dark:text-mb-text-soft'">
                                        {{ pull.unknown_employee ?? '—' }}
                                    </td>
                                </tr>
                                <tr v-if="pull.error || pull.warnings.length" class="bg-mb-inset/40">
                                    <td colspan="8" class="py-2 px-4 text-xs space-y-1">
                                        <p v-if="pull.error" class="text-mb-rose dark:text-mb-rose-soft">{{ pull.error }}</p>
                                        <p v-for="warning in pull.warnings" :key="warning" class="text-mb-amber">{{ warning }}</p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- HRMS Webhook Health -->
            <div class="p-6 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line space-y-5">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-bold tracking-tight text-mb-text-strong">Webhook events</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Last event
                            <span class="font-mono text-mb-text-soft">{{ hrms.last_event_at || 'none yet' }}</span>
                            · {{ hrms.total_events }} in total
                        </p>
                    </div>
                    <span :class="['inline-flex items-center gap-1.5 text-[11px] font-semibold whitespace-nowrap shrink-0', webhookStatus.tone.text]">
                        <span :class="['w-1.5 h-1.5 shrink-0 rounded-full', webhookStatus.tone.dot]" aria-hidden="true"></span>
                        {{ webhookStatus.label }}
                    </span>
                </div>

                <div v-if="hrms.stuck_count > 0" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30">
                    <p class="text-sm font-semibold text-mb-amber-soft">
                        {{ hrms.stuck_count }} {{ hrms.stuck_count === 1 ? 'event was' : 'events were' }} accepted but never processed, for over {{ hrms.stale_after_minutes }} minutes.
                    </p>
                    <p class="text-xs text-mb-amber-soft mt-1">
                        Usually the queue worker is not running. Check <span class="font-mono">php artisan queue:work</span>.
                    </p>
                </div>

                <div v-if="hrms.abandoned_count > 0" class="p-4 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30">
                    <p class="text-sm font-semibold text-mb-rose-soft">
                        {{ hrms.abandoned_count }} failed {{ hrms.abandoned_count === 1 ? 'event' : 'events' }} gave up after the retry limit and need a human.
                    </p>
                </div>

                <!-- Six figures with dividers, not six boxes inside this box. -->
                <div class="flex flex-wrap sm:flex-nowrap sm:divide-x divide-mb-line">
                    <div v-for="metric in hrmsMetrics" :key="metric.label" class="min-w-0 py-2 px-4 first:pl-0 last:pr-0">
                        <span class="block text-[10px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">{{ metric.label }}</span>
                        <div class="text-3xl font-light tracking-tight mt-1 tabular-nums" :class="metric.value > 0 ? metric.tone : 'text-mb-text-strong'">
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
            <div class="p-6 rounded-2xl bg-mb-card/70 backdrop-blur-xl border border-mb-line">
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
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-rose dark:text-mb-rose-soft whitespace-nowrap">
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-rose" aria-hidden="true"></span>
                                        Missing snapshot
                                    </span>
                                </td>
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
