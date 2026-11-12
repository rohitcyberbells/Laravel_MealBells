<script setup>
import AppLayout from '../../../Layouts/AppLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    range: { type: Number, default: 14 },
    report: { type: Object, required: true },
});

const changeRange = (newRange) => {
    router.get('/company-admin/reports/attendance', { range: newRange }, { preserveState: true });
};
</script>

<template>
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-mb-text-strong tracking-tight">🕒 Attendance — what it would have changed</h1>
                    <p class="text-sm text-mb-text-muted">
                        {{ report.from }} to {{ report.to }} · {{ report.timezone }}
                    </p>
                </div>

                <div class="flex gap-2">
                    <button
                        v-for="option in [7, 14, 30]"
                        :key="option"
                        @click="changeRange(option)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer',
                            range === option
                                ? 'bg-mb-tint-emerald/20 text-mb-emerald-soft border-mb-tint-emerald/40'
                                : 'bg-mb-card text-mb-text-muted border-mb-line hover:text-mb-text-strong',
                        ]"
                    >
                        {{ option }} days
                    </button>
                </div>
            </div>

            <!-- Said first and said plainly. A page full of absences is
                 otherwise easy to read as meals already withheld. -->
            <div class="bg-mb-tint-amber/10 border border-mb-tint-amber/40 rounded-xl p-5">
                <h2 class="font-bold text-mb-amber-soft">Nothing here changed a single meal</h2>
                <p class="text-xs text-mb-amber-soft/80 mt-1">
                    Attendance is being read and recorded only. No skip was created, no count was altered, and the
                    kitchen received exactly what it always receives. This page exists to answer one question before
                    anything is switched on: <span class="font-semibold">how many meals would we not have ordered?</span>
                </p>
            </div>

            <div v-if="!report.enabled" class="bg-mb-card border border-mb-line rounded-xl p-5">
                <p class="text-sm text-mb-text-soft font-semibold">Attendance is not switched on for this company.</p>
                <p class="text-xs text-mb-text-faint mt-1">
                    Nothing is being read, so this page will stay empty. Your platform administrator turns it on.
                </p>
            </div>

            <!-- Said before the number it qualifies. A figure that silently
                 includes people who were on approved leave would make the case
                 for acting on attendance look better than it is. -->
            <div v-if="report.is_upper_bound" class="bg-mb-card border border-mb-tint-amber/40 rounded-xl p-5">
                <h2 class="font-bold text-mb-amber-soft">This is an upper bound, not the saving</h2>
                <p class="text-xs text-mb-text-muted mt-1">
                    {{ report.days_without_leave_data }} of the
                    {{ report.days_read }} {{ report.days_read === 1 ? 'day' : 'days' }} read
                    {{ report.days_without_leave_data === 1 ? 'is from' : 'are from' }}
                    before MealBells held any leave records for your company, so anyone who was on approved leave or
                    working from home on those days is counted here as absent. The real saving is lower &mdash;
                    possibly much lower.
                </p>
                <p v-if="report.first_known_skip_date" class="text-xs text-mb-text-faint mt-2">
                    Leave and WFH are accounted for from
                    <span class="font-mono text-mb-text-muted">{{ report.first_known_skip_date }}</span> onwards. Days on or
                    after that date are marked <span class="text-mb-emerald">leave known</span> in the table below;
                    judge the number on those.
                </p>
            </div>

            <!-- The figure the decision turns on -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-mb-card border border-mb-line rounded-xl p-5">
                    <p class="text-[11px] uppercase font-semibold text-mb-text-faint">
                        Meals we would not have ordered
                        <span v-if="report.is_upper_bound" class="text-mb-amber">(at most)</span>
                    </p>
                    <p class="text-3xl font-bold text-mb-text-strong mt-1">
                        {{ report.average_per_day ?? '—' }}
                        <span class="text-sm font-normal text-mb-text-muted">per day</span>
                    </p>
                    <p class="text-xs text-mb-text-faint mt-1">
                        {{ report.total_would_be_absent }} in total, across {{ report.days_read }}
                        {{ report.days_read === 1 ? 'day' : 'days' }} actually read
                    </p>
                </div>

                <div class="bg-mb-card border border-mb-line rounded-xl p-5">
                    <p class="text-[11px] uppercase font-semibold text-mb-text-faint">Employees read</p>
                    <p class="text-3xl font-bold text-mb-text-strong mt-1">
                        {{ report.employees_read }}
                        <span class="text-sm font-normal text-mb-text-muted">of {{ report.eligible_employees }}</span>
                    </p>
                    <!-- A feature running on an unknown fraction of the
                         workforce is worse than one switched off. -->
                    <p
                        v-if="report.employees_read < report.eligible_employees"
                        class="text-xs text-mb-amber mt-1"
                    >
                        {{ report.eligible_employees - report.employees_read }} eligible
                        {{ report.eligible_employees - report.employees_read === 1 ? 'employee is' : 'employees are' }}
                        not being read — their attendance is kept elsewhere
                    </p>
                    <p v-else class="text-xs text-mb-text-faint mt-1">Everyone eligible is being read</p>
                </div>

                <div class="bg-mb-card border border-mb-line rounded-xl p-5">
                    <p class="text-[11px] uppercase font-semibold text-mb-text-faint">Days covered</p>
                    <p class="text-3xl font-bold text-mb-text-strong mt-1">
                        {{ report.days_read }}
                        <span class="text-sm font-normal text-mb-text-muted">of {{ report.meal_days }} meal days</span>
                    </p>
                    <p v-if="report.days_read < report.meal_days" class="text-xs text-mb-text-faint mt-1">
                        A day with no reading is left out of the average rather than counted as zero
                    </p>
                    <p v-else class="text-xs text-mb-text-faint mt-1">Every meal day in this range was read</p>
                </div>
            </div>

            <div class="bg-mb-card border border-mb-line rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-mb-inset/60 text-[11px] uppercase text-mb-text-faint">
                        <tr>
                            <th class="text-left py-3 px-4">Day</th>
                            <th class="text-right py-3 px-4">Clocked in</th>
                            <th class="text-right py-3 px-4">Absent</th>
                            <th class="text-right py-3 px-4" title="The HR system had no answer for these people">Unknown</th>
                            <th class="text-right py-3 px-4" title="Already on leave, WFH, a recurring rule, or entered by hand">Already not eating</th>
                            <th class="text-right py-3 px-4">Would not have ordered</th>
                            <th class="text-right py-3 px-4" title="Whether MealBells held leave and WFH records for this date">Leave known</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-mb-line">
                        <tr v-for="day in report.days" :key="day.date" class="hover:bg-mb-inset/40">
                            <td class="py-3 px-4">
                                <span class="font-mono text-mb-text-soft">{{ day.date }}</span>
                                <span class="text-mb-text-faint ml-2">{{ day.day_name }}</span>
                            </td>

                            <template v-if="day.read">
                                <td class="text-right py-3 px-4 text-mb-text-soft">{{ day.present }}</td>
                                <td class="text-right py-3 px-4 text-mb-text-soft">{{ day.absent }}</td>
                                <td class="text-right py-3 px-4" :class="day.unknown > 0 ? 'text-mb-amber' : 'text-mb-text-faint'">
                                    {{ day.unknown }}
                                </td>
                                <td class="text-right py-3 px-4 text-mb-text-faint">{{ day.already_not_eating }}</td>
                                <td class="text-right py-3 px-4 font-bold" :class="day.would_be_absent > 0 ? 'text-mb-text-strong' : 'text-mb-text-faint'">
                                    {{ day.would_be_absent }}
                                    <span v-if="!day.leave_known" class="text-mb-amber font-normal" title="Counts people who may have been on leave">↑</span>
                                </td>
                                <td class="text-right py-3 px-4 text-xs" :class="day.leave_known ? 'text-mb-emerald' : 'text-mb-amber'">
                                    {{ day.leave_known ? 'yes' : 'no' }}
                                </td>
                            </template>

                            <td v-else colspan="6" class="text-right py-3 px-4 text-xs text-mb-text-ghost italic">
                                not read — a failed or suspicious reading records nothing rather than guessing
                            </td>
                        </tr>

                        <tr v-if="!report.days.length">
                            <td colspan="7" class="py-10 text-center text-sm text-mb-text-faint">
                                No meal days in this range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-mb-card border border-mb-line rounded-xl p-5 space-y-2 text-xs text-mb-text-faint">
                <p class="text-mb-text-muted font-semibold">How to read this</p>
                <p>
                    <span class="text-mb-text-soft">Absent</span> means the HR system had no clock-in for that person by
                    your cutoff. <span class="text-mb-text-soft">Unknown</span> means it gave no usable answer at all —
                    those people are treated as present, so nobody loses a meal to a missing record.
                </p>
                <p>
                    <span class="text-mb-text-soft">Would not have ordered</span> excludes anyone already not eating for
                    another reason, so somebody on approved leave is counted once, not twice.
                </p>
                <p>
                    A day where more than 40% looked absent is recorded as not read. That much of a company is rarely
                    away; the HR system is more likely to have been having a bad morning, and a wasted meal costs less
                    than somebody going without lunch.
                </p>
                <p>
                    <span class="text-mb-text-soft">Leave known</span> says whether MealBells held leave and WFH records
                    for that date. Where it does not, the day's figure counts people who may well have been away, so it
                    is a ceiling rather than a saving. Marked <span class="text-mb-amber">&uarr;</span> in the table.
                </p>
                <p>
                    Employee headcount is today's, not a reconstruction of each past day — so a range spanning a
                    joiner or a leaver is approximate.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
