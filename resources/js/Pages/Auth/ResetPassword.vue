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
    <div class="mb-theme min-h-screen bg-mb-page text-mb-text-strong flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-mb-card border border-mb-line rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-mb-emerald text-center mb-1">MealBells</h1>
            <p class="text-mb-text-muted text-sm text-center mb-6">Choose a new password</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">Email address</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p v-if="form.errors.email" class="text-mb-rose text-xs mt-1">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">New password</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p class="text-[11px] text-mb-text-faint mt-1">At least 8 characters, with a letter and a number.</p>
                    <p v-if="form.errors.password" class="text-mb-rose text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">Confirm new password</label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-50 font-semibold text-mb-on-accent py-2 rounded-lg transition cursor-pointer"
                >
                    {{ form.processing ? 'Saving…' : 'Set new password' }}
                </button>
            </form>

            <div class="mt-5 pt-4 border-t border-mb-line text-center">
                <Link href="/login" class="text-xs text-mb-text-muted hover:text-mb-emerald">Back to sign in</Link>
            </div>
        </div>
    </div>
</template>
