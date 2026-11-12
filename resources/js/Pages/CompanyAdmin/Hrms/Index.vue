<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    company: { type: Object, required: true },
    connection: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    employee_hints: { type: Array, default: () => [] },
    pull: { type: Object, default: () => ({ adapters: [] }) },
    attendance: { type: Object, default: () => ({}) },
    pull_test: { type: Object, default: null },
    new_secret: { type: String, default: null },
    test_result: { type: Object, default: null },
});

const testForm = useForm({
    event: 'leave_approved',
    employee: props.employee_hints[0]?.external_id || '',
    from: '',
    to: '',
});

const rotate = () => {
    const verb = props.connection.has_secret ? 'Rotate' : 'Generate';
    if (!confirm(`${verb} the webhook secret? The old secret stops working immediately.`)) {
        return;
    }
    testForm.transform(() => ({})).post('/company-admin/hrms/secret', { preserveScroll: true });
};

// The password is write-only: it is never sent to this page, so the field starts
// blank and an empty value means "keep the stored one".
const pullForm = useForm({
    base_url: props.pull.base_url || '',
    email: props.pull.email || '',
    password: '',
    adapter: props.pull.adapter || props.pull.adapters[0] || 'cyberpulse',
});

// Write-only, exactly like the pull password: the key is never sent to this
// page, so the field starts blank and an empty value means "keep the stored
// one". Someone opening this form and saving it without retyping a key they
// cannot see must not clear it.
const attendanceForm = useForm({ attendance_api_key: '' });

const saveAttendanceKey = () => {
    attendanceForm.post('/company-admin/hrms/attendance-key', {
        preserveScroll: true,
        onSuccess: () => attendanceForm.reset('attendance_api_key'),
    });
};

const forgetAttendanceKey = () => {
    if (!confirm('Remove the attendance API key? No attendance will be read until a new one is saved.')) {
        return;
    }
    router.delete('/company-admin/hrms/attendance-key', { preserveScroll: true });
};

const savePull = () => {
    pullForm.post('/company-admin/hrms/pull-connection', {
        preserveScroll: true,
        onSuccess: () => pullForm.reset('password'),
    });
};

const pullTesting = ref(false);

const testPull = () => {
    pullTesting.value = true;
    router.post('/company-admin/hrms/pull-test', {}, {
        preserveScroll: true,
        onFinish: () => { pullTesting.value = false; },
    });
};

const pullStatusTone = (status) => ({
    ok: 'bg-mb-tint-emerald/15 text-mb-emerald border-mb-tint-emerald/30',
    suspicious: 'bg-mb-tint-amber/15 text-mb-amber border-mb-tint-amber/30',
    failed: 'bg-mb-tint-rose/15 text-mb-rose border-mb-tint-rose/30',
}[status] || 'bg-mb-line-strong/15 text-mb-text-soft border-mb-line-strong/30');

const sendTest = () => {
    testForm.transform((data) => data).post('/company-admin/hrms/test-event', { preserveScroll: true });
};

const statusTone = (status) => ({
    applied: 'bg-mb-tint-emerald/15 text-mb-emerald border-mb-tint-emerald/30',
    blocked: 'bg-mb-tint-amber/15 text-mb-amber border-mb-tint-amber/30',
    ignored: 'bg-mb-line-strong/15 text-mb-text-soft border-mb-line-strong/30',
    stale: 'bg-mb-line-strong/15 text-mb-text-muted border-mb-line-strong/30',
    failed: 'bg-mb-tint-rose/15 text-mb-rose border-mb-tint-rose/30',
    received: 'bg-mb-tint-indigo/15 text-mb-indigo-soft border-mb-tint-indigo/30',
}[status] || 'bg-mb-line-strong/15 text-mb-text-soft border-mb-line-strong/30');

const dayList = (event) => {
    const parts = [];
    if (event.applied_days.length) parts.push(`+${event.applied_days.join(', ')}`);
    if (event.already_days.length) parts.push(`=${event.already_days.join(', ')}`);
    if (event.released_days.length) parts.push(`−${event.released_days.join(', ')}`);
    return parts;
};

const hasEvents = computed(() => props.events.length > 0);
</script>

