<script setup>
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

</script>

<template>
    <AppLayout>
    <div class="p-6">
        <div class="max-w-7xl mx-auto pb-6 border-b border-mb-line mb-8">
            <h1 class="text-3xl font-bold text-mb-emerald">Super Admin Dashboard</h1>
        </div>

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- Pairing Tool Card -->
            <section class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl">
                <h2 class="text-xl font-bold text-mb-emerald-soft mb-4">Pair Company ↔ Tiffin Service</h2>
                <form @submit.prevent="submitAssign" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs uppercase font-semibold text-mb-text-muted mb-1">Select Company</label>
                        <select v-model="assignForm.company_id" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong">
                            <option value="" disabled>Choose Company</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs uppercase font-semibold text-mb-text-muted mb-1">Select Tiffin Service</label>
                        <select v-model="assignForm.tiffin_service_id" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong">
                            <option value="" disabled>Choose Tiffin Service</option>
                            <option v-for="t in tiffinServices" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="assignForm.processing" class="bg-mb-tint-emerald hover:bg-mb-tint-emerald font-bold text-mb-on-accent py-2 px-4 rounded-lg transition">
                        Confirm Pairing
                    </button>
                </form>
            </section>

            <!-- Shown exactly once, straight after a reset: the plaintext is
                 never stored, so this render is the only copy. -->
            <div v-if="temporary_password" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-xl p-5 space-y-3">
                <div>
                    <h4 class="font-bold text-mb-amber-soft">
                        🔑 Temporary password<span v-if="reset_for"> for {{ reset_for.name }}</span>
                    </h4>
                    <p class="text-xs text-mb-amber-soft/80">
                        <span v-if="reset_for" class="font-mono">{{ reset_for.email }}</span>
                        <span v-if="reset_for"> — </span>share it now; it is not shown again. They must change it on first sign-in.
                    </p>
                </div>
                <code class="block bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-xs text-mb-emerald-soft break-all">{{ temporary_password }}</code>
            </div>

            <!-- Tabs -->
            <div class="flex space-x-4 border-b border-mb-line">
            <!-- Shown exactly once: the plaintext is never stored and cannot be recovered -->
            <div v-if="hrms_secret" class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-xl p-5 space-y-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h4 class="font-bold text-mb-amber-soft">🔑 HRMS webhook secret for {{ hrms_secret.company_name }}</h4>
                        <p class="text-xs text-mb-amber-soft/80">
                            Copy it now. It is encrypted at rest and will never be shown again - rotating is the only way to get a new one.
                        </p>
                    </div>
                </div>
                <div>
                    <label class="text-[11px] uppercase font-semibold text-mb-amber-soft/70">Webhook URL</label>
                    <code class="block bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-xs text-mb-text break-all">{{ hrms_secret.webhook_url }}</code>
                </div>
                <div>
                    <label class="text-[11px] uppercase font-semibold text-mb-amber-soft/70">Secret</label>
                    <code class="block bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-xs text-mb-emerald-soft break-all">{{ hrms_secret.secret }}</code>
                </div>
            </div>

                <button @click="activeTab = 'companies'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'companies' ? 'border-mb-emerald text-mb-emerald' : 'border-transparent text-mb-text-muted hover:text-mb-text']">
                    Companies ({{ companies.length }})
                </button>
                <button @click="activeTab = 'tiffins'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'tiffins' ? 'border-mb-emerald text-mb-emerald' : 'border-transparent text-mb-text-muted hover:text-mb-text']">
                    Tiffin Services ({{ tiffinServices.length }})
                </button>
                <button @click="activeTab = 'assignments'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'assignments' ? 'border-mb-emerald text-mb-emerald' : 'border-transparent text-mb-text-muted hover:text-mb-text']">
                    Pairing History
                </button>
            </div>

            <!-- Companies -->
            <section v-if="activeTab === 'companies'" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add Company Form -->
                <div class="bg-mb-card border border-mb-line rounded-xl p-6">
                    <h3 class="text-lg font-bold text-mb-text-strong mb-4">Add New Company</h3>
                    <form @submit.prevent="submitCompany" class="space-y-3">
                        <div>
                            <input v-model="companyForm.name" placeholder="Company Name" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.name" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.address" placeholder="Address" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.address" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="companyForm.errors.contact_phone" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-mb-line">
                            <p class="text-xs text-mb-text-muted mb-2">Company Admin Account</p>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_name" placeholder="Admin Name" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_name" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_email" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="companyForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="companyForm.errors.admin_password" class="text-mb-rose text-xs mt-1">{{ companyForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="companyForm.processing" class="w-full bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-50 text-mb-on-accent font-bold py-2 rounded-lg text-sm">
                            Create Company
                        </button>
                    </form>
                </div>

                <!-- Companies List -->
                <div class="lg:col-span-2 space-y-4">
                    <div v-for="c in companies" :key="c.id" class="bg-mb-card border border-mb-line rounded-xl p-5 flex justify-between items-center">
                        <div>
                            <h4 class="text-lg font-bold text-mb-text-strong">{{ c.name }}</h4>
                            <p class="text-xs text-mb-text-muted">{{ c.address || 'No address' }} | {{ c.contact_phone || 'No phone' }}</p>
                            <div class="mt-2 text-xs flex items-center gap-2">
                                <span class="text-mb-text-muted">Assigned Tiffin: </span>
                                <template v-if="c.assignments?.find(a => a.is_active)">
                                    <span class="text-mb-emerald font-semibold">
                                        {{ c.assignments.find(a => a.is_active).tiffin_service?.name }}
                                    </span>
                                    <button @click="unpairCompany(c.id, c.name)" class="bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 text-mb-amber border border-mb-tint-amber/30 text-[10px] px-2 py-0.5 rounded transition font-medium">
                                        Unpair
                                    </button>
                                </template>
                                <span v-else class="text-mb-amber italic">Not Assigned</span>
                            </div>
                            <div class="mt-1 text-xs flex items-center gap-2">
                                <span class="text-mb-text-muted">HRMS webhook: </span>
                                <span v-if="c.hrms?.has_secret" class="text-mb-emerald font-semibold">Connected</span>
                                <span v-else class="text-mb-text-faint italic">No secret</span>
                            </div>
                            <div class="mt-2 space-y-1">
                                <p class="text-[11px] uppercase font-semibold text-mb-text-faint">Admin accounts</p>
                                <div v-for="admin in c.admins" :key="admin.id" class="flex items-center gap-2 text-xs">
                                    <span class="text-mb-text-soft">{{ admin.name }}</span>
                                    <span class="text-mb-text-faint font-mono">{{ admin.email }}</span>
                                    <span v-if="admin.must_change_password" class="text-mb-amber/80 text-[10px]" title="Has not changed their temporary password yet">
                                        pending first sign-in
                                    </span>
                                    <!-- A deactivated account keeps everything it
                                         created; it simply cannot sign in. -->
                                    <span v-if="!admin.is_active" class="text-mb-rose text-[10px] font-bold uppercase" :title="`Deactivated ${admin.deactivated_at ?? ''}`">
                                        deactivated
                                    </span>
                                    <button
                                        @click="resetAdminPassword(admin)"
                                        class="bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 text-mb-amber border border-mb-tint-amber/30 text-[10px] px-2 py-0.5 rounded transition font-medium"
                                    >
                                        Reset password
                                    </button>
                                    <button
                                        v-if="admin.id !== user?.id"
                                        @click="setActive(admin)"
                                        :class="[
                                            'text-[10px] px-2 py-0.5 rounded transition font-medium border',
                                            admin.is_active
                                                ? 'bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border-mb-tint-rose/30'
                                                : 'bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 text-mb-emerald border-mb-tint-emerald/30'
                                        ]"
                                    >
                                        {{ admin.is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                    <!-- Locking yourself out of the only account
                                         that can unlock accounts is a one-way door. -->
                                    <span v-else class="text-mb-text-ghost text-[10px]">you</span>
                                </div>
                                <p v-if="!c.admins || !c.admins.length" class="text-xs text-mb-text-faint italic">
                                    No company admin account.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                @click="rotateHrmsSecret(c.id, c.name, c.hrms?.has_secret)"
                                class="bg-mb-tint-indigo/10 hover:bg-mb-tint-indigo/20 text-mb-indigo-soft border border-mb-tint-indigo/30 text-xs px-3 py-1.5 rounded-lg transition font-medium"
                                :title="c.hrms?.secret_rotated_at ? `Last rotated ${c.hrms.secret_rotated_at}` : 'No secret yet'"
                            >
                                {{ c.hrms?.has_secret ? '🔄 Rotate HRMS secret' : '🔑 Generate HRMS secret' }}
                            </button>
                            <button @click="beginArchive(c)" class="bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border border-mb-tint-rose/30 text-xs px-3 py-1.5 rounded-lg transition font-medium">
                                Archive
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Archiving asks for the name, because nothing else about this
                 action is reversible by a click. -->
            <div v-if="archiving" class="bg-mb-tint-rose/10 border border-mb-tint-rose/40 rounded-xl p-5 space-y-3">
                <div>
                    <h4 class="font-bold text-mb-rose-soft">Archive {{ archiving.name }}?</h4>
                    <p class="text-xs text-mb-rose-soft/80 mt-1">
                        Its employees, skips and counts are all kept — nothing is deleted. Everyone at this company
                        stops being able to sign in, and you can restore it below.
                    </p>
                </div>
                <div>
                    <label class="block text-[11px] uppercase font-semibold text-mb-rose-soft/70 mb-1">
                        Type <span class="font-mono text-mb-rose-soft">{{ archiving.name }}</span> to confirm
                    </label>
                    <input
                        v-model="archiveForm.confirm_name"
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                        :placeholder="archiving.name"
                    />
                    <p v-if="archiveForm.errors.confirm_name" class="text-xs text-mb-rose-soft mt-1">{{ archiveForm.errors.confirm_name }}</p>
                </div>
                <div class="flex gap-2">
                    <button
                        @click="archiveCompany"
                        :disabled="archiveForm.processing || archiveForm.confirm_name !== archiving.name"
                        class="px-4 py-2 bg-mb-tint-rose hover:bg-mb-tint-rose disabled:opacity-40 text-mb-text-strong font-bold rounded-lg text-sm cursor-pointer"
                    >
                        Archive company
                    </button>
                    <button @click="archiving = null" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-line-mid cursor-pointer">
                        Cancel
                    </button>
                </div>
            </div>

            <div v-if="archivedCompanies.length" class="bg-mb-card border border-mb-line rounded-xl p-5 space-y-3">
                <h3 class="text-sm font-bold text-mb-text-soft">Archived companies</h3>
                <div v-for="c in archivedCompanies" :key="c.id" class="flex items-center justify-between gap-3 text-xs bg-mb-inset border border-mb-line rounded-lg px-3 py-2">
                    <div>
                        <span class="font-bold text-mb-text">{{ c.name }}</span>
                        <span class="font-mono text-mb-text-faint ml-2">{{ c.code }}</span>
                        <span class="text-mb-text-faint ml-2">archived {{ c.deleted_at }}<span v-if="c.deleted_by"> by {{ c.deleted_by }}</span></span>
                    </div>
                    <button @click="restoreCompany(c)" class="bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/30 px-3 py-1 rounded transition font-medium">
                        Restore
                    </button>
                </div>
            </div>

            <!-- Tiffin Services -->
            <section v-if="activeTab === 'tiffins'" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add Tiffin Form -->
                <div class="bg-mb-card border border-mb-line rounded-xl p-6">
                    <h3 class="text-lg font-bold text-mb-text-strong mb-4">Add Tiffin Service</h3>
                    <form @submit.prevent="submitTiffin" class="space-y-3">
                        <div>
                            <input v-model="tiffinForm.name" placeholder="Tiffin Service Name" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.name" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.address" placeholder="Kitchen Address" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.address" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                            <p v-if="tiffinForm.errors.contact_phone" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-mb-line">
                            <p class="text-xs text-mb-text-muted mb-2">Tiffin Admin Account</p>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_name" placeholder="Admin Name" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_name" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_email" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="tiffinForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                                <p v-if="tiffinForm.errors.admin_password" class="text-mb-rose text-xs mt-1">{{ tiffinForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="tiffinForm.processing" class="w-full bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-50 text-mb-on-accent font-bold py-2 rounded-lg text-sm">
                            Create Tiffin Service
                        </button>
                    </form>
                </div>

                <!-- Tiffin List -->
                <div class="lg:col-span-2 space-y-4">
                    <div v-for="t in tiffinServices" :key="t.id" class="bg-mb-card border border-mb-line rounded-xl p-5 flex justify-between items-center">
                        <div>
                            <h4 class="text-lg font-bold text-mb-text-strong">{{ t.name }}</h4>
                            <p class="text-xs text-mb-text-muted">{{ t.address || 'No address' }} | {{ t.contact_phone || 'No phone' }}</p>
                            <div class="mt-2 space-y-1">
                                <p class="text-[11px] uppercase font-semibold text-mb-text-faint">Admin accounts</p>
                                <div v-for="admin in t.admins" :key="admin.id" class="flex items-center gap-2 text-xs">
                                    <span class="text-mb-text-soft">{{ admin.name }}</span>
                                    <span class="text-mb-text-faint font-mono">{{ admin.email }}</span>
                                    <span v-if="admin.must_change_password" class="text-mb-amber/80 text-[10px]" title="Has not changed their temporary password yet">
                                        pending first sign-in
                                    </span>
                                    <!-- A deactivated account keeps everything it
                                         created; it simply cannot sign in. -->
                                    <span v-if="!admin.is_active" class="text-mb-rose text-[10px] font-bold uppercase" :title="`Deactivated ${admin.deactivated_at ?? ''}`">
                                        deactivated
                                    </span>
                                    <button
                                        @click="resetAdminPassword(admin)"
                                        class="bg-mb-tint-amber/10 hover:bg-mb-tint-amber/20 text-mb-amber border border-mb-tint-amber/30 text-[10px] px-2 py-0.5 rounded transition font-medium"
                                    >
                                        Reset password
                                    </button>
                                    <button
                                        v-if="admin.id !== user?.id"
                                        @click="setActive(admin)"
                                        :class="[
                                            'text-[10px] px-2 py-0.5 rounded transition font-medium border',
                                            admin.is_active
                                                ? 'bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border-mb-tint-rose/30'
                                                : 'bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 text-mb-emerald border-mb-tint-emerald/30'
                                        ]"
                                    >
                                        {{ admin.is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                    <!-- Locking yourself out of the only account
                                         that can unlock accounts is a one-way door. -->
                                    <span v-else class="text-mb-text-ghost text-[10px]">you</span>
                                </div>
                                <p v-if="!t.admins || !t.admins.length" class="text-xs text-mb-text-faint italic">
                                    No tiffin admin account.
                                </p>
                            </div>
                        </div>
                        <button @click="beginArchiveTiffin(t)" class="bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 text-mb-rose border border-mb-tint-rose/30 text-xs px-3 py-1.5 rounded-lg transition font-medium">
                            Archive
                        </button>
                    </div>

                    <!-- Archiving asks for the name, because a mis-click here
                         silently stops a kitchen being told what to cook. -->
                    <div v-if="archivingTiffin" class="bg-mb-tint-rose/10 border border-mb-tint-rose/40 rounded-xl p-5 space-y-3">
                        <div>
                            <h4 class="font-bold text-mb-rose-soft">Archive {{ archivingTiffin.name }}?</h4>
                            <p class="text-xs text-mb-rose-soft/80 mt-1">
                                Its menus, overrides and past counts are all kept — nothing is deleted. Its logins
                                stop working, and every company it serves is unpaired, so they will stop getting a
                                daily count until you pair them with another service. You can restore it below.
                            </p>
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase font-semibold text-mb-rose-soft/70 mb-1">
                                Type <span class="font-mono text-mb-rose-soft">{{ archivingTiffin.name }}</span> to confirm
                            </label>
                            <input
                                v-model="archiveTiffinForm.confirm_name"
                                class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong font-mono"
                                :placeholder="archivingTiffin.name"
                            />
                            <p v-if="archiveTiffinForm.errors.confirm_name" class="text-xs text-mb-rose-soft mt-1">{{ archiveTiffinForm.errors.confirm_name }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="archiveTiffin"
                                :disabled="archiveTiffinForm.processing || archiveTiffinForm.confirm_name !== archivingTiffin.name"
                                class="px-4 py-2 bg-mb-tint-rose hover:bg-mb-tint-rose disabled:opacity-40 text-mb-text-strong font-bold rounded-lg text-sm cursor-pointer"
                            >
                                Archive service
                            </button>
                            <button @click="archivingTiffin = null" class="px-4 py-2 bg-mb-raised hover:bg-mb-chip text-mb-text rounded-lg text-sm border border-mb-line-mid cursor-pointer">
                                Cancel
                            </button>
                        </div>
                    </div>

                    <div v-if="archivedTiffinServices.length" class="bg-mb-card border border-mb-line rounded-xl p-5 space-y-3">
                        <h3 class="text-sm font-bold text-mb-text-soft">Archived tiffin services</h3>
                        <div v-for="t in archivedTiffinServices" :key="t.id" class="flex items-center justify-between gap-3 text-xs bg-mb-inset border border-mb-line rounded-lg px-3 py-2">
                            <div>
                                <span class="font-bold text-mb-text">{{ t.name }}</span>
                                <span class="text-mb-text-faint ml-2">archived {{ t.deleted_at }}<span v-if="t.deleted_by"> by {{ t.deleted_by }}</span></span>
                            </div>
                            <button @click="restoreTiffin(t)" class="bg-mb-tint-emerald/10 hover:bg-mb-tint-emerald/20 text-mb-emerald border border-mb-tint-emerald/30 px-3 py-1 rounded transition font-medium">
                                Restore
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assignments -->
            <section v-if="activeTab === 'assignments'" class="bg-mb-card border border-mb-line rounded-xl p-6">
                <h3 class="text-lg font-bold text-mb-text-strong mb-4">Pairing History</h3>
                <div class="space-y-3">
                    <div v-for="a in assignments" :key="a.id" class="flex justify-between items-center p-3 bg-mb-inset rounded-lg text-sm border border-mb-line">
                        <div>
                            <span class="font-bold text-mb-text-strong">{{ a.company?.name }}</span>
                            <span class="text-mb-text-faint mx-2">↔</span>
                            <span class="font-bold text-mb-emerald">{{ a.tiffin_service?.name }}</span>
                        </div>
                        <div>
                            <span v-if="a.is_active" class="bg-mb-tint-emerald/20 text-mb-emerald-soft text-xs px-2.5 py-1 rounded-full border border-mb-tint-emerald/30 font-semibold">Active</span>
                            <span v-else class="bg-mb-raised text-mb-text-muted text-xs px-2.5 py-1 rounded-full">Inactive</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>
