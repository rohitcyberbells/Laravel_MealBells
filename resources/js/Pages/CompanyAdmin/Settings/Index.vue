<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
defineProps({
    cutoff_time: String,
    timezone: String,
    wfh_auto_skip: Boolean,
    meal_days: Array,
    primary_admin_id: Number,
    backup_admin_id: Number,
    company_admins: Array,
});
</script>

<template>
    <AppLayout>
        <div class="p-6">
        <h1 class="text-xl font-bold">Company Settings Page</h1>
        </div>
    </AppLayout>
</template>
