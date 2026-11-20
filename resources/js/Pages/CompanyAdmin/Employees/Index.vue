<script setup>
import { computed, ref, watch } from 'vue';
import { router, Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import SelectField from '../../../Layouts/SelectField.vue';
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

/**
 * Where this employee's attendance comes from, in words.
 *
 * The column printed the raw enum, so the roster read "Integrated" down every
 * row — a value that means nothing unless you already know the schema. What it
 * decides is whether the HR pull touches this person at all.
 */
const ATTENDANCE_LABELS = {
    integrated: 'HR system',
    manual: 'Entered by hand',
    none: 'Not tracked',
};

const attendanceLabel = (source) => ATTENDANCE_LABELS[source ?? 'manual'] ?? 'Entered by hand';

const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

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

/**
 * Laravel labels the ends "&laquo; Previous" and "Next &raquo;". The arrow is
 * drawn as an icon instead, so the label keeps only its words.
 */
const pageLabel = (label) => String(label).replace(/&laquo;|&raquo;|\u00ab|\u00bb/g, '').trim();
</script>

<template>
    <AppLayout>
        <div class="p-4 sm:p-6 space-y-5">
            <!--
                A page heading, not a card. It was wrapped in the same frosted
                surface the content below uses, so the page opened with a box
                whose only job was to hold a title.
            -->
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">Employees</h1>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                        Manage company employees, meal eligibility, and attendance settings
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        @click="openCsvModal"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" />
                        </svg>
                        Bulk CSV import
                    </button>

                    <button
                        v-if="selectedForLogins.length"
                        @click="createLogins"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-mb-card border border-mb-tint-amber/50 text-xs font-semibold text-mb-amber hover:bg-mb-tint-amber/10 cursor-pointer transition whitespace-nowrap"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 1-3.3 6.3L4 21H2v-3l8.7-8.7A4 4 0 0 1 15 7Z" />
                        </svg>
                        Create logins ({{ selectedForLogins.length }})
                    </button>

                    <button
                        @click="showSkipImportModal = true"
                        class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2ZM8 2v4M16 2v4M4 10h16" />
                        </svg>
                        Leave / WFH import
                    </button>

                    <button
                        @click="openCreateModal"
                        class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-lg text-xs font-semibold text-mb-on-fill bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 cursor-pointer transition whitespace-nowrap"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M12 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM19 8v6M22 11h-6" />
                        </svg>
                        Add employee
                    </button>
                </div>
            </header>

            <!--
                A filter bar, not two more cards. Both controls were 2xl-rounded
                frosted panels the height of a button and a half, so the row
                read as heavier than the table it filters. Plain fields now, at
                the same 40px as every other control on the page, and the
                status box only as wide as its longest option.
            -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- Capped: a search box has nothing to do with the width of
                     the table, and at full width the caret started a screen
                     away from the icon that labels it. -->
                <div class="relative grow basis-72 max-w-sm min-w-0">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-mb-text-muted dark:text-mb-text-soft pointer-events-none">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM21 21l-4.3-4.3" />
                        </svg>
                    </span>
                    <input
                        v-model="search"
                        type="text"
                        aria-label="Search employees"
                        placeholder="Search code, name or email"
                        class="w-full h-10 pl-9 pr-3 bg-mb-card border border-mb-field rounded-lg text-mb-text-strong text-sm focus:outline-none focus:border-mb-tint-cyan transition"
                    />
                </div>

                <!-- Status Filter -->
                <div class="w-40 shrink-0">
                    <SelectField v-model="status" :options="statusOptions" aria-label="Filter by status" />
                </div>
            </div>

            <LoginCredentialsPanel
                v-if="credentials && !dismissedCredentials"
                :credentials="credentials"
                @dismiss="dismissedCredentials = true"
            />

            <!-- Employee Data Table -->
            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead class="bg-mb-inset/80 border-b border-mb-line text-mb-text-muted dark:text-mb-text-soft uppercase tracking-wider font-semibold">
                            <tr>
                                <th class="pl-4 pr-1 py-2 w-9"></th>
                                <th class="px-3 py-2">Employee Code</th>
                                <th class="px-3 py-2">Name & Email</th>
                                <!--
                                    Eligibility and status had a column each,
                                    then one shared column, and both were the
                                    same word repeated down every row. They are
                                    now a mark beside the name, shown only when
                                    a row is not eligible or not active - so a
                                    normal roster carries no column at all.
                                -->
                                <th class="px-3 py-2">Attendance from</th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-mb-line/60 font-medium">
                            <tr
                                v-for="emp in employees.data"
                                :key="emp.id"
                                class="hover:bg-mb-raised/40 transition-colors group"
                            >
                                <td class="pl-4 pr-1 py-2">
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
                                    <svg v-else class="w-4 h-4 text-mb-emerald" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" role="img" aria-label="Already has a login">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9.5 18 20 7" />
                                    </svg>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <!-- A monospace code is already distinct from
                                         the prose beside it; a bordered chip on
                                         every row just drew a box per cell. -->
                                    <span class="font-mono text-xs font-semibold tracking-wide text-mb-text-soft">
                                        {{ emp.employee_code }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span class="text-mb-text-strong font-semibold text-sm leading-tight group-hover:text-mb-cyan-soft transition-colors">
                                            {{ emp.name }}
                                        </span>
                                        <span class="text-mb-text-muted dark:text-mb-text-soft text-[11px] leading-tight mt-0.5">
                                            {{ emp.email || 'No email provided' }}
                                        </span>

                                        <div
                                            v-if="!emp.is_meal_eligible || emp.status !== 'active'"
                                            class="flex items-center gap-3 mt-1"
                                        >
                                            <span v-if="!emp.is_meal_eligible" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-amber">
                                                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                                Not eligible
                                            </span>
                                            <span v-if="emp.status !== 'active'" class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-rose dark:text-mb-rose-soft">
                                                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-rose" aria-hidden="true"></span>
                                                Inactive
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="text-[11px] font-semibold text-mb-text-muted dark:text-mb-text-soft">
                                        {{ attendanceLabel(emp.attendance_source) }}
                                    </span>
                                </td>
                                <!--
                                    Icons, not four labelled chips per row. The
                                    words were repeated down the whole table and
                                    swallowed the column; each button now says
                                    what it does to a screen reader and on
                                    hover, where it is read once rather than
                                    twenty-one times.
                                -->
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            @click="openRecurringModal(emp)"
                                            class="relative w-8 h-8 shrink-0 rounded-lg bg-mb-card border border-mb-field text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised flex items-center justify-center cursor-pointer transition"
                                            :title="`Days ${emp.name} never eats in`"
                                            :aria-label="`Days ${emp.name} never eats in`"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />
                                            </svg>
                                            <span
                                                v-if="activeRuleCount(emp.id)"
                                                class="absolute -top-1.5 -right-1.5 min-w-4 h-4 px-1 rounded-full bg-mb-amber text-mb-page text-[10px] font-bold flex items-center justify-center"
                                            >
                                                {{ activeRuleCount(emp.id) }}
                                            </span>
                                        </button>

                                        <!-- Only offered where an account exists; the
                                             rest are provisioned via Create logins. -->
                                        <button
                                            v-if="emp.user_id"
                                            @click="resetPassword(emp)"
                                            class="w-8 h-8 shrink-0 rounded-lg bg-mb-card border border-mb-field text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised flex items-center justify-center cursor-pointer transition"
                                            :title="`Reset ${emp.name}'s password`"
                                            :aria-label="`Reset ${emp.name}'s password`"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 1-3.3 6.3L4 21H2v-3l8.7-8.7A4 4 0 0 1 15 7Z" />
                                            </svg>
                                        </button>

                                        <button
                                            @click="openEditModal(emp)"
                                            class="w-8 h-8 shrink-0 rounded-lg bg-mb-card border border-mb-field text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised flex items-center justify-center cursor-pointer transition"
                                            :title="`Edit ${emp.name}`"
                                            :aria-label="`Edit ${emp.name}`"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                            </svg>
                                        </button>

                                        <!-- Offered only where there is still a
                                             person to remove. -->
                                        <button
                                            v-if="!emp.anonymised_at"
                                            @click="beginAnonymise(emp)"
                                            class="w-8 h-8 shrink-0 rounded-lg bg-mb-card border border-mb-tint-rose/50 text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10 flex items-center justify-center cursor-pointer transition"
                                            :title="`Remove ${emp.name}'s personal details at their request`"
                                            :aria-label="`Remove ${emp.name}'s personal details`"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M12 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM22 11h-6" />
                                            </svg>
                                        </button>
                                        <span
                                            v-else
                                            class="w-8 h-8 shrink-0 flex items-center justify-center text-mb-text-muted dark:text-mb-text-soft"
                                            :title="`${emp.name}'s personal details have been removed`"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" role="img" aria-label="Personal details already removed">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM8 12h8" />
                                            </svg>
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- The confirmation, inline rather than a modal:
                                 it has to sit next to the row it is about. -->
                            <tr v-if="anonymising">
                                <td colspan="5" class="p-0">
                                    <div class="bg-mb-tint-rose/10 border-y border-mb-tint-rose/40 p-5 space-y-3">
                                        <div>
                                            <h4 class="font-bold text-mb-rose-soft">
                                                Remove {{ anonymising.name }}'s personal details?
                                            </h4>
                                            <p class="text-xs text-mb-rose-soft mt-1">
                                                Their name, email, employee code, HR system id and login are removed
                                                and cannot be restored. Their past meals stay counted, so your daily
                                                totals and the figures the kitchen was given do not change. Any reason
                                                text on their skips is cleared.
                                            </p>
                                        </div>
                                        <div>
                                            <label for="index-type-to-confirm" class="block text-[11px] uppercase font-semibold text-mb-rose-soft mb-1">
                                                Type <span class="font-mono text-mb-rose-soft">{{ anonymising.name }}</span> to confirm
                                            </label>
                                            <input id="index-type-to-confirm"
                                                v-model="anonymiseForm.confirm_name"
                                                class="w-full max-w-md bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
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
                                                class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-field cursor-pointer"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!employees.data || !employees.data.length">
                                <td colspan="5" class="p-12 text-center">
                                    <div class="max-w-xs mx-auto space-y-3">
                                        <h3 class="text-base font-bold text-mb-text-strong">No Employees Found</h3>
                                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft">
                                            No employee records match your search or filter criteria.
                                        </p>
                                        <button
                                            @click="openCreateModal"
                                            class="inline-block px-4 py-2 rounded-xl text-xs font-bold text-mb-on-fill bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 transition"
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
                <div v-if="employees.links && employees.links.length > 3" class="p-4 border-t border-mb-line flex flex-wrap items-center justify-between gap-3">
                    <span class="text-xs text-mb-text-muted dark:text-mb-text-soft">
                        Showing {{ employees.from || 0 }} to {{ employees.to || 0 }} of {{ employees.total }} employees
                    </span>
                    <div class="flex items-center gap-1.5">
                        <Link
                            v-for="(link, idx) in employees.links"
                            :key="idx"
                            :href="link.url || '#'"
                            :class="[
                                'min-w-8 h-8 inline-flex items-center justify-center gap-1 px-2.5 rounded-lg text-xs font-semibold transition tabular-nums',
                                // Filled, the way the daily roster marks its
                                // page: a bordered white box read as one more
                                // button rather than as where you are.
                                link.active
                                    ? 'bg-mb-text-strong text-mb-page'
                                    : link.url
                                        ? 'bg-mb-card border border-mb-field text-mb-text-soft hover:bg-mb-raised'
                                        : 'text-mb-text-muted dark:text-mb-text-soft opacity-40 cursor-default'
                            ]"
                        >
                            <svg v-if="idx === 0" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                            </svg>
                            <span v-html="pageLabel(link.label)"></span>
                            <svg v-if="idx === employees.links.length - 1" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                            </svg>
                        </Link>
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
