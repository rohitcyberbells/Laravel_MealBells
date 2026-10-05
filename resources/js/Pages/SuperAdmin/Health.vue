<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || page.props.user);

const props = defineProps({
    scheduler: {
        type: Object,
        required: true,
    },
    failed_jobs_count: {
        type: Number,
        default: 0,
    },
    snapshots_last_24h: {
        type: Number,
        default: 0,
    },
    missing_snapshots_today: {
        type: Array,
        default: () => [],
    },
});

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans">
        <!-- Top Navigation -->
        <header class="sticky top-0 z-40 bg-slate-900/80 backdrop-blur-md border-b border-slate-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center space-x-6">
                        <Link href="/super-admin/dashboard" class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-purple-500 to-indigo-500 flex items-center justify-center font-black text-xl text-white shadow-md">
                                🛡️
                            </div>
                            <span class="font-bold text-lg bg-gradient-to-r from-purple-400 to-indigo-400 bg-clip-text text-transparent">
                                MealBells Platform
                            </span>
                        </Link>
                        <nav class="hidden md:flex items-center space-x-2 pl-6 border-l border-slate-800">
                            <Link href="/super-admin/dashboard" class="px-3 py-1.5 rounded-lg text-sm text-slate-300 hover:text-white hover:bg-slate-800">
                                🏢 Dashboard
                            </Link>
                            <Link href="/super-admin/health" class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-slate-800 text-purple-400 border border-slate-700">
                                🩺 System Health
                            </Link>
                        </nav>
                    </div>

                    <div class="flex items-center space-x-4">
                        <span class="text-sm font-semibold text-slate-300">{{ user?.email }}</span>
                        <button @click="logout" class="px-3.5 py-1.5 text-xs font-bold text-red-400 bg-red-500/10 hover:bg-red-500/20 rounded-lg border border-red-500/30 cursor-pointer">
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Body -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-white">🩺 Platform System Health</h1>
                <p class="text-sm text-slate-400">Real-time status of scheduler heartbeat, queue worker failures, and snapshot locks</p>
            </div>

            <!-- Health Metric Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Scheduler Heartbeat Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase text-slate-400">Scheduler Status</span>
                        <span
                            :class="[
                                'px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider',
                                scheduler.is_stale
                                    ? 'bg-red-500/20 text-red-400 border border-red-500/40 animate-pulse'
                                    : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40'
                            ]"
                        >
                            {{ scheduler.is_stale ? '⚠️ STALE (>3m)' : '✅ HEALTHY' }}
                        </span>
                    </div>
                    <div class="text-3xl font-extrabold text-white">
                        {{ scheduler.minutes_ago !== null ? `${scheduler.minutes_ago} min ago` : 'Never' }}
                    </div>
                    <p class="text-xs text-slate-400">
                        Cache Heartbeat: {{ scheduler.last_run_timestamp ? new Date(scheduler.last_run_timestamp * 1000).toLocaleString() : 'No heartbeat recorded' }}
                    </p>
                </div>

                <!-- Queue Failed Jobs Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-slate-400">Queue Failed Jobs</span>
                    <div class="text-3xl font-extrabold text-white">
                        <span :class="failed_jobs_count > 0 ? 'text-red-400' : 'text-emerald-400'">
                            {{ failed_jobs_count }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">
                        Jobs in `failed_jobs` table requiring investigation
                    </p>
                </div>

                <!-- Locked Snapshots Card -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                    <span class="text-xs font-semibold uppercase text-slate-400">Snapshots Locked (Last 24h)</span>
                    <div class="text-3xl font-extrabold text-indigo-400">
                        {{ snapshots_last_24h }}
                    </div>
                    <p class="text-xs text-slate-400">
                        Confirmed meal count records locked across all companies
                    </p>
                </div>
            </div>

            <!-- Missing Snapshots Table -->
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl">
                <h2 class="text-base font-bold text-slate-200 mb-4">Missing Today Snapshots (Cutoff Passed)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-800/60 text-slate-400 uppercase text-[11px] font-semibold">
                            <tr>
                                <th class="py-3 px-4">Company ID</th>
                                <th class="py-3 px-4">Company Name</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Cutoff Time</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <tr v-for="item in missing_snapshots_today" :key="item.company_id" class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 font-mono text-slate-400">#{{ item.company_id }}</td>
                                <td class="py-3 px-4 font-semibold text-slate-200">{{ item.company_name }}</td>
                                <td class="py-3 px-4 font-mono text-slate-300">{{ item.date }}</td>
                                <td class="py-3 px-4 text-slate-400">{{ item.cutoff_time }}</td>
                                <td class="py-3 px-4 text-red-400 font-bold">⚠️ MISSING SNAPSHOT</td>
                            </tr>
                            <tr v-if="missing_snapshots_today.length === 0">
                                <td colspan="5" class="py-6 text-center text-emerald-400 font-medium">
                                    All companies with passed cutoff have locked snapshots for today!
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</template>
