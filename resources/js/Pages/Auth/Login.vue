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
    <div class="min-h-screen bg-slate-950 text-white flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-emerald-400 text-center mb-1">MealBells</h1>
            <p class="text-slate-400 text-sm text-center mb-6">Sign in to your dashboard</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">
                        Email or employee code
                    </label>
                    <input
                        v-model="form.identifier"
                        type="text"
                        autocomplete="username"
                        placeholder="you@company.com or ACME001"
                        required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                    />
                    <p v-if="form.errors.identifier" class="text-red-400 text-xs mt-1">{{ form.errors.identifier }}</p>
                </div>

                <!-- Only an employee code needs a company, so the field appears
                     once the input stops looking like an address. -->
                <div v-if="form.identifier && !looksLikeEmail">
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Company code</label>
                    <input
                        v-model="form.company_code"
                        type="text"
                        placeholder="ACME01"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white uppercase focus:outline-none focus:border-emerald-500"
                    />
                    <p class="text-[11px] text-slate-500 mt-1">Your HR team can tell you this.</p>
                    <p v-if="form.errors.company_code" class="text-red-400 text-xs mt-1">{{ form.errors.company_code }}</p>
                    <p v-if="form.errors.login_code" class="text-red-400 text-xs mt-1">{{ form.errors.login_code }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                    />
                    <p v-if="form.errors.password" class="text-red-400 text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                        <input v-model="form.remember" type="checkbox" class="accent-emerald-500 cursor-pointer" />
                        Keep me signed in
                    </label>
                    <Link href="/forgot-password" class="text-xs text-slate-400 hover:text-emerald-400">
                        Forgot password?
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 font-semibold text-slate-950 py-2 rounded-lg transition cursor-pointer"
                >
                    Sign In
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-slate-800">
                <p class="text-xs font-semibold text-slate-400 uppercase mb-2 text-center">Quick demo login</p>
                <div class="grid grid-cols-2 gap-2">
                    <button @click="fillDemo('root@mealbells.test')" class="bg-slate-800 hover:bg-slate-700 text-xs text-emerald-300 py-1.5 px-2 rounded border border-slate-700 cursor-pointer">
                        Super Admin
                    </button>
                    <button @click="fillDemo('vendor@mealbells.test')" class="bg-slate-800 hover:bg-slate-700 text-xs text-amber-300 py-1.5 px-2 rounded border border-slate-700 cursor-pointer">
                        Tiffin Admin
                    </button>
                    <button @click="fillDemo('hr@acme.test')" class="bg-slate-800 hover:bg-slate-700 text-xs text-cyan-300 py-1.5 px-2 rounded border border-slate-700 cursor-pointer">
                        Company Admin
                    </button>
                    <button @click="fillDemo('acme001@demo.test')" class="bg-slate-800 hover:bg-slate-700 text-xs text-indigo-300 py-1.5 px-2 rounded border border-slate-700 cursor-pointer">
                        Employee
                    </button>
                </div>
                <p class="text-[11px] text-slate-500 mt-2 text-center">
                    Employees can also use <span class="font-mono">ACME01</span> + <span class="font-mono">ACME001</span>.
                </p>
            </div>
        </div>
    </div>
</template>
