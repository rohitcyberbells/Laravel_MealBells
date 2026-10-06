<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    company: { type: Object, required: true },
    connection: { type: Object, required: true },
    events: { type: Array, default: () => [] },
    employee_hints: { type: Array, default: () => [] },
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
