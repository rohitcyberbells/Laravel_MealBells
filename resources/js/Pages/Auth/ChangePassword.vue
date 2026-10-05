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
    <div class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4 font-sans selection:bg-cyan-500 selection:text-white">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl space-y-6">
            <!-- Header -->
            <div class="text-center space-y-2">
                <div class="inline-flex w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/30 items-center justify-center text-2xl mb-2">
                    🔑
                </div>
                <h1 class="text-xl font-bold text-white tracking-tight">Password Change Required</h1>
                <p class="text-xs text-slate-400">
                    Welcome <span class="text-cyan-400 font-semibold">{{ user?.name || user?.email }}</span>. Please set a new secure password to access your account.
                </p>
            </div>

            <!-- Flash Error / Message -->
            <div v-if="message" class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs font-medium text-center">
                {{ message }}
            </div>
            <div v-if="errorMessage" class="p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 text-xs font-medium text-center">
                {{ errorMessage }}
            </div>

            <!-- Change Password Form -->
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Current Temporary Password</label>
                    <input
                        type="password"
                        v-model="form.current_password"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-cyan-500 transition-colors"
                        placeholder="Enter temporary password"
                    />
                    <p v-if="form.errors.current_password" class="text-xs text-red-400 mt-1 font-medium">
                        {{ form.errors.current_password }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">New Password (min 8 chars)</label>
                    <input
                        type="password"
                        v-model="form.password"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-cyan-500 transition-colors"
                        placeholder="Enter new password"
                    />
                    <p v-if="form.errors.password" class="text-xs text-red-400 mt-1 font-medium">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1">Confirm New Password</label>
                    <input
                        type="password"
                        v-model="form.password_confirmation"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-cyan-500 transition-colors"
                        placeholder="Confirm new password"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-3 px-4 rounded-xl text-xs font-bold uppercase tracking-wider text-slate-950 bg-gradient-to-r from-cyan-400 to-emerald-400 hover:from-cyan-300 hover:to-emerald-300 transition-all shadow-lg cursor-pointer disabled:opacity-50"
                >
                    {{ form.processing ? 'Updating...' : 'Update Password & Proceed' }}
                </button>
            </form>
        </div>
    </div>
</template>
