<script setup>
import AuthLayout from '../../Layouts/AuthLayout.vue';
import TextField from '../../Layouts/TextField.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** True when the account is still on a password somebody else set. */
    forced: { type: Boolean, default: false },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const message = computed(() => page.props.message || flash.value.message);
const errorMessage = computed(() => flash.value.error);

/*
 * A line only when there is something to say.
 *
 * Forced, there is: a wall that reads "required" invites people to wonder what
 * they did wrong, and the actual reason - that two people currently know this
 * password - is worth a sentence.
 *
 * Choosing to be here, there is not. "Signed in as Rohit" told the person
 * their own name under a heading that had already said what the screen was
 * for, which is the kind of line a form grows when a slot exists and somebody
 * fills it.
 */
const subtitle = computed(() => (props.forced
    ? 'The password you signed in with was set by someone else, so more than one person knows it. Choose your own and that stops being true.'
    : null
));

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
    <AuthLayout :title="forced ? 'Set your own password' : 'Change your password'" :subtitle="subtitle">
        <div v-if="message" class="mb-5 p-3.5 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-sm text-mb-emerald-soft">
            {{ message }}
        </div>
        <div v-if="errorMessage" class="mb-5 p-3.5 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-sm text-mb-rose-soft">
            {{ errorMessage }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <TextField
                id="change-current-password"
                v-model="form.current_password"
                :label="forced ? 'Temporary password' : 'Current password'"
                type="password"
                autocomplete="current-password"
                required
                autofocus
                :error="form.errors.current_password"
            />

            <TextField
                id="change-new-password"
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                hint="At least 8 characters, with a letter and a number."
                :error="form.errors.password"
            />

            <TextField
                id="change-new-password-confirmation"
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
                {{ form.processing ? 'Saving…' : forced ? 'Set password and continue' : 'Save new password' }}
            </button>
        </form>

        <!--
            A way out, but only when there is one. Forced here, the account has
            no password of its own yet and going back lands on a redirect
            straight to this screen.
        -->
        <template v-if="! forced" #footer>
            <button type="button" class="font-medium hover:text-mb-text-strong transition-colors cursor-pointer" @click="goBack">
                Cancel
            </button>
        </template>
    </AuthLayout>
</template>
