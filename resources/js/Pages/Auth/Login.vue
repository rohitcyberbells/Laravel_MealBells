<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const form = useForm({
    identifier: '',
    company_code: '',
    password: '',
    remember: false,
});

// An address signs in by email; anything else is an employee code, which needs
// the company code alongside it.
const looksLikeEmail = computed(() => form.identifier.includes('@'));

const submit = () => {
    form.transform((data) => looksLikeEmail.value
        ? { identifier: data.identifier, password: data.password, remember: data.remember }
        : data
    ).post('/login');
};

const fillDemo = (identifier, companyCode = '') => {
    form.identifier = identifier;
    form.company_code = companyCode;
    form.password = 'demo1234';
};
</script>

<template>
    <div class="mb-theme min-h-screen bg-mb-page text-mb-text-strong flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-mb-card border border-mb-line rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-mb-emerald text-center mb-1">MealBells</h1>
            <p class="text-mb-text-muted text-sm text-center mb-6">Sign in to your dashboard</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">
                        Email or employee code
                    </label>
                    <input
                        v-model="form.identifier"
                        type="text"
                        autocomplete="username"
                        placeholder="you@company.com or ACME001"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p v-if="form.errors.identifier" class="text-mb-rose text-xs mt-1">{{ form.errors.identifier }}</p>
                </div>

                <!-- Only an employee code needs a company, so the field appears
                     once the input stops looking like an address. -->
                <div v-if="form.identifier && !looksLikeEmail">
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">Company code</label>
                    <input
                        v-model="form.company_code"
                        type="text"
                        placeholder="ACME01"
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong uppercase focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p class="text-[11px] text-mb-text-faint mt-1">Your HR team can tell you this.</p>
                    <p v-if="form.errors.company_code" class="text-mb-rose text-xs mt-1">{{ form.errors.company_code }}</p>
                    <p v-if="form.errors.login_code" class="text-mb-rose text-xs mt-1">{{ form.errors.login_code }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">Password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p v-if="form.errors.password" class="text-mb-rose text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-xs text-mb-text-muted cursor-pointer">
                        <input v-model="form.remember" type="checkbox" class="accent-mb-tint-emerald cursor-pointer" />
                        Keep me signed in
                    </label>
                    <Link href="/forgot-password" class="text-xs text-mb-text-muted hover:text-mb-emerald">
                        Forgot password?
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-50 font-semibold text-mb-on-accent py-2 rounded-lg transition cursor-pointer"
                >
                    Sign In
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-mb-line">
                <p class="text-xs font-semibold text-mb-text-muted uppercase mb-2 text-center">Quick demo login</p>
                <div class="grid grid-cols-2 gap-2">
                    <button @click="fillDemo('root@mealbells.test')" class="bg-mb-raised hover:bg-mb-chip text-xs text-mb-emerald-soft py-1.5 px-2 rounded border border-mb-line-mid cursor-pointer">
                        Super Admin
                    </button>
                    <button @click="fillDemo('vendor@mealbells.test')" class="bg-mb-raised hover:bg-mb-chip text-xs text-mb-amber-soft py-1.5 px-2 rounded border border-mb-line-mid cursor-pointer">
                        Tiffin Admin
                    </button>
                    <button @click="fillDemo('hr@acme.test')" class="bg-mb-raised hover:bg-mb-chip text-xs text-mb-cyan-soft py-1.5 px-2 rounded border border-mb-line-mid cursor-pointer">
                        Company Admin
                    </button>
                    <button @click="fillDemo('acme001@demo.test')" class="bg-mb-raised hover:bg-mb-chip text-xs text-mb-indigo-soft py-1.5 px-2 rounded border border-mb-line-mid cursor-pointer">
                        Employee
                    </button>
                </div>
                <p class="text-[11px] text-mb-text-faint mt-2 text-center">
                    Employees can also use <span class="font-mono">ACME01</span> + <span class="font-mono">ACME001</span>.
                </p>
            </div>
        </div>
    </div>
</template>
