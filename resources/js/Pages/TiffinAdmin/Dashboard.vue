<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref } from 'vue';
import { useForm, router} from '@inertiajs/vue3';

const props = defineProps({
    tiffinService: Object,
    assignedCompany: Object,
    weeklyMenu: Object,
    weekStartDate: String,
    todayOverride: Object,
});

const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Prepare weekly items map
const getExistingMeal = (day) => {
    if (!props.weeklyMenu?.items) return '';
    const item = props.weeklyMenu.items.find(i => i.day_of_week.toLowerCase() === day.toLowerCase());
    return item ? item.meal_description : '';
};
const menuForm = useForm({
    week_start_date: props.weekStartDate,
    status: props.weeklyMenu?.status || 'draft',
    items: daysOfWeek.map(day => ({
        day_of_week: day,
        meal_description: getExistingMeal(day),
    })),
});
const overrideForm = useForm({
    meal_description: props.todayOverride?.meal_description || '',
    reason: props.todayOverride?.reason || '',
});
const saveMenu = (statusType) => {
    menuForm.status = statusType;
    menuForm.post('/tiffin-admin/weekly-menu');
};
const submitOverride = () => {
    overrideForm.post('/tiffin-admin/daily-override');
};
</script>
<template>
    <AppLayout>
    <div class="p-6">
        <div class="max-w-7xl mx-auto pb-6 border-b border-mb-line mb-8">
            <div>
                <div class="flex items-center space-x-3">
                    <h1 class="text-3xl font-bold text-mb-amber">{{ tiffinService?.name || 'Tiffin Service' }}</h1>
                    <span v-if="assignedCompany" class="bg-mb-tint-emerald/20 text-mb-emerald-soft border border-mb-tint-emerald/30 text-xs px-3 py-1 rounded-full font-semibold">
                        Assigned to: {{ assignedCompany.name }}
                    </span>
                    <span v-else class="bg-mb-tint-amber/20 text-mb-amber-soft border border-mb-tint-amber/30 text-xs px-3 py-1 rounded-full font-semibold">
                        Not Assigned Yet
                    </span>
                </div>
                <p class="text-mb-text-muted text-sm mt-1">Kitchen Command & Menu Management</p>
            </div>
        </div>
        <main class="max-w-7xl mx-auto space-y-8">
            <!-- 🚨 Today's Meal Override Section -->
            <section class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-mb-amber-soft flex items-center gap-2">
                            🚨 Today's Meal Override
                        </h2>
                        <p class="text-mb-text-muted text-xs">Use this if today's actual meal changes due to kitchen ingredients or availability.</p>
                    </div>
                    <span v-if="todayOverride" class="bg-mb-tint-amber/20 text-mb-amber-soft border border-mb-tint-amber/40 text-xs font-semibold px-3 py-1 rounded-full">
                        Active Today
                    </span>
                </div>
                <form @submit.prevent="submitOverride" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs uppercase font-semibold text-mb-text-muted mb-1">Today's Actual Meal</label>
                        <input v-model="overrideForm.meal_description" placeholder="e.g. Paneer Butter Masala + Roti + Rice" required class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <div>
                        <label class="block text-xs uppercase font-semibold text-mb-text-muted mb-1">Reason (Optional)</label>
                        <input v-model="overrideForm.reason" placeholder="e.g. Fresh Paneer arrived early!" class="w-full bg-mb-inset border border-mb-line-mid rounded-lg px-3 py-2 text-sm text-mb-text-strong" />
                    </div>
                    <button type="submit" :disabled="overrideForm.processing" class="bg-mb-tint-amber hover:bg-mb-tint-amber font-bold text-mb-on-accent py-2 px-4 rounded-lg text-sm transition">
                        Post Today's Override
                    </button>
                </form>
            </section>
            <!-- 📅 Weekly Menu Builder -->
            <section class="bg-mb-card border border-mb-line rounded-xl p-6 shadow-xl">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-mb-text-strong">📅 Weekly Menu Builder</h2>
                        <p class="text-mb-text-muted text-xs">Week starting: <span class="text-mb-emerald font-semibold">{{ weekStartDate }}</span></p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <span v-if="menuForm.status === 'published'" class="bg-mb-tint-emerald/20 text-mb-emerald-soft border border-mb-tint-emerald/30 text-xs px-3 py-1 rounded-full font-semibold">
                            PUBLISHED
                        </span>
                        <span v-else class="bg-mb-tint-amber/20 text-mb-amber-soft border border-mb-tint-amber/30 text-xs px-3 py-1 rounded-full font-semibold">
                            DRAFT
                        </span>
                        <button @click="saveMenu('draft')" :disabled="menuForm.processing" class="bg-mb-raised hover:bg-mb-chip text-mb-text border border-mb-line-mid text-sm px-4 py-2 rounded-lg transition font-semibold">
                            Save Draft
                        </button>
                        <button @click="saveMenu('published')" :disabled="menuForm.processing" class="bg-mb-tint-emerald hover:bg-mb-tint-emerald text-mb-on-accent font-bold text-sm px-4 py-2 rounded-lg transition">
                            Publish Menu
                        </button>
                    </div>
                </div>
                <!-- 7 Day Meal Inputs -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="(item, index) in menuForm.items" :key="item.day_of_week" class="bg-mb-inset border border-mb-line rounded-lg p-4">
                        <label class="block text-xs uppercase font-bold text-mb-amber mb-2">{{ item.day_of_week }}</label>
                        <textarea v-model="item.meal_description" rows="3" placeholder="Enter meal details (e.g. Rajma Chawal + Salad + Gulab Jamun)" required class="w-full bg-mb-card border border-mb-line rounded-lg p-2 text-sm text-mb-text-strong focus:border-mb-tint-amber focus:outline-none"></textarea>
                    </div>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>








