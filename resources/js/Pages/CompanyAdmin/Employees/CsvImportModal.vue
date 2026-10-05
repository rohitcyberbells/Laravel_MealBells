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
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in">
        <div class="relative w-full max-w-3xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6 max-h-[90vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-4 shrink-0">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-emerald-500/10 rounded-xl text-emerald-400 border border-emerald-500/20">
                        📄
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-white">Bulk CSV Import</h2>
                        <p class="text-xs text-slate-400">Upload CSV file to preview & import employees</p>
                    </div>
                </div>
                <button @click="closeModal" class="text-slate-400 hover:text-white text-lg p-1 transition-colors">
                    ✕
                </button>
            </div>

            <div class="overflow-y-auto space-y-6 pr-1 grow">
                <!-- CSV Format Sample & Dropzone -->
                <div v-if="!csvPreview" class="space-y-4">
                    <div class="p-4 bg-slate-950 border border-slate-800 rounded-xl text-xs space-y-2 text-slate-300">
                        <p class="font-semibold text-cyan-400">💡 Expected CSV Headers:</p>
                        <code class="block bg-slate-900 p-2.5 rounded-lg border border-slate-800 font-mono text-[11px] text-emerald-300">
                            employee_code,name,email,attendance_source,is_meal_eligible,status
                        </code>
                        <p class="text-slate-400 text-[11px]">
                            • Required columns: <strong class="text-white">employee_code</strong>, <strong class="text-white">name</strong>.<br>
                            • Optional: <strong class="text-white">email</strong>, <strong class="text-white">attendance_source</strong> (manual, integrated, none), <strong class="text-white">is_meal_eligible</strong> (true/false or 1/0), <strong class="text-white">status</strong> (active/inactive).
                        </p>
                    </div>

                    <!-- File Picker -->
                    <div class="border-2 border-dashed border-slate-800 hover:border-cyan-500/50 rounded-2xl p-8 text-center transition bg-slate-950/50">
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
                            <div class="text-sm font-semibold text-slate-200">
                                {{ selectedFile ? selectedFile.name : 'Click to select CSV file' }}
                            </div>
                            <div class="text-xs text-slate-500">Supported formats: .csv, .txt (max 2MB)</div>
                        </label>
                    </div>

                    <div v-if="selectedFile" class="flex justify-end">
                        <button
                            @click="uploadPreview"
                            :disabled="isUploading"
                            class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-950 bg-gradient-to-r from-cyan-400 to-emerald-400 hover:from-cyan-300 hover:to-emerald-300 shadow-lg shadow-cyan-500/20 disabled:opacity-50 transition cursor-pointer"
                        >
                            {{ isUploading ? 'Analyzing CSV...' : 'Preview CSV Rows 🔍' }}
                        </button>
                    </div>
                </div>

                <!-- CSV Preview Section -->
                <div v-else class="space-y-6">
                    <!-- Summary Stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl">
                            <span class="block text-[11px] text-slate-400 uppercase font-semibold">Valid Rows</span>
                            <span class="text-2xl font-black text-emerald-400">
                                {{ csvPreview.valid_rows?.length || 0 }}
                            </span>
                        </div>
                        <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl">
                            <span class="block text-[11px] text-slate-400 uppercase font-semibold">Validation Errors</span>
                            <span :class="['text-2xl font-black', csvPreview.errors?.length ? 'text-red-400' : 'text-slate-400']">
                                {{ csvPreview.errors?.length || 0 }}
                            </span>
                        </div>
                        <div class="col-span-2 sm:col-span-1 bg-slate-950 border border-slate-800 p-4 rounded-xl flex items-center justify-center">
                            <button
                                @click="page.props.flash.csvPreview = null"
                                class="text-xs font-semibold text-cyan-400 hover:text-cyan-300 underline"
                            >
                                🔄 Select Different File
                            </button>
                        </div>
                    </div>

                    <!-- Validation Errors List if Any -->
                    <div v-if="csvPreview.errors && csvPreview.errors.length" class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 space-y-2">
                        <h4 class="text-xs font-bold text-red-300 uppercase tracking-wider flex items-center gap-1.5">
                            ⚠️ Row Errors (Will be Skipped):
                        </h4>
                        <div class="max-h-32 overflow-y-auto space-y-1.5 text-xs text-red-200 font-mono">
                            <div v-for="(err, idx) in csvPreview.errors" :key="idx" class="bg-slate-950/60 p-2 rounded border border-red-500/20">
                                Line {{ err.row }}: <span class="font-bold">{{ err.field }}</span> - {{ err.message }}
                            </div>
                        </div>
                    </div>

                    <!-- Valid Rows Preview Table -->
                    <div v-if="csvPreview.valid_rows && csvPreview.valid_rows.length" class="space-y-3">
                        <h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider">
                            Ready to Import ({{ csvPreview.valid_rows.length }} Records)
                        </h4>
                        <div class="overflow-x-auto border border-slate-800 rounded-xl bg-slate-950">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-900 border-b border-slate-800 text-slate-400 uppercase tracking-wider">
                                    <tr>
                                        <th class="p-3 font-semibold">Code</th>
                                        <th class="p-3 font-semibold">Name</th>
                                        <th class="p-3 font-semibold">Email</th>
                                        <th class="p-3 font-semibold">Attendance</th>
                                        <th class="p-3 font-semibold">Eligibility</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60">
                                    <tr v-for="(row, idx) in csvPreview.valid_rows.slice(0, 10)" :key="idx" class="hover:bg-slate-900/40">
                                        <td class="p-3 font-mono text-cyan-400 font-bold">{{ row.employee_code }}</td>
                                        <td class="p-3 font-medium text-white">{{ row.name }}</td>
                                        <td class="p-3 text-slate-400">{{ row.email || '-' }}</td>
                                        <td class="p-3 text-slate-300 capitalize">{{ row.attendance_source || 'manual' }}</td>
                                        <td class="p-3">
                                            <span :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                row.is_meal_eligible !== false ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400'
                                            ]">
                                                {{ row.is_meal_eligible !== false ? 'Eligible' : 'Ineligible' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="csvPreview.valid_rows.length > 10" class="text-[11px] text-slate-500 text-right italic">
                            ...and {{ csvPreview.valid_rows.length - 10 }} more rows
                        </p>
                    </div>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800 shrink-0">
                <button
                    type="button"
                    @click="closeModal"
                    class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition"
                >
                    Cancel
                </button>
                <button
                    v-if="csvPreview && csvPreview.valid_rows && csvPreview.valid_rows.length"
                    @click="confirmImport"
                    :disabled="isImporting"
                    class="px-5 py-2 rounded-xl text-xs font-bold text-slate-950 bg-gradient-to-r from-emerald-400 to-cyan-400 hover:from-emerald-300 hover:to-cyan-300 shadow-lg shadow-emerald-500/20 disabled:opacity-50 transition cursor-pointer"
                >
                    {{ isImporting ? 'Importing...' : `Confirm & Import ${csvPreview.valid_rows.length} Records 🚀` }}
                </button>
            </div>
        </div>
    </div>
</template>
