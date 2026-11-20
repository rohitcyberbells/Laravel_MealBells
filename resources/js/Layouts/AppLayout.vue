<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ThemeToggle from './ThemeToggle.vue';
import NavIcon from './NavIcon.vue';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const workspace = computed(() => page.props.workspace ?? page.props.company?.name ?? null);

// Rendered straight from the server, so a role only ever sees its own links.
const navigation = computed(() => page.props.navigation ?? []);

// Every role gets the icon rail on desktop. It was once only for roles with
// one or two destinations, because the menu icons were emoji and eight of them
// in a column told you nothing. They are drawn glyphs now, so the labels can
// live in the tooltip and give the page back 176px. Below lg the off-canvas
// drawer still shows them in full.

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
        <!--
            The ambient wash every signed-in screen sits on. It lives here
            rather than in each page because a page that forgot it read as a
            different app; main carries relative z-10 so page content always
            paints above it without every page having to say so.
        -->
        <div class="pointer-events-none fixed inset-0 z-0 overflow-hidden" aria-hidden="true">
            <div class="absolute -top-40 -left-32 w-[34rem] h-[34rem] mb-wash-a">
                <div class="absolute inset-0 rounded-full bg-mb-tint-emerald/18 blur-[110px] mb-tint-1"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-cyan/14 blur-[110px] mb-tint-2"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-amber/10 blur-[110px] mb-tint-3"></div>
            </div>
            <div class="absolute top-1/3 -right-40 w-[38rem] h-[38rem] mb-wash-b">
                <div class="absolute inset-0 rounded-full bg-mb-tint-emerald/18 blur-[120px] mb-tint-3"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-cyan/14 blur-[120px] mb-tint-1"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-amber/10 blur-[120px] mb-tint-2"></div>
            </div>
            <div class="absolute -bottom-48 left-1/4 w-[30rem] h-[30rem] mb-wash-c">
                <div class="absolute inset-0 rounded-full bg-mb-tint-emerald/18 blur-[110px] mb-tint-2"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-cyan/14 blur-[110px] mb-tint-3"></div>
                <div class="absolute inset-0 rounded-full bg-mb-tint-amber/10 blur-[110px] mb-tint-1"></div>
            </div>
        </div>

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
                // Frosted rather than solid, so the page's ambient light
                // carries through it instead of stopping at a hard column.
                // The border stays: it is what gives glass an edge.
                'fixed inset-y-0 left-0 z-50 w-64 flex flex-col bg-mb-card/60 backdrop-blur-xl border-r border-mb-line transition-transform duration-200',
                'lg:translate-x-0',
                // The icon rail drops the column entirely on desktop - no
                // panel, no edge - and lets its tiles float on the page. The
                // off-canvas drawer keeps its surface, and its labels, at every
                // width below lg, where it sits over the content rather than
                // beside it.
                'lg:w-20 lg:bg-transparent lg:backdrop-blur-none lg:border-r-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Brand and workspace -->
            <div class="flex items-center gap-2.5 px-5 h-16 border-b border-mb-line shrink-0 lg:px-0 lg:justify-center lg:border-b-0">
                <div class="w-10 h-10 shrink-0 rounded-xl bg-gradient-to-tr from-mb-tint-cyan to-mb-tint-emerald flex items-center justify-center text-white shadow-md shadow-mb-tint-cyan/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8" />
                        <path d="M13.7 21a2 2 0 0 1-3.4 0" />
                    </svg>
                </div>
                <div class="flex flex-col min-w-0 lg:hidden">
                    <span class="font-bold tracking-tight bg-gradient-to-r from-mb-cyan to-mb-emerald bg-clip-text text-transparent">
                        MealBells
                    </span>
                    <span class="text-xs text-mb-text-muted font-medium truncate">
                        {{ workspace || 'Portal' }}
                    </span>
                </div>
            </div>

            <!-- Role menu -->
            <nav class="flex-1 overflow-y-auto px-3 lg:px-2 py-4 space-y-1">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    @click="sidebarOpen = false"
                    :class="[
                        'flex items-center gap-2.5 p-2 rounded-xl text-sm transition-colors',
                        'lg:w-12 lg:h-12 lg:mx-auto lg:p-0 lg:justify-center lg:rounded-2xl lg:shadow-sm',
                        // The border stays transparent rather than absent,
                        // because the rail's tiles set only a border colour and
                        // need the width to come from here.
                        item.href === activeHref
                            ? 'text-mb-text-strong font-semibold border border-transparent'
                            : 'text-mb-text-soft font-medium hover:text-mb-text-strong hover:bg-mb-raised/50 border border-transparent',
                        // On the rail each item is its own tile, so it carries
                        // the surface the column no longer has.
                        item.href === activeHref
                            ? 'lg:bg-mb-text-strong lg:text-mb-page lg:border-transparent'
                            : 'lg:bg-mb-card lg:border-mb-line lg:hover:bg-mb-raised',
                    ]"
                    :aria-current="item.href === activeHref ? 'page' : undefined"
                    :title="item.label"
                >
                    <!--
                        In the drawer the icon sits in its own rounded square,
                        which carries the active state beside the label. On the
                        rail the Link itself is already that square, so the box
                        steps out of the way and only the glyph remains.
                    -->
                    <span
                        :class="[
                            'w-8 h-8 shrink-0 rounded-lg border flex items-center justify-center transition-colors',
                            item.href === activeHref
                                ? 'bg-mb-text-strong text-mb-page border-transparent'
                                : 'bg-mb-inset text-mb-text-muted border-mb-line',
                            'lg:w-5 lg:h-5 lg:bg-transparent lg:border-transparent lg:text-current',
                        ]"
                    >
                        <NavIcon :name="item.icon" class="w-4 h-4" />
                    </span>
                    <span class="truncate lg:hidden">{{ item.label }}</span>
                </Link>
            </nav>

            <!-- Theme, user and logout -->
            <div class="border-t border-mb-line p-4 space-y-3 shrink-0 lg:px-2 lg:py-3 lg:border-t-0 lg:flex lg:flex-col lg:items-center">
                <ThemeToggle compact vertical class="hidden lg:inline-flex" data-diff-ignore />
                <div class="min-w-0 lg:hidden">
                    <p class="text-sm font-semibold text-mb-text truncate">{{ user?.name }}</p>
                    <p class="text-xs text-mb-text-faint truncate">{{ user?.email }}</p>
                </div>
                <button
                    @click="logout"
                    title="Logout"
                    aria-label="Logout"
                    class="w-full px-3 py-2 text-xs font-bold text-mb-rose bg-mb-tint-rose/10 hover:bg-mb-tint-rose/20 rounded-lg border border-mb-tint-rose/30 cursor-pointer transition-colors lg:w-12 lg:h-12 lg:px-0 lg:flex lg:items-center lg:justify-center lg:rounded-2xl lg:shadow-sm"
                >
                    <span class="lg:hidden">Logout</span>
                    <svg class="hidden lg:block w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h6" />
                    </svg>
                </button>
            </div>
        </aside>

        <!-- Content -->
        <div class="lg:pl-20">
            <!--
                flow-root, because the flash banners lead with mt-4 and nothing
                between them and <body> has a border or padding to stop it. The
                margin collapsed all the way out of the shell, pushed the whole
                app down 16px, and the body's slate-950 showed through as a dark
                band above the page - in the light theme especially.
            -->
            <main class="relative z-10 min-h-screen flow-root">
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
