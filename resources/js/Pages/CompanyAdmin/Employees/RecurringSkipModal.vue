<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    show: Boolean,
    employee: { type: Object, default: null },
    rules: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const WEEKDAYS = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};

const form = useForm({
    weekday: 5,
    starts_on: new Date().toISOString().slice(0, 10),
    ends_on: '',
});

// A rule carries no reason of its own, so the list is only legible if it says
// which rules are live right now rather than merely stored.
const isRunning = (rule) => {
    if (!rule.active) return false;
    const today = new Date().toISOString().slice(0, 10);
    if (rule.starts_on > today) return false;
    return !rule.ends_on || rule.ends_on >= today;
};

const sortedRules = computed(() => [...props.rules].sort((a, b) => a.weekday - b.weekday));

watch(() => props.show, (open) => {
    if (open) {
        form.clearErrors();
        form.ends_on = '';
    }
});

const basePath = computed(() => `/company-admin/employees/${props.employee?.id}/recurring-skips`);

const submit = () => {
    form.transform((data) => ({ ...data, ends_on: data.ends_on || null }))
        .post(basePath.value, {
            preserveScroll: true,
            onSuccess: () => form.reset('ends_on'),
        });
};

// The state is sent explicitly: the endpoint requires it so that a double
// submit cannot switch a rule back on and quietly resume skipping meals.
const togglePause = (rule) => {
    router.patch(`${basePath.value}/${rule.id}`, { active: !rule.active }, { preserveScroll: true });
};

const remove = (rule) => {
    if (!confirm(`Delete the "every ${WEEKDAYS[rule.weekday]}" rule for ${props.employee?.name}? Future skips it created are cancelled.`)) {
        return;
    }
    router.delete(`${basePath.value}/${rule.id}`, { preserveScroll: true });
};
</script>

<template>
    <div v-if="show && employee" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
        <div class="w-full max-w-2xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-4 p-6 border-b border-slate-800">
                <div>
                    <h2 class="text-lg font-bold text-white">🔄 Recurring skips</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ employee.name }}
                        <span class="font-mono text-cyan-400">{{ employee.employee_code }}</span>
                    </p>
                </div>
                <button @click="emit('close')" class="text-slate-400 hover:text-white text-xl leading-none cursor-pointer" aria-label="Close">×</button>
            </div>

            <div class="p-6 space-y-6">
                <p class="text-xs text-slate-400">
                    A rule skips this employee's meal every week on the chosen day. Skips are generated a
                    few days ahead, so a change takes effect from the next generated day onward — it does
                    not reopen a count that has already locked.
                </p>

                <form @submit.prevent="submit" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end bg-slate-950 p-4 rounded-xl border border-slate-800">
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Weekday</label>
                        <select v-model.number="form.weekday" class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500 cursor-pointer">
                            <option v-for="(label, num) in WEEKDAYS" :key="num" :value="Number(num)">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Starts on</label>
                        <input v-model="form.starts_on" type="date" required class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" />
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase font-semibold text-slate-400 mb-1">Ends on</label>
                        <input v-model="form.ends_on" type="date" class="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500" />
                        <p class="text-[10px] text-slate-500 mt-1">Leave blank for no end.</p>
                    </div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full px-4 py-2 bg-amber-500 hover:bg-amber-400 disabled:opacity-40 text-slate-950 font-bold text-xs rounded-xl transition cursor-pointer"
                    >
                        Add rule
                    </button>
                </form>

                <p v-if="form.errors.recurring" class="text-xs text-red-400">{{ form.errors.recurring }}</p>
                <p v-if="form.errors.weekday" class="text-xs text-red-400">{{ form.errors.weekday }}</p>
                <p v-if="form.errors.starts_on" class="text-xs text-red-400">{{ form.errors.starts_on }}</p>
                <p v-if="form.errors.ends_on" class="text-xs text-red-400">{{ form.errors.ends_on }}</p>

                <div class="space-y-3">
                    <div
                        v-for="rule in sortedRules"
                        :key="rule.id"
                        class="flex items-center justify-between gap-3 p-3.5 bg-slate-950 border border-slate-800 rounded-xl"
                    >
                        <div>
                            <p class="font-bold text-white text-sm">Every {{ WEEKDAYS[rule.weekday] }}</p>
                            <p class="text-[11px] text-slate-400">
                                From {{ rule.starts_on }}
                                <span v-if="rule.ends_on">until {{ rule.ends_on }}</span>
                                <span v-else>onwards</span>
                                <span v-if="rule.active && !isRunning(rule)" class="text-slate-500"> — not in effect today</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button
                                @click="togglePause(rule)"
                                :title="rule.active ? 'Pause this rule' : 'Resume this rule'"
                                :class="[
                                    'px-3 py-1 rounded-lg text-xs font-semibold border cursor-pointer',
                                    rule.active
                                        ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                                        : 'bg-slate-800 text-slate-400 border-slate-700'
                                ]"
                            >
                                {{ rule.active ? 'Active' : 'Paused' }}
                            </button>
                            <button
                                @click="remove(rule)"
                                class="px-3 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-lg text-xs font-semibold cursor-pointer"
                            >
                                Delete
                            </button>
                        </div>
                    </div>

                    <p v-if="!sortedRules.length" class="text-xs text-slate-500 italic">
                        No recurring skip rules for this employee.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
