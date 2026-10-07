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
    ok: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    suspicious: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
    failed: 'bg-red-500/15 text-red-400 border-red-500/30',
}[status] || 'bg-slate-500/15 text-slate-300 border-slate-500/30');

const sendTest = () => {
    testForm.transform((data) => data).post('/company-admin/hrms/test-event', { preserveScroll: true });
};

const statusTone = (status) => ({
    applied: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    blocked: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
    ignored: 'bg-slate-500/15 text-slate-300 border-slate-500/30',
    stale: 'bg-slate-500/15 text-slate-400 border-slate-500/30',
    failed: 'bg-red-500/15 text-red-400 border-red-500/30',
    received: 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
}[status] || 'bg-slate-500/15 text-slate-300 border-slate-500/30');

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
                <h1 class="text-2xl font-bold text-white">🔗 HRMS Connection</h1>
                <p class="text-sm text-slate-400">
                    Point your HR system at this URL and it will record leave and WFH as meal skips automatically.
                </p>
            </div>

            <!-- Pull connection: for an HR system that cannot push to us -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-5">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-base font-bold text-slate-200">⬇️ Pull from your HR system</h2>
                        <p class="text-xs text-slate-400 mt-1">
                            Use this when your HR system cannot send us webhooks. We sign in and read approved
                            leave on a schedule — nothing is ever written back to it.
                        </p>
                    </div>
                    <span v-if="pull.last_pull_status" :class="['px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border', pullStatusTone(pull.last_pull_status)]">
                        {{ pull.last_pull_status }}
                    </span>
                </div>

                <p v-if="pull.last_pull_at" class="text-xs text-slate-500">
                    Last run <span class="font-mono text-slate-400">{{ pull.last_pull_at }}</span>
                    <span v-if="pull.last_pull_error" class="text-red-400"> — {{ pull.last_pull_error }}</span>
                </p>

                <form @submit.prevent="savePull" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">HR system URL</label>
                        <input v-model="pullForm.base_url" type="url" placeholder="https://hrms.yourcompany.com" required
                               class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono" />
                        <p class="text-[10px] text-slate-500 mt-1">Must be https — this sends a password to that host.</p>
                        <p v-if="pullForm.errors.base_url" class="text-xs text-red-400 mt-1">{{ pullForm.errors.base_url }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Login email</label>
                        <input v-model="pullForm.email" type="email" required
                               class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <p v-if="pullForm.errors.email" class="text-xs text-red-400 mt-1">{{ pullForm.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Password</label>
                        <input v-model="pullForm.password" type="password" autocomplete="new-password"
                               :placeholder="pull.has_password ? 'Stored — leave blank to keep it' : 'Required'"
                               class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <!-- Never sent to this page, so it cannot be shown back. -->
                        <p class="text-[10px] mt-1" :class="pull.has_password ? 'text-emerald-400' : 'text-slate-500'">
                            {{ pull.has_password ? '✓ A password is stored. It is encrypted and never shown again.' : 'No password stored yet.' }}
                        </p>
                        <p v-if="pullForm.errors.password" class="text-xs text-red-400 mt-1">{{ pullForm.errors.password }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">HR system</label>
                        <select v-model="pullForm.adapter" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white cursor-pointer">
                            <option v-for="name in pull.adapters" :key="name" :value="name">{{ name }}</option>
                        </select>
                        <p v-if="pullForm.errors.adapter" class="text-xs text-red-400 mt-1">{{ pullForm.errors.adapter }}</p>
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" :disabled="pullForm.processing"
                                class="px-4 py-2 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-40 text-slate-950 font-bold rounded-lg text-sm cursor-pointer">
                            Save connection
                        </button>
                        <button type="button" @click="testPull" :disabled="pullTesting || !pull.has_password"
                                :title="pull.has_password ? 'Fetch and report, without changing anything' : 'Save a password first'"
                                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-slate-200 font-bold rounded-lg text-sm border border-slate-700 cursor-pointer">
                            {{ pullTesting ? 'Testing…' : 'Test connection' }}
                        </button>
                    </div>
                </form>

                <!-- A dry run: reports what a real one would do, writes nothing -->
                <div
                    v-if="pull_test"
                    :class="[
                        'rounded-xl p-4 border',
                        pull_test.ok ? 'bg-emerald-500/10 border-emerald-500/30' : 'bg-red-500/10 border-red-500/30'
                    ]"
                >
                    <p class="text-sm font-bold" :class="pull_test.ok ? 'text-emerald-300' : 'text-red-300'">
                        {{ pull_test.ok ? '✅ Connected' : '❌ Could not connect' }}
                    </p>
                    <p v-if="pull_test.error" class="text-xs mt-1" :class="pull_test.ok ? 'text-emerald-200/80' : 'text-red-200/90'">
                        {{ pull_test.error }}
                    </p>

                    <div v-if="pull_test.ok" class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div v-for="metric in [
                            { label: 'Fetched', value: pull_test.fetched },
                            { label: 'Would apply', value: pull_test.applied },
                            { label: 'Would cancel', value: pull_test.cancelled },
                            { label: 'Ignored', value: pull_test.ignored },
                            { label: 'Unknown employee', value: pull_test.unknown_employee },
                        ]" :key="metric.label" class="bg-slate-950/60 rounded-lg px-3 py-2 border border-slate-800">
                            <span class="text-[10px] uppercase font-semibold text-slate-400">{{ metric.label }}</span>
                            <div class="text-lg font-extrabold text-slate-100">{{ metric.value }}</div>
                        </div>
                    </div>

                    <p v-if="pull_test.ok" class="text-[11px] text-slate-400 mt-2">
                        Nothing was changed — this was a dry run.
                    </p>

                    <!-- Named, not just counted: an admin can only fix the
                         mapping if they know who failed to match. -->
                    <div v-if="pull_test.unmatched?.length" class="mt-3 rounded-lg bg-amber-500/10 border border-amber-500/30 p-3">
                        <p class="text-xs font-bold text-amber-300">
                            {{ pull_test.unmatched.length }} leave(s) matched no employee in MealBells
                        </p>
                        <p class="text-[11px] text-amber-200/80 mt-0.5">
                            Set the HR employee id as <span class="font-mono">external_id</span>, or the matching email, on each
                            employee — then test again.
                        </p>
                        <table class="w-full text-left text-[11px] mt-2">
                            <thead class="text-amber-200/70 uppercase font-semibold">
                                <tr>
                                    <th class="py-1 pr-3">HR employee id</th>
                                    <th class="py-1 pr-3">Email</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-slate-300">
                                <tr v-for="row in pull_test.unmatched" :key="row.leave">
                                    <td class="py-1 pr-3">{{ row.employee_ref || '—' }}</td>
                                    <td class="py-1 pr-3">{{ row.employee_email || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-for="warning in pull_test.warnings" :key="warning" class="text-xs text-amber-300 mt-1">{{ warning }}</p>
                </div>
            </div>

            <!-- One-time secret -->
            <div v-if="new_secret" class="bg-amber-500/10 border border-amber-500/40 rounded-2xl p-5 space-y-2">
                <h3 class="font-bold text-amber-300">🔑 Your new webhook secret</h3>
                <p class="text-xs text-amber-200/80">
                    Copy it now and paste it into your HR system. It is encrypted at rest and will never be shown again.
                </p>
                <code class="block bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-emerald-300 break-all">{{ new_secret }}</code>
            </div>

            <!-- Test result -->
            <div
                v-if="test_result"
                :class="[
                    'border rounded-2xl p-4 text-sm',
                    test_result.ok
                        ? 'bg-emerald-500/10 border-emerald-500/40 text-emerald-300'
                        : 'bg-red-500/10 border-red-500/40 text-red-300'
                ]"
            >
                <p class="font-semibold">
                    {{ test_result.ok ? '✅ Test event accepted' : '❌ Test event failed' }}
                    <span v-if="test_result.status" class="font-mono text-xs">(HTTP {{ test_result.status }})</span>
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
                <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-4">
                    <h2 class="text-base font-bold text-slate-200">Endpoint</h2>

                    <div>
                        <label class="text-[11px] uppercase font-semibold text-slate-400">Webhook URL</label>
                        <code class="block bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200 break-all">{{ connection.webhook_url }}</code>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <span class="text-slate-400">Secret</span>
                            <p>
                                <span v-if="connection.has_secret" class="text-emerald-400 font-semibold">Configured</span>
                                <span v-else class="text-amber-400 font-semibold">Not set yet</span>
                            </p>
                            <p v-if="connection.secret_rotated_at" class="text-slate-500 font-mono">
                                Rotated {{ connection.secret_rotated_at }}
                            </p>
                        </div>
                        <div>
                            <span class="text-slate-400">Payload adapter</span>
                            <p class="text-slate-200 font-mono">{{ connection.adapter }}</p>
                        </div>
                        <div>
                            <span class="text-slate-400">Signature header</span>
                            <p class="text-slate-200 font-mono">{{ connection.signature_header }}</p>
                        </div>
                        <div>
                            <span class="text-slate-400">Timestamp header</span>
                            <p class="text-slate-200 font-mono">{{ connection.timestamp_header }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-slate-500">
                        Sign <code class="text-slate-300">HMAC-SHA256("{timestamp}.{raw body}")</code> with the secret.
                        Requests older than {{ connection.tolerance_seconds }}s or larger than
                        {{ Math.round(connection.max_body_bytes / 1024) }}KB are rejected.
                    </p>

                    <button
                        @click="rotate"
                        class="bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 text-xs px-3 py-2 rounded-lg font-medium cursor-pointer"
                    >
                        {{ connection.has_secret ? '🔄 Rotate secret' : '🔑 Generate secret' }}
                    </button>
                </div>

                <!-- Send a test event -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-3">
                    <h2 class="text-base font-bold text-slate-200">Send a test event</h2>
                    <p class="text-xs text-slate-400">
                        Posts a correctly signed event to your own endpoint, exactly as your HR system would.
                    </p>

                    <select v-model="testForm.event" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                        <option value="leave_approved">leave_approved</option>
                        <option value="wfh_approved">wfh_approved</option>
                        <option value="leave_cancelled">leave_cancelled</option>
                    </select>

                    <input
                        v-model="testForm.employee"
                        placeholder="Employee reference (external id or code)"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white"
                    />

                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="testForm.from" type="date" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <input v-model="testForm.to" type="date" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                    </div>
                    <p class="text-[11px] text-slate-500">Leave the dates empty to use the next day the count is still open.</p>

                    <button
                        @click="sendTest"
                        :disabled="testForm.processing || !connection.has_secret"
                        class="w-full bg-cyan-500 hover:bg-cyan-600 disabled:opacity-40 text-slate-950 font-bold py-2 rounded-lg text-sm cursor-pointer"
                    >
                        Send test event
                    </button>
                    <p v-if="!connection.has_secret" class="text-[11px] text-amber-400">Generate a secret first.</p>

                    <div v-if="employee_hints.length" class="pt-2 border-t border-slate-800">
                        <p class="text-[11px] uppercase font-semibold text-slate-400 mb-1">Known references</p>
                        <ul class="text-xs text-slate-400 space-y-0.5">
                            <li v-for="hint in employee_hints" :key="hint.employee_code" class="font-mono">
                                {{ hint.external_id }} · {{ hint.name }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Recent events -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800">
                <h2 class="text-base font-bold text-slate-200 mb-4">Last 20 events</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Event</th>
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4">Received</th>
                                <th class="py-3 px-4">Days</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="event in events" :key="event.id" class="hover:bg-slate-800/30 align-top">
                                <td class="py-3 px-4 font-mono text-xs text-slate-400">{{ event.external_event_id }}</td>
                                <td class="py-3 px-4 text-xs text-slate-400">{{ event.event_type }}</td>
                                <td class="py-3 px-4">
                                    <span :class="['px-2 py-0.5 rounded-full text-[11px] font-bold uppercase border', statusTone(event.status)]">
                                        {{ event.status }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono text-xs text-slate-400">{{ event.received_at }}</td>
                                <td class="py-3 px-4 text-xs space-y-0.5">
                                    <div v-for="part in dayList(event)" :key="part" class="font-mono text-slate-300">{{ part }}</div>
                                    <div v-for="blocked in event.blocked_days" :key="blocked.date + blocked.reason" class="text-amber-400 font-mono">
                                        {{ blocked.date }} ({{ blocked.reason }})
                                    </div>
                                    <div v-if="event.error" class="text-red-400">{{ event.error }}</div>
                                    <div v-for="note in event.notes" :key="note" class="text-slate-500">{{ note }}</div>
                                </td>
                            </tr>
                            <tr v-if="!hasEvents">
                                <td colspan="5" class="py-6 text-center text-slate-500">
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
