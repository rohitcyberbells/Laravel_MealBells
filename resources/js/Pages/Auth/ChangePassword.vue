<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || page.props.user);
const flash = computed(() => page.props.flash || {});
const message = computed(() => page.props.message || flash.value.message);
const errorMessage = computed(() => flash.value.error);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/change-password', {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
};
</script>

<template>
    <div class="mb-theme min-h-screen bg-mb-page text-mb-text-strong flex items-center justify-center p-4 font-sans selection:bg-mb-tint-cyan selection:text-white">
        <div class="w-full max-w-md bg-mb-card border border-mb-line rounded-2xl p-6 sm:p-8 shadow-2xl space-y-6">
            <!-- Header -->
            <div class="text-center space-y-2">
                <div class="inline-flex w-12 h-12 rounded-2xl bg-mb-tint-amber/10 border border-mb-tint-amber/30 items-center justify-center text-2xl mb-2">
                    🔑
                </div>
                <h1 class="text-xl font-bold text-mb-text-strong tracking-tight">Password Change Required</h1>
                <p class="text-xs text-mb-text-muted">
                    Welcome <span class="text-mb-cyan font-semibold">{{ user?.name || user?.email }}</span>. Please set a new secure password to access your account.
                </p>
            </div>

            <!-- Flash Error / Message -->
            <div v-if="message" class="p-3 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-mb-emerald-soft text-xs font-medium text-center">
                {{ message }}
            </div>
            <div v-if="errorMessage" class="p-3 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-mb-rose-soft text-xs font-medium text-center">
                {{ errorMessage }}
            </div>

            <!-- Change Password Form -->
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-mb-text-soft mb-1">Current Temporary Password</label>
                    <input
                        type="password"
                        v-model="form.current_password"
                        required
                        class="w-full bg-mb-inset border border-mb-line rounded-xl px-4 py-2.5 text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-cyan transition-colors"
                        placeholder="Enter temporary password"
                    />
                    <p v-if="form.errors.current_password" class="text-xs text-mb-rose mt-1 font-medium">
                        {{ form.errors.current_password }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-mb-text-soft mb-1">New Password (min 8 chars)</label>
                    <input
                        type="password"
                        v-model="form.password"
                        required
                        class="w-full bg-mb-inset border border-mb-line rounded-xl px-4 py-2.5 text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-cyan transition-colors"
                        placeholder="Enter new password"
                    />
                    <p v-if="form.errors.password" class="text-xs text-mb-rose mt-1 font-medium">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-mb-text-soft mb-1">Confirm New Password</label>
                    <input
                        type="password"
                        v-model="form.password_confirmation"
                        required
                        class="w-full bg-mb-inset border border-mb-line rounded-xl px-4 py-2.5 text-sm text-mb-text-strong focus:outline-none focus:border-mb-tint-cyan transition-colors"
                        placeholder="Confirm new password"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-3 px-4 rounded-xl text-xs font-bold uppercase tracking-wider text-mb-on-accent bg-gradient-to-r from-mb-cyan to-mb-emerald hover:from-mb-cyan-soft hover:to-mb-emerald-soft transition-all shadow-lg cursor-pointer disabled:opacity-50"
                >
                    {{ form.processing ? 'Updating...' : 'Update Password & Proceed' }}
                </button>
            </form>
        </div>
    </div>
</template>
