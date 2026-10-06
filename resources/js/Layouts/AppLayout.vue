<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const workspace = computed(() => page.props.workspace ?? page.props.company?.name ?? null);

// Rendered straight from the server, so a role only ever sees its own links.
const navigation = computed(() => page.props.navigation ?? []);

const flashMessage = computed(() => page.props.flash?.message ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

const sidebarOpen = ref(false);

// Longest match wins, so /company-admin/reports/adoption does not also light up
// /company-admin/dashboard.
const activeHref = computed(() => {
    const url = page.url;

    return navigation.value
        .map((item) => item.href)
        .filter((href) => url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`))
        .sort((a, b) => b.length - a.length)[0] ?? null;
});

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 font-sans selection:bg-cyan-500 selection:text-white">
        <!-- Mobile bar: the sidebar is off-canvas below lg -->
        <div class="lg:hidden sticky top-0 z-40 flex items-center justify-between h-14 px-4 bg-slate-900/90 backdrop-blur-md border-b border-slate-800">
            <button
                @click="sidebarOpen = true"
                class="p-2 -ml-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 cursor-pointer"
                aria-label="Open menu"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <span class="font-bold bg-gradient-to-r from-cyan-400 to-emerald-400 bg-clip-text text-transparent">
                MealBells
            </span>
            <span class="w-6" />
        </div>

        <!-- Backdrop, mobile only -->
        <div
            v-if="sidebarOpen"
            @click="sidebarOpen = false"
            class="lg:hidden fixed inset-0 z-40 bg-slate-950/70"
        />

        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 flex flex-col bg-slate-900 border-r border-slate-800 transition-transform duration-200',
                'lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Brand and workspace -->
            <div class="flex items-center gap-3 px-5 h-16 border-b border-slate-800 shrink-0">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-emerald-500 flex items-center justify-center font-black text-xl text-slate-950 shadow-md shadow-cyan-500/20">
                    🔔
                </div>
                <div class="flex flex-col min-w-0">
                    <span class="font-bold tracking-tight bg-gradient-to-r from-cyan-400 to-emerald-400 bg-clip-text text-transparent">
                        MealBells
                    </span>
                    <span class="text-xs text-slate-400 font-medium truncate">
                        {{ workspace || 'Portal' }}
                    </span>
                </div>
            </div>

            <!-- Role menu -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    @click="sidebarOpen = false"
                    :class="[
                        'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                        item.href === activeHref
                            ? 'bg-slate-800 text-cyan-400 border border-slate-700'
                            : 'text-slate-300 hover:text-white hover:bg-slate-800/50 border border-transparent'
                    ]"
                    :aria-current="item.href === activeHref ? 'page' : undefined"
                >
                    <span class="text-base leading-none">{{ item.icon }}</span>
                    <span class="truncate">{{ item.label }}</span>
                </Link>
            </nav>

            <!-- User and logout -->
            <div class="border-t border-slate-800 p-4 space-y-3 shrink-0">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-slate-200 truncate">{{ user?.name }}</p>
                    <p class="text-xs text-slate-500 truncate">{{ user?.email }}</p>
                </div>
                <button
                    @click="logout"
                    class="w-full px-3 py-2 text-xs font-bold text-red-400 bg-red-500/10 hover:bg-red-500/20 rounded-lg border border-red-500/30 cursor-pointer transition-colors"
                >
                    Logout
                </button>
            </div>
        </aside>

        <!-- Content -->
        <div class="lg:pl-64">
            <main class="min-h-screen">
                <div v-if="flashMessage" class="mx-4 mt-4 lg:mx-8 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-300">
                    {{ flashMessage }}
                </div>
                <div v-if="flashError" class="mx-4 mt-4 lg:mx-8 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-300">
                    {{ flashError }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
