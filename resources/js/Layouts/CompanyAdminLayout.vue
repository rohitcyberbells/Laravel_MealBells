<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || page.props.user);
const company = computed(() => page.props.company);
const flash = computed(() => page.props.flash || {});
const message = computed(() => page.props.message || flash.value.message || flash.value.success);
const errorMessage = computed(() => flash.value.error);

const logout = () => {
    router.post('/logout');
};

const currentRoute = computed(() => page.url);
const isCurrent = (path) => currentRoute.value.startsWith(path);
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans selection:bg-cyan-500 selection:text-white">
        <!-- Top Navigation Bar -->
        <header class="sticky top-0 z-40 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 shadow-lg">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <!-- Brand & Company Info -->
                    <div class="flex items-center space-x-6">
                        <Link href="/company-admin/dashboard" class="flex items-center space-x-3 group">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-emerald-500 flex items-center justify-center font-black text-xl text-slate-950 shadow-md shadow-cyan-500/20 group-hover:scale-105 transition-transform">
                                🔔
                            </div>
                            <div class="flex flex-col">
                                <span class="font-bold text-lg tracking-tight bg-gradient-to-r from-cyan-400 to-emerald-400 bg-clip-text text-transparent">
                                    MealBells
                                </span>
                                <span class="text-xs text-slate-400 font-medium">
                                    {{ company?.name || 'Company Portal' }}
                                </span>
                            </div>
                        </Link>

                        <!-- Nav Links -->
                        <nav class="hidden md:flex items-center space-x-1 pl-6 border-l border-slate-800">
                            <Link
                                href="/company-admin/dashboard"
                                :class="[
                                    'px-3.5 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isCurrent('/company-admin/dashboard')
                                        ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/50'
                                ]"
                            >
                                📊 Dashboard
                            </Link>
                            <Link
                                href="/company-admin/employees"
                                :class="[
                                    'px-3.5 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isCurrent('/company-admin/employees')
                                        ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/50'
                                ]"
                            >
                                👥 Employees
                            </Link>
                            <Link
                                href="/company-admin/calendar"
                                :class="[
                                    'px-3.5 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isCurrent('/company-admin/calendar')
                                        ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/50'
                                ]"
                            >
                                📅 Calendar
                            </Link>
                            <Link
                                href="/company-admin/reports/adoption"
                                :class="[
                                    'px-3.5 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isCurrent('/company-admin/reports/adoption')
                                        ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/50'
                                ]"
                            >
                                📈 Adoption Report
                            </Link>

                            <Link
                                href="/company-admin/hrms"
                                :class="[
                                    'px-3.5 py-2 rounded-lg text-sm font-medium transition-colors',
                                    isCurrent('/company-admin/hrms')
                                        ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/50'
                                ]"
                            >
                                🔗 HRMS
                            </Link>
                        </nav>
                    </div>

                    <!-- Right User Section -->
                    <div class="flex items-center space-x-4">
                        <div class="hidden sm:flex flex-col text-right">
                            <span class="text-sm font-semibold text-slate-200">{{ user?.name || user?.email }}</span>
                            <span class="text-[11px] text-cyan-400 font-mono tracking-wider">COMPANY ADMIN</span>
                        </div>
                        <button
                            @click="logout"
                            class="inline-flex items-center px-3.5 py-1.5 rounded-lg text-xs font-semibold text-red-400 bg-red-500/10 hover:bg-red-500/20 border border-red-500/30 transition-all shadow-sm active:scale-95 cursor-pointer"
                        >
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash Messages Toast/Banner -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div v-if="message" class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 flex items-center justify-between shadow-lg backdrop-blur-sm">
                <div class="flex items-center space-x-3">
                    <span class="text-xl">✅</span>
                    <span class="text-sm font-medium">{{ message }}</span>
                </div>
                <button @click="$page.props.message = null" class="text-emerald-400 hover:text-emerald-200 text-sm font-bold">✕</button>
            </div>

            <div v-if="errorMessage" class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 flex items-center justify-between shadow-lg backdrop-blur-sm">
                <div class="flex items-center space-x-3">
                    <span class="text-xl">⚠️</span>
                    <span class="text-sm font-medium">{{ errorMessage }}</span>
                </div>
                <button @click="flash.error = null" class="text-red-400 hover:text-red-200 text-sm font-bold">✕</button>
            </div>
        </div>

        <!-- Main Body Content -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <slot />
        </main>
    </div>
</template>
