<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import CompanyAdminLayout from '../../../Layouts/CompanyAdminLayout.vue';

const props = defineProps({
    month: String,
    days: Array,
    meal_days: Array,
});

const form = useForm({
    date: '',
    type: 'holiday',
    note: '',
});

const selectedMonth = ref(props.month);

const weekdaysMap = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};

const changeMonth = () => {
    router.get('/company-admin/calendar', { month: selectedMonth.value }, { preserveState: true });
};

const saveDay = () => {
    form.post('/company-admin/calendar', {
        onSuccess: () => {
            form.reset('date', 'note');
        },
    });
};

const removeDay = (dayId) => {
    router.delete(`/company-admin/calendar/${dayId}`);
};
</script>

<template>
    <CompanyAdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
                <div>
                    <h1 class="text-2xl font-black text-white flex items-center gap-2">
                        📅 Company Calendar & Holidays
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Override default working days with company holidays or compensatory working days
                    </p>
                </div>

                <div class="flex items-center space-x-3">
                    <label class="text-xs font-semibold text-slate-400">Select Month:</label>
                    <input
                        v-model="selectedMonth"
                        type="month"
                        @change="changeMonth"
                        class="px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                    />
                </div>
            </div>

            <!-- Set Calendar Day Form Card -->
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-slate-200">➕ Add / Update Calendar Override</h3>
                <form @submit.prevent="saveDay" class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Date</label>
                        <input
                            v-model="form.date"
                            type="date"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Type</label>
                        <select
                            v-model="form.type"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        >
                            <option value="holiday">🔴 Company Holiday</option>
                            <option value="working_day">🟢 Working Day (Compensatory)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 mb-1">Note / Reason</label>
                        <input
                            v-model="form.note"
                            type="text"
                            placeholder="e.g. Diwali, Independence Day"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:outline-none focus:border-cyan-500"
                        />
                    </div>
                    <div>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-bold text-xs rounded-xl shadow-md transition disabled:opacity-50 cursor-pointer"
                        >
                            Save Override
                        </button>
                    </div>
                </form>
            </div>

            <!-- Overrides Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <div class="p-4 border-b border-slate-800 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-white">Active Calendar Overrides for {{ month }}</h3>
                    <span class="text-xs text-slate-400">{{ days.length }} Overrides</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-semibold">
                            <tr>
                                <th class="p-3">Date</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Note</th>
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="day in days" :key="day.id" class="hover:bg-slate-800/40">
                                <td class="p-3 font-bold font-mono text-cyan-400">{{ day.date }}</td>
                                <td class="p-3">
                                    <span :class="[
                                        'px-2.5 py-1 rounded-full text-[10px] font-bold border',
                                        day.type === 'holiday' ? 'bg-red-500/20 text-red-300 border-red-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                                    ]">
                                        {{ day.type === 'holiday' ? '🔴 Holiday' : '🟢 Special Working Day' }}
                                    </span>
                                </td>
                                <td class="p-3 text-slate-300">{{ day.note || '-' }}</td>
                                <td class="p-3 text-right">
                                    <button
                                        @click="removeDay(day.id)"
                                        class="text-red-400 hover:text-red-300 font-semibold cursor-pointer"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!days || !days.length">
                                <td colspan="4" class="p-8 text-center text-slate-500">
                                    No custom calendar overrides configured for this month.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </CompanyAdminLayout>
</template>
