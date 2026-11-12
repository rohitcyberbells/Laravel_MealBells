<script setup>
import { computed, ref, watch } from 'vue';
import { router, Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmployeeFormModal from './EmployeeFormModal.vue';
import CsvImportModal from './CsvImportModal.vue';
import SkipImportModal from './SkipImportModal.vue';
import LoginCredentialsPanel from './LoginCredentialsPanel.vue';
import RecurringSkipModal from './RecurringSkipModal.vue';

const props = defineProps({
    company: Object,
    employees: Object,
    filters: Object,
    // Keyed by employee id, for the rows on this page only.
    recurring_rules: { type: Object, default: () => ({}) },
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || '');

const showFormModal = ref(false);
const selectedEmployee = ref(null);
const showCsvModal = ref(false);
const showSkipImportModal = ref(false);

const page = usePage();

// Shown once, straight after provisioning; it is flash data, so a reload clears
// it on its own.
const credentials = computed(() => page.props.flash?.credentials ?? null);
const dismissedCredentials = ref(false);

// Only employees who have no account yet can be provisioned.
const selectedForLogins = ref([]);

const toggleForLogins = (id) => {
    const at = selectedForLogins.value.indexOf(id);
    at === -1 ? selectedForLogins.value.push(id) : selectedForLogins.value.splice(at, 1);
};

const createLogins = () => {
    if (!selectedForLogins.value.length) return;

    router.post('/company-admin/employees/logins', { employee_ids: selectedForLogins.value }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedForLogins.value = [];
            dismissedCredentials.value = false;
        },
    });
};

const showRecurringModal = ref(false);
const recurringEmployee = ref(null);

const rulesFor = (employeeId) => props.recurring_rules?.[employeeId] ?? [];

// Count only the rules that are live, so the badge does not advertise a paused
// or future rule as something currently skipping meals.
const activeRuleCount = (employeeId) => rulesFor(employeeId).filter((r) => r.active).length;

const openRecurringModal = (employee) => {
    recurringEmployee.value = employee;
    showRecurringModal.value = true;
};

// The modal reads from props, so it has to follow the reloaded page rather than
// the employee object it was opened with.
const recurringRules = computed(() => recurringEmployee.value ? rulesFor(recurringEmployee.value.id) : []);

const resetPassword = (employee) => {
    if (!confirm(`Reset the password for ${employee.name} (${employee.employee_code})? Their current password stops working immediately.`)) {
        return;
    }

    router.post(`/company-admin/employees/${employee.id}/reset-password`, {}, {
        preserveScroll: true,
        onSuccess: () => { dismissedCredentials.value = false; },
    });
};

// Typed, not clicked. This is irreversible - the name, address, employee code
// and login are gone afterwards - so a confirm() is the wrong control.
const anonymiseForm = useForm({ confirm_name: '' });
const anonymising = ref(null);

const beginAnonymise = (employee) => {
    anonymising.value = employee;
    anonymiseForm.reset('confirm_name');
    anonymiseForm.clearErrors();
};

const anonymise = () => {
    anonymiseForm.post(`/company-admin/employees/${anonymising.value.id}/anonymise`, {
        preserveScroll: true,
        onSuccess: () => { anonymising.value = null; },
    });
};

const openCreateModal = () => {
    selectedEmployee.value = null;
    showFormModal.value = true;
};

const openEditModal = (employee) => {
    selectedEmployee.value = employee;
    showFormModal.value = true;
};

const openCsvModal = () => {
    showCsvModal.value = true;
};

