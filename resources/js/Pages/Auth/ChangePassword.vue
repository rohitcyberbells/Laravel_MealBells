<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** True when the account is still on a password somebody else set. */
    forced: { type: Boolean, default: false },
});

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

const goBack = () => window.history.back();

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
            <div class="space-y-2">
                <h1 class="text-xl font-bold text-mb-text-strong tracking-tight">
                    {{ forced ? 'Set your own password' : 'Change your password' }}
                </h1>
                <!--
                    Saying why, rather than only that. A wall that reads
                    "required" invites people to wonder what they did wrong;
                    the actual reason is that two people currently know this
                    password, and that is worth one sentence.
                -->
                <p v-if="forced" class="text-xs leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
                    The password you signed in with was set by someone else, so more than
                    one person knows it. Choose your own and that stops being true.
                </p>
                <p v-else class="text-xs leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
                    Signed in as
                    <span class="font-semibold text-mb-text-strong">{{ user?.name || user?.email }}</span>.
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
                    <label for="changepassword-current-temporary-password" class="block text-xs font-medium text-mb-text-soft mb-1">Current Temporary Password</label>
                    <input id="changepassword-current-temporary-password"
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
                    <label for="changepassword-new-password-min" class="block text-xs font-medium text-mb-text-soft mb-1">New Password (min 8 chars)</label>
                    <input id="changepassword-new-password-min"
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
                    <label for="changepassword-confirm-new-password" class="block text-xs font-medium text-mb-text-soft mb-1">Confirm New Password</label>
                    <input id="changepassword-confirm-new-password"
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
                    class="w-full py-3 px-4 rounded-xl text-xs font-bold uppercase tracking-wider text-mb-on-fill bg-mb-fill-emerald border border-transparent dark:border-mb-tint-emerald/50 hover:opacity-90 transition-opacity cursor-pointer disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : forced ? 'Set password and continue' : 'Save new password' }}
                </button>

                <!--
                    A way out, but only when there is one. Forced here, the
                    account has no usable password yet and a back link would
                    lead to a redirect straight back to this screen.
                -->
                <button
                    v-if="!forced"
                    type="button"
                    @click="goBack"
                    class="w-full py-2.5 text-xs font-semibold text-mb-text-muted dark:text-mb-text-soft hover:text-mb-text-strong transition-colors cursor-pointer"
                >
                    Cancel
                </button>
            </form>
        </div>
    </div>
</template>
