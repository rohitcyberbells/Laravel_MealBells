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
        { label: 'Already skipped', value: summary.value.already_skipped, tone: 'text-slate-300' },
        { label: 'Withdrawn earlier', value: summary.value.blocked_cancelled, tone: 'text-slate-300' },
        { label: 'Non-meal days', value: summary.value.non_meal_days, tone: 'text-slate-300' },
        { label: 'Rejected', value: summary.value.rejected, tone: 'text-red-400' },
    ];
});
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto bg-slate-950/80">
        <div class="w-full max-w-3xl my-8 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
                <div>
                    <h3 class="text-base font-bold text-white">Import leave / WFH</h3>
                    <p class="text-xs text-slate-400">Nothing is recorded until you confirm the preview.</p>
                </div>
                <button @click="close" class="text-slate-400 hover:text-white text-xl leading-none cursor-pointer">×</button>
            </div>

            <div class="p-6 space-y-5">
                <div v-if="fatalError" class="p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-300">
                    {{ fatalError }}
                </div>

                <!-- Choose a file -->
                <template v-if="stage === 'choose'">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Type</label>
                            <select v-model="type" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                                <option value="leave">Leave</option>
                                <option value="wfh">Work from home</option>
                            </select>
                            <p v-if="type === 'wfh'" class="text-[11px] text-slate-500 mt-1">
                                Only accepted if WFH auto-skip is on in Settings.
                            </p>
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">CSV file</label>
                            <input type="file" accept=".csv,text/csv,text/plain" @change="pickFile" class="w-full text-xs text-slate-300 cursor-pointer" />
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-400 space-y-1">
                        <p class="font-semibold text-slate-300">Columns</p>
                        <code class="block text-slate-200">employee_code,from_date,to_date,reason</code>
                        <p>
                            <strong class="text-slate-300">to_date</strong> and
                            <strong class="text-slate-300">reason</strong> are optional; a single day can omit to_date.
                            Dates may be <span class="font-mono">YYYY-MM-DD</span> or <span class="font-mono">DD/MM/YYYY</span>.
                            Weekends and holidays in a range are left out automatically.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="close" class="px-4 py-2 text-sm text-slate-300 hover:text-white cursor-pointer">Cancel</button>
                        <button
                            @click="preview"
                            :disabled="!file || busy"
                            class="px-4 py-2 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-40 text-slate-950 font-bold rounded-lg text-sm cursor-pointer"
                        >
                            {{ busy ? 'Checking…' : 'Check file' }}
                        </button>
                    </div>
                </template>

                <!-- Preview -->
                <template v-else-if="stage === 'preview'">
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                        <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30">
                            <span class="text-[11px] uppercase font-semibold text-emerald-400">Will import</span>
                            <div class="text-2xl font-extrabold text-emerald-300">{{ willCreate }}</div>
                        </div>
                        <div v-for="item in skippedRows" :key="item.label" class="p-3 rounded-xl bg-slate-800/50 border border-slate-800">
                            <span class="text-[11px] uppercase font-semibold text-slate-400">{{ item.label }}</span>
                            <div :class="['text-2xl font-extrabold', item.value > 0 ? item.tone : 'text-slate-600']">
                                {{ item.value }}
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-slate-400">
                        {{ summary.total_rows }} row(s) read from the file.
                    </p>

                    <div v-if="rowErrors.length" class="space-y-2">
                        <p class="text-xs font-semibold text-red-300">{{ rowErrors.length }} problem(s) found</p>
                        <div class="max-h-56 overflow-y-auto rounded-xl border border-slate-800">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="bg-slate-800/60 text-slate-400 uppercase text-[10px] font-semibold sticky top-0">
                                    <tr>
                                        <th class="py-2 px-3 w-16">Row</th>
                                        <th class="py-2 px-3 w-32">Field</th>
                                        <th class="py-2 px-3">Why</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <tr v-for="(rowError, index) in rowErrors" :key="index">
                                        <td class="py-2 px-3 font-mono text-slate-500">{{ rowError.row ?? '—' }}</td>
                                        <td class="py-2 px-3 font-mono text-amber-400">{{ rowError.field ?? '—' }}</td>
                                        <td class="py-2 px-3">{{ rowError.message }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-[11px] text-slate-500">
                            These rows are left out. Confirming imports only the {{ willCreate }} valid day(s).
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="reset" class="px-4 py-2 text-sm text-slate-300 hover:text-white cursor-pointer">Choose another file</button>
                        <button
                            @click="confirmImport"
                            :disabled="busy || willCreate === 0"
                            class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 text-slate-950 font-bold rounded-lg text-sm cursor-pointer"
                        >
                            {{ busy ? 'Importing…' : `Import ${willCreate} day(s)` }}
                        </button>
                    </div>
                </template>

                <!-- Done -->
                <template v-else>
                    <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 space-y-1">
                        <p class="text-sm font-bold text-emerald-300">Import finished</p>
                        <p class="text-xs text-emerald-200/80">
                            {{ outcomes.created }} skip(s) recorded ·
                            {{ outcomes.already_skipped }} already skipped ·
                            {{ outcomes.blocked_cancelled }} withdrawn earlier ·
                            {{ outcomes.rejected }} rejected
                        </p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button @click="reset" class="px-4 py-2 text-sm text-slate-300 hover:text-white cursor-pointer">Import another file</button>
                        <button @click="close" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-lg text-sm border border-slate-700 cursor-pointer">
                            Done
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
