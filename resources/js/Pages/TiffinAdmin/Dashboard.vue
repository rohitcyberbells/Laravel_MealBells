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
    <div class="relative z-10 p-4 sm:p-6 space-y-5">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-mb-text-strong">
                    {{ tiffinService?.name || 'Tiffin Service' }}
                </h1>
                <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                    Today's meal and the week ahead.
                </p>
            </div>

            <span v-if="assignedCompany" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-emerald whitespace-nowrap">
                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                Cooking for {{ assignedCompany.name }}
            </span>
            <span v-else class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber whitespace-nowrap">
                <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                Not assigned yet
            </span>
        </header>

        <main class="space-y-5">
            <!--
                The exception path, so it is deliberately quieter than the menu
                builder below: it is only used on a day the kitchen could not
                cook what was published.
            -->
            <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-mb-text-strong">Today's meal override</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Only if today's actual meal changed — ingredients, availability.
                        </p>
                    </div>
                    <span v-if="todayOverride" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber whitespace-nowrap">
                        <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                        Active today
                    </span>
                </div>

                <form @submit.prevent="submitOverride" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                    <div>
                        <label for="override-meal" class="block text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft mb-1.5">
                            Today's actual meal
                        </label>
                        <input
                            id="override-meal"
                            v-model="overrideForm.meal_description"
                            placeholder="e.g. Paneer Butter Masala + Roti + Rice"
                            required
                            class="w-full h-10 px-3 bg-mb-card border border-mb-field rounded-lg text-sm text-mb-text-strong placeholder:text-mb-text-muted focus:border-mb-tint-emerald focus:outline-none"
                        />
                    </div>
                    <div>
                        <label for="override-reason" class="block text-[11px] font-semibold uppercase tracking-[0.08em] text-mb-text-muted dark:text-mb-text-soft mb-1.5">
                            Reason <span class="font-normal normal-case tracking-normal">(optional)</span>
                        </label>
                        <input
                            id="override-reason"
                            v-model="overrideForm.reason"
                            placeholder="e.g. Fresh paneer arrived early"
                            class="w-full h-10 px-3 bg-mb-card border border-mb-field rounded-lg text-sm text-mb-text-strong placeholder:text-mb-text-muted focus:border-mb-tint-emerald focus:outline-none"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="overrideForm.processing"
                        class="h-10 px-4 rounded-lg text-sm font-semibold whitespace-nowrap bg-mb-fill-emerald text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 transition hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {{ overrideForm.processing ? 'Posting…' : "Post today's override" }}
                    </button>
                </form>
            </section>

            <!--
                Five days read down the page rather than across a three-column
                grid, which left Thursday and Friday on a short second row with
                a hole beside them. Each day is a label and a field, not a card
                holding a card.
            -->
            <section class="bg-mb-card/70 backdrop-blur-xl border border-mb-line rounded-2xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-mb-text-strong">Weekly menu</h2>
                        <p class="text-xs text-mb-text-muted dark:text-mb-text-soft mt-0.5">
                            Week of {{ weekStartDate }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <span v-if="menuForm.status === 'published'" class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-emerald whitespace-nowrap">
                            <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-emerald" aria-hidden="true"></span>
                            Published
                        </span>
                        <span v-else class="inline-flex items-center gap-1.5 text-xs font-semibold text-mb-amber whitespace-nowrap">
                            <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-mb-amber" aria-hidden="true"></span>
                            Draft — not visible to employees
                        </span>

                        <button
                            @click="saveMenu('draft')"
                            :disabled="menuForm.processing"
                            class="h-10 px-4 rounded-lg text-sm font-semibold bg-transparent text-mb-text border border-mb-field transition hover:bg-mb-raised disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Save draft
                        </button>
                        <button
                            @click="saveMenu('published')"
                            :disabled="menuForm.processing"
                            class="h-10 px-4 rounded-lg text-sm font-semibold bg-mb-fill-emerald text-mb-on-fill border border-transparent dark:border-mb-tint-emerald/50 transition hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Publish menu
                        </button>
                    </div>
                </div>

                <div class="divide-y divide-mb-line/60">
                    <div
                        v-for="(item, index) in menuForm.items"
                        :key="item.day_of_week"
                        class="py-4 first:pt-0 last:pb-0 grid grid-cols-1 sm:grid-cols-[7rem_1fr] gap-2 sm:gap-4 sm:items-start"
                    >
                        <label
                            :for="`menu-day-${index}`"
                            class="text-sm font-medium text-mb-text-strong sm:pt-2.5"
                        >
                            {{ item.day_of_week }}
                        </label>
                        <textarea
                            :id="`menu-day-${index}`"
                            v-model="item.meal_description"
                            rows="2"
                            placeholder="e.g. Rajma Chawal + Salad + Gulab Jamun"
                            class="w-full px-3 py-2 bg-mb-card border border-mb-field rounded-lg text-sm text-mb-text-strong placeholder:text-mb-text-muted resize-y focus:border-mb-tint-emerald focus:outline-none"
                        ></textarea>
                    </div>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>
