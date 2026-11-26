<script setup>
/**
 * A labelled text input in the app's field language, sibling to SelectField.
 *
 * The four auth pages each wrote their own label, input, hint and error markup,
 * which is how they drifted: the same field was 40px on one screen and 38px on
 * another, and two of them had labels the app had stopped using.
 */
defineProps({
    modelValue: { type: [String, Number], default: '' },
    id: { type: String, required: true },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    autocomplete: { type: String, default: null },
    inputmode: { type: String, default: null },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
    /** Shown under the field in muted text, before any error. */
    hint: { type: String, default: null },
    error: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label :for="id" class="block text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft mb-1.5">
            {{ label }}
        </label>

        <input
            :id="id"
            :value="modelValue"
            :type="type"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :placeholder="placeholder"
            :required="required"
            :autofocus="autofocus"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id}-error` : (hint ? `${id}-hint` : undefined)"
            class="w-full h-11 px-3.5 rounded-xl bg-mb-inset border text-sm text-mb-text-strong placeholder:text-mb-text-faint transition-colors focus:outline-none focus:border-mb-tint-emerald"
            :class="error ? 'border-mb-tint-rose' : 'border-mb-field'"
            @input="$emit('update:modelValue', $event.target.value)"
        />

        <p v-if="hint && ! error" :id="`${id}-hint`" class="mt-1.5 text-[11px] text-mb-text-muted dark:text-mb-text-soft">
            {{ hint }}
        </p>
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-xs font-medium text-mb-rose">
            {{ error }}
        </p>
    </div>
</template>
