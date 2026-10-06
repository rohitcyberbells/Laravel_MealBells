<script setup>
import { computed, ref, watch } from 'vue';
import { router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import EmployeeFormModal from './EmployeeFormModal.vue';
import CsvImportModal from './CsvImportModal.vue';
import SkipImportModal from './SkipImportModal.vue';
import LoginCredentialsPanel from './LoginCredentialsPanel.vue';

const props = defineProps({
    company: Object,
    employees: Object,
    filters: Object,
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

const resetPassword = (employee) => {
    if (!confirm(`Reset the password for ${employee.name} (${employee.employee_code})? Their current password stops working immediately.`)) {
        return;
    }

    router.post(`/company-admin/employees/${employee.id}/reset-password`, {}, {
        preserveScroll: true,
        onSuccess: () => { dismissedCredentials.value = false; },
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
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-900/60 border border-slate-800 p-6 rounded-2xl backdrop-blur-md shadow-xl">
                <div>
                    <h1 class="text-2xl font-black text-white tracking-tight flex items-center gap-2">
                        👥 Employee Roster
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Manage company employees, meal eligibility, and attendance settings
                    </p>
                </div>
                <div class="flex items-center space-x-3">
                    <button
                        @click="openCsvModal"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 transition shadow-sm cursor-pointer"
                    >
                        <span>📥</span>
                        <span>Bulk CSV Import</span>
                    </button>
                    <button
                        v-if="selectedForLogins.length"
                        @click="createLogins"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-amber-200 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 transition shadow-sm cursor-pointer"
                    >
                        <span>🔑</span>
                        <span>Create logins ({{ selectedForLogins.length }})</span>
                    </button>
                    <button
                        @click="showSkipImportModal = true"
                        class="inline-flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 transition shadow-sm cursor-pointer"
                    >
                        <span>🗓️</span>
                        <span>Leave / WFH Import</span>
                    </button>
                    <button
                        @click="openCreateModal"
                        class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-950 bg-gradient-to-r from-cyan-400 to-emerald-400 hover:from-cyan-300 hover:to-emerald-300 shadow-lg shadow-cyan-500/20 transition cursor-pointer"
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
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500 text-sm pointer-events-none">
                        🔍
                    </span>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search by employee code, name, or email..."
                        class="w-full pl-10 pr-4 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-white text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition shadow-sm"
                    />
                </div>

                <!-- Status Filter -->
                <div>
                    <select
                        v-model="status"
                        class="w-full px-4 py-2.5 bg-slate-900 border border-slate-800 rounded-xl text-white text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition shadow-sm cursor-pointer"
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
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 uppercase tracking-wider font-semibold">
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
                        <tbody class="divide-y divide-slate-800/60 font-medium">
                            <tr
                                v-for="emp in employees.data"
                                :key="emp.id"
                                class="hover:bg-slate-800/40 transition-colors group"
                            >
                                <td class="p-4">
                                    <!-- Only employees without an account can be
                                         provisioned; the rest show a tick. -->
                                    <input
                                        v-if="!emp.user_id"
                                        type="checkbox"
                                        :checked="selectedForLogins.includes(emp.id)"
                                        @change="toggleForLogins(emp.id)"
                                        class="accent-cyan-500 cursor-pointer"
                                        :aria-label="`Select ${emp.name} for a login`"
                                    />
                                    <span v-else class="text-emerald-400 text-xs" title="Already has a login">✓</span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-md bg-slate-950 border border-cyan-500/30 text-cyan-400 font-mono font-bold tracking-wide">
                                        {{ emp.employee_code }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span class="text-white font-bold text-sm group-hover:text-cyan-300 transition-colors">
                                            {{ emp.name }}
                                        </span>
                                        <span class="text-slate-400 text-[11px]">
                                            {{ emp.email || 'No email provided' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-slate-300 text-[11px] font-semibold capitalize">
                                        {{ emp.attendance_source || 'manual' }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border',
                                            emp.is_meal_eligible
                                                ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                                                : 'bg-slate-800 text-slate-400 border-slate-700'
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
                                                ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                                                : 'bg-red-500/10 text-red-400 border-red-500/30'
                                        ]"
                                    >
                                        {{ emp.status === 'active' ? '🟢 Active' : '🔴 Inactive' }}
                                    </span>
                                </td>
                                <td class="p-4 whitespace-nowrap text-right space-x-2">
                                    <!-- Only offered where an account exists; the
                                         rest are provisioned via Create logins. -->
                                    <button
                                        v-if="emp.user_id"
                                        @click="resetPassword(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-amber-400 hover:text-white bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 transition cursor-pointer"
                                    >
                                        Reset password
                                    </button>
                                    <button
                                        @click="openEditModal(emp)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-cyan-400 hover:text-white bg-cyan-500/10 hover:bg-cyan-500/20 border border-cyan-500/30 transition cursor-pointer"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!employees.data || !employees.data.length">
                                <td colspan="7" class="p-12 text-center">
                                    <div class="max-w-xs mx-auto space-y-3">
                                        <div class="text-4xl">🔍</div>
                                        <h3 class="text-base font-bold text-white">No Employees Found</h3>
                                        <p class="text-xs text-slate-400">
                                            No employee records match your search or filter criteria.
                                        </p>
                                        <button
                                            @click="openCreateModal"
                                            class="inline-block px-4 py-2 rounded-xl text-xs font-bold text-slate-950 bg-cyan-400 hover:bg-cyan-300 transition"
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
                <div v-if="employees.links && employees.links.length > 3" class="p-4 bg-slate-950/60 border-t border-slate-800 flex items-center justify-between">
                    <span class="text-xs text-slate-400">
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
                                    ? 'bg-cyan-500 text-slate-950 font-bold'
                                    : link.url
                                        ? 'bg-slate-900 text-slate-300 hover:bg-slate-800 border border-slate-800'
                                        : 'bg-slate-950 text-slate-600 cursor-not-allowed'
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
        </div>
    </AppLayout>
</template>
