<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    // Shapes differ slightly: provisioning sends the address and company code,
    // a password reset sends only the name, code and password.
    credentials: { type: Array, default: () => [] },
});

const emit = defineEmits(['dismiss']);

const copied = ref(false);

const loginIdFor = (c) => (c.can_login_with_email && c.email)
    ? c.email
    : `${c.company_code ?? ''}${c.company_code ? ' / ' : ''}${c.employee_code}`;

const rows = computed(() => props.credentials.map((c) => ({
    name: c.name,
    code: c.employee_code,
    loginId: loginIdFor(c),
    password: c.temporary_password,
    mailed: c.mail_sent === true,
    noEmail: c.can_login_with_email === false,
})));

const asText = computed(() => [
    ['Name', 'Employee code', 'Login ID', 'Temporary password'].join('\t'),
    ...rows.value.map((r) => [r.name, r.code, r.loginId, r.password].join('\t')),
].join('\n'));

const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

const copyAll = async () => {
    try {
        await navigator.clipboard.writeText(asText.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch (error) {
        copied.value = false;
    }
};

const downloadCsv = () => {
    const csv = [
        ['Name', 'Employee code', 'Login ID', 'Temporary password'].map(csvCell).join(','),
        ...rows.value.map((r) => [r.name, r.code, r.loginId, r.password].map(csvCell).join(',')),
    ].join('\n');

    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `employee-logins-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
};
</script>

<template>
    <div v-if="rows.length" class="rounded-2xl bg-amber-500/10 border border-amber-500/40 p-5 space-y-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-sm font-bold text-amber-300">
                    🔑 {{ rows.length }} login{{ rows.length === 1 ? '' : 's' }} generated
                </h3>
                <p class="text-xs text-amber-200/80 mt-1">
                    These passwords are only shown now — copy or download them before you leave this page.
                    They are stored hashed, so they cannot be shown again; resetting is the only way to get a new one.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="copyAll"
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-amber-200 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 cursor-pointer"
                >
                    {{ copied ? '✅ Copied' : 'Copy all' }}
                </button>
                <button
                    @click="downloadCsv"
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 cursor-pointer"
                >
                    Download CSV
                </button>
                <button
                    @click="emit('dismiss')"
                    class="text-amber-300/70 hover:text-amber-200 text-lg leading-none cursor-pointer"
                    aria-label="Dismiss"
                >
                    ×
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-amber-500/20">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-950/60 text-amber-200/70 uppercase text-[10px] font-semibold">
                    <tr>
                        <th class="py-2 px-3">Name</th>
                        <th class="py-2 px-3">Code</th>
                        <th class="py-2 px-3">Login ID</th>
                        <th class="py-2 px-3">Temporary password</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-amber-500/10">
                    <tr v-for="row in rows" :key="row.code">
                        <td class="py-2 px-3 text-slate-200 font-semibold">{{ row.name }}</td>
                        <td class="py-2 px-3 font-mono text-slate-400">{{ row.code }}</td>
                        <td class="py-2 px-3 font-mono text-slate-300">
                            {{ row.loginId }}
                            <span v-if="row.mailed" class="ml-1 text-emerald-400" title="Emailed to the employee">✉</span>
                            <span v-else-if="row.noEmail" class="ml-1 text-amber-400/80 text-[10px]">no email — code only</span>
                        </td>
                        <td class="py-2 px-3 font-mono text-emerald-300 select-all">{{ row.password }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
