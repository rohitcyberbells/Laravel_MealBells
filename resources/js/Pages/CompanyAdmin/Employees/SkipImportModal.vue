<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['close']);

const type = ref('leave');
const file = ref(null);
const busy = ref(false);

// 'choose' -> 'preview' -> 'done'
const stage = ref('choose');

const summary = ref(null);
const rowErrors = ref([]);
const token = ref(null);
const outcomes = ref(null);
const fatalError = ref(null);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const reset = () => {
    stage.value = 'choose';
    summary.value = null;
    rowErrors.value = [];
    token.value = null;
    outcomes.value = null;
    fatalError.value = null;
    file.value = null;
};

const close = () => {
    reset();
    emit('close');
};

const pickFile = (event) => {
    file.value = event.target.files[0] ?? null;
};

const preview = async () => {
    if (!file.value) return;

    busy.value = true;
    fatalError.value = null;

    const body = new FormData();
    body.append('file', file.value);
    body.append('type', type.value);

    try {
        const response = await fetch('/company-admin/skip-imports/preview', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body,
        });

        const payload = await response.json();

        if (!response.ok) {
            // Validation comes back as {errors: {...}}; anything else as a message.
            fatalError.value = payload.message
                || Object.values(payload.errors ?? {}).flat().join(' ')
                || 'The file could not be read.';
            return;
        }

        summary.value = payload.summary;
        rowErrors.value = payload.errors ?? [];
        token.value = payload.token;
        stage.value = 'preview';
    } catch (error) {
        fatalError.value = 'The file could not be uploaded.';
    } finally {
        busy.value = false;
    }
};

const confirmImport = async () => {
    busy.value = true;
    fatalError.value = null;

    try {
        const response = await fetch('/company-admin/skip-imports/confirm', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ token: token.value }),
        });

        const payload = await response.json();

        if (!response.ok) {
            fatalError.value = payload.message ?? 'The import could not be completed.';
            return;
        }

        outcomes.value = payload.outcomes;
        stage.value = 'done';

        // The counts on the page behind the modal are now stale.
        router.reload({ only: ['employees'] });
    } catch (error) {
        fatalError.value = 'The import could not be completed.';
    } finally {
        busy.value = false;
    }
};

const willCreate = computed(() => summary.value?.will_create ?? 0);

