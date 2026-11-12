<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

const props = defineProps({
    date: String,
    is_meal_day: Boolean,
    count: Object,
    status: String,
    cutoff_time: String,
    seconds_left: Number,
    skips: Array,
    extra_meals: Array,
    employees_for_search: Array,
    snapshot: { type: Object, default: null },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

// Per-employee outcome of the last bulk skip. Flash data, so it clears itself.
const bulkSummary = computed(() => page.props.flash?.bulkSummary ?? null);
const dismissedBulk = ref(false);

const employeeLabel = (id) => {
    const match = props.employees_for_search.find((e) => e.id === id);

    return match ? `${match.name} (${match.employee_code})` : `Employee #${id}`;
};

const bulkApplied = computed(() => {
    const s = bulkSummary.value;

    return s ? s.created_count + s.reactivated_count : 0;
});

// Everything the engine did not newly apply, with the reason it gave.
const bulkNotApplied = computed(() => {
    const s = bulkSummary.value;

    if (!s) return [];

    return (s.results ?? [])
        .filter((r) => !['created', 'reactivated'].includes(r.status))
        .map((r) => ({
            who: employeeLabel(r.employee_id),
            date: r.date,
            // A refusal carries a reason; an already-skipped day does not.
            why: r.reason ?? r.status.replace(/_/g, ' '),
        }));
});

const isLocked = computed(() => props.status === 'locked');

/* ---------- status badge ---------- */

const statusLabel = computed(() => {
    if (props.snapshot?.reviewed_at) return 'Reviewed';
    if (isLocked.value) return 'Locked';
    return 'Draft';
});

const statusTone = computed(() => ({
    Reviewed: 'bg-mb-tint-emerald/20 text-mb-emerald-soft border-mb-tint-emerald/40',
    Locked: 'bg-mb-tint-indigo/20 text-mb-indigo-soft border-mb-tint-indigo/40',
    Draft: 'bg-mb-line-strong/20 text-mb-text-soft border-mb-line-strong/40',
}[statusLabel.value]));

/* ---------- cutoff countdown ---------- */

// Floored once, here. The server sends a float (Carbon's diff carries
// fractional seconds), and `float % 60` produced "18:51:7.715735999998287" on
// screen - the hours and minutes were fine because they go through Math.floor.
const remaining = ref(Math.max(0, Math.floor(props.seconds_left ?? 0)));

const ticker = setInterval(() => {
    if (remaining.value > 0) remaining.value -= 1;
}, 1000);

onUnmounted(() => clearInterval(ticker));

const countdown = computed(() => {
    if (remaining.value <= 0) return null;
    const h = Math.floor(remaining.value / 3600);
    const m = Math.floor((remaining.value % 3600) / 60);
    const s = Math.floor(remaining.value % 60);
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

/* ---------- date navigation ---------- */

const selectedDate = ref(props.date);

const goToDate = () => {
    router.get('/company-admin/daily', { date: selectedDate.value }, { preserveScroll: true });
};

/* ---------- per-employee skip ---------- */

// The skips list carries the employee, so the roster can show each person's
// current state without a second query.
const skipByEmployee = computed(() => {
    const map = {};
    for (const skip of props.skips) {
        if (!skip.cancelled_at) map[skip.employee_id] = skip;
    }
    return map;
});

// Trimmed, so a column of empty subtext does not appear for skips that carry
// no reason at all.
const skipReason = (skip) => (skip?.reason ?? '').trim() || null;

const skipForm = useForm({ employee_id: null, date: props.date, source: 'hr', reason: '' });

const addSkip = (employeeId) => {
    skipForm.employee_id = employeeId;
    skipForm.date = props.date;
    skipForm.post('/company-admin/skips', { preserveScroll: true });
};

const removeSkip = (skipId) => {
    router.delete(`/company-admin/skips/${skipId}`, { preserveScroll: true });
};

/* ---------- bulk skip ---------- */

const selected = ref([]);

const toggleSelected = (employeeId) => {
    const at = selected.value.indexOf(employeeId);
    at === -1 ? selected.value.push(employeeId) : selected.value.splice(at, 1);
};

const bulkForm = useForm({ employee_ids: [], dates: [], source: 'hr', reason: '' });

const submitBulk = () => {
    bulkForm.employee_ids = [...selected.value];
    bulkForm.dates = [props.date];
    bulkForm.post('/company-admin/skips/bulk', {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
    });
};

/* ---------- extra meals ---------- */

const extraForm = useForm({ date: props.date, quantity: 1, type: 'guest', reason: '' });

const submitExtra = () => {
    extraForm.date = props.date;
    extraForm.post('/company-admin/extra-meals', {
        preserveScroll: true,
        onSuccess: () => extraForm.reset('quantity', 'reason'),
    });
};

const cancelExtra = (id) => {
    router.delete(`/company-admin/extra-meals/${id}`, { preserveScroll: true });
};

/* ---------- locked-day actions ---------- */

const lateForm = useForm({ date: props.date, change_quantity: 1, reason: '' });

const submitLateChange = () => {
    lateForm.date = props.date;
    lateForm.post('/company-admin/late-changes', {
        preserveScroll: true,
        onSuccess: () => lateForm.reset('reason'),
    });
};

const acknowledge = () => {
    router.post('/company-admin/daily/acknowledge', { date: props.date }, { preserveScroll: true });
};

const sourceTone = (source) => ({
    hr: 'bg-mb-tint-cyan/15 text-mb-cyan-soft border-mb-tint-cyan/30',
    leave: 'bg-mb-tint-amber/15 text-mb-amber-soft border-mb-tint-amber/30',
    wfh: 'bg-mb-tint-indigo/15 text-mb-indigo-soft border-mb-tint-indigo/30',
    self: 'bg-mb-tint-emerald/15 text-mb-emerald-soft border-mb-tint-emerald/30',
    recurring: 'bg-purple-500/15 text-purple-300 border-purple-500/30',
    link: 'bg-mb-line-strong/15 text-mb-text-soft border-mb-line-strong/30',
}[source] ?? 'bg-mb-line-strong/15 text-mb-text-soft border-mb-line-strong/30');
</script>

<template>
    <AppLayout>
        <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6">
            <!-- Header + date picker -->
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-mb-text-strong">🍱 Daily Meal Count</h1>
                    <p class="text-sm text-mb-text-muted">
                        Cutoff {{ cutoff_time }}
                        <span v-if="countdown" class="text-mb-cyan font-mono font-semibold">· {{ countdown }} left</span>
                        <span v-else class="text-mb-amber font-semibold">· cutoff passed</span>
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Date</label>
                        <input v-model="selectedDate" type="date" class="bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <button @click="goToDate" class="px-4 py-2 bg-mb-tint-cyan hover:bg-mb-tint-cyan text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer">
                        Go
                    </button>
                </div>
            </div>

            <!-- Guard errors -->
            <div v-for="(message, key) in errors" :key="key" class="p-3 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-sm text-mb-rose-soft">
                {{ message }}
            </div>

            <div v-if="!is_meal_day" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/30 text-sm text-mb-amber-soft">
                This is not a meal day for your company, so no count is produced.
            </div>

            <!-- Outcome of the last bulk skip -->
            <div
                v-if="bulkSummary && !dismissedBulk"
                class="p-4 rounded-2xl bg-mb-card border border-mb-line-mid space-y-3"
            >
                <div class="flex items-start justify-between gap-4">
                    <p class="text-sm font-bold text-mb-text">
                        Bulk skip:
                        <span class="text-mb-emerald">{{ bulkApplied }} applied</span>
                        <span v-if="bulkNotApplied.length" class="text-mb-amber">
                            · {{ bulkNotApplied.length }} not applied
                        </span>
                        <span class="text-mb-text-faint font-normal">
                            (of {{ bulkSummary.total_processed }} attempted)
                        </span>
                    </p>
                    <button
                        @click="dismissedBulk = true"
                        class="text-mb-text-faint hover:text-mb-text-soft text-lg leading-none cursor-pointer"
                        aria-label="Dismiss"
                    >
                        ×
                    </button>
                </div>

                <div v-if="bulkNotApplied.length" class="space-y-1">
                    <div
                        v-for="(row, index) in bulkNotApplied"
                        :key="`${row.who}-${row.date}-${index}`"
                        class="flex flex-wrap items-baseline gap-x-2 text-xs p-2 rounded-lg bg-mb-raised/40"
                    >
                        <span class="font-semibold text-mb-text">{{ row.who }}</span>
                        <span class="font-mono text-mb-text-faint">{{ row.date }}</span>
                        <span class="text-mb-amber">{{ row.why }}</span>
                    </div>
                </div>
            </div>

            <!-- Count card -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-base font-bold text-mb-text">Count for {{ date }}</h2>
                    <div class="flex items-center gap-2">
                        <span :class="['px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider border', statusTone]">
                            {{ statusLabel }}
                        </span>
                        <span v-if="snapshot?.lock_type" class="text-[11px] text-mb-text-faint font-mono">{{ snapshot.lock_type }}-locked</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line">
                        <span class="text-[11px] uppercase font-semibold text-mb-text-muted">Base eligible</span>
                        <div class="text-2xl font-extrabold text-mb-text mt-1">{{ count.base_eligible_count }}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line">
                        <span class="text-[11px] uppercase font-semibold text-mb-text-muted">Skips</span>
                        <div class="text-2xl font-extrabold text-mb-amber mt-1">−{{ count.skip_count }}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line">
                        <span class="text-[11px] uppercase font-semibold text-mb-text-muted">Extra</span>
                        <div class="text-2xl font-extrabold text-mb-indigo mt-1">+{{ count.extra_count }}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-raised/50 border border-mb-line">
                        <span class="text-[11px] uppercase font-semibold text-mb-text-muted">Expected</span>
                        <div class="text-2xl font-extrabold text-mb-text-strong mt-1">{{ count.final_expected_count }}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-mb-tint-cyan/10 border border-mb-tint-cyan/30">
                        <span class="text-[11px] uppercase font-semibold text-mb-cyan">Adjusted total</span>
                        <div class="text-2xl font-extrabold text-mb-cyan-soft mt-1">{{ count.adjusted_total }}</div>
                    </div>
                </div>

                <p v-if="snapshot?.reviewed_at" class="text-xs text-mb-emerald">
                    Reviewed by {{ snapshot.reviewed_by }} at {{ snapshot.reviewed_at }}
                </p>
            </div>

            <!-- Locked-day actions -->
            <div v-if="isLocked" class="p-6 rounded-2xl bg-mb-card border border-mb-tint-indigo/30 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-base font-bold text-mb-text">After cutoff</h2>
                        <p class="text-xs text-mb-text-muted">
                            The count is locked, so meals change through a recorded adjustment rather than a skip.
                        </p>
                    </div>
                    <button
                        v-if="!snapshot?.reviewed_at"
                        @click="acknowledge"
                        class="px-4 py-2 bg-mb-tint-emerald/15 hover:bg-mb-tint-emerald/25 text-mb-emerald-soft border border-mb-tint-emerald/40 rounded-lg text-sm font-bold cursor-pointer"
                    >
                        ✅ Review this count
                    </button>
                </div>

                <form @submit.prevent="submitLateChange" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Change (+/−)</label>
                        <input v-model.number="lateForm.change_quantity" type="number" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" required />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Reason</label>
                        <input v-model="lateForm.reason" placeholder="Agreed with the vendor" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" required />
                    </div>
                    <button type="submit" :disabled="lateForm.processing" class="px-4 py-2 bg-mb-tint-indigo hover:bg-mb-tint-indigo disabled:opacity-40 text-mb-text-strong font-bold rounded-lg text-sm cursor-pointer">
                        Record change
                    </button>
                </form>

                <div v-if="count.changes?.length" class="space-y-1.5">
                    <div v-for="change in count.changes" :key="change.id" class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-mb-raised/40">
                        <span class="font-mono font-bold" :class="change.change_quantity > 0 ? 'text-mb-emerald' : 'text-mb-rose'">
                            {{ change.change_quantity > 0 ? '+' : '' }}{{ change.change_quantity }}
                        </span>
                        <span class="flex-1 px-3 text-mb-text-soft truncate">{{ change.reason }}</span>
                        <span class="text-mb-text-faint">{{ change.requested_by?.name }}</span>
                    </div>
                </div>
            </div>

            <!-- Extra meals -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <h2 class="text-base font-bold text-mb-text">Extra meals</h2>

                <form @submit.prevent="submitExtra" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Type</label>
                        <select v-model="extraForm.type" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong">
                            <option value="guest">Guest</option>
                            <option value="visitor">Visitor</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Quantity</label>
                        <input v-model.number="extraForm.quantity" type="number" min="1" max="100" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" required />
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Reason</label>
                        <input v-model="extraForm.reason" placeholder="Client visit" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <button type="submit" :disabled="extraForm.processing" class="px-4 py-2 bg-mb-tint-cyan hover:bg-mb-tint-cyan disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer">
                        Add extra
                    </button>
                </form>

                <div v-if="extra_meals.length" class="space-y-1.5">
                    <div v-for="extra in extra_meals" :key="extra.id" class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-mb-raised/40">
                        <span class="font-bold text-mb-indigo-soft font-mono">+{{ extra.quantity }}</span>
                        <span class="px-2 capitalize text-mb-text-muted">{{ extra.type }}</span>
                        <span class="flex-1 px-3 text-mb-text-soft truncate">{{ extra.reason }}</span>
                        <span v-if="extra.creator" class="text-mb-text-faint hidden sm:inline">{{ extra.creator.name }}</span>
                        <span v-if="extra.cancelled_at" class="text-mb-text-faint italic">cancelled</span>
                        <button v-else @click="cancelExtra(extra.id)" class="px-2 py-1 rounded text-mb-rose bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 border border-mb-tint-rose/30 cursor-pointer">
                            Cancel
                        </button>
                    </div>
                </div>
                <p v-else class="text-xs text-mb-text-faint">No extra meals for this date.</p>
            </div>

            <!-- Roster -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h2 class="text-base font-bold text-mb-text">
                        Employees <span class="text-mb-text-faint font-normal">({{ employees_for_search.length }})</span>
                    </h2>

                    <form v-if="selected.length" @submit.prevent="submitBulk" class="flex items-end gap-2">
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Bulk source</label>
                            <select v-model="bulkForm.source" class="bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong">
                                <option value="hr">HR</option>
                                <option value="leave">Leave</option>
                                <option value="wfh">WFH</option>
                            </select>
                        </div>
                        <button type="submit" :disabled="bulkForm.processing" class="px-4 py-2 bg-mb-tint-amber hover:bg-mb-tint-amber disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer">
                            Skip {{ selected.length }} selected
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-mb-text-soft">
                        <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4 w-10"></th>
                                <th class="py-3 px-4">Code</th>
                                <th class="py-3 px-4">Name</th>
                                <th class="py-3 px-4">Meal</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line">
                            <tr v-for="employee in employees_for_search" :key="employee.id" class="hover:bg-mb-raised/30">
                                <td class="py-3 px-4">
                                    <input
                                        type="checkbox"
                                        :checked="selected.includes(employee.id)"
                                        :disabled="!!skipByEmployee[employee.id] || isLocked"
                                        @change="toggleSelected(employee.id)"
                                        class="accent-mb-tint-cyan cursor-pointer disabled:opacity-30"
                                    />
                                </td>
                                <td class="py-3 px-4 font-mono text-xs text-mb-text-muted">{{ employee.employee_code }}</td>
                                <td class="py-3 px-4 font-semibold text-mb-text">{{ employee.name }}</td>
                                <td class="py-3 px-4">
                                    <template v-if="skipByEmployee[employee.id]">
                                        <span
                                            :class="['px-2 py-0.5 rounded-full text-[11px] font-bold uppercase border', sourceTone(skipByEmployee[employee.id].source)]"
                                            :title="skipReason(skipByEmployee[employee.id]) || `Skipped · ${skipByEmployee[employee.id].source}`"
                                        >
                                            Skipped · {{ skipByEmployee[employee.id].source }}
                                        </span>
                                        <!-- The source alone does not say which leave
                                             this was. Our own label only - the vendor's
                                             reason text is never fetched or stored. -->
                                        <span
                                            v-if="skipReason(skipByEmployee[employee.id])"
                                            class="block text-[11px] text-mb-text-faint mt-0.5 truncate max-w-[16rem]"
                                        >
                                            {{ skipReason(skipByEmployee[employee.id]) }}
                                        </span>
                                    </template>
                                    <span v-else class="text-mb-emerald text-xs font-semibold">Taking meal</span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <!-- Locked days are refused by the engine, so the
                                         buttons say so rather than inviting a failure. -->
                                    <span v-if="isLocked" class="text-[11px] text-mb-text-faint">
                                        Count locked — use an after-cutoff change
                                    </span>
                                    <button
                                        v-else-if="skipByEmployee[employee.id]"
                                        @click="removeSkip(skipByEmployee[employee.id].id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-emerald bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 border border-mb-tint-emerald/30 cursor-pointer"
                                    >
                                        Cancel skip
                                    </button>
                                    <button
                                        v-else
                                        @click="addSkip(employee.id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-amber bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 border border-mb-tint-amber/30 cursor-pointer"
                                    >
                                        Mark skip
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!employees_for_search.length">
                                <td colspan="5" class="py-6 text-center text-mb-text-faint">
                                    No active, meal-eligible employees yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
