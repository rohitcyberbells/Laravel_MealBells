<script setup>
/**
 * The frame every signed-out screen sits in.
 *
 * All four auth pages had repeated the same wrapper by hand, and none of them
 * were brought onto the app's visual language when the rest of it was: a flat
 * card on a flat page, while every signed-in screen had the ambient wash, the
 * glass card and the brand mark. Signing in was the first thing anybody saw of
 * MealBells and the only screen that looked like a different product.
 *
 * The wash is the same three drifting fields as AppLayout. It is repeated
 * rather than shared because AppLayout carries the whole signed-in chrome -
 * sidebar, workspace, flash, logout - none of which belongs here.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="mb-theme relative min-h-screen bg-mb-page text-mb-text font-sans selection:bg-mb-tint-cyan selection:text-white">
        <!--
            Blur sits on each tint rather than the parent: a filter on the
            parent collapses the subtree into one buffer, so every frame of the
            cross-fade would re-run the blur over the whole thing.
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

        <main class="relative z-10 min-h-screen flex items-center justify-center px-4 py-10">
            <div class="w-full max-w-[25rem]">
                <!--
                    No brand lockup above the card. The sidebar's gradient tile
                    is read at 40px against dark chrome; floated large and alone
                    over a pale page it was the loudest thing on screen, and the
                    one nobody came here to look at.
                -->
                <div class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6 sm:p-7 shadow-xl shadow-black/5">
                    <h1 class="text-xl font-bold tracking-tight text-mb-text-strong">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-1.5 text-sm leading-relaxed text-mb-text-muted dark:text-mb-text-soft">
                        {{ subtitle }}
                    </p>

                    <div class="mt-6">
                        <slot />
                    </div>
                </div>

                <!--
                    Outside the card rather than a bordered strip inside it. The
                    way back is not part of the form, and a divider across the
                    bottom of every auth card made each one look unfinished.
                -->
                <div v-if="$slots.footer" class="mt-5 text-center text-xs text-mb-text-muted dark:text-mb-text-soft">
                    <slot name="footer" />
                </div>
            </div>
        </main>
    </div>
</template>
