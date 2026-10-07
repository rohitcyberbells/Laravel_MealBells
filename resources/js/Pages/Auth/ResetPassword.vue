<script setup>
import { useForm, Link } from '@inertiajs/vue3';

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
    <div class="min-h-screen bg-slate-950 text-white flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-emerald-400 text-center mb-1">MealBells</h1>
            <p class="text-slate-400 text-sm text-center mb-6">Choose a new password</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Email address</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                    />
                    <p v-if="form.errors.email" class="text-red-400 text-xs mt-1">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">New password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                    />
                    <p class="text-[11px] text-slate-500 mt-1">At least 8 characters, with a letter and a number.</p>
                    <p v-if="form.errors.password" class="text-red-400 text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Confirm new password</label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 font-semibold text-slate-950 py-2 rounded-lg transition cursor-pointer"
                >
                    {{ form.processing ? 'Saving…' : 'Set new password' }}
                </button>
            </form>

            <div class="mt-5 pt-4 border-t border-slate-800 text-center">
                <Link href="/login" class="text-xs text-slate-400 hover:text-emerald-400">Back to sign in</Link>
            </div>
        </div>
    </div>
</template>
