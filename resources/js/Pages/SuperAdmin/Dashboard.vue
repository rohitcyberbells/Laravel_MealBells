<script setup>
import SelectField from '../../Layouts/SelectField.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    companies: Array,
    hrms_secret: { type: Object, default: null },
    tiffinServices: Array,
    assignments: Array,
    archivedCompanies: { type: Array, default: () => [] },
    archivedTiffinServices: { type: Array, default: () => [] },
    temporary_password: { type: String, default: null },
    reset_for: { type: Object, default: null },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

const activeTab = ref('companies');

const tabs = computed(() => [
    { key: 'companies', label: `Companies (${props.companies.length})` },
    { key: 'tiffins', label: `Tiffin services (${props.tiffinServices.length})` },
    { key: 'assignments', label: 'Pairing history' },
]);

// A pairing is what makes a company's count reach a kitchen, so the count of
// live ones is the number this page exists to keep non-zero.
const activePairings = computed(() => (props.assignments ?? []).filter((a) => a.is_active).length);

// A company with no active pairing gets no meals at all, which is the one
// state on this page worth noticing at a glance.
const unpairedCompanies = computed(() =>
    (props.companies ?? []).filter((c) => ! c.assignments?.some((a) => a.is_active)).length
);

const setActive = (admin) => {
    const verb = admin.is_active ? 'Deactivate' : 'Reactivate';
    if (!confirm(`${verb} ${admin.name} (${admin.email})? ${admin.is_active
        ? 'They will not be able to sign in. Nothing they created is removed.'
        : 'They will be able to sign in again.'}`)) {
        return;
    }
    router.post(`/super-admin/users/${admin.id}/active`, { is_active: !admin.is_active }, { preserveScroll: true });
};

const resetAdminPassword = (admin) => {
    if (!confirm(`Reset the password for ${admin.name} (${admin.email})? Their current password stops working immediately.`)) {
        return;
    }
    router.post(`/super-admin/users/${admin.id}/reset-password`, {}, { preserveScroll: true });
};

const rotateHrmsSecret = (companyId, companyName, hasSecret) => {
    const verb = hasSecret ? 'Rotate' : 'Generate';
    if (!confirm(`${verb} the HRMS webhook secret for ${companyName}? The old secret stops working immediately.`)) {
        return;
    }
    router.post(`/super-admin/companies/${companyId}/hrms-secret`, {}, { preserveScroll: true });
};

// Forms
const companyForm = useForm({
    name: '',
    address: '',
    contact_phone: '',
    admin_name: '',
    admin_email: '',
    admin_password: 'password',
});

const tiffinForm = useForm({
    name: '',
    address: '',
    contact_phone: '',
    admin_name: '',
    admin_email: '',
    admin_password: 'password',
});

const assignForm = useForm({
    company_id: '',
    tiffin_service_id: '',
});

const submitCompany = () => {
    companyForm.post('/super-admin/companies', {
        onSuccess: () => companyForm.reset('name', 'address', 'contact_phone', 'admin_name', 'admin_email'),
    });
};

const submitTiffin = () => {
    tiffinForm.post('/super-admin/tiffin-services', {
        onSuccess: () => tiffinForm.reset('name', 'address', 'contact_phone', 'admin_name', 'admin_email'),
    });
};

const submitAssign = () => {
    assignForm.post('/super-admin/assign', {
        onSuccess: () => assignForm.reset(),
    });
};

// Typed, not clicked. A confirm() is one keystroke away from archiving the
// wrong tenant, and this is the most destructive action in the application.
const archiveForm = useForm({ confirm_name: '' });
const archiving = ref(null);

const beginArchive = (company) => {
    archiving.value = company;
    archiveForm.reset('confirm_name');
    archiveForm.clearErrors();
};

