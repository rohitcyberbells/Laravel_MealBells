<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const submit = () => {
    form.post('/login');
};

const fillDemo = (email) => {
    form.email = email;
    form.password = 'password';
};

</script>

<template>
    <div class="min-h-screen bg-slate-950 text-white flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-2xl">
            <h1 class="text-2xl font-bold text-emerald-400 text-center mb-1">MealBell</h1>
            <p class="text-slate-400 text-sm text-center mb-6">Sign in to your dashboard</p>
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Email</label>
                    <input v-model="form.email" type="email" required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
                    <p v-if="form.errors.email" class="text-red-400 text-xs mt-1">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Password</label>
                    <input v-model="form.password" type="password" required
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-emerald-500" />
                    <p v-if="form.errors.password" class="text-red-400 text-xs mt-1">{{ form.errors.password }}</p>
                </div>
                <button type="submit" :disabled="form.processing"
                    class="w-full bg-emerald-500 hover:bg-emerald-600 font-semibold text-slate-950 py-2 rounded-lg transition">
                    Sign In
                </button>
            </form>
            <div class="mt-6 pt-4 border-t border-slate-800">
                <p class="text-xs font-semibold text-slate-400 uppercase mb-2 text-center">Quick Demo Login</p>
                <div class="grid grid-cols-3 gap-2">
                    <button @click="fillDemo('admin@mealbells.com')" class="bg-slate-800 hover:bg-slate-700 text-xs text-emerald-300 py-1.5 px-2 rounded border border-slate-700">
                        Super Admin
                    </button>
                    <button @click="fillDemo('tiffin@mealbells.com')" class="bg-slate-800 hover:bg-slate-700 text-xs text-amber-300 py-1.5 px-2 rounded border border-slate-700">
                        Tiffin Admin
                    </button>
                    <button @click="fillDemo('company@mealbells.com')" class="bg-slate-800 hover:bg-slate-700 text-xs text-cyan-300 py-1.5 px-2 rounded border border-slate-700">
                        Company Admin
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>