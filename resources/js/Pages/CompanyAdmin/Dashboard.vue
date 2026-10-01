<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    company: Object,
    tiffinService: Object,
    weeklyMenu: Object,
    todayOverride: Object,
    todayMeal: Object,
    notifications: Array,
});

const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

const getPlannedMeal = (day) => {
    if (!props.weeklyMenu?.items) return null;
    return props.weeklyMenu.items.find(i => i.day_of_week.toLowerCase() === day.toLowerCase());
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-white p-6">
        <!-- Header -->
        <header class="max-w-7xl mx-auto flex justify-between items-center pb-6 border-b border-slate-800 mb-8">
            <div>
                <div class="flex items-center space-x-3">
                    <h1 class="text-3xl font-bold text-cyan-400">{{ company?.name || 'Company Dashboard' }}</h1>
                    <span v-if="tiffinService" class="bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-xs px-3 py-1 rounded-full font-semibold">
                        Assigned Tiffin: {{ tiffinService.name }}
                    </span>
                    <span v-else class="bg-amber-500/20 text-amber-300 border border-amber-500/30 text-xs px-3 py-1 rounded-full font-semibold">
                        No Tiffin Service Assigned Yet
                    </span>
                </div>
                <p class="text-slate-400 text-sm mt-1">Employee Meal Portal & Daily Menu Overview</p>
            </div>
            <button @click="logout" class="bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/40 text-sm px-4 py-2 rounded-lg transition">
                Logout
            </button>
        </header>

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- Unassigned Banner -->
            <section v-if="!tiffinService" class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-6 text-center">
                <h2 class="text-xl font-bold text-amber-400 mb-2">Notice: Account Pending Pairing</h2>
                <p class="text-slate-300 text-sm max-w-lg mx-auto">
                    Your company is not yet paired with an assigned tiffin service. Please contact the Super Admin to pair your account.
                </p>
            </section>

            <template v-else>
                <!-- 🔔 Recent Notifications Feed -->
                <section v-if="notifications && notifications.length" class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <h2 class="text-xl font-bold text-amber-400 flex items-center gap-2 mb-4">
                        🔔 Recent Alerts & Notifications
                    </h2>
                    <div class="space-y-3">
                        <div v-for="notif in notifications" :key="notif.id" class="bg-slate-950 border border-slate-800 rounded-lg p-4 flex items-start justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-white">{{ notif.data.title }}</h3>
                                <p class="text-xs text-slate-300 mt-1">{{ notif.data.message }}</p>
                                <p v-if="notif.data.reason" class="text-xs text-amber-300 mt-1">Reason: {{ notif.data.reason }}</p>
                            </div>
                            <span class="text-[10px] text-slate-500 whitespace-nowrap ml-4">
                                {{ new Date(notif.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                            </span>
                        </div>
                    </div>
                </section>

                <!-- 🍱 Today's Featured Meal Card -->
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-emerald-400 flex items-center gap-2">
                             Today's Meal
                        </h2>
                        <span v-if="todayMeal?.is_override" class="bg-amber-500/20 text-amber-300 border border-amber-500/40 text-xs font-semibold px-3 py-1 rounded-full">
                             Daily Kitchen Override Active
                        </span>
                    </div>

                    <div v-if="todayMeal" class="bg-slate-950 border border-slate-800 rounded-lg p-5">
                        <p class="text-lg font-bold text-white">{{ todayMeal.meal_description }}</p>
                        <p v-if="todayMeal.reason" class="text-xs text-amber-300 mt-2 bg-amber-500/10 p-2 rounded border border-amber-500/20">
                            <strong>Note from kitchen:</strong> {{ todayMeal.reason }}
                        </p>
                    </div>

                    <div v-else class="bg-slate-950 border border-slate-800 rounded-lg p-5 text-center">
                        <p class="text-slate-400 text-sm italic">No meal schedule published for today yet.</p>
                    </div>
                </section>

                <!--  Published Weekly Menu (Monday - Friday) -->
                <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-xl font-bold text-white"> Published Weekly Menu</h2>
                            <p class="text-slate-400 text-xs">Official menu provided by <span class="text-emerald-400 font-semibold">{{ tiffinService.name }}</span></p>
                        </div>
                        <div v-if="tiffinService.contact_phone" class="text-xs text-slate-400">
                            Kitchen Contact: <span class="text-slate-200 font-semibold">{{ tiffinService.contact_phone }}</span>
                        </div>
                    </div>

                    <div v-if="weeklyMenu?.items?.length" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        <div v-for="day in daysOfWeek" :key="day" class="bg-slate-950 border border-slate-800 rounded-lg p-4">
                            <h3 class="text-xs uppercase font-bold text-cyan-400 mb-2">{{ day }}</h3>
                            <p class="text-sm text-slate-200">
                                {{ getPlannedMeal(day)?.meal_description || 'No meal scheduled' }}
                            </p>
                        </div>
                    </div>

                    <div v-else class="text-center py-8 bg-slate-950 rounded-lg border border-slate-800">
                        <p class="text-slate-400 text-sm">The tiffin service has not published the weekly menu yet.</p>
                    </div>
                </section>
            </template>
        </main>
    </div>
</template>