const archiveCompany = () => {
    archiveForm.delete(`/super-admin/companies/${archiving.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { archiving.value = null; },
    });
};

const restoreCompany = (company) => {
    router.post(`/super-admin/companies/${company.id}/restore`, {}, { preserveScroll: true });
};

const unpairCompany = (companyId, companyName) => {
    if (confirm(`Are you sure you want to unpair ${companyName}?`)) {
        router.post('/super-admin/unpair', { company_id: companyId });
    }
};

// Typed, not clicked, for the same reason as a company: archiving the wrong
// vendor stops every company they serve getting a daily count.
const archiveTiffinForm = useForm({ confirm_name: '' });
const archivingTiffin = ref(null);

const beginArchiveTiffin = (tiffin) => {
    archivingTiffin.value = tiffin;
    archiveTiffinForm.reset('confirm_name');
    archiveTiffinForm.clearErrors();
};

const archiveTiffin = () => {
    archiveTiffinForm.delete(`/super-admin/tiffin-services/${archivingTiffin.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { archivingTiffin.value = null; },
    });
};

const restoreTiffin = (tiffin) => {
    router.post(`/super-admin/tiffin-services/${tiffin.id}/restore`, {}, { preserveScroll: true });
};


const companyOptions = computed(() => (props.companies ?? []).map((company) => ({
    value: company.id,
    label: company.name,
})));

const tiffinServiceOptions = computed(() => (props.tiffinServices ?? []).map((service) => ({
    value: service.id,
    label: service.name,
})));
</script>

