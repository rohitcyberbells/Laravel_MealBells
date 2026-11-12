<script setup>
import { useForm, usePage, Link } from '@inertiajs/vue3';
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
    <div class="mb-theme min-h-screen bg-mb-page text-mb-text-strong flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-mb-card border border-mb-line rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-mb-emerald text-center mb-1">MealBells</h1>
            <p class="text-mb-text-muted text-sm text-center mb-6">Reset your password</p>

            <div v-if="flashMessage" class="mb-4 p-3 rounded-lg bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-sm text-mb-emerald-soft">
                {{ flashMessage }}
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-mb-text-soft uppercase mb-1">Email address</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        placeholder="you@company.com"
                        required
                        class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-mb-text-strong focus:outline-none focus:border-mb-tint-emerald"
                    />
                    <p v-if="form.errors.email" class="text-mb-rose text-xs mt-1">{{ form.errors.email }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-mb-tint-emerald hover:bg-mb-tint-emerald disabled:opacity-50 font-semibold text-mb-on-accent py-2 rounded-lg transition cursor-pointer"
                >
                    {{ form.processing ? 'Sending…' : 'Send reset link' }}
                </button>
            </form>

            <!-- An employee with no address of their own cannot be mailed, so
                 the alternative is spelled out rather than left to fail quietly. -->
            <p class="text-[11px] text-mb-text-faint mt-4 text-center">
                Signing in with a company code and employee code instead of an email? Ask your HR team to reset it —
                we have no address to send this to.
            </p>

            <div class="mt-5 pt-4 border-t border-mb-line text-center">
                <Link href="/login" class="text-xs text-mb-text-muted hover:text-mb-emerald">Back to sign in</Link>
            </div>
        </div>
    </div>
</template>
