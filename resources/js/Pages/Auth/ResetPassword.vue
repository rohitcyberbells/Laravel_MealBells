<script setup>
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextField from '../../Layouts/TextField.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email || '',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post('/reset-password', {
    onFinish: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <AuthLayout title="Choose a new password" subtitle="This link works once. Pick something only you know.">
        <form @submit.prevent="submit" class="space-y-4">
            <TextField
                id="reset-email"
                v-model="form.email"
                label="Email address"
                type="email"
                autocomplete="email"
                inputmode="email"
                required
                :error="form.errors.email"
            />

            <TextField
                id="reset-password"
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                hint="At least 8 characters, with a letter and a number."
                :error="form.errors.password"
            />

            <TextField
                id="reset-password-confirmation"
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full h-11 rounded-xl bg-mb-tint-emerald hover:brightness-110 disabled:opacity-50 disabled:hover:brightness-100 font-semibold text-sm text-mb-on-accent shadow-lg shadow-mb-tint-emerald/20 transition cursor-pointer"
            >
                {{ form.processing ? 'Saving…' : 'Set new password' }}
            </button>
        </form>

        <template #footer>
            <Link href="/login" class="font-medium hover:text-mb-emerald transition-colors">Back to sign in</Link>
        </template>
    </AuthLayout>
</template>
