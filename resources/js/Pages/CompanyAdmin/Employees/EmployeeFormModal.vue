<script setup>
import SelectField from '../../../Layouts/SelectField.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps({
    show: Boolean,
    employee: Object, // Null for Create, Object for Edit
});

const emit = defineEmits(['close']);

const form = useForm({
    employee_code: '',
    name: '',
    email: '',
    attendance_source: 'manual',
    is_meal_eligible: true,
    status: 'active',
});

watch(() => props.employee, (newVal) => {
    if (newVal) {
        form.employee_code = newVal.employee_code || '';
        form.name = newVal.name || '';
        form.email = newVal.email || '';
        form.attendance_source = newVal.attendance_source || 'manual';
        form.is_meal_eligible = newVal.is_meal_eligible !== undefined ? Boolean(newVal.is_meal_eligible) : true;
        form.status = newVal.status || 'active';
    } else {
        form.reset();
        form.attendance_source = 'manual';
        form.is_meal_eligible = true;
        form.status = 'active';
    }
}, { immediate: true });

const submit = () => {
    if (props.employee) {
        form.put(`/company-admin/employees/${props.employee.id}`, {
            onSuccess: () => {
                form.reset();
                emit('close');
            },
        });
    } else {
        form.post('/company-admin/employees', {
            onSuccess: () => {
                form.reset();
                emit('close');
            },
        });
    }
};

const closeModal = () => {
    form.clearErrors();
    emit('close');
};

const ATTENDANCE_SOURCES = [
    { value: 'manual', label: 'Manual (self or HR)' },
    { value: 'integrated', label: 'Integrated (HRMS sync)' },
    { value: 'none', label: 'None (always counted)' },
];

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mb-inset/80 backdrop-blur-sm animate-fade-in">
        <div class="relative w-full max-w-lg bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6">
            <!-- Modal Header -->
            <div class="flex items-center justify-between border-b border-mb-line pb-4">
                <div class="min-w-0">
                    <div>
                        <h2 class="text-xl font-bold text-mb-text-strong">
                            {{ employee ? 'Edit Employee' : 'Add New Employee' }}
                        </h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft">
                            {{ employee ? 'Update details for ' + employee.name : 'Create a single employee record' }}
                        </p>
                    </div>
                </div>
                <button @click="closeModal" aria-label="Close" class="shrink-0 p-1 rounded-lg text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="space-y-5">
                <!-- Employee Code -->
                <div>
                    <label for="employeeformmodal-employee-code" class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Employee Code <span class="text-mb-cyan">*</span>
                    </label>
                    <input id="employeeformmodal-employee-code"
                        v-model="form.employee_code"
                        type="text"
                        placeholder="e.g. EMP101"
                        :disabled="Boolean(employee)"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan disabled:opacity-50 disabled:cursor-not-allowed transition"
                    />
                    <p v-if="form.errors.employee_code" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">
                        {{ form.errors.employee_code }}
                    </p>
                </div>

                <!-- Full Name -->
                <div>
                    <label for="employeeformmodal-full-name" class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Full Name <span class="text-mb-cyan">*</span>
                    </label>
                    <input id="employeeformmodal-full-name"
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Rahul Sharma"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition"
                    />
                    <p v-if="form.errors.name" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">
                        {{ form.errors.name }}
                    </p>
                </div>

                <!-- Email -->
                <div>
                    <label for="employeeformmodal-email-address" class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Email Address
                    </label>
                    <input id="employeeformmodal-email-address"
                        v-model="form.email"
                        type="email"
                        placeholder="e.g. rahul@company.com"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition"
                    />
                    <p v-if="form.errors.email" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">
                        {{ form.errors.email }}
                    </p>
                </div>

                <!-- Attendance Source -->
                <div>
                    <label for="employee-attendance-source" class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Attendance Source
                    </label>
                    <SelectField
                        id="employee-attendance-source"
                        v-model="form.attendance_source"
                        :options="ATTENDANCE_SOURCES"
                    />
                    <p v-if="form.errors.attendance_source" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">
                        {{ form.errors.attendance_source }}
                    </p>
                </div>

                <!-- Meal Eligibility & Status Grid -->
                <div class="grid grid-cols-2 gap-4 pt-2">
                    <!-- Is Meal Eligible -->
                    <div>
                        <span class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">Meal eligible</span>
                        <label class="h-10 flex items-center gap-2.5 cursor-pointer">
                            <input
                                v-model="form.is_meal_eligible"
                                type="checkbox"
                                class="w-5 h-5 shrink-0 accent-mb-tint-cyan rounded border-mb-field focus:ring-0 cursor-pointer"
                            />
                            <span class="text-sm text-mb-text">Included in meal counts</span>
                        </label>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="employee-status" class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">Status</label>
                        <SelectField
                            id="employee-status"
                            v-model="form.status"
                            :options="STATUS_OPTIONS"
                        />
                    </div>
                </div>

                <!-- Modal Actions -->
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-mb-line">
                    <button
                        type="button"
                        @click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2 rounded-xl text-xs font-bold text-mb-on-fill bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 disabled:opacity-50 transition cursor-pointer"
                    >
                        {{ form.processing ? 'Saving...' : (employee ? 'Update Employee' : 'Save Employee') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
