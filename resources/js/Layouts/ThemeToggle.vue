<script setup>
import { setTheme, theme } from '../theme.js';

// compact drops the words and keeps the icons, for the mobile bar where there
// is no room for "Day" and "Night" beside the brand.
defineProps({
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div
        role="group"
        aria-label="Theme"
        class="inline-flex gap-1 p-1 rounded-full bg-mb-card border border-mb-line"
    >
        <button
            type="button"
            :aria-pressed="theme === 'light'"
            :aria-label="compact ? 'Day' : undefined"
            :title="compact ? 'Day' : undefined"
            @click="setTheme('light')"
            :class="[
                'inline-flex items-center justify-center gap-1.5 min-h-9 rounded-full text-[13px] font-semibold border cursor-pointer transition-colors',
                compact ? 'w-9' : 'px-3.5',
                theme === 'light'
                    ? 'bg-mb-tint-amber/10 border-mb-tint-amber/40 text-mb-amber'
                    : 'border-transparent text-mb-text-muted hover:text-mb-text'
            ]"
        >
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4" />
                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            </svg>
            <span v-if="!compact">Day</span>
        </button>
        <button
            type="button"
            :aria-pressed="theme === 'dark'"
            :aria-label="compact ? 'Night' : undefined"
            :title="compact ? 'Night' : undefined"
            @click="setTheme('dark')"
            :class="[
                'inline-flex items-center justify-center gap-1.5 min-h-9 rounded-full text-[13px] font-semibold border cursor-pointer transition-colors',
                compact ? 'w-9' : 'px-3.5',
                theme === 'dark'
                    ? 'bg-mb-tint-slate/40 border-mb-line-strong text-mb-cyan'
                    : 'border-transparent text-mb-text-muted hover:text-mb-text'
            ]"
        >
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
            </svg>
            <span v-if="!compact">Night</span>
        </button>
    </div>
</template>