// Search & Filter Watcher
let searchTimeout;
watch([search, status], () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            '/company-admin/employees',
            { search: search.value, status: status.value },
            { preserveState: true, replace: true }
        );
    }, 300);
});
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <!-- Top Action Banner & Stats -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-mb-card/60 border border-mb-line p-6 rounded-2xl backdrop-blur-md shadow-xl">
                <div>
                    <h1 class="text-2xl font-black text-mb-text-strong tracking-tight flex items-center gap-2">
                        👥 Employee Roster
                    </h1>
                    <p class="text-xs text-mb-text-muted mt-1">
                        Manage company employees, meal eligibility, and attendance settings
                    </p>
                </div>
                <div class="flex items-center space-x-3">
                    <button
                        @click="openCsvModal"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-mb-text bg-mb-raised hover:bg-mb-chip border border-mb-line-mid transition shadow-sm cursor-pointer"
                    >
                        <span>📥</span>
                        <span>Bulk CSV Import</span>
                    </button>
                    <button
                        v-if="selectedForLogins.length"
                        @click="createLogins"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-mb-amber-soft bg-mb-tint-amber/15 hover:bg-mb-tint-amber/25 border border-mb-tint-amber/40 transition shadow-sm cursor-pointer"
                    >
                        <span>🔑</span>
                        <span>Create logins ({{ selectedForLogins.length }})</span>
                    </button>
                    <button
                        @click="showSkipImportModal = true"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-mb-text bg-mb-raised hover:bg-mb-chip border border-mb-line-mid transition shadow-sm cursor-pointer"
                    >
                        <span>🗓️</span>
                        <span>Leave / WFH Import</span>
                    </button>
                    <button
                        @click="openCreateModal"
                        class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-bold text-mb-on-accent bg-gradient-to-r from-mb-cyan to-mb-emerald hover:from-mb-cyan-soft hover:to-mb-emerald-soft shadow-lg shadow-mb-tint-cyan/20 transition cursor-pointer"
                    >
                        <span>➕</span>
                        <span>Add Employee</span>
                    </button>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Search Input -->
                <div class="sm:col-span-2 relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-mb-text-faint text-sm pointer-events-none">
                        🔍
                    </span>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by employee code, name, or email..."
                        class="w-full pl-10 pr-4 py-2.5 bg-mb-card border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition shadow-sm"
                    />
                </div>

                <!-- Status Filter -->
                <div>
                    <select
                        v-model="status"
                        class="w-full px-4 py-2.5 bg-mb-card border border-mb-line rounded-xl text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan focus:ring-1 focus:ring-mb-tint-cyan transition shadow-sm cursor-pointer"
                    >
                        <option value="">All Statuses (Active & Inactive)</option>
                        <option value="active">🟢 Active Only</option>
                        <option value="inactive">🔴 Inactive Only</option>
                    </select>
                </div>
            </div>

            <LoginCredentialsPanel
                v-if="credentials && !dismissedCredentials"
                :credentials="credentials"
                @dismiss="dismissedCredentials = true"
            />

            <!-- Employee Data Table -->
            <div class="bg-mb-card border border-mb-line rounded-2xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-mb-inset/80 border-b border-mb-line text-mb-text-muted uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="p-4 w-10"></th>
                                <th class="p-4">Employee Code</th>
                                <th class="p-4">Name & Email</th>
                                <th class="p-4">Attendance Source</th>
                                <th class="p-4">Meal Eligibility</th>
                                <th class="p-4">Status</th>
                                <th class="p-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line/60 font-medium">
                            <tr
                                v-for="emp in employees.data"
                                :key="emp.id"
                                class="hover:bg-mb-raised/40 transition-colors group"
                            >
                                <td class="p-4">
                                    <!-- Only employees without an account can be
                                         provisioned; the rest show a tick. -->
                                    <input
                                        v-if="!emp.user_id"
                                        type="checkbox"
                                        :checked="selectedForLogins.includes(emp.id)"
                                        @change="toggleForLogins(emp.id)"
                                        class="accent-mb-tint-cyan cursor-pointer"
                                        :aria-label="`Select ${emp.name} for a login`"
                                    />
                                    <span v-else class="text-mb-emerald text-xs" title="Already has a login">✓</span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-md bg-mb-inset border border-mb-tint-cyan/30 text-mb-cyan font-mono font-bold tracking-wide">
                                        {{ emp.employee_code }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span class="text-mb-text-strong font-bold text-sm group-hover:text-mb-cyan-soft transition-colors">
                                            {{ emp.name }}
                                        </span>
                                        <span class="text-mb-text-muted text-[11px]">
                                            {{ emp.email || 'No email provided' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-mb-inset border border-mb-line text-mb-text-soft text-[11px] font-semibold capitalize">
                                        {{ emp.attendance_source || 'manual' }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border',
                                            emp.is_meal_eligible
                                                ? 'bg-mb-tint-emerald/10 text-mb-emerald-soft border-mb-tint-emerald/30'
                                                : 'bg-mb-raised text-mb-text-muted border-mb-line-mid'
                                        ]"
                                    >
                                        {{ emp.is_meal_eligible ? ' Eligible' : ' Ineligible' }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border',
                                            emp.status === 'active'
                                                ? 'bg-mb-tint-emerald/10 text-mb-emerald-soft border-mb-tint-emerald/30'
                                                : 'bg-mb-tint-rose/10 text-mb-rose border-mb-tint-rose/30'
                                        ]"
                                    >
                                        {{ emp.status === 'active' ? '🟢 Active' : '🔴 Inactive' }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap text-right space-x-2">
                                    <!-- Only offered where an account exists; the
                                         rest are provisioned via Create logins. -->
                                    <button
                                        @click="openRecurringModal(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-amber-soft hover:text-mb-text-strong bg-mb-raised hover:bg-mb-chip border border-mb-line-mid transition cursor-pointer"
                                        :title="`Recurring skips for ${emp.name}`"
                                    >
                                        🔄 Recurring
                                        <span v-if="activeRuleCount(emp.id)" class="text-mb-amber font-bold">({{ activeRuleCount(emp.id) }})</span>
                                    </button>
                                    <button
                                        v-if="emp.user_id"
                                        @click="resetPassword(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-amber hover:text-mb-text-strong bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 border border-mb-tint-amber/30 transition cursor-pointer"
                                    >
                                        Reset password
                                    </button>
                                    <button
                                        @click="openEditModal(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-cyan hover:text-mb-text-strong bg-mb-tint-cyan/10 hover:bg-mb-tint-cyan/20 border border-mb-tint-cyan/30 transition cursor-pointer"
                                    >
                                        Edit
                                    </button>
                                    <!-- Offered only where there is still a
                                         person to remove. -->
                                    <button
                                        v-if="!emp.anonymised_at"
                                        @click="beginAnonymise(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-mb-rose hover:text-mb-text-strong bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 border border-mb-tint-rose/30 transition cursor-pointer"
                                        :title="`Remove ${emp.name}'s personal details at their request`"
                                    >
                                        Remove details
                                    </button>
                                    <span v-else class="px-3 py-1.5 text-xs text-mb-text-faint italic">
                                        details removed
                                    </span>
                                </td>
                            </tr>

                            <!-- The confirmation, inline rather than a modal:
                                 it has to sit next to the row it is about. -->
                            <tr v-if="anonymising">
                                <td colspan="7" class="p-0">
                                    <div class="bg-mb-tint-rose/10 border-y border-mb-tint-rose/40 p-5 space-y-3">
                                        <div>
                                            <h4 class="font-bold text-mb-rose-soft">
                                                Remove {{ anonymising.name }}'s personal details?
                                            </h4>
                                            <p class="text-xs text-mb-rose-soft/80 mt-1">
                                                Their name, email, employee code, HR system id and login are removed
                                                and cannot be restored. Their past meals stay counted, so your daily
                                                totals and the figures the kitchen was given do not change. Any reason
                                                text on their skips is cleared.
                                            </p>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] uppercase font-semibold text-mb-rose-soft/70 mb-1">
                                                Type <span class="font-mono text-mb-rose-soft">{{ anonymising.name }}</span> to confirm
                                            </label>
                                            <input
                                                v-model="anonymiseForm.confirm_name"
                                                class="w-full max-w-md bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                                                :placeholder="anonymising.name"
                                            />
                                            <p v-if="anonymiseForm.errors.confirm_name" class="text-xs text-mb-rose-soft mt-1">
                                                {{ anonymiseForm.errors.confirm_name }}
                                            </p>
                                        </div>
                                        <div class="flex gap-2">
                                            <button
                                                @click="anonymise"
                                                :disabled="anonymiseForm.processing || anonymiseForm.confirm_name !== anonymising.name"
                                                class="px-4 py-2 bg-mb-tint-rose hover:bg-mb-tint-rose disabled:opacity-40 text-mb-text-strong font-bold rounded-lg text-sm cursor-pointer"
                                            >
                                                Remove their details
                                            </button>
                                            <button
                                                @click="anonymising = null"
                                                class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-line-mid cursor-pointer"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!employees.data || !employees.data.length">
                                <td colspan="7" class="p-12 text-center">
                                    <div class="max-w-xs mx-auto space-y-3">
                                        <div class="text-4xl">🔍</div>
                                        <h3 class="text-base font-bold text-mb-text-strong">No Employees Found</h3>
                                        <p class="text-xs text-mb-text-muted">
                                            No employee records match your search or filter criteria.
                                        </p>
                                        <button
                                            @click="openCreateModal"
                                            class="inline-block px-4 py-2 rounded-xl text-xs font-bold text-mb-on-accent bg-mb-cyan hover:bg-mb-cyan-soft transition"
                                        >
                                            Add First Employee
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="employees.links && employees.links.length > 3" class="p-4 bg-mb-inset/60 border-t border-mb-line flex items-center justify-between">
                    <span class="text-xs text-mb-text-muted">
                        Showing {{ employees.from || 0 }} to {{ employees.to || 0 }} of {{ employees.total }} employees
                    </span>
                    <div class="flex items-center space-x-1">
                        <Link
                            v-for="(link, idx) in employees.links"
                            :key="idx"
                            :href="link.url || '#'"
                            v-html="link.label"
                            :class="[
                                'px-3 py-1.5 rounded-lg text-xs font-semibold transition',
                                link.active
                                    ? 'bg-mb-tint-cyan text-mb-on-accent font-bold'
                                    : link.url
                                        ? 'bg-mb-card text-mb-text-soft hover:bg-mb-raised border border-mb-line'
                                        : 'bg-mb-inset text-mb-text-ghost cursor-not-allowed'
                            ]"
                        />
                    </div>
                </div>
            </div>

            <!-- Modals -->
            <EmployeeFormModal
                :show="showFormModal"
                :employee="selectedEmployee"
                @close="showFormModal = false"
            />

            <CsvImportModal
                :show="showCsvModal"
                @close="showCsvModal = false"
            />

            <SkipImportModal
                :show="showSkipImportModal"
                @close="showSkipImportModal = false"
            />

            <RecurringSkipModal
                :show="showRecurringModal"
                :employee="recurringEmployee"
                :rules="recurringRules"
                @close="showRecurringModal = false"
            />
        </div>
    </AppLayout>
</template>
