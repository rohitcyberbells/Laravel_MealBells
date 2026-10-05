<script setup>
defineProps({
    date: String,
    is_meal_day: Boolean,
    count: Object,
    status: String,
    cutoff_time: String,
    seconds_left: Number,
    skips: Array,
    extra_meals: Array,
    employees_for_search: Array,
});
</script>

<template>
    <div class="p-6">
        <h1 class="text-xl font-bold">Company Daily Count Page</h1>
    </div>
</template>
