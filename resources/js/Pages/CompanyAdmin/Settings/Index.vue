<script setup>
import SelectField from '../../../Layouts/SelectField.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    cutoff_time: String,
    timezone: String,
    wfh_auto_skip: Boolean,
    attendance_absence_enabled: Boolean,
    meal_days: Array,
    primary_admin_id: Number,
    backup_admin_id: Number,
    company_admins: Array,
    advance_limit_days: Number,
    attendance_sources: Array,
    temporary_password: { type: String, default: null },
    last_changed: { type: Object, default: null },
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
    attendance_absence_enabled: !!props.attendance_absence_enabled,
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

/** The empty option carries null, which is what clearing the field stores. */
const adminOptions = computed(() => [
    { value: null, label: '— none —' },
    ...(props.company_admins ?? []).map((admin) => ({
        value: admin.id,
        label: `${admin.name} (${admin.email})`,
    })),
]);
</script>

<template>
    <AppLayout>
        <div class="p-4 sm:p-6 space-y-5">
            <div>
                <h1 class="text-2xl font-bold text-mb-text-strong">Company Settings</h1>
                <p class="text-sm text-mb-text-muted dark:text-mb-text-soft">
                    These drive the daily count: when it closes, which days it runs, and who is told about it.
                </p>
                <!-- Moving the cutoff changes every future count, so it is worth
                     being able to see who last did. -->
                <p v-if="last_changed" class="text-xs text-mb-text-faint dark:text-mb-text-soft mt-1">
                    Last changed <span class="font-mono text-mb-text-muted dark:text-mb-text-soft">{{ last_changed.at }}</span>
                    <span v-if="last_changed.by"> by {{ last_changed.by }}</span>
                </p>
            </div>

            <div v-if="flashMessage" class="p-3 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-sm text-mb-emerald-soft">
                {{ flashMessage }}
            </div>

            <div v-if="temporary_password" class="p-4 rounded-xl bg-mb-tint-amber/10 border border-mb-tint-amber/40 space-y-2">
                <p class="text-sm font-bold text-mb-amber-soft">New admin created</p>
                <p class="text-xs text-mb-amber-soft">Share this temporary password now; it is not shown again.</p>
                <code class="block bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-xs text-mb-emerald-soft">{{ temporary_password }}</code>
            </div>

            <!-- Meal settings -->
            <form @submit.prevent="save" class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-6">
                <h2 class="text-base font-bold text-mb-text">Meal schedule</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="index-cutoff-time" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Cutoff time</label>
                        <input id="index-cutoff-time" v-model="form.cutoff_time" type="time" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <p class="text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-1">After this the count locks and only recorded changes are possible.</p>
                        <p v-if="form.errors.cutoff_time" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ form.errors.cutoff_time }}</p>
                    </div>

                    <div>
                        <label for="index-timezone" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Timezone</label>
                        <input id="index-timezone" v-model="form.timezone" list="tz-options" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <datalist id="tz-options">
                            <option v-for="tz in TIMEZONES" :key="tz" :value="tz" />
                        </datalist>
                        <p class="text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-1">Cutoffs and meal days are judged on this clock.</p>
                        <p v-if="form.errors.timezone" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ form.errors.timezone }}</p>
                    </div>
                </div>

                <div>
                    <span id="meal-days-label" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-2">Meal days</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-labelledby="meal-days-label">
                        <button
                            v-for="day in WEEKDAYS"
                            :key="day.value"
                            type="button"
                            :aria-pressed="form.meal_days.includes(day.value)"
                            @click="toggleDay(day.value)"
                            :class="[
                                'px-4 py-2 rounded-lg text-sm font-semibold border transition-colors cursor-pointer',
                                form.meal_days.includes(day.value)
                                    ? 'bg-mb-tint-cyan/20 text-mb-cyan-soft border-mb-tint-cyan/40'
                                    : 'bg-mb-inset text-mb-text-muted dark:text-mb-text-soft border-mb-field hover:text-mb-text'
                            ]"
                        >
                            {{ day.label }}
                        </button>
                    </div>
                    <p class="text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-2">A calendar holiday still overrides these for a single date.</p>
                    <p v-if="form.errors.meal_days" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ form.errors.meal_days }}</p>
                </div>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="form.wfh_auto_skip" type="checkbox" class="mt-0.5 accent-mb-tint-cyan cursor-pointer" />
                    <span>
                        <span class="text-sm font-semibold text-mb-text">Treat work-from-home as a skip</span>
                        <span class="block text-[11px] text-mb-text-faint dark:text-mb-text-soft">
                            When off, a WFH record is ignored by both the CSV import and the HR feed, and the person is still counted.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="form.attendance_absence_enabled" type="checkbox" class="mt-0.5 accent-mb-tint-cyan cursor-pointer" />
                    <span>
                        <span class="text-sm font-semibold text-mb-text">Read attendance from the HR system</span>
                        <span class="block text-[11px] text-mb-text-faint dark:text-mb-text-soft">
                            Shortly before each cutoff, MealBells asks your HR system who had clocked in, and reports how
                            many meals would not have been ordered. <span class="text-mb-amber">It does not change any
                            count and creates no skip</span> &mdash; see Reports &rarr; Attendance (shadow).
                        </span>
                        <span class="block text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-1">
                            Needs an attendance API key on the HRMS screen, and only applies to employees whose
                            attendance source is <span class="font-mono text-mb-text-muted dark:text-mb-text-soft">integrated</span>.
                        </span>
                    </span>
                </label>

                <h2 class="text-base font-bold text-mb-text pt-2">Who hears about the count</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="primary-admin" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Primary admin</label>
                        <SelectField
                            id="primary-admin"
                            v-model="form.primary_admin_id"
                            :options="adminOptions"
                        />
                        <p class="text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-1">Gets the daily summary before cutoff.</p>
                        <p v-if="form.errors.primary_admin_id" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ form.errors.primary_admin_id }}</p>
                    </div>

                    <div>
                        <label for="backup-admin" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Backup admin</label>
                        <SelectField
                            id="backup-admin"
                            v-model="form.backup_admin_id"
                            :options="adminOptions"
                        />
                        <p class="text-[11px] text-mb-text-faint dark:text-mb-text-soft mt-1">Gets an escalation if an unusual count is not reviewed.</p>
                        <p v-if="form.errors.backup_admin_id" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ form.errors.backup_admin_id }}</p>
                    </div>
                </div>

                <button type="submit" :disabled="form.processing" class="px-5 py-2.5 bg-mb-fill-emerald hover:opacity-90 border border-transparent dark:border-mb-tint-emerald/50 disabled:opacity-40 text-mb-on-fill font-bold rounded-lg text-sm cursor-pointer">
                    Save settings
                </button>
            </form>

            <!-- Add an admin -->
            <div class="p-6 rounded-2xl bg-mb-card border border-mb-line space-y-4">
                <div>
                    <h2 class="text-base font-bold text-mb-text">Company admins</h2>
                    <p class="text-xs text-mb-text-muted dark:text-mb-text-soft">{{ company_admins.length }} admin(s). A new one gets a temporary password to change on first sign-in.</p>
                </div>

                <form @submit.prevent="addAdmin" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <div>
                        <label for="index-name" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Name</label>
                        <input id="index-name" v-model="adminForm.name" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <p v-if="adminForm.errors.name" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ adminForm.errors.name }}</p>
                    </div>
                    <div>
                        <label for="index-email" class="block text-[11px] uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Email</label>
                        <input id="index-email" v-model="adminForm.email" type="email" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                        <p v-if="adminForm.errors.email" class="text-xs text-mb-rose dark:text-mb-rose-soft mt-1">{{ adminForm.errors.email }}</p>
                    </div>
                    <button type="submit" :disabled="adminForm.processing" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip disabled:opacity-40 text-mb-text font-bold rounded-lg text-sm border border-mb-field cursor-pointer">
                        Add admin
                    </button>
                </form>
            </div>

            <!-- Not company settings, but people look for them here -->
            <div class="p-6 rounded-2xl bg-mb-card/60 border border-mb-line space-y-3">
                <h2 class="text-base font-bold text-mb-text">Set elsewhere</h2>

                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <p class="text-mb-text-soft font-semibold">Advance limit</p>
                        <p class="text-xs text-mb-text-faint dark:text-mb-text-soft">
                            How far ahead meals can be recorded. A platform-wide setting, the same for every company.
                        </p>
                    </div>
                    <span class="font-mono text-mb-text-muted dark:text-mb-text-soft whitespace-nowrap">{{ advance_limit_days }} days</span>
                </div>

                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <p class="text-mb-text-soft font-semibold">Attendance source</p>
                        <p class="text-xs text-mb-text-faint dark:text-mb-text-soft">
                            Per employee, not per company: {{ attendance_sources.join(', ') }}.
                        </p>
                    </div>
                    <Link href="/company-admin/employees" class="text-mb-cyan hover:text-mb-cyan-soft text-xs font-semibold whitespace-nowrap">
                        Employees →
                    </Link>
                </div>
            </div>

            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 space-y-4">
                <h2 class="text-base font-bold text-mb-text">Your data</h2>

                <div class="flex items-start justify-between gap-4 text-sm">
                    <div>
                        <p class="text-mb-text-soft font-semibold">Export everything</p>
                        <p class="text-xs text-mb-text-faint dark:text-mb-text-soft">
                            A zip of four CSVs — employees, skips, extra meals and the daily counts the kitchen
                            was given — with a README explaining each column. It contains personal data, so
                            keep it somewhere you would keep a payroll file.
                        </p>
                    </div>
                    <!-- A plain link, not an Inertia visit: the response is a
                         file download and Inertia would try to parse it. -->
                    <a
                        href="/company-admin/export"
                        class="bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/30 px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition"
                    >
                        Download export
                    </a>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
