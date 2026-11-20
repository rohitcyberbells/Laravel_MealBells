<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A select whose dropped-down list we actually control.
 *
 * A native <select> cannot be styled past its closed state: the list the
 * browser opens is drawn by the operating system, so on a Mac it arrives with
 * a blue highlight and a tick in a font and padding of its own, over an app
 * that is emerald and slate. color-scheme gets its background right and
 * nothing else.
 *
 * So the list is ours: a button, a listbox, and the keyboard behaviour a
 * native select would have given for free - arrows to move, Enter to choose,
 * Escape to close, Home and End to jump, and the list closing on a click
 * elsewhere.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    /** @type {{ value: string|number, label: string }[]} */
    options: { type: Array, required: true },
    id: { type: String, default: null },
    placeholder: { type: String, default: 'Select' },
    ariaLabel: { type: String, default: null },
    /**
     * Matches the field sizing of whatever row the select sits in: 'md' is the
     * app's usual 40px field, 'lg' the taller one the employee screens use.
     */
    size: { type: String, default: 'md' },
});

const triggerSize = computed(() => (
    props.size === 'lg' ? 'h-11 rounded-xl' : 'h-10 rounded-lg'
));

const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const active = ref(-1);
const root = ref(null);
const list = ref(null);

const selectedIndex = computed(() => props.options.findIndex((option) => option.value === props.modelValue));

const selectedLabel = computed(() => props.options[selectedIndex.value]?.label ?? props.placeholder);

const listId = computed(() => `${props.id ?? 'field'}-listbox`);

const optionId = (index) => `${listId.value}-${index}`;

// block: 'nearest' so choosing with the keyboard never jumps the list when the
// option is already on screen.
const revealActive = () => list.value?.children[active.value]?.scrollIntoView({ block: 'nearest' });

const openList = async () => {
    open.value = true;
    active.value = selectedIndex.value >= 0 ? selectedIndex.value : 0;
    await nextTick();
    revealActive();
};

const close = () => {
    open.value = false;
};

const choose = (index) => {
    const option = props.options[index];

    if (! option) {
        return;
    }

    emit('update:modelValue', option.value);
    close();
};

const move = async (delta) => {
    if (! open.value) {
        await openList();

        return;
    }

    const count = props.options.length;
    active.value = (active.value + delta + count) % count;
    revealActive();
};

const jumpTo = async (index) => {
    if (! open.value) {
        await openList();
    }

    active.value = index;
    revealActive();
};

const onKeydown = (event) => {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Home':
            event.preventDefault();
            jumpTo(0);
            break;
        case 'End':
            event.preventDefault();
            jumpTo(props.options.length - 1);
            break;
        case 'Enter':
        case ' ':
            event.preventDefault();
            open.value ? choose(active.value) : openList();
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                close();
            }
            break;
        case 'Tab':
            close();
            break;
    }
};

// Pointer down rather than click, so the list is gone before whatever was
// clicked underneath reacts.
const onPointerDown = (event) => {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        close();
    }
};

onMounted(() => document.addEventListener('pointerdown', onPointerDown));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onPointerDown));
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <button
            :id="id"
            type="button"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
            :aria-label="ariaLabel"
            class="w-full flex items-center justify-between gap-2 pl-3 pr-2.5 bg-mb-inset border border-mb-field text-sm text-mb-text-strong cursor-pointer transition-colors focus:outline-none focus:border-mb-tint-emerald"
            :class="triggerSize"
            @click="open ? close() : openList()"
        >
            <span class="truncate" :class="selectedIndex < 0 ? 'text-mb-text-muted dark:text-mb-text-soft' : ''">
                {{ selectedLabel }}
            </span>
            <svg
                class="w-3.5 h-3.5 shrink-0 text-mb-text-muted dark:text-mb-text-soft transition-transform"
                :class="open ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <ul
            v-if="open"
            :id="listId"
            ref="list"
            role="listbox"
            :aria-label="ariaLabel"
            class="absolute z-30 mt-1 w-full max-h-60 overflow-y-auto py-1 rounded-lg bg-mb-card border border-mb-line shadow-lg shadow-black/10"
        >
            <li
                v-for="(option, index) in options"
                :id="optionId(index)"
                :key="String(option.value)"
                role="option"
                :aria-selected="option.value === modelValue"
                :class="[
                    'flex items-center justify-between gap-2 px-3 py-2 text-sm cursor-pointer',
                    index === active ? 'bg-mb-raised' : '',
                    option.value === modelValue ? 'font-semibold text-mb-emerald' : 'text-mb-text-soft',
                ]"
                @mouseenter="active = index"
                @click="choose(index)"
            >
                <span class="truncate">{{ option.label }}</span>
                <svg
                    v-if="option.value === modelValue"
                    class="w-3.5 h-3.5 shrink-0"
                    fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9.5 18 20 7" />
                </svg>
            </li>
        </ul>
    </div>
</template>
