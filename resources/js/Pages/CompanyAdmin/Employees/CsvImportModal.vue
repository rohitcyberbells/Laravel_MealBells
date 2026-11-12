<script setup>
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['close']);

const page = usePage();
const selectedFile = ref(null);
const fileInput = ref(null);
const isUploading = ref(false);
const isImporting = ref(false);

const csvPreview = computed(() => page.props.flash?.csvPreview || page.props.csvPreview || null);

const handleFileChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        selectedFile.value = file;
    }
};

const uploadPreview = () => {
    if (!selectedFile.value) return;

    isUploading.value = true;
    const formData = new FormData();
    formData.append('file', selectedFile.value);

    router.post('/company-admin/employees/csv-preview', formData, {
        preserveScroll: true,
        onFinish: () => {
            isUploading.value = false;
        },
    });
};

const confirmImport = () => {
    if (!csvPreview.value || !csvPreview.value.valid_rows || !csvPreview.value.valid_rows.length) return;

    isImporting.value = true;
    router.post('/company-admin/employees/csv-import', {
        rows: csvPreview.value.valid_rows,
    }, {
        onSuccess: () => {
            closeModal();
        },
        onFinish: () => {
            isImporting.value = false;
        },
    });
};

const closeModal = () => {
    selectedFile.value = null;
    if (fileInput.value) fileInput.value.value = '';
    // Clear flash preview
    if (page.props.flash) page.props.flash.csvPreview = null;
    emit('close');
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mb-inset/80 backdrop-blur-sm animate-fade-in">
        <div class="relative w-full max-w-3xl bg-mb-card border border-mb-line rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6 max-h-[90vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-mb-line pb-4 shrink-0">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-mb-tint-emerald/10 rounded-xl text-mb-emerald border border-mb-tint-emerald/20">
                        📄
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-mb-text-strong">Bulk CSV Import</h2>
                        <p class="text-xs text-mb-text-muted">Upload CSV file to preview & import employees</p>
                    </div>
                </div>
                <button @click="closeModal" class="text-mb-text-muted hover:text-mb-text-strong text-lg p-1 transition-colors">
                    ✕
                </button>
            </div>

            <div class="overflow-y-auto space-y-6 pr-1 grow">
                <!-- CSV Format Sample & Dropzone -->
                <div v-if="!csvPreview" class="space-y-4">
                    <div class="p-4 bg-mb-inset border border-mb-line rounded-xl text-xs space-y-2 text-mb-text-soft">
                        <p class="font-semibold text-mb-cyan">💡 Expected CSV Headers:</p>
                        <code class="block bg-mb-card p-2.5 rounded-lg border border-mb-line font-mono text-[11px] text-mb-emerald-soft">
                            employee_code,name,email,attendance_source,is_meal_eligible,status
                        </code>
                        <p class="text-mb-text-muted text-[11px]">
                            • Required columns: <strong class="text-mb-text-strong">employee_code</strong>, <strong class="text-mb-text-strong">name</strong>.<br>
                            • Optional: <strong class="text-mb-text-strong">email</strong>, <strong class="text-mb-text-strong">attendance_source</strong> (manual, integrated, none), <strong class="text-mb-text-strong">is_meal_eligible</strong> (true/false or 1/0), <strong class="text-mb-text-strong">status</strong> (active/inactive).
                        </p>
                    </div>

                    <!-- File Picker -->
                    <div class="border-2 border-dashed border-mb-line hover:border-mb-tint-cyan/50 rounded-2xl p-8 text-center transition bg-mb-inset/50">
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".csv,text/csv,.txt"
                            @change="handleFileChange"
                            class="hidden"
                            id="csv_file_input"
                        />
                        <label for="csv_file_input" class="cursor-pointer space-y-3 block">
                            <div class="text-4xl">📥</div>
                            <div class="text-sm font-semibold text-mb-text">
                                {{ selectedFile ? selectedFile.name : 'Click to select CSV file' }}
                            </div>
                            <div class="text-xs text-mb-text-faint">Supported formats: .csv, .txt (max 2MB)</div>
                        </label>
                    </div>

                    <div v-if="selectedFile" class="flex justify-end">
                        <button
                            @click="uploadPreview"
                            :disabled="isUploading"
                            class="px-5 py-2.5 rounded-xl text-xs font-bold text-mb-on-accent bg-gradient-to-r from-mb-cyan to-mb-emerald hover:from-mb-cyan-soft hover:to-mb-emerald-soft shadow-lg shadow-mb-tint-cyan/20 disabled:opacity-50 transition cursor-pointer"
                        >
                            {{ isUploading ? 'Analyzing CSV...' : 'Preview CSV Rows 🔍' }}
                        </button>
                    </div>
                </div>

                <!-- CSV Preview Section -->
                <div v-else class="space-y-6">
                    <!-- Summary Stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div class="bg-mb-inset border border-mb-line p-4 rounded-xl">
                            <span class="block text-[11px] text-mb-text-muted uppercase font-semibold">Valid Rows</span>
                            <span class="text-2xl font-black text-mb-emerald">
                                {{ csvPreview.valid_rows?.length || 0 }}
                            </span>
                        </div>
                        <div class="bg-mb-inset border border-mb-line p-4 rounded-xl">
                            <span class="block text-[11px] text-mb-text-muted uppercase font-semibold">Validation Errors</span>
                            <span :class="['text-2xl font-black', csvPreview.errors?.length ? 'text-mb-rose' : 'text-mb-text-muted']">
                                {{ csvPreview.errors?.length || 0 }}
                            </span>
                        </div>
                        <div class="col-span-2 sm:col-span-1 bg-mb-inset border border-mb-line p-4 rounded-xl flex items-center justify-center">
                            <button
                                @click="page.props.flash.csvPreview = null"
                                class="text-xs font-semibold text-mb-cyan hover:text-mb-cyan-soft underline"
                            >
                                🔄 Select Different File
                            </button>
                        </div>
                    </div>

                    <!-- Validation Errors List if Any -->
                    <div v-if="csvPreview.errors && csvPreview.errors.length" class="bg-mb-tint-rose/10 border border-mb-tint-rose/30 rounded-xl p-4 space-y-2">
                        <h4 class="text-xs font-bold text-mb-rose-soft uppercase tracking-wider flex items-center gap-1.5">
                            ⚠️ Row Errors (Will be Skipped):
                        </h4>
                        <div class="max-h-32 overflow-y-auto space-y-1.5 text-xs text-mb-rose-soft font-mono">
                            <div v-for="(err, idx) in csvPreview.errors" :key="idx" class="bg-mb-inset/60 p-2 rounded border border-mb-tint-rose/20">
                                Line {{ err.row }}: <span class="font-bold">{{ err.field }}</span> - {{ err.message }}
                            </div>
                        </div>
                    </div>

                    <!-- Valid Rows Preview Table -->
                    <div v-if="csvPreview.valid_rows && csvPreview.valid_rows.length" class="space-y-3">
                        <h4 class="text-xs font-bold text-mb-text-soft uppercase tracking-wider">
                            Ready to Import ({{ csvPreview.valid_rows.length }} Records)
                        </h4>
                        <div class="overflow-x-auto border border-mb-line rounded-xl bg-mb-inset">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-mb-card border-b border-mb-line text-mb-text-muted uppercase tracking-wider">
                                    <tr>
                                        <th class="p-3 font-semibold">Code</th>
                                        <th class="p-3 font-semibold">Name</th>
                                        <th class="p-3 font-semibold">Email</th>
                                        <th class="p-3 font-semibold">Attendance</th>
                                        <th class="p-3 font-semibold">Eligibility</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-mb-line/60">
                                    <tr v-for="(row, idx) in csvPreview.valid_rows.slice(0, 10)" :key="idx" class="hover:bg-mb-card/40">
                                        <td class="p-3 font-mono text-mb-cyan font-bold">{{ row.employee_code }}</td>
                                        <td class="p-3 font-medium text-mb-text-strong">{{ row.name }}</td>
                                        <td class="p-3 text-mb-text-muted">{{ row.email || '-' }}</td>
                                        <td class="p-3 text-mb-text-soft capitalize">{{ row.attendance_source || 'manual' }}</td>
                                        <td class="p-3">
                                            <span :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                row.is_meal_eligible !== false ? 'bg-mb-tint-emerald/20 text-mb-emerald-soft border border-mb-tint-emerald/30' : 'bg-mb-raised text-mb-text-muted'
                                            ]">
                                                {{ row.is_meal_eligible !== false ? 'Eligible' : 'Ineligible' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="csvPreview.valid_rows.length > 10" class="text-[11px] text-mb-text-faint text-right italic">
                            ...and {{ csvPreview.valid_rows.length - 10 }} more rows
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-mb-line shrink-0">
                <button
                    type="button"
                    @click="closeModal"
                    class="px-4 py-2 rounded-xl text-xs font-semibold text-mb-text-muted hover:text-mb-text-strong hover:bg-mb-raised transition"
                >
                    Cancel
                </button>
                <button
                    v-if="csvPreview && csvPreview.valid_rows && csvPreview.valid_rows.length"
                    @click="confirmImport"
                    :disabled="isImporting"
                    class="px-5 py-2 rounded-xl text-xs font-bold text-mb-on-accent bg-gradient-to-r from-mb-emerald to-mb-cyan hover:from-mb-emerald-soft hover:to-mb-cyan-soft shadow-lg shadow-mb-tint-emerald/20 disabled:opacity-50 transition cursor-pointer"
                >
                    {{ isImporting ? 'Importing...' : `Confirm & Import ${csvPreview.valid_rows.length} Records 🚀` }}
                </button>
            </div>
        </div>
    </div>
</template>
