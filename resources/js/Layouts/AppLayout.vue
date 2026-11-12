<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ThemeToggle from './ThemeToggle.vue';

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
    <!--
        mb-theme sits here rather than on each page: the shell is the outermost
        thing every signed-in screen shares, so one class puts every page, its
        cards and its modals under the same toggle.
    -->
    <div class="mb-theme min-h-screen bg-mb-page text-mb-text font-sans selection:bg-mb-tint-cyan selection:text-white">
        <!-- Mobile bar: the sidebar is off-canvas below lg -->
        <div class="lg:hidden sticky top-0 z-40 flex items-center justify-between h-14 px-4 bg-mb-card/90 backdrop-blur-md border-b border-mb-line">
            <button
                @click="sidebarOpen = true"
                class="p-2 -ml-2 rounded-lg text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised cursor-pointer"
                aria-label="Open menu"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <span class="font-bold bg-gradient-to-r from-mb-cyan to-mb-emerald bg-clip-text text-transparent">
                MealBells
            </span>
            <ThemeToggle compact data-diff-ignore />
        </div>

        <!-- Backdrop, mobile only -->
        <div
            v-if="sidebarOpen"
            @click="sidebarOpen = false"
            class="lg:hidden fixed inset-0 z-40 bg-mb-page/70"
        />

        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 flex flex-col bg-mb-card border-r border-mb-line transition-transform duration-200',
                'lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Brand and workspace -->
            <div class="flex items-center gap-3 px-5 h-16 border-b border-mb-line shrink-0">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-mb-tint-cyan to-mb-tint-emerald flex items-center justify-center font-black text-xl text-mb-on-accent shadow-md shadow-mb-tint-cyan/20">
                    🔔
                </div>
                <div class="flex flex-col min-w-0">
                    <span class="font-bold tracking-tight bg-gradient-to-r from-mb-cyan to-mb-emerald bg-clip-text text-transparent">
                        MealBells
                    </span>
                    <span class="text-xs text-mb-text-muted font-medium truncate">
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
                            ? 'bg-mb-raised text-mb-cyan border border-mb-line-mid'
                            : 'text-mb-text-soft hover:text-mb-text-strong hover:bg-mb-raised/50 border border-transparent'
                    ]"
                    :aria-current="item.href === activeHref ? 'page' : undefined"
                >
                    <span class="text-base leading-none">{{ item.icon }}</span>
                    <span class="truncate">{{ item.label }}</span>
                </Link>
            </nav>

            <!-- Theme, user and logout -->
            <div class="border-t border-mb-line p-4 space-y-3 shrink-0">
                <ThemeToggle class="hidden lg:inline-flex" data-diff-ignore />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-mb-text truncate">{{ user?.name }}</p>
                    <p class="text-xs text-mb-text-faint truncate">{{ user?.email }}</p>
                </div>
                <button
                    @click="logout"
                    class="w-full px-3 py-2 text-xs font-bold text-mb-rose bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 rounded-lg border border-mb-tint-rose/30 cursor-pointer transition-colors"
                >
                    Logout
                </button>
            </div>
        </aside>

        <!-- Content -->
        <div class="lg:pl-64">
            <main class="min-h-screen">
                <div v-if="flashMessage" class="mx-4 mt-4 lg:mx-8 p-3 rounded-xl bg-mb-tint-emerald/10 border border-mb-tint-emerald/30 text-sm text-mb-emerald-soft">
                    {{ flashMessage }}
                </div>
                <div v-if="flashError" class="mx-4 mt-4 lg:mx-8 p-3 rounded-xl bg-mb-tint-rose/10 border border-mb-tint-rose/30 text-sm text-mb-rose-soft">
                    {{ flashError }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
