<script setup>
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextField from '../../Layouts/TextField.vue';
import { Link, useForm } from '@inertiajs/vue3';

defineProps({
    /**
     * The one-click role buttons, or an empty list.
     *
     * The server builds these from whatever database is loaded, and sends
     * none outside local. They used to be written here, which made them right
     * for exactly one database: switching between the demo file and a working
     * one left every button filling in an account that does not exist.
     *
     * @type {{ label: string, email: string, password: string }[]}
     */
    demoAccounts: { type: Array, default: () => [] },
});

const form = useForm({
    identifier: '',
    password: '',
    remember: false,
});

const submit = () => form.post('/login');

const fillDemo = (account) => {
    form.identifier = account.email;
    form.password = account.password;
};
</script>

<template>
    <AuthLayout title="Sign in" subtitle="Your credentials come from whoever set up your account — your HR team, or MealBells.">
        <form @submit.prevent="submit" class="space-y-4">
            <TextField
                id="login-email"
                v-model="form.identifier"
                label="Email address"
                type="email"
                autocomplete="username"
                inputmode="email"
                placeholder="you@company.com"
                required
                autofocus
                :error="form.errors.identifier"
            />

            <TextField
                id="login-password"
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />

            <div class="flex items-center justify-between gap-3 pt-0.5">
                <label class="flex items-center gap-2 text-xs text-mb-text-muted dark:text-mb-text-soft cursor-pointer">
                    <input v-model="form.remember" type="checkbox" class="w-3.5 h-3.5 accent-mb-tint-emerald cursor-pointer" />
                    Keep me signed in
                </label>
                <Link href="/forgot-password" class="text-xs font-medium text-mb-text-muted dark:text-mb-text-soft hover:text-mb-emerald transition-colors">
                    Forgot password?
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full h-11 rounded-xl bg-mb-tint-emerald hover:brightness-110 disabled:opacity-50 disabled:hover:brightness-100 font-semibold text-sm text-mb-on-accent shadow-lg shadow-mb-tint-emerald/20 transition cursor-pointer"
            >
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>

        <div v-if="demoAccounts.length" class="mt-7 pt-5 border-t border-mb-line">
            <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft mb-2.5">
                Quick demo login
            </p>
            <div class="grid grid-cols-2 gap-2">
                <button
                    v-for="account in demoAccounts"
                    :key="account.email"
                    type="button"
                    :title="account.email"
                    class="h-9 px-2.5 rounded-lg bg-mb-raised hover:bg-mb-chip border border-mb-line text-xs font-medium text-mb-text-soft hover:text-mb-text-strong transition-colors cursor-pointer"
                    @click="fillDemo(account)"
                >
                    {{ account.label }}
                </button>
            </div>
        </div>

        <!--
            One door, four roles, and nobody signs themselves up - so the only
            useful thing to say is who to ask, and that differs. Saying "ask
            your HR team" was right for an employee and wrong for a kitchen,
            which has no HR team and is set up by MealBells.
        -->
        <template #footer>
            No account yet? Accounts are created for you — employees by their HR team,
            companies and kitchens by MealBells.
        </template>
    </AuthLayout>
</template>
