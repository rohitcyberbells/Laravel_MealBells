<script setup>
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
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mb-inset/80 backdrop-blur-sm animate-fade-in">
        <div class="relative w-full max-w-lg bg-mb-card border border-mb-line rounded-2xl shadow-2xl p-6 sm:p-8 space-y-6">
            <!-- Modal Header -->
            <div class="flex items-center justify-between border-b border-mb-line pb-4">
                <div class="flex items-center space-x-3">
                    <div class="p-2 bg-mb-tint-cyan/10 rounded-xl text-mb-cyan border border-mb-tint-cyan/20">
                        {{ employee ? '✏️' : '➕' }}
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-mb-text-strong">
                            {{ employee ? 'Edit Employee' : 'Add New Employee' }}
                        </h2>
                        <p class="text-xs text-mb-text-muted">
                            {{ employee ? 'Update details for ' + employee.name : 'Create a single employee record' }}
                        </p>
                    </div>
                </div>
                <button @click="closeModal" class="text-mb-text-muted hover:text-mb-text-strong text-lg p-1 transition-colors">
                    ✕
                </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="space-y-5">
                <!-- Employee Code -->
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Employee Code <span class="text-mb-cyan">*</span>
                    </label>
                    <input
                        v-model="form.employee_code"
                        type="text"
                        placeholder="e.g. EMP101"
                        :disabled="Boolean(employee)"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan disabled:opacity-50 disabled:cursor-not-allowed transition"
                    />
                    <p v-if="form.errors.employee_code" class="text-xs text-mb-rose mt-1">
                        {{ form.errors.employee_code }}
                    </p>
                </div>

                <!-- Full Name -->
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Full Name <span class="text-mb-cyan">*</span>
                    </label>
                    <input
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Rahul Sharma"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition"
                    />
                    <p v-if="form.errors.name" class="text-xs text-mb-rose mt-1">
                        {{ form.errors.name }}
                    </p>
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Email Address
                    </label>
                    <input
                        v-model="form.email"
                        type="email"
                        placeholder="e.g. rahul@company.com"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition"
                    />
                    <p v-if="form.errors.email" class="text-xs text-mb-rose mt-1">
                        {{ form.errors.email }}
                    </p>
                </div>

                <!-- Attendance Source -->
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase tracking-wider mb-1.5">
                        Attendance Source
                    </label>
                    <select
                        v-model="form.attendance_source"
                        class="w-full px-4 py-2.5 bg-mb-inset border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition"
                    >
                        <option value="manual">Manual (Self/HR)</option>
                        <option value="integrated">Integrated (HRMS Sync)</option>
                        <option value="none">None (Always Counted)</option>
                    </select>
                    <p v-if="form.errors.attendance_source" class="text-xs text-mb-rose mt-1">
                        {{ form.errors.attendance_source }}
                    </p>
                </div>

                <!-- Meal Eligibility & Status Grid -->
                <div class="grid grid-cols-2 gap-4 pt-2">
                    <!-- Is Meal Eligible -->
                    <div class="bg-mb-inset border border-mb-line rounded-xl p-3.5 flex items-center justify-between">
                        <div>
                            <span class="block text-xs font-semibold text-mb-text">Meal Eligible</span>
                            <span class="text-[10px] text-mb-text-muted">Included in meal counts</span>
                        </div>
                        <input
                            v-model="form.is_meal_eligible"
                            type="checkbox"
                            class="w-5 h-5 accent-mb-tint-cyan rounded border-mb-line-mid focus:ring-0 cursor-pointer"
                        />
                    </div>

                    <!-- Status -->
                    <div class="bg-mb-inset border border-mb-line rounded-xl p-3.5">
                        <label class="block text-xs font-semibold text-mb-text-soft mb-1">Status</label>
                        <select
                            v-model="form.status"
                            class="w-full bg-transparent text-mb-text-strong text-xs font-semibold focus:outline-none cursor-pointer"
                        >
                            <option value="active" class="bg-mb-card text-mb-emerald">🟢 Active</option>
                            <option value="inactive" class="bg-mb-card text-mb-text-muted">🔴 Inactive</option>
                        </select>
                    </div>
                </div>

                <!-- Modal Actions -->
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-mb-line">
                    <button
                        type="button"
                        @click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-semibold text-mb-text-muted hover:text-mb-text-strong hover:bg-mb-raised transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2 rounded-xl text-xs font-bold text-mb-on-accent bg-gradient-to-r from-mb-cyan to-mb-emerald hover:from-mb-cyan-soft hover:to-mb-emerald-soft shadow-lg shadow-mb-tint-cyan/20 disabled:opacity-50 transition cursor-pointer"
                    >
                        {{ form.processing ? 'Saving...' : (employee ? 'Update Employee' : 'Save Employee') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
