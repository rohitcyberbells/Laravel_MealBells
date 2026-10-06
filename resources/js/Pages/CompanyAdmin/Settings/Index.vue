<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    cutoff_time: String,
    timezone: String,
    wfh_auto_skip: Boolean,
    meal_days: Array,
    primary_admin_id: Number,
    backup_admin_id: Number,
    company_admins: Array,
    advance_limit_days: Number,
    attendance_sources: Array,
    temporary_password: { type: String, default: null },
});

const page = usePage();
const flashMessage = computed(() => page.props.flash?.message ?? null);

const WEEKDAYS = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

const TIMEZONES = [
    'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London',
    'America/New_York', 'Australia/Sydney', 'UTC',
];

const form = useForm({
    // Trimmed to HH:MM: the column stores seconds, which a time input rejects.
    cutoff_time: (props.cutoff_time || '11:00').slice(0, 5),
    timezone: props.timezone || 'Asia/Kolkata',
    wfh_auto_skip: !!props.wfh_auto_skip,
    meal_days: [...(props.meal_days || [1, 2, 3, 4, 5])],
    primary_admin_id: props.primary_admin_id,
    backup_admin_id: props.backup_admin_id,
});

const toggleDay = (value) => {
    const at = form.meal_days.indexOf(value);
    at === -1 ? form.meal_days.push(value) : form.meal_days.splice(at, 1);
};

const save = () => {
    form.transform((data) => ({
        ...data,
        meal_days: [...data.meal_days].sort((a, b) => a - b),
        primary_admin_id: data.primary_admin_id || null,
        backup_admin_id: data.backup_admin_id || null,
    })).put('/company-admin/settings', { preserveScroll: true });
};

const adminForm = useForm({ name: '', email: '' });

const addAdmin = () => {
    adminForm.post('/company-admin/admins', {
        preserveScroll: true,
        onSuccess: () => adminForm.reset(),
    });
};
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl mx-auto p-4 sm:p-6 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-white">⚙️ Company Settings</h1>
                <p class="text-sm text-slate-400">
                    These drive the daily count: when it closes, which days it runs, and who is told about it.
                </p>
            </div>

            <div v-if="flashMessage" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-300">
                {{ flashMessage }}
            </div>

            <div v-if="temporary_password" class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/40 space-y-2">
                <p class="text-sm font-bold text-amber-300">New admin created</p>
                <p class="text-xs text-amber-200/80">Share this temporary password now; it is not shown again.</p>
                <code class="block bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-emerald-300">{{ temporary_password }}</code>
            </div>

            <!-- Meal settings -->
            <form @submit.prevent="save" class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-6">
                <h2 class="text-base font-bold text-slate-200">Meal schedule</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Cutoff time</label>
                        <input v-model="form.cutoff_time" type="time" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <p class="text-[11px] text-slate-500 mt-1">After this the count locks and only recorded changes are possible.</p>
                        <p v-if="form.errors.cutoff_time" class="text-xs text-red-400 mt-1">{{ form.errors.cutoff_time }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Timezone</label>
                        <input v-model="form.timezone" list="tz-options" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <datalist id="tz-options">
                            <option v-for="tz in TIMEZONES" :key="tz" :value="tz" />
                        </datalist>
                        <p class="text-[11px] text-slate-500 mt-1">Cutoffs and meal days are judged on this clock.</p>
                        <p v-if="form.errors.timezone" class="text-xs text-red-400 mt-1">{{ form.errors.timezone }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-2">Meal days</label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="day in WEEKDAYS"
                            :key="day.value"
                            type="button"
                            @click="toggleDay(day.value)"
                            :class="[
                                'px-4 py-2 rounded-lg text-sm font-semibold border transition-colors cursor-pointer',
                                form.meal_days.includes(day.value)
                                    ? 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40'
                                    : 'bg-slate-950 text-slate-400 border-slate-700 hover:text-slate-200'
                            ]"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-2">A calendar holiday still overrides these for a single date.</p>
                    <p v-if="form.errors.meal_days" class="text-xs text-red-400 mt-1">{{ form.errors.meal_days }}</p>
                </div>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="form.wfh_auto_skip" type="checkbox" class="mt-0.5 accent-cyan-500 cursor-pointer" />
                    <span>
                        <span class="text-sm font-semibold text-slate-200">Treat work-from-home as a skip</span>
                        <span class="block text-[11px] text-slate-500">
                            When off, a WFH record is ignored by both the CSV import and the HR feed, and the person is still counted.
                        </span>
                    </span>
                </label>

                <h2 class="text-base font-bold text-slate-200 pt-2">Who hears about the count</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Primary admin</label>
                        <select v-model="form.primary_admin_id" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                            <option :value="null">— none —</option>
                            <option v-for="admin in company_admins" :key="admin.id" :value="admin.id">
                                {{ admin.name }} ({{ admin.email }})
                            </option>
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">Gets the daily summary before cutoff.</p>
                        <p v-if="form.errors.primary_admin_id" class="text-xs text-red-400 mt-1">{{ form.errors.primary_admin_id }}</p>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Backup admin</label>
                        <select v-model="form.backup_admin_id" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
                            <option :value="null">— none —</option>
                            <option v-for="admin in company_admins" :key="admin.id" :value="admin.id">
                                {{ admin.name }} ({{ admin.email }})
                            </option>
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">Gets an escalation if an unusual count is not reviewed.</p>
                        <p v-if="form.errors.backup_admin_id" class="text-xs text-red-400 mt-1">{{ form.errors.backup_admin_id }}</p>
                    </div>
                </div>

                <button type="submit" :disabled="form.processing" class="px-5 py-2.5 bg-cyan-500 hover:bg-cyan-600 disabled:opacity-40 text-slate-950 font-bold rounded-lg text-sm cursor-pointer">
                    Save settings
                </button>
            </form>

            <!-- Add an admin -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-4">
                <div>
                    <h2 class="text-base font-bold text-slate-200">Company admins</h2>
                    <p class="text-xs text-slate-400">{{ company_admins.length }} admin(s). A new one gets a temporary password to change on first sign-in.</p>
                </div>

                <form @submit.prevent="addAdmin" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Name</label>
                        <input v-model="adminForm.name" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <p v-if="adminForm.errors.name" class="text-xs text-red-400 mt-1">{{ adminForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Email</label>
                        <input v-model="adminForm.email" type="email" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                        <p v-if="adminForm.errors.email" class="text-xs text-red-400 mt-1">{{ adminForm.errors.email }}</p>
                    </div>
                    <button type="submit" :disabled="adminForm.processing" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-slate-200 font-bold rounded-lg text-sm border border-slate-700 cursor-pointer">
                        Add admin
                    </button>
                </form>
            </div>

            <!-- Not company settings, but people look for them here -->
            <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 space-y-3">
                <h2 class="text-base font-bold text-slate-200">Set elsewhere</h2>

                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <p class="text-slate-300 font-semibold">Advance limit</p>
                        <p class="text-xs text-slate-500">
                            How far ahead meals can be recorded. A platform-wide setting, the same for every company.
                        </p>
                    </div>
                    <span class="font-mono text-slate-400 whitespace-nowrap">{{ advance_limit_days }} days</span>
                </div>

                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <p class="text-slate-300 font-semibold">Attendance source</p>
                        <p class="text-xs text-slate-500">
                            Per employee, not per company: {{ attendance_sources.join(', ') }}.
                        </p>
                    </div>
                    <Link href="/company-admin/employees" class="text-cyan-400 hover:text-cyan-300 text-xs font-semibold whitespace-nowrap">
                        Employees →
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