<template>
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-mb-text-strong">🔗 HRMS Connection</h1>
                <p class="text-sm text-mb-text-muted">
                    Point your HR system at this URL and it will record leave and WFH as meal skips automatically.
                </p>
            </div>

            <!-- Pull connection: for an HR system that cannot push to us -->
            <div class="bg-mb-card border border-mb-line rounded-2xl p-6 space-y-5">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-base font-bold text-mb-text">⬇️ Pull from your HR system</h2>
                        <p class="text-xs text-mb-text-muted mt-1">
                            Use this when your HR system cannot send us webhooks. We sign in and read approved
                            leave on a schedule — nothing is ever written back to it.
                        </p>
                    </div>
                    <span v-if="pull.last_pull_status" :class="['px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border', pullStatusTone(pull.last_pull_status)]">
                        {{ pull.last_pull_status }}
                    </span>
                </div>

                <p v-if="pull.last_pull_at" class="text-xs text-mb-text-faint">
                    Last run <span class="font-mono text-mb-text-muted">{{ pull.last_pull_at }}</span>
                    <span v-if="pull.last_pull_error" class="text-mb-rose"> — {{ pull.last_pull_error }}</span>
                </p>

                <form @submit.prevent="savePull" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">HR system URL</label>
                        <input v-model="pullForm.base_url" type="url" placeholder="https://hrms.yourcompany.com" required
                               class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono" />
                        <p class="text-[10px] text-mb-text-faint mt-1">Must be https — this sends a password to that host.</p>
                        <p v-if="pullForm.errors.base_url" class="text-xs text-mb-rose mt-1">{{ pullForm.errors.base_url }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Login email</label>
                        <input v-model="pullForm.email" type="email" required
                               class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <p v-if="pullForm.errors.email" class="text-xs text-mb-rose mt-1">{{ pullForm.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Password</label>
                        <input v-model="pullForm.password" type="password" autocomplete="new-password"
                               :placeholder="pull.has_password ? 'Stored — leave blank to keep it' : 'Required'"
                               class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <!-- Never sent to this page, so it cannot be shown back. -->
                        <p class="text-[10px] mt-1" :class="pull.has_password ? 'text-mb-emerald' : 'text-mb-text-faint'">
                            {{ pull.has_password ? '✓ A password is stored. It is encrypted and never shown again.' : 'No password stored yet.' }}
                        </p>
                        <p v-if="pullForm.errors.password" class="text-xs text-mb-rose mt-1">{{ pullForm.errors.password }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">HR system</label>
                        <select v-model="pullForm.adapter" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong cursor-pointer">
                            <option v-for="name in pull.adapters" :key="name" :value="name">{{ name }}</option>
                        </select>
                        <p v-if="pullForm.errors.adapter" class="text-xs text-mb-rose mt-1">{{ pullForm.errors.adapter }}</p>
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" :disabled="pullForm.processing"
                                class="px-4 py-2 bg-mb-tint-cyan hover:bg-mb-tint-cyan disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer">
                            Save connection
                        </button>
                        <button type="button" @click="testPull" :disabled="pullTesting || !pull.has_password"
                                :title="pull.has_password ? 'Fetch and report, without changing anything' : 'Save a password first'"
                                class="px-4 py-2 bg-mb-raised hover:bg-mb-chip disabled:opacity-40 text-mb-text font-bold rounded-lg text-sm border border-mb-line-mid cursor-pointer">
                            {{ pullTesting ? 'Testing…' : 'Test connection' }}
                        </button>
                    </div>
                </form>

                <!-- A dry run: reports what a real one would do, writes nothing -->
                <div
                    v-if="pull_test"
                    :class="[
                        'rounded-xl p-4 border',
                        pull_test.ok ? 'bg-mb-tint-emerald/10 border-mb-tint-emerald/30' : 'bg-mb-tint-rose/10 border-mb-tint-rose/30'
                    ]"
                >
                    <p class="text-sm font-bold" :class="pull_test.ok ? 'text-mb-emerald-soft' : 'text-mb-rose-soft'">
                        {{ pull_test.ok ? '✅ Connected' : '❌ Could not connect' }}
                    </p>
                    <p v-if="pull_test.error" class="text-xs mt-1" :class="pull_test.ok ? 'text-mb-emerald-soft/80' : 'text-mb-rose-soft/90'">
                        {{ pull_test.error }}
                    </p>

                    <div v-if="pull_test.ok" class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div v-for="metric in [
                            { label: 'Fetched', value: pull_test.fetched },
                            { label: 'Would apply', value: pull_test.applied },
                            { label: 'Would cancel', value: pull_test.cancelled },
                            { label: 'Ignored', value: pull_test.ignored },
                            { label: 'Unknown employee', value: pull_test.unknown_employee },
                        ]" :key="metric.label" class="bg-mb-inset/60 rounded-lg px-3 py-2 border border-mb-line">
                            <span class="text-[10px] uppercase font-semibold text-mb-text-muted">{{ metric.label }}</span>
                            <div class="text-lg font-extrabold text-mb-text-strong">{{ metric.value }}</div>
                        </div>
                    </div>

                    <p v-if="pull_test.ok" class="text-[11px] text-mb-text-muted mt-2">
                        Nothing was changed — this was a dry run.
                    </p>

                    <!-- Named, not just counted: an admin can only fix the
                         mapping if they know who failed to match. -->
                    <div v-if="pull_test.unmatched?.length" class="mt-3 rounded-lg bg-mb-tint-amber/10 border border-mb-tint-amber/30 p-3">
                        <p class="text-xs font-bold text-mb-amber-soft">
                            {{ pull_test.unmatched.length }} leave(s) matched no employee in MealBells
                        </p>
                        <p class="text-[11px] text-mb-amber-soft/80 mt-0.5">
                            Set the HR employee id as <span class="font-mono">external_id</span>, or the matching email, on each
                            employee — then test again.
                        </p>
                        <table class="w-full text-left text-[11px] mt-2">
                            <thead class="text-mb-amber-soft/70 uppercase font-semibold">
                                <tr>
                                    <th class="py-1 pr-3">HR employee id</th>
                                    <th class="py-1 pr-3">Email</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-mb-text-soft">
                                <tr v-for="row in pull_test.unmatched" :key="row.leave">
                                    <td class="py-1 pr-3">{{ row.employee_ref || '—' }}</td>
                                    <td class="py-1 pr-3">{{ row.employee_email || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-for="warning in pull_test.warnings" :key="warning" class="text-xs text-mb-amber-soft mt-1">{{ warning }}</p>
                </div>
            </div>

            <!-- Attendance feed. A different endpoint, a different kind of
                 credential, and - in shadow mode - no effect on any count. -->
            <div class="bg-mb-card border border-mb-line rounded-2xl p-5 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-mb-text-strong">🕒 Attendance feed</h3>
                        <p class="text-xs text-mb-text-muted">
                            Reads who had clocked in by your cutoff and reports how many meals would not have been
                            ordered. <span class="text-mb-amber/90">It changes no count and creates no skip.</span>
                        </p>
                    </div>

                    <span
                        :class="[
                            'px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border',
                            attendance.enabled && attendance.has_key
                                ? 'bg-mb-tint-emerald/10 text-mb-emerald-soft border-mb-tint-emerald/30'
                                : 'bg-mb-raised text-mb-text-muted border-mb-line-mid',
                        ]"
                    >
                        {{ attendance.enabled && attendance.has_key ? 'reading' : 'not reading' }}
                    </span>
                </div>

                <!-- Both switches have to be on, and they live in different
                     places, so say which one is missing rather than leaving
                     somebody to guess why nothing happens. -->
                <p v-if="!attendance.base_url_set" class="text-xs text-mb-amber-soft">
                    Save the HR system connection above first — the attendance feed uses the same base URL.
                </p>
                <p v-else-if="!attendance.has_key" class="text-xs text-mb-amber-soft">
                    No attendance key stored, so nothing is being read. Your HR system generates this key.
                </p>
                <p v-else-if="!attendance.enabled" class="text-xs text-mb-amber-soft">
                    A key is stored, but attendance is switched off in
                    <span class="font-semibold">Settings &rarr; Read attendance from the HR system</span>.
                </p>

                <form @submit.prevent="saveAttendanceKey" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Attendance API key</label>
                        <input
                            v-model="attendanceForm.attendance_api_key"
                            type="password"
                            autocomplete="off"
                            :placeholder="attendance.has_key ? 'Stored — leave blank to keep it' : 'Paste the key from your HR system'"
                            class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                        />
                        <p v-if="attendanceForm.errors.attendance_api_key" class="text-xs text-mb-rose mt-1">
                            {{ attendanceForm.errors.attendance_api_key }}
                        </p>
                        <p class="text-[10px] mt-1" :class="attendance.has_key ? 'text-mb-emerald' : 'text-mb-text-faint'">
                            {{ attendance.has_key
                                ? '✓ A key is stored. It is encrypted and never shown again.'
                                : 'No key stored yet.' }}
                        </p>
                    </div>

                    <div class="flex items-end gap-2 pt-5">
                        <button type="submit" :disabled="attendanceForm.processing"
                                class="px-4 py-2 bg-mb-tint-cyan hover:bg-mb-tint-cyan disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer">
                            Save key
                        </button>
                        <button v-if="attendance.has_key" type="button" @click="forgetAttendanceKey"
                                class="px-4 py-2 bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border border-mb-tint-rose/30 rounded-lg text-sm font-semibold cursor-pointer">
                            Remove
                        </button>
                    </div>
                </form>

                <div v-if="attendance.last_pull_at" class="rounded-xl bg-mb-inset/60 border border-mb-line p-4 space-y-2">
                    <p class="text-xs text-mb-text-muted">
                        Last read <span class="font-mono text-mb-text-soft">{{ attendance.last_pull_at }}</span>
                        <span v-if="attendance.last_pull_status"> — {{ attendance.last_pull_status }}</span>
                        <span v-if="attendance.last_pull_error" class="text-mb-rose"> — {{ attendance.last_pull_error }}</span>
                    </p>

                    <div v-if="attendance.last_summary" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div v-for="metric in [
                            { label: 'Clocked in', value: attendance.last_summary.present },
                            { label: 'Absent', value: attendance.last_summary.absent },
                            { label: 'Unknown', value: attendance.last_summary.unknown },
                            { label: 'Not matched', value: attendance.last_summary.unmatched },
                        ]" :key="metric.label" class="bg-mb-card rounded-lg px-3 py-2 border border-mb-line">
                            <span class="text-[10px] uppercase font-semibold text-mb-text-muted">{{ metric.label }}</span>
                            <div class="text-lg font-extrabold text-mb-text-strong">{{ metric.value ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- One-time secret -->
            <div v-if="new_secret" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-2xl p-5 space-y-2">
                <h3 class="font-bold text-mb-amber-soft">🔑 Your new webhook secret</h3>
                <p class="text-xs text-mb-amber-soft/80">
                    Copy it now and paste it into your HR system. It is encrypted at rest and will never be shown again.
                </p>
                <code class="block bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-xs text-mb-emerald-soft break-all">{{ new_secret }}</code>
            </div>

            <!-- Test result -->
            <div
                v-if="test_result"
                :class="[
                    'border rounded-2xl p-4 text-sm',
                    test_result.ok
                        ? 'bg-mb-tint-emerald/10 border-mb-tint-emerald/40 text-mb-emerald-soft'
                        : 'bg-mb-tint-rose/10 border-mb-tint-rose/40 text-mb-rose-soft'
                ]"
            >
                <p class="font-semibold">
                    <template v-if="test_result.dry_run">🧪 Test event built and signed — not delivered</template>
                    <template v-else>{{ test_result.ok ? '✅ Test event accepted' : '❌ Test event failed' }}</template>
                    <span v-if="test_result.status" class="font-mono text-xs">(HTTP {{ test_result.status }})</span>
                </p>
                <p v-if="test_result.dry_run" class="text-xs mt-1">
                    The signature and payload are correct, so your HR system can post the same shape. Delivering it
                    here would cancel a real employee's meal, so it is not sent in production.
                </p>
                <p v-if="test_result.error" class="text-xs mt-1">{{ test_result.error }}</p>
                <p v-if="test_result.ok && !test_result.employee_matched" class="text-xs mt-1">
                    No employee matched that reference, so the event will be recorded as blocked.
                </p>
                <p v-if="test_result.event_status" class="text-xs mt-1">
                    Outcome: <span class="font-bold uppercase">{{ test_result.event_status }}</span>
                    <span v-if="test_result.summary"> — {{ test_result.summary }}</span>
                </p>
                <p v-if="test_result.event_id" class="text-xs mt-1 font-mono">Event ID: {{ test_result.event_id }}</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Connection details -->
                <div class="lg:col-span-2 p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                    <h2 class="text-base font-bold text-mb-text">Endpoint</h2>

                    <div>
                        <label class="text-[11px] uppercase font-semibold text-mb-text-muted">Webhook URL</label>
                        <code class="block bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-xs text-mb-text break-all">{{ connection.webhook_url }}</code>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <span class="text-mb-text-muted">Secret</span>
                            <p>
                                <span v-if="connection.has_secret" class="text-mb-emerald font-semibold">Configured</span>
                                <span v-else class="text-mb-amber font-semibold">Not set yet</span>
                            </p>
                            <p v-if="connection.secret_rotated_at" class="text-mb-text-faint font-mono">
                                Rotated {{ connection.secret_rotated_at }}
                            </p>
                        </div>
                        <div>
                            <span class="text-mb-text-muted">Payload adapter</span>
                            <p class="text-mb-text font-mono">{{ connection.adapter }}</p>
                        </div>
                        <div>
                            <span class="text-mb-text-muted">Signature header</span>
                            <p class="text-mb-text font-mono">{{ connection.signature_header }}</p>
                        </div>
                        <div>
                            <span class="text-mb-text-muted">Timestamp header</span>
                            <p class="text-mb-text font-mono">{{ connection.timestamp_header }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-mb-text-faint">
                        Sign <code class="text-mb-text-soft">HMAC-SHA256("{timestamp}.{raw body}")</code> with the secret.
                        Requests older than {{ connection.tolerance_seconds }}s or larger than
                        {{ Math.round(connection.max_body_bytes / 1024) }}KB are rejected.
                    </p>

                    <button
                        @click="rotate"
                        class="bg-mb-tint-indigo/10 hover:bg-mb-tint-indigo/20 text-mb-indigo-soft border border-mb-tint-indigo/30 text-xs px-3 py-2 rounded-lg font-medium cursor-pointer"
                    >
                        {{ connection.has_secret ? '🔄 Rotate secret' : '🔑 Generate secret' }}
                    </button>
                </div>

                <!-- Send a test event -->
                <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-3">
                    <h2 class="text-base font-bold text-mb-text">Send a test event</h2>
                    <p class="text-xs text-mb-text-muted">
                        Posts a correctly signed event to your own endpoint, exactly as your HR system would.
                    </p>

                    <select v-model="testForm.event" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong">
                        <option value="leave_approved">leave_approved</option>
                        <option value="wfh_approved">wfh_approved</option>
                        <option value="leave_cancelled">leave_cancelled</option>
                    </select>

                    <input
                        v-model="testForm.employee"
                        placeholder="Employee reference (external id or code)"
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong"
                    />

                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="testForm.from" type="date" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <input v-model="testForm.to" type="date" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <p class="text-[11px] text-mb-text-faint">Leave the dates empty to use the next day the count is still open.</p>

                    <button
                        @click="sendTest"
                        :disabled="testForm.processing || !connection.has_secret"
                        class="w-full bg-mb-tint-cyan hover:bg-mb-tint-cyan disabled:opacity-40 text-mb-on-accent font-bold py-2 rounded-lg text-sm cursor-pointer"
                    >
                        Send test event
                    </button>
                    <p v-if="!connection.has_secret" class="text-[11px] text-mb-amber">Generate a secret first.</p>

                    <div v-if="employee_hints.length" class="pt-2 border-t border-mb-line">
                        <p class="text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Known references</p>
                        <ul class="text-xs text-mb-text-muted space-y-0.5">
                            <li v-for="hint in employee_hints" :key="hint.employee_code" class="font-mono">
                                {{ hint.external_id }} · {{ hint.name }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Recent events -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line">
                <h2 class="text-base font-bold text-mb-text mb-4">Last 20 events</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Event</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4">Received</th>
                                <th class="py-3 px-4">Days</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="event in events" :key="event.id" class="hover:bg-mb-raised/30 align-top">
                                <td class="py-3 px-4 font-mono text-xs text-mb-text-muted">{{ event.external_event_id }}</td>
                                <td class="py-3 px-4 text-xs text-mb-text-muted">{{ event.event_type }}</td>
                                <td class="py-3 px-4">
                                    <span :class="['px-2 py-0.5 rounded-full text-[11px] font-bold uppercase border', statusTone(event.status)]">
                                        {{ event.status }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono text-xs text-mb-text-muted">{{ event.received_at }}</td>
                                <td class="py-3 px-4 text-xs space-y-0.5">
                                    <div v-for="part in dayList(event)" :key="part" class="font-mono text-mb-text-soft">{{ part }}</div>
                                    <div v-for="blocked in event.blocked_days" :key="blocked.date + blocked.reason" class="text-mb-amber font-mono">
                                        {{ blocked.date }} ({{ blocked.reason }})
                                    </div>
                                    <div v-if="event.error" class="text-mb-rose">{{ event.error }}</div>
                                    <div v-for="note in event.notes" :key="note" class="text-mb-text-faint">{{ note }}</div>
                                </td>
                            </tr>
                            <tr v-if="!hasEvents">
                                <td colspan="5" class="py-6 text-center text-mb-text-faint">
                                    No events received yet. Send a test event to check the connection.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
