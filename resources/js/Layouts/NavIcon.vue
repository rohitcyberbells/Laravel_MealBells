<script setup>
import { computed } from 'vue';

/**
 * The sidebar's icons, as stroke paths rather than emoji.
 *
 * Emoji are drawn by the operating system, so the menu looked different on
 * every machine and could not take the active item's colour. These inherit
 * currentColor and sit on the same 24x24 grid as the icons the pages use.
 *
 * config/navigation.php names one of these; an unknown name falls back to a
 * dot, so adding a menu line never renders an empty box.
 */
const ICONS = {
    chart: ['M3 21h18', 'M7 21V11', 'M12 21V5', 'M17 21v-7'],
    bowl: ['M3 11h18', 'M5 11a7 7 0 0 1 14 0', 'M4 15h16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z'],
    users: [
        'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1',
        'M12 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'M22 19v-1a4 4 0 0 0-3-3.9',
        'M16 4.1a3 3 0 0 1 0 5.8',
    ],
    calendar: ['M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z', 'M8 2v4M16 2v4M4 10h16'],
    trending: ['M22 7l-8.5 8.5-5-5L2 17', 'M16 7h6v6'],
    clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 7v5l3 2'],
    link: [
        'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7',
        'M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    ],
    settings: ['M4 6h10M18 6h2M4 12h2M10 12h10M4 18h10M18 18h2', 'M16 4v4M8 10v4M16 16v4'],
    building: ['M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16', 'M15 21V11h3a2 2 0 0 1 2 2v8', 'M3 21h18', 'M8 7h3M8 11h3M8 15h3'],
    pulse: ['M3 12h4l2-6 4 12 2-6h6'],
    clipboard: [
        'M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2',
        'M9 2h6v4H9z',
        'M9 11h6M9 15h4',
    ],
    pot: ['M4 9h16v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4Z', 'M2 9h20', 'M8 5V3M12 5V3M16 5V3'],
    plate: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z'],
    dot: ['M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z'],
};

const props = defineProps({
    name: { type: String, default: 'dot' },
});

const paths = computed(() => ICONS[props.name] ?? ICONS.dot);
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path v-for="(d, index) in paths" :key="index" :d="d" />
    </svg>
</template>
