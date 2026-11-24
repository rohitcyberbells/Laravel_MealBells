<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    // Shapes differ slightly: provisioning sends the address and company code,
    // a password reset sends only the name, code and password.
    credentials: { type: Array, default: () => [] },
    /**
     * Employees the run could not give a login to, each with a reason.
     * Sign-in is by email, so an employee without one gets no account -
     * and saying nothing would leave a count that does not match what the
     * admin selected.
     */
    skipped: { type: Array, default: () => [] },
});

const emit = defineEmits(['dismiss']);

const copied = ref(false);

const rows = computed(() => props.credentials.map((c) => ({
    name: c.name,
    code: c.employee_code,
    loginId: c.email,
    password: c.temporary_password,
    mailed: c.mail_sent === true,
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
    <div v-if="rows.length || skipped.length" class="rounded-2xl bg-mb-tint-amber/10 border border-mb-tint-amber/40 p-5 space-y-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-sm font-bold text-mb-amber-soft">
                    {{ rows.length }} login{{ rows.length === 1 ? '' : 's' }} generated
                </h3>
                <p class="text-xs text-mb-amber-soft mt-1">
                    These passwords are only shown now — copy or download them before you leave this page.
                    They are stored hashed, so they cannot be shown again; resetting is the only way to get a new one.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="copyAll"
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-mb-amber-soft bg-mb-tint-amber/15 hover:bg-mb-tint-amber/25 border border-mb-tint-amber/40 cursor-pointer"
                >
                    {{ copied ? 'Copied' : 'Copy all' }}
                </button>
                <button
                    @click="downloadCsv"
                    class="px-3 py-1.5 rounded-lg text-xs font-bold text-mb-text bg-mb-raised hover:bg-mb-chip border border-mb-field cursor-pointer"
                >
                    Download CSV
                </button>
                <button
                    @click="emit('dismiss')"
                    class="text-mb-amber-soft hover:text-mb-amber-soft text-lg leading-none cursor-pointer"
                    aria-label="Dismiss"
                >
                    ×
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-mb-tint-amber/20">
            <table class="w-full text-left text-xs">
                <thead class="bg-mb-inset/60 text-mb-amber-soft uppercase text-[10px] font-semibold">
                    <tr>
                        <th class="py-2 px-3">Name</th>
                        <th class="py-2 px-3">Code</th>
                        <th class="py-2 px-3">Login ID</th>
                        <th class="py-2 px-3">Temporary password</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-mb-tint-amber/10">
                    <tr v-for="row in rows" :key="row.code">
                        <td class="py-2 px-3 text-mb-text font-semibold">{{ row.name }}</td>
                        <td class="py-2 px-3 font-mono text-mb-text-muted dark:text-mb-text-soft">{{ row.code }}</td>
                        <td class="py-2 px-3 font-mono text-mb-text-soft">
                            {{ row.loginId }}
                            <span v-if="row.mailed" class="ml-1 text-mb-emerald" title="Emailed to the employee">mailed</span>
                        </td>
                        <td class="py-2 px-3 font-mono text-mb-emerald-soft select-all">{{ row.password }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
            Named, not counted. "2 skipped" leaves the admin to work out who,
            and the fix is a one-field edit they can make straight away.
        -->
        <div v-if="skipped.length" class="rounded-xl border border-mb-line bg-mb-card/60 p-4">
            <p class="text-xs font-semibold text-mb-text-strong">
                {{ skipped.length }} employee{{ skipped.length === 1 ? '' : 's' }} could not be given a login
            </p>
            <ul class="mt-2.5 space-y-1.5">
                <li
                    v-for="person in skipped"
                    :key="person.employee_code"
                    class="flex flex-wrap items-baseline gap-x-2 text-[11px]"
                >
                    <span class="font-mono text-mb-text-soft">{{ person.employee_code }}</span>
                    <span class="font-medium text-mb-text">{{ person.name }}</span>
                    <span class="text-mb-text-muted dark:text-mb-text-soft">{{ person.reason }}</span>
                </li>
            </ul>
            <p class="mt-3 text-[11px] text-mb-text-muted dark:text-mb-text-soft">
                Sign-in is by email address. Add one to the employee and generate the
                login again.
            </p>
        </div>
    </div>
</template>