<template>
    <AppLayout>
        <!--
            The same ambient layer the employee portal uses: three soft tinted
            blobs, blurred past recognition, so the frosted cards have
            something to frost. z-0 rather than a negative index, which would
            put them behind the shell's own background.
        -->
    <div class="relative z-10 p-4 sm:p-6 space-y-5">
        <header>
            <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">Super Admin</h1>
            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                Companies, tiffin services, and who is paired with whom.
            </p>
        </header>

        <section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
            <div class="bg-mb-fill-emerald text-mb-on-fill-soft border border-transparent dark:border-mb-tint-emerald/50 rounded-2xl p-4 pb-5 flex flex-col gap-3">
                <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 0 0-2 2v10h18V9a2 2 0 0 0-2-2h-3M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M8 7h8" />
                    </svg>
                    <span class="text-xs font-semibold">Live pairings</span>
                </div>
                <p class="text-4xl font-light leading-none tracking-tight text-mb-on-fill">{{ activePairings }}</p>
                <p class="text-[11px] leading-snug">Companies whose count reaches a kitchen.</p>
            </div>

            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                <div class="flex items-center gap-1.5 text-mb-text-soft">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M15 21V11h3a2 2 0 0 1 2 2v8M3 21h18M8 7h3M8 11h3M8 15h3" />
                    </svg>
                    <span class="text-xs font-semibold">Companies</span>
                </div>
                <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">{{ companies.length }}</p>
                <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Active tenants on the platform.</p>
            </div>

            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                <div class="flex items-center gap-1.5 text-mb-text-soft">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 11h18M5 11a7 7 0 0 1 14 0M4 15h16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" />
                    </svg>
                    <span class="text-xs font-semibold">Tiffin services</span>
                </div>
                <p class="text-4xl font-light leading-none tracking-tight text-mb-text-strong">{{ tiffinServices.length }}</p>
                <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">Kitchens available to pair.</p>
            </div>

            <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-4 pb-5 flex flex-col gap-3">
                <div class="flex items-center gap-1.5" :class="unpairedCompanies ? 'text-mb-amber' : 'text-mb-text-soft'">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01M10.3 4.3 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z" />
                    </svg>
                    <span class="text-xs font-semibold">Unpaired</span>
                </div>
                <p class="text-4xl font-light leading-none tracking-tight" :class="unpairedCompanies ? 'text-mb-amber' : 'text-mb-text-strong'">{{ unpairedCompanies }}</p>
                <p class="text-[11px] leading-snug text-mb-text-muted dark:text-mb-text-soft">
                    {{ unpairedCompanies ? 'These companies receive no meals.' : 'Every company has a kitchen.' }}
                </p>
            </div>
        </section>

        <main class="space-y-5">
            <!-- Pairing Tool Card -->
            <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <h2 class="text-xl font-bold text-mb-emerald-soft mb-4">Pair Company ↔ Tiffin Service</h2>
                <form @submit.prevent="submitAssign" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label for="pair-company" class="block text-xs uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Select Company</label>
                        <SelectField
                            id="pair-company"
                            v-model="assignForm.company_id"
                            :options="companyOptions"
                            placeholder="Choose Company"
                        />
                    </div>
                    <div>
                        <label for="pair-service" class="block text-xs uppercase font-semibold text-mb-text-muted dark:text-mb-text-soft mb-1">Select Tiffin Service</label>
                        <SelectField
                            id="pair-service"
                            v-model="assignForm.tiffin_service_id"
                            :options="tiffinServiceOptions"
                            placeholder="Choose Tiffin Service"
                        />
                    </div>
                    <button type="submit" :disabled="assignForm.processing" class="bg-mb-fill-emerald hover:opacity-90 disabled:opacity-50 font-bold text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 py-2 px-4 rounded-xl cursor-pointer transition">
                        Confirm Pairing
                    </button>
                </form>
            </section>

            <!-- Shown exactly once, straight after a reset: the plaintext is
                 never stored, so this render is the only copy. -->
            <div v-if="temporary_password" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-2xl p-5 space-y-3">
                <div>
                    <h4 class="font-bold text-mb-amber-soft flex items-center gap-2">
                        <svg class="w-4 h-4 shrink-0 text-mb-amber" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 1-3.3 6.3L4 21H2v-3l8.7-8.7A4 4 0 0 1 15 7Z" />
                        </svg>
                        Temporary password<span v-if="reset_for">&nbsp;for {{ reset_for.name }}</span>
                    </h4>
                    <p class="text-xs text-mb-amber-soft">
                        <span v-if="reset_for" class="font-mono">{{ reset_for.email }}</span>
                        <span v-if="reset_for"> — </span>share it now; it is not shown again. They must change it on first sign-in.
                    </p>
                </div>
                <code class="block bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-xs text-mb-emerald-soft break-all">{{ temporary_password }}</code>
            </div>

            <!-- Shown exactly once: the plaintext is never stored and cannot
                 be recovered. It used to sit inside the tab bar, where its
                 layout fought the tabs for the same row. -->
            <div v-if="hrms_secret" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-2xl p-5 space-y-3">
                <div class="flex items-start gap-2.5">
                    <svg class="w-4 h-4 shrink-0 mt-0.5 text-mb-amber" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 1-3.3 6.3L4 21H2v-3l8.7-8.7A4 4 0 0 1 15 7Z" />
                    </svg>
                    <div>
                        <h4 class="font-bold text-mb-amber-soft">HRMS webhook secret for {{ hrms_secret.company_name }}</h4>
                        <p class="text-xs text-mb-amber-soft mt-0.5">
                            Copy it now. It is encrypted at rest and will never be shown again — rotating is the only way to get a new one.
                        </p>
                    </div>
                </div>
                <div>
                    <span class="text-[11px] uppercase font-semibold text-mb-amber-soft">Webhook URL</span>
                    <code class="block bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-xs text-mb-text break-all mt-1">{{ hrms_secret.webhook_url }}</code>
                </div>
                <div>
                    <span class="text-[11px] uppercase font-semibold text-mb-amber-soft">Secret</span>
                    <code class="block bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-xs text-mb-emerald break-all mt-1">{{ hrms_secret.secret }}</code>
                </div>
            </div>

            <!-- Tabs, as the segmented control the employee portal uses -->
            <div class="inline-flex gap-1 p-1 rounded-xl bg-mb-inset border border-mb-line">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    @click="activeTab = tab.key"
                    :aria-pressed="activeTab === tab.key"
                    :class="[
                        'h-9 px-4 rounded-lg text-xs font-semibold cursor-pointer transition',
                        activeTab === tab.key
                            ? 'bg-mb-card border border-mb-field text-mb-text-strong'
                            : 'text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong',
                    ]"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Companies -->
            <section v-if="activeTab === 'companies'" class="flex flex-wrap items-start gap-5">
                <!-- Add Company Form -->
                <div class="grow basis-80 min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                    <h3 class="text-lg font-bold text-mb-text-strong mb-4">Add New Company</h3>
                    <form @submit.prevent="submitCompany" class="space-y-3">
                        <div>
                            <input v-model="companyForm.name" placeholder="Company Name" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.name" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.address" placeholder="Address" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.address" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.contact_phone" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-mb-line">
                            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mb-2">Company Admin Account</p>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_name" placeholder="Admin Name" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_name" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_email" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="companyForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_password" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ companyForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="companyForm.processing" class="w-full bg-mb-fill-emerald hover:opacity-90 disabled:opacity-50 text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 font-bold py-2 rounded-xl text-sm cursor-pointer transition">
                            Create Company
                        </button>
                    </form>
                </div>

                <!-- Companies List -->
                <div class="grow basis-[34rem] min-w-0 space-y-4">
                    <!--
                        Three stacked rows, not a two-column split. The card
                        used to be justify-between with items-center, so the
                        action buttons floated against the vertical middle of a
                        tall left column, and the admin list was one long inline
                        row that wrapped a name onto two lines and broke a
                        button in half.
                    -->
                    <div v-for="c in companies" :key="c.id" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h4 class="text-base font-bold tracking-tight text-mb-text-strong">{{ c.name }}</h4>
                                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                    {{ c.address || 'No address' }} · {{ c.contact_phone || 'No phone' }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button
                                    @click="rotateHrmsSecret(c.id, c.name, c.hrms?.has_secret)"
                                    class="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-mb-card border border-mb-field text-xs font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                                    :title="c.hrms?.secret_rotated_at ? `Last rotated ${c.hrms.secret_rotated_at}` : 'No secret yet'"
                                >
                                    <svg v-if="c.hrms?.has_secret" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />
                                    </svg>
                                    <svg v-else class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 1 1-3.3 6.3L4 21H2v-3l8.7-8.7A4 4 0 0 1 15 7Z" />
                                    </svg>
                                    {{ c.hrms?.has_secret ? 'Rotate HRMS secret' : 'Generate HRMS secret' }}
                                </button>
                                <button
                                    @click="beginArchive(c)"
                                    class="h-8 px-3 rounded-lg bg-mb-card border border-mb-tint-rose/50 text-xs font-semibold text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10 cursor-pointer transition whitespace-nowrap"
                                >
                                    Archive
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
                            <div class="flex items-center gap-2">
                                <span class="text-mb-text-muted dark:text-mb-text-soft">Tiffin service</span>
                                <template v-if="c.assignments?.find(a => a.is_active)">
                                    <span class="inline-flex items-center gap-1.5 font-semibold text-mb-emerald">
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                                        {{ c.assignments.find(a => a.is_active).tiffin_service?.name }}
                                    </span>
                                    <button
                                        @click="unpairCompany(c.id, c.name)"
                                        class="h-7 px-2.5 rounded-lg bg-mb-card border border-mb-field text-[11px] font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition"
                                    >
                                        Unpair
                                    </button>
                                </template>
                                <span v-else class="inline-flex items-center gap-1.5 font-semibold text-mb-amber">
                                    <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                    Not paired
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="text-mb-text-muted dark:text-mb-text-soft">HRMS webhook</span>
                                <span v-if="c.hrms?.has_secret" class="inline-flex items-center gap-1.5 font-semibold text-mb-emerald">
                                    <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                                    Connected
                                </span>
                                <span v-else class="inline-flex items-center gap-1.5 font-semibold text-mb-text-muted dark:text-mb-text-soft">
                                    <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-text-faint" aria-hidden="true"></span>
                                    No secret
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-mb-line">
                            <p class="text-[11px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Admin accounts</p>

                            <ul class="mt-1 divide-y divide-mb-line">
                                <li v-for="admin in c.admins" :key="admin.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                                    <div class="grow basis-56 min-w-0">
                                        <p class="text-xs font-semibold text-mb-text-strong truncate">{{ admin.name }}</p>
                                        <p class="text-[11px] font-mono text-mb-text-muted dark:text-mb-text-soft truncate">{{ admin.email }}</p>
                                    </div>

                                    <span
                                        v-if="admin.must_change_password"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-amber whitespace-nowrap"
                                        title="Has not changed their temporary password yet"
                                    >
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                        Pending first sign-in
                                    </span>

                                    <!-- A deactivated account keeps everything it
                                         created; it simply cannot sign in. -->
                                    <span
                                        v-if="!admin.is_active"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-rose dark:text-mb-rose-soft whitespace-nowrap"
                                        :title="`Deactivated ${admin.deactivated_at ?? ''}`"
                                    >
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-rose" aria-hidden="true"></span>
                                        Deactivated
                                    </span>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            @click="resetAdminPassword(admin)"
                                            class="h-7 px-2.5 rounded-lg bg-mb-card border border-mb-field text-[11px] font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                                        >
                                            Reset password
                                        </button>
                                        <button
                                            v-if="admin.id !== user?.id"
                                            @click="setActive(admin)"
                                            :class="[
                                                'h-7 px-2.5 rounded-lg bg-mb-card border text-[11px] font-semibold cursor-pointer transition whitespace-nowrap',
                                                admin.is_active
                                                    ? 'border-mb-tint-rose/50 text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10'
                                                    : 'border-mb-tint-emerald/50 text-mb-emerald hover:bg-mb-tint-emerald/10'
                                            ]"
                                        >
                                            {{ admin.is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                        <!-- Locking yourself out of the only account
                                             that can unlock accounts is a one-way door. -->
                                        <span v-else class="px-1 text-[11px] text-mb-text-muted dark:text-mb-text-soft">you</span>
                                    </div>
                                </li>
                            </ul>

                            <p v-if="!c.admins || !c.admins.length" class="text-xs italic text-mb-text-muted dark:text-mb-text-soft mt-2">
                                No company admin account.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Archiving asks for the name, because nothing else about this
                 action is reversible by a click. -->
            <div v-if="archiving" class="bg-mb-tint-rose/10 border border-mb-tint-rose/40 rounded-2xl p-5 space-y-3">
                <div>
                    <h4 class="font-bold text-mb-rose-soft">Archive {{ archiving.name }}?</h4>
                    <p class="text-xs text-mb-rose-soft mt-1">
                        Its employees, skips and counts are all kept — nothing is deleted. Everyone at this company
                        stops being able to sign in, and you can restore it below.
                    </p>
                </div>
                <div>
                    <label for="dashboard-type-to-confirm" class="block text-[11px] uppercase font-semibold text-mb-rose-soft mb-1">
                        Type <span class="font-mono text-mb-rose-soft">{{ archiving.name }}</span> to confirm
                    </label>
                    <input id="dashboard-type-to-confirm"
                        v-model="archiveForm.confirm_name"
                        class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                        :placeholder="archiving.name"
                    />
                    <p v-if="archiveForm.errors.confirm_name" class="text-xs text-mb-rose-soft mt-1">{{ archiveForm.errors.confirm_name }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        @click="archiveCompany"
                        :disabled="archiveForm.processing || archiveForm.confirm_name !== archiving.name"
                        class="px-4 py-2 bg-mb-fill-rose hover:opacity-90 disabled:opacity-40 text-mb-on-fill-rose border border-transparent dark:border-mb-tint-rose/50 font-bold rounded-xl text-sm cursor-pointer transition"
                    >
                        Archive company
                    </button>
                    <button @click="archiving = null" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-field cursor-pointer">
                        Cancel
                    </button>
                </div>
            </div>

            <div v-if="archivedCompanies.length" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-5 space-y-3">
                <h3 class="text-sm font-bold text-mb-text-soft">Archived companies</h3>
                <div v-for="c in archivedCompanies" :key="c.id" class="flex items-center justify-between gap-3 text-xs bg-mb-inset border border-mb-line rounded-lg px-3 py-2">
                    <div>
                        <span class="font-bold text-mb-text">{{ c.name }}</span>
                        <span class="font-mono text-mb-text-faint dark:text-mb-text-soft ml-2">{{ c.code }}</span>
                        <span class="text-mb-text-faint dark:text-mb-text-soft ml-2">archived {{ c.deleted_at }}<span v-if="c.deleted_by"> by {{ c.deleted_by }}</span></span>
                    </div>
                    <button @click="restoreCompany(c)" class="h-7 px-2.5 shrink-0 rounded-lg bg-mb-card border border-mb-tint-emerald/50 text-[11px] font-semibold text-mb-emerald hover:bg-mb-tint-emerald/10 cursor-pointer transition whitespace-nowrap">
                        Restore
                    </button>
                </div>
            </div>

            <!-- Tiffin Services -->
            <section v-if="activeTab === 'tiffins'" class="flex flex-wrap items-start gap-5">
                <!-- Add Tiffin Form -->
                <div class="grow basis-80 min-w-0 bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                    <h3 class="text-lg font-bold text-mb-text-strong mb-4">Add Tiffin Service</h3>
                    <form @submit.prevent="submitTiffin" class="space-y-3">
                        <div>
                            <input v-model="tiffinForm.name" placeholder="Tiffin Service Name" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.name" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.address" placeholder="Kitchen Address" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.address" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.contact_phone" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-mb-line">
                            <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mb-2">Tiffin Admin Account</p>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_name" placeholder="Admin Name" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_name" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_email" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="tiffinForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_password" class="text-mb-rose dark:text-mb-rose-soft text-xs mt-1">{{ tiffinForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="tiffinForm.processing" class="w-full bg-mb-fill-emerald hover:opacity-90 disabled:opacity-50 text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 font-bold py-2 rounded-xl text-sm cursor-pointer transition">
                            Create Tiffin Service
                        </button>
                    </form>
                </div>

                <!-- Tiffin List -->
                <div class="grow basis-[34rem] min-w-0 space-y-4">
                    <div v-for="t in tiffinServices" :key="t.id" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h4 class="text-base font-bold tracking-tight text-mb-text-strong">{{ t.name }}</h4>
                                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                                    {{ t.address || 'No address' }} · {{ t.contact_phone || 'No phone' }}
                                </p>
                            </div>
                            <button
                                @click="beginArchiveTiffin(t)"
                                class="h-8 px-3 shrink-0 rounded-lg bg-mb-card border border-mb-tint-rose/50 text-xs font-semibold text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10 cursor-pointer transition whitespace-nowrap"
                            >
                                Archive
                            </button>
                        </div>

                        <div class="mt-4 pt-3 border-t border-mb-line">
                            <p class="text-[11px] uppercase font-semibold tracking-wider text-mb-text-muted dark:text-mb-text-soft">Admin accounts</p>

                            <ul class="mt-1 divide-y divide-mb-line">
                                <li v-for="admin in t.admins" :key="admin.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                                    <div class="grow basis-56 min-w-0">
                                        <p class="text-xs font-semibold text-mb-text-strong truncate">{{ admin.name }}</p>
                                        <p class="text-[11px] font-mono text-mb-text-muted dark:text-mb-text-soft truncate">{{ admin.email }}</p>
                                    </div>

                                    <span
                                        v-if="admin.must_change_password"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-amber whitespace-nowrap"
                                        title="Has not changed their temporary password yet"
                                    >
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                                        Pending first sign-in
                                    </span>

                                    <!-- A deactivated account keeps everything it
                                         created; it simply cannot sign in. -->
                                    <span
                                        v-if="!admin.is_active"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-mb-rose dark:text-mb-rose-soft whitespace-nowrap"
                                        :title="`Deactivated ${admin.deactivated_at ?? ''}`"
                                    >
                                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-rose" aria-hidden="true"></span>
                                        Deactivated
                                    </span>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            @click="resetAdminPassword(admin)"
                                            class="h-7 px-2.5 rounded-lg bg-mb-card border border-mb-field text-[11px] font-semibold text-mb-text-soft hover:bg-mb-raised cursor-pointer transition whitespace-nowrap"
                                        >
                                            Reset password
                                        </button>
                                        <button
                                            v-if="admin.id !== user?.id"
                                            @click="setActive(admin)"
                                            :class="[
                                                'h-7 px-2.5 rounded-lg bg-mb-card border text-[11px] font-semibold cursor-pointer transition whitespace-nowrap',
                                                admin.is_active
                                                    ? 'border-mb-tint-rose/50 text-mb-rose dark:text-mb-rose-soft hover:bg-mb-tint-rose/10'
                                                    : 'border-mb-tint-emerald/50 text-mb-emerald hover:bg-mb-tint-emerald/10'
                                            ]"
                                        >
                                            {{ admin.is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                        <!-- Locking yourself out of the only account
                                             that can unlock accounts is a one-way door. -->
                                        <span v-else class="px-1 text-[11px] text-mb-text-muted dark:text-mb-text-soft">you</span>
                                    </div>
                                </li>
                            </ul>

                            <p v-if="!t.admins || !t.admins.length" class="text-xs italic text-mb-text-muted dark:text-mb-text-soft mt-2">
                                No tiffin admin account.
                            </p>
                        </div>
                    </div>

                    <!-- Archiving asks for the name, because a mis-click here
                         silently stops a kitchen being told what to cook. -->
                    <div v-if="archivingTiffin" class="bg-mb-tint-rose/10 border border-mb-tint-rose/40 rounded-2xl p-5 space-y-3">
                        <div>
                            <h4 class="font-bold text-mb-rose-soft">Archive {{ archivingTiffin.name }}?</h4>
                            <p class="text-xs text-mb-rose-soft mt-1">
                                Its menus, overrides and past counts are all kept — nothing is deleted. Its logins
                                stop working, and every company it serves is unpaired, so they will stop getting a
                                daily count until you pair them with another service. You can restore it below.
                            </p>
                        </div>
                        <div>
                            <label for="dashboard-type-to-confirm-2" class="block text-[11px] uppercase font-semibold text-mb-rose-soft mb-1">
                                Type <span class="font-mono text-mb-rose-soft">{{ archivingTiffin.name }}</span> to confirm
                            </label>
                            <input id="dashboard-type-to-confirm-2"
                                v-model="archiveTiffinForm.confirm_name"
                                class="w-full bg-mb-inset border border-mb-field rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                                :placeholder="archivingTiffin.name"
                            />
                            <p v-if="archiveTiffinForm.errors.confirm_name" class="text-xs text-mb-rose-soft mt-1">{{ archiveTiffinForm.errors.confirm_name }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="archiveTiffin"
                                :disabled="archiveTiffinForm.processing || archiveTiffinForm.confirm_name !== archivingTiffin.name"
                                class="px-4 py-2 bg-mb-fill-rose hover:opacity-90 disabled:opacity-40 text-mb-on-fill-rose border border-transparent dark:border-mb-tint-rose/50 font-bold rounded-xl text-sm cursor-pointer transition"
                            >
                                Archive service
                            </button>
                            <button @click="archivingTiffin = null" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-field cursor-pointer">
                                Cancel
                            </button>
                        </div>
                    </div>

                    <div v-if="archivedTiffinServices.length" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-5 space-y-3">
                        <h3 class="text-sm font-bold text-mb-text-soft">Archived tiffin services</h3>
                        <div v-for="t in archivedTiffinServices" :key="t.id" class="flex items-center justify-between gap-3 text-xs bg-mb-inset border border-mb-line rounded-lg px-3 py-2">
                            <div>
                                <span class="font-bold text-mb-text">{{ t.name }}</span>
                                <span class="text-mb-text-faint dark:text-mb-text-soft ml-2">archived {{ t.deleted_at }}<span v-if="t.deleted_by"> by {{ t.deleted_by }}</span></span>
                            </div>
                            <button @click="restoreTiffin(t)" class="h-7 px-2.5 shrink-0 rounded-lg bg-mb-card border border-mb-tint-emerald/50 text-[11px] font-semibold text-mb-emerald hover:bg-mb-tint-emerald/10 cursor-pointer transition whitespace-nowrap">
                                Restore
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assignments -->
            <section v-if="activeTab === 'assignments'" class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <h3 class="text-lg font-bold text-mb-text-strong mb-4">Pairing History</h3>
                <div class="space-y-3">
                    <div v-for="a in assignments" :key="a.id" class="flex justify-between items-center p-3 bg-mb-inset rounded-lg text-sm border border-mb-line">
                        <div>
                            <span class="font-bold text-mb-text-strong">{{ a.company?.name }}</span>
                            <span class="text-mb-text-faint dark:text-mb-text-soft mx-2">↔</span>
                            <span class="font-bold text-mb-emerald">{{ a.tiffin_service?.name }}</span>
                        </div>
                        <div>
                            <span v-if="a.is_active" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-emerald whitespace-nowrap">
                                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                                Active
                            </span>
                            <span v-else class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft whitespace-nowrap">
                                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-text-faint" aria-hidden="true"></span>
                                Inactive
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>