const skippedRows = computed(() => {
    if (!summary.value) return [];

    return [
        { label: 'Already skipped', value: summary.value.already_skipped, tone: 'text-mb-text-soft' },
        { label: 'Withdrawn earlier', value: summary.value.blocked_cancelled, tone: 'text-mb-text-soft' },
        { label: 'Non-meal days', value: summary.value.non_meal_days, tone: 'text-mb-text-soft' },
        { label: 'Rejected', value: summary.value.rejected, tone: 'text-mb-rose' },
    ];
});
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto bg-mb-inset/80">
        <div class="w-full max-w-3xl my-8 bg-mb-card border border-mb-line rounded-2xl shadow-2xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-mb-line">
                <div>
                    <h3 class="text-base font-bold text-mb-text-strong">Import leave / WFH</h3>
                    <p class="text-xs text-mb-text-muted">Nothing is recorded until you confirm the preview.</p>
                </div>
                <button @click="close" class="text-mb-text-muted hover:text-mb-text-strong text-xl leading-none cursor-pointer">×</button>
            </div>

            <div class="p-6 space-y-5">
                <div v-if="fatalError" class="p-3 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-sm text-mb-rose-soft">
                    {{ fatalError }}
                </div>

                <!-- Choose a file -->
                <template v-if="stage === 'choose'">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">Type</label>
                            <select v-model="type" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong">
                                <option value="leave">Leave</option>
                                <option value="wfh">Work from home</option>
                            </select>
                            <p v-if="type === 'wfh'" class="text-[11px] text-mb-text-faint mt-1">
                                Only accepted if WFH auto-skip is on in Settings.
                            </p>
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-mb-text-muted mb-1">CSV file</label>
                            <input type="file" accept=".csv,text/csv,text/plain" @change="pickFile" class="w-full text-xs text-mb-text-soft cursor-pointer" />
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-mb-inset border border-mb-line text-xs text-mb-text-muted space-y-1">
                        <p class="font-semibold text-mb-text-soft">Columns</p>
                        <code class="block text-mb-text">employee_code,from_date,to_date,reason</code>
                        <p>
                            <strong class="text-mb-text-soft">to_date</strong> and
                            <strong class="text-mb-text-soft">reason</strong> are optional; a single day can omit to_date.
                            Dates may be <span class="font-mono">YYYY-MM-DD</span> or <span class="font-mono">DD/MM/YYYY</span>.
                            Weekends and holidays in a range are left out automatically.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="close" class="px-4 py-2 text-sm text-mb-text-soft hover:text-mb-text-strong cursor-pointer">Cancel</button>
                        <button
                            @click="preview"
                            :disabled="!file || busy"
                            class="px-4 py-2 bg-mb-tint-cyan hover:bg-mb-tint-cyan disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer"
                        >
                            {{ busy ? 'Checking…' : 'Check file' }}
                        </button>
                    </div>
                </template>

                <!-- Preview -->
                <template v-else-if="stage === 'preview'">
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div class="p-3 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30">
                            <span class="text-[11px] uppercase font-semibold text-mb-emerald">Will import</span>
                            <div class="text-2xl font-extrabold text-mb-emerald-soft">{{ willCreate }}</div>
                        </div>
                        <div v-for="item in skippedRows" :key="item.label" class="p-3 rounded-xl bg-mb-raised/50 border border-mb-line">
                            <span class="text-[11px] uppercase font-semibold text-mb-text-muted">{{ item.label }}</span>
                            <div :class="['text-2xl font-extrabold', item.value > 0 ? item.tone : 'text-mb-text-ghost']">
                                {{ item.value }}
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-mb-text-muted">
                        {{ summary.total_rows }} row(s) read from the file.
                    </p>

                    <div v-if="rowErrors.length" class="space-y-2">
                        <p class="text-xs font-semibold text-mb-rose-soft">{{ rowErrors.length }} problem(s) found</p>
                        <div class="max-h-56 overflow-y-auto rounded-xl border border-mb-line">
                            <table class="w-full text-left text-xs text-mb-text-soft">
                                <thead class="bg-mb-raised/60 text-mb-text-muted uppercase text-[10px] font-semibold sticky top-0">
                                    <tr>
                                        <th class="py-2 px-3 w-16">Row</th>
                                        <th class="py-2 px-3 w-32">Field</th>
                                        <th class="py-2 px-3">Why</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-mb-line">
                                    <tr v-for="(rowError, index) in rowErrors" :key="index">
                                        <td class="py-2 px-3 font-mono text-mb-text-faint">{{ rowError.row ?? '—' }}</td>
                                        <td class="py-2 px-3 font-mono text-mb-amber">{{ rowError.field ?? '—' }}</td>
                                        <td class="py-2 px-3">{{ rowError.message }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-[11px] text-mb-text-faint">
                            These rows are left out. Confirming imports only the {{ willCreate }} valid day(s).
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="reset" class="px-4 py-2 text-sm text-mb-text-soft hover:text-mb-text-strong cursor-pointer">Choose another file</button>
                        <button
                            @click="confirmImport"
                            :disabled="busy || willCreate === 0"
                            class="px-4 py-2 bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-40 text-mb-on-accent font-bold rounded-lg text-sm cursor-pointer"
                        >
                            {{ busy ? 'Importing…' : `Import ${willCreate} day(s)` }}
                        </button>
                    </div>
                </template>

                <!-- Done -->
                <template v-else>
                    <div class="p-4 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 space-y-1">
                        <p class="text-sm font-bold text-mb-emerald-soft">Import finished</p>
                        <p class="text-xs text-mb-emerald-soft/80">
                            {{ outcomes.created }} skip(s) recorded ·
                            {{ outcomes.already_skipped }} already skipped ·
                            {{ outcomes.blocked_cancelled }} withdrawn earlier ·
                            {{ outcomes.rejected }} rejected
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="reset" class="px-4 py-2 text-sm text-mb-text-soft hover:text-mb-text-strong cursor-pointer">Import another file</button>
                        <button @click="close" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text font-bold rounded-lg text-sm border border-mb-line-mid cursor-pointer">
                            Done
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
