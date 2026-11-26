<script setup>
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextField from '../../Layouts/TextField.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
// The response is the same whether or not an account exists, so this message is
// all anyone ever sees.
const flashMessage = computed(() => page.props.flash?.message ?? null);

const form = useForm({ email: '' });

const submit = () => form.post('/forgot-password', {
    onSuccess: () => form.reset('email'),
});
</script>

<template>
    <AuthLayout
        title="Reset your password"
        subtitle="Enter the address you sign in with and we will send you a link."
    >
        <div v-if="flashMessage" class="mb-5 p-3.5 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-sm text-mb-emerald-soft">
            {{ flashMessage }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <TextField
                id="forgot-email"
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="email"
                inputmode="email"
                placeholder="you@company.com"
                required
                autofocus
                :error="form.errors.email"
            />

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full h-11 rounded-xl bg-mb-tint-emerald hover:brightness-110 disabled:opacity-50 disabled:hover:brightness-100 font-semibold text-sm text-mb-on-accent shadow-lg shadow-mb-tint-emerald/20 transition cursor-pointer"
            >
                {{ form.processing ? 'Sending…' : 'Send reset link' }}
            </button>
        </form>

        <!--
            An employee with no address on their record cannot be mailed, so the
            alternative is spelled out rather than left to fail quietly. This
            used to offer the company-code sign-in as the way round, which no
            longer exists: without an address there is no account at all, and
            only HR can add one.
        -->
        <p class="mt-5 pt-5 border-t border-mb-line text-[11px] leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
            No email address on your employee record? There is nothing for us to send to —
            ask your HR team to add one, and your login can be created then.
        </p>

        <template #footer>
            <Link href="/login" class="font-medium hover:text-mb-emerald transition-colors">Back to sign in</Link>
        </template>
    </AuthLayout>
</template>
