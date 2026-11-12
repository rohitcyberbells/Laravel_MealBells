<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { theme } from '../../../theme.js';

const props = defineProps({
    company: Object,
    tiffinService: Object,
    todayStats: Object,
    todayMeal: Object,
    // Locked: what the kitchen was told. Open: the live estimate.
    expected: { type: Number, default: 0 },
});

const SKIP_LABELS = {
    leave: 'On leave',
    wfh: 'Working from home',
    hr: 'Marked by HR',
    self: 'Skipped themselves',
    link: 'Skipped by email link',
    recurring: 'Recurring skip',
};

const WEEKDAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// How long before the cutoff the ring starts filling. Only a visual scale.
const RING_WINDOW_MINUTES = 180;
const RING_CIRCUMFERENCE = 2 * Math.PI * 15;

const selected = ref(null);
const select = (name) => {
    selected.value = selected.value === name ? null : name;
};

const isMealDay = computed(() => props.todayStats.is_meal_day);
const isLocked = computed(() => props.todayStats.is_locked);

// A holiday closes the kitchen in both themes; the lit windows are the only
// thing that says otherwise.
const kitchenOpen = isMealDay;

// The van waits at the kitchen while the count can still move, and drives to
// the office once it has locked. 150 units along the road, projected.
const vanOffset = computed(() => (isLocked.value ? 'translate(130px, 75px)' : 'translate(0px, 0px)'));

const tiffinLabel = computed(() => `${props.expected} ${props.expected === 1 ? 'tiffin' : 'tiffins'}`);

const cutoffLabel = computed(() => (props.company?.setting?.cutoff_time || '11:00:00').slice(0, 5));
const timezone = computed(() => props.company?.setting?.timezone || 'Asia/Kolkata');

const now = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => {
        now.value = Date.now();
    }, 30000);
});
onBeforeUnmount(() => clearInterval(clock));

// In the company's timezone, not the browser's: the cutoff is a local time at
// the office, and an admin working from elsewhere would otherwise be told the
// wrong number of minutes.
const minutesNow = computed(() => {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone.value,
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(now.value));
    const pick = (type) => Number(parts.find((p) => p.type === type).value);

    return pick('hour') * 60 + pick('minute');
});

const minutesLeft = computed(() => {
    const [hour, minute] = cutoffLabel.value.split(':').map(Number);

    return hour * 60 + minute - minutesNow.value;
});

const timeLeftLabel = computed(() => {
    const hours = Math.floor(minutesLeft.value / 60);
    const minutes = minutesLeft.value % 60;

    return hours ? `${hours}h ${minutes}m left` : `${minutes} min left`;
});

const status = computed(() => {
    if (!isMealDay.value) {
        return { label: 'No meal today', detail: 'Kitchen closed', tone: 'idle', fill: 0 };
    }
    if (isLocked.value) {
        return { label: 'Locked · sent to kitchen', detail: `Locked at the ${cutoffLabel.value} cutoff`, tone: 'locked', fill: 1 };
    }
    if (minutesLeft.value > 0) {
        const fill = Math.min(1, Math.max(0, 1 - minutesLeft.value / RING_WINDOW_MINUTES));

        return { label: 'Open · still an estimate', detail: `Cutoff ${cutoffLabel.value} · ${timeLeftLabel.value}`, tone: 'open', fill };
    }

    // Amber, not green: the count should have locked by now, so this is worth
    // a second look (the scheduler is what locks it).
    return { label: 'Cutoff passed', detail: 'Waiting for the count to lock', tone: 'late', fill: 1 };
});

const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - status.value.fill));

// Parsed as a plain calendar date: `new Date('2026-10-08')` is UTC midnight,
// which in a behind-UTC timezone renders as the previous day.
const dateLabel = computed(() => {
    const [y, m, d] = props.todayStats.date.split('-').map(Number);

    return `${WEEKDAY[new Date(y, m - 1, d).getDay()]} ${d} ${MONTH[m - 1]}`;
});

const summary = computed(() => {
    const s = props.todayStats;
    if (!s.is_meal_day) {
        return `${dateLabel.value} · No meal today: it is not a meal day for your company.`;
    }
    if (s.is_locked) {
        return `${dateLabel.value} · ${props.expected} meals locked and sent to ${props.tiffinService.name}.`;
    }

    return `${dateLabel.value} · ${props.expected} meals expected (${s.base_eligible_count} eligible − ${s.skip_count} skips + ${s.extra_count} extra) · ${status.value.detail} · ${props.tiffinService.name}`;
});

const officeRows = computed(() => {
    const s = props.todayStats;
    const skips = Object.entries(s.breakdown ?? {})
        .filter(([, count]) => count > 0)
        .map(([source, count]) => ({ label: SKIP_LABELS[source] ?? source, value: `−${count}`, tone: 'text-mb-amber' }));

    return [
        { label: 'Eligible', value: String(s.base_eligible_count), tone: 'text-mb-text' },
        ...skips,
        ...(s.extra_count > 0 ? [{ label: 'Guests', value: `+${s.extra_count}`, tone: 'text-mb-cyan-soft' }] : []),
    ];
});

const panels = { kitchen: 'Kitchen', van: 'Van', office: 'Office' };

/*
 * The WebGL version of the drawing. The SVG above is the first paint, the
 * fallback, and what phones and reduced-motion users keep: three.js is only
 * fetched once all of those are ruled out, as its own chunk.
 */
const sceneHost = ref(null);
const sceneReady = ref(false);
let scene = null;
let unmounted = false;

const wants3d = () => {
    if (window.matchMedia('(max-width: 639px)').matches || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return false;
    }

    try {
        const probe = document.createElement('canvas');

        return Boolean(probe.getContext('webgl2') || probe.getContext('webgl'));
    } catch {
        return false;
    }
};

const sceneState = computed(() => ({
    isMealDay: isMealDay.value,
    isLocked: isLocked.value,
    tiffinLabel: tiffinLabel.value,
}));

onMounted(async () => {
    if (!wants3d()) {
        return;
    }

    try {
        const { createMealFlowScene } = await import('./mealFlowScene.js');
        if (unmounted || !sceneHost.value) {
            return;
        }
        scene = createMealFlowScene(sceneHost.value, {
            theme: theme.value,
            state: sceneState.value,
            selected: selected.value,
            onSelect: select,
        });
        sceneReady.value = true;
    } catch {
        // A failed chunk or a lost context leaves the SVG, which already
        // shows everything the scene would.
        scene?.dispose();
        scene = null;
    }
});

onBeforeUnmount(() => {
    unmounted = true;
    scene?.dispose();
    scene = null;
});

watch(theme, (next) => scene?.setTheme(next));
watch(selected, (next) => scene?.setSelected(next));
watch(sceneState, (next) => scene?.setState(next));
</script>

<template>
    <div class="space-y-2.5">
        <section
            aria-label="Today's meal flow"
            class="relative h-64 sm:h-auto sm:aspect-[1100/560] sm:max-h-[72vh] rounded-xl overflow-hidden border border-mb-line bg-(--mb-s-bg) transition-colors duration-[600ms]"
        >
            <!-- Decorative: everything it shows is also in the status card,
                 the inspect buttons and the summary line below. -->
            <div ref="sceneHost" class="absolute inset-0" aria-hidden="true"></div>
            <svg v-if="!sceneReady" viewBox="0 0 1100 340" preserveAspectRatio="xMidYMid slice" aria-hidden="true" class="mb-scene absolute inset-0 w-full h-full">
<g class="stroke-(--mb-s-grid)" stroke-width="1"><line x1="128.8" y1="-492" x2="1601" y2="358" /><line x1="68.1" y1="-457" x2="1540.3" y2="393" /><line x1="7.5" y1="-422" x2="1479.7" y2="428" /><line x1="-53.1" y1="-387" x2="1419.1" y2="463" /><line x1="-113.7" y1="-352" x2="1358.5" y2="498" /><line x1="-174.3" y1="-317" x2="1297.9" y2="533" /><line x1="-235" y1="-282" x2="1237.2" y2="568" /><line x1="-295.6" y1="-247" x2="1176.6" y2="603" /><line x1="-356.2" y1="-212" x2="1116" y2="638" /><line x1="-416.8" y1="-177" x2="1055.4" y2="673" /><line x1="-477.4" y1="-142" x2="994.8" y2="708" /><line x1="-538.1" y1="-107" x2="934.1" y2="743" /><line x1="-598.7" y1="-72" x2="873.5" y2="778" /><line x1="-659.3" y1="-37" x2="812.9" y2="813" /><line x1="-719.9" y1="-2" x2="752.3" y2="848" /><line x1="-780.5" y1="33" x2="691.7" y2="883" /><line x1="-841.2" y1="68" x2="631" y2="918" /><line x1="224" y1="-477" x2="-815.2" y2="123" /><line x1="284.6" y1="-442" x2="-754.6" y2="158" /><line x1="345.3" y1="-407" x2="-693.9" y2="193" /><line x1="405.9" y1="-372" x2="-633.3" y2="228" /><line x1="466.5" y1="-337" x2="-572.7" y2="263" /><line x1="527.1" y1="-302" x2="-512.1" y2="298" /><line x1="587.7" y1="-267" x2="-451.5" y2="333" /><line x1="648.4" y1="-232" x2="-390.8" y2="368" /><line x1="709" y1="-197" x2="-330.2" y2="403" /><line x1="769.6" y1="-162" x2="-269.6" y2="438" /><line x1="830.2" y1="-127" x2="-209" y2="473" /><line x1="890.8" y1="-92" x2="-148.4" y2="508" /><line x1="951.5" y1="-57" x2="-87.7" y2="543" /><line x1="1012.1" y1="-22" x2="-27.1" y2="578" /><line x1="1072.7" y1="13" x2="33.5" y2="613" /><line x1="1133.3" y1="48" x2="94.1" y2="648" /><line x1="1193.9" y1="83" x2="154.7" y2="683" /><line x1="1254.6" y1="118" x2="215.4" y2="718" /><line x1="1315.2" y1="153" x2="276" y2="753" /><line x1="1375.8" y1="188" x2="336.6" y2="788" /><line x1="1436.4" y1="223" x2="397.2" y2="823" /><line x1="1497" y1="258" x2="457.8" y2="858" /><line x1="1557.7" y1="293" x2="518.5" y2="893" /><line x1="1618.3" y1="328" x2="579.1" y2="928" /></g>
<!-- Night sky -->
<g class="opacity-0 dark:opacity-100"><g class="fill-mb-text-soft" opacity="0.8"><circle cx="40" cy="30" r="1.4" /><circle cx="96" cy="62" r="1.4" /><circle cx="150" cy="22" r="1.4" /><circle cx="210" cy="48" r="1.4" /><circle cx="262" cy="18" r="1.4" /><circle cx="36" cy="104" r="1.4" /><circle cx="120" cy="98" r="1.4" /><circle cx="470" cy="24" r="1.4" /><circle cx="700" cy="36" r="1.4" /><circle cx="742" cy="96" r="1.4" /><circle cx="860" cy="30" r="1.4" /><circle cx="930" cy="70" r="1.4" /><circle cx="1010" cy="24" r="1.4" /><circle cx="1060" cy="92" r="1.4" /><circle cx="905" cy="128" r="1.4" /><circle cx="780" cy="140" r="1.4" /></g><circle cx="800" cy="64" r="22" class="fill-mb-text" /><circle cx="811" cy="57" r="19" class="fill-(--mb-s-bg)" /></g>
<!-- Day sky -->
<g class="dark:opacity-0"><circle cx="800" cy="64" r="40" class="fill-mb-amber-soft" opacity="0.45" /><circle cx="800" cy="64" r="22" class="fill-mb-tint-amber" /></g>
<g class="opacity-0 dark:opacity-100"><ellipse v-if="kitchenOpen" cx="380" cy="160" rx="92" ry="40" class="fill-mb-tint-amber" opacity="0.16" /></g>
<!-- Road -->
<polygon points="-356.2,-212 1116,638 1055.4,673 -416.8,-177" class="fill-(--mb-s-road) stroke-(--mb-s-road-edge)" /><g class="fill-(--mb-s-dash)"><polygon points="-21.1,14.5 -0.3,26.5 -3.7,28.5 -24.5,16.5" /><polygon points="22.2,39.5 43,51.5 39.6,53.5 18.8,41.5" /><polygon points="65.5,64.5 86.3,76.5 82.9,78.5 62.1,66.5" /><polygon points="108.8,89.5 129.6,101.5 126.2,103.5 105.4,91.5" /><polygon points="152.1,114.5 172.9,126.5 169.5,128.5 148.7,116.5" /><polygon points="195.4,139.5 216.2,151.5 212.8,153.5 192,141.5" /><polygon points="238.7,164.5 259.5,176.5 256.1,178.5 235.3,166.5" /><polygon points="282,189.5 302.8,201.5 299.4,203.5 278.6,191.5" /><polygon points="325.3,214.5 346.1,226.5 342.7,228.5 321.9,216.5" /><polygon points="368.6,239.5 389.4,251.5 386,253.5 365.2,241.5" /><polygon points="411.9,264.5 432.7,276.5 429.3,278.5 408.5,266.5" /><polygon points="455.2,289.5 476,301.5 472.6,303.5 451.8,291.5" /><polygon points="498.5,314.5 519.3,326.5 515.9,328.5 495.1,316.5" /><polygon points="541.8,339.5 562.6,351.5 559.2,353.5 538.4,341.5" /><polygon points="585.1,364.5 605.9,376.5 602.5,378.5 581.7,366.5" /></g>
<!-- Daylight shadows -->
<g class="dark:opacity-0"><g class="fill-mb-card" opacity="0.1"><polygon points="319.3,138 397.2,183 397.2,228 319.3,183" /><polygon points="509.8,248 605.1,303 605.1,373 509.8,318" /></g></g>
<rect x="351.9" y="36" width="4" height="12" class="fill-(--mb-s-trunk)" /><polygon points="340.9,38 353.9,4 366.9,38" class="fill-(--mb-s-tree)" /><polygon points="353.9,4 366.9,38 353.9,42" class="fill-(--mb-s-tree-dark)" /><rect x="403.9" y="46" width="4" height="12" class="fill-(--mb-s-trunk)" /><polygon points="392.9,48 405.9,14 418.9,48" class="fill-(--mb-s-tree)" /><polygon points="405.9,14 418.9,48 405.9,52" class="fill-(--mb-s-tree-dark)" />
<!-- Kitchen -->
<g class="cursor-pointer" @click="select('kitchen')"><polygon points="319.3,138 397.2,183 397.2,125 319.3,80" class="fill-(--mb-s-left) stroke-(--mb-s-edge)" /><polygon points="466.5,143 397.2,183 397.2,125 466.5,85" class="fill-(--mb-s-right) stroke-(--mb-s-edge)" /><polygon points="388.6,40 466.5,85 397.2,125 319.3,80" class="fill-(--mb-s-top) stroke-(--mb-s-edge)" /><polygon points="319.3,80 397.2,125 397.2,119 319.3,74" class="fill-(--mb-s-roof-kitchen)" /><polygon points="466.5,85 397.2,125 397.2,119 466.5,79" class="fill-(--mb-s-roof-kitchen)" /><polygon points="388.6,34 466.5,79 397.2,119 319.3,74" class="fill-(--mb-s-roof-kitchen)" /><polygon points="383.4,55 395.5,62 395.5,40 383.4,33" class="fill-(--mb-s-left) stroke-(--mb-s-edge)" /><polygon points="407.6,55 395.5,62 395.5,40 407.6,33" class="fill-(--mb-s-right) stroke-(--mb-s-edge)" /><polygon points="395.5,26 407.6,33 395.5,40 383.4,33" class="fill-(--mb-s-top) stroke-(--mb-s-edge)" /><g :class="kitchenOpen ? 'fill-(--mb-s-win-on)' : 'fill-(--mb-s-win-off)'"><polygon points="334.4,122.8 343.1,127.8 343.1,117.8 334.4,112.8" /><polygon points="353.9,134 362.6,139 362.6,129 353.9,124" /><polygon points="373.4,145.3 382.1,150.3 382.1,140.3 373.4,135.3" /><polygon points="447.7,129.8 439.1,134.8 439.1,124.8 447.7,119.8" /><polygon points="424.6,143.2 416,148.2 416,138.2 424.6,133.2" /></g></g>
<!-- Office, with today's tiffins stacked at the door -->
<g class="cursor-pointer" @click="select('office')"><polygon points="509.8,248 605.1,303 605.1,191 509.8,136" class="fill-(--mb-s-left) stroke-(--mb-s-edge)" /><polygon points="683,258 605.1,303 605.1,191 683,146" class="fill-(--mb-s-right) stroke-(--mb-s-edge)" /><polygon points="587.7,91 683,146 605.1,191 509.8,136" class="fill-(--mb-s-top) stroke-(--mb-s-edge)" /><polygon points="509.8,136 605.1,191 605.1,186 509.8,131" class="fill-(--mb-s-roof-office)" /><polygon points="683,146 605.1,191 605.1,186 683,141" class="fill-(--mb-s-roof-office)" /><polygon points="587.7,86 683,141 605.1,186 509.8,131" class="fill-(--mb-s-roof-office)" /><g class="fill-(--mb-s-win-on)"><polygon points="543.6,250.1 552.2,255.1 552.2,245.1 543.6,240.1" /><polygon points="524.5,216.7 533.2,221.7 533.2,211.7 524.5,206.7" /><polygon points="562.6,238.7 571.3,243.7 571.3,233.7 562.6,228.7" /><polygon points="581.7,227.3 590.3,232.3 590.3,222.3 581.7,217.3" /><polygon points="543.6,182.9 552.2,187.9 552.2,177.9 543.6,172.9" /><polygon points="562.6,193.9 571.3,198.9 571.3,188.9 562.6,183.9" /><polygon points="667.8,249.4 659.2,254.4 659.2,244.4 667.8,239.4" /><polygon points="628.9,249.5 620.2,254.5 620.2,244.5 628.9,239.5" /><polygon points="648.4,215.8 639.7,220.8 639.7,210.8 648.4,205.8" /><polygon points="667.8,182.2 659.2,187.2 659.2,177.2 667.8,172.2" /></g><g class="fill-(--mb-s-win-off)"><polygon points="524.5,239.1 533.2,244.1 533.2,234.1 524.5,229.1" /><polygon points="562.6,261.1 571.3,266.1 571.3,256.1 562.6,251.1" /><polygon points="581.7,272.1 590.3,277.1 590.3,267.1 581.7,262.1" /><polygon points="543.6,227.7 552.2,232.7 552.2,222.7 543.6,217.7" /><polygon points="581.7,249.7 590.3,254.7 590.3,244.7 581.7,239.7" /><polygon points="524.5,194.3 533.2,199.3 533.2,189.3 524.5,184.3" /><polygon points="543.6,205.3 552.2,210.3 552.2,200.3 543.6,195.3" /><polygon points="562.6,216.3 571.3,221.3 571.3,211.3 562.6,206.3" /><polygon points="524.5,171.9 533.2,176.9 533.2,166.9 524.5,161.9" /><polygon points="581.7,204.9 590.3,209.9 590.3,199.9 581.7,194.9" /><polygon points="648.4,260.6 639.7,265.6 639.7,255.6 648.4,250.6" /><polygon points="628.9,271.9 620.2,276.9 620.2,266.9 628.9,261.9" /><polygon points="667.8,227 659.2,232 659.2,222 667.8,217" /><polygon points="648.4,238.2 639.7,243.2 639.7,233.2 648.4,228.2" /><polygon points="667.8,204.6 659.2,209.6 659.2,199.6 667.8,194.6" /><polygon points="628.9,227.1 620.2,232.1 620.2,222.1 628.9,217.1" /><polygon points="648.4,193.4 639.7,198.4 639.7,188.4 648.4,183.4" /><polygon points="628.9,204.7 620.2,209.7 620.2,199.7 628.9,194.7" /></g><g v-if="isMealDay"><polygon points="541,300 560,311 560,302 541,291" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="579.1,300 560,311 560,302 579.1,291" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="560,280 579.1,291 560,302 541,291" class="fill-(--mb-s-lid-1) stroke-(--mb-s-edge)" /><polygon points="541,289 560,300 560,291 541,280" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="579.1,289 560,300 560,291 579.1,280" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="560,269 579.1,280 560,291 541,280" class="fill-(--mb-s-lid-2) stroke-(--mb-s-edge)" /><polygon points="541,278 560,289 560,280 541,269" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="579.1,278 560,289 560,280 579.1,269" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="560,258 579.1,269 560,280 541,269" class="fill-(--mb-s-lid-3) stroke-(--mb-s-edge)" /><polygon points="541,267 560,278 560,269 541,258" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="579.1,267 560,278 560,269 579.1,258" class="fill-(--mb-s-tiffin) stroke-(--mb-s-edge)" /><polygon points="560,247 579.1,258 560,269 541,258" class="fill-(--mb-s-lid-1) stroke-(--mb-s-edge)" /></g></g>
<!-- Van: waits at the kitchen until the count locks, then drives to the office -->
<g v-if="isMealDay" class="cursor-pointer transition-transform duration-[1200ms] ease-in-out motion-reduce:transition-none" :style="{ transform: vanOffset }" @click="select('van')"><polygon points="308.9,220 347,242 347,212 308.9,190" class="fill-(--mb-s-van-left) stroke-(--mb-s-edge)" /><polygon points="383.4,221 347,242 347,212 383.4,191" class="fill-(--mb-s-van-right) stroke-(--mb-s-edge)" /><polygon points="345.3,169 383.4,191 347,212 308.9,190" class="fill-(--mb-s-van-top) stroke-(--mb-s-edge)" /><polygon points="347,242 359.1,249 359.1,229 347,222" class="fill-(--mb-s-van-left) stroke-(--mb-s-edge)" /><polygon points="395.5,228 359.1,249 359.1,229 395.5,208" class="fill-(--mb-s-van-right) stroke-(--mb-s-edge)" /><polygon points="383.4,201 395.5,208 359.1,229 347,222" class="fill-(--mb-s-van-top) stroke-(--mb-s-edge)" /><polygon points="312.4,212 343.5,230 343.5,224 312.4,206" class="fill-(--mb-s-van-stripe)" /><g class="fill-(--mb-s-lamp)"><polygon points="392,226 386.8,229 386.8,224 392,221" /><polygon points="367.8,240 362.6,243 362.6,238 367.8,235" /></g><ellipse cx="319.3" cy="226" rx="5" ry="6" class="fill-mb-card" /><ellipse cx="350.5" cy="244" rx="5" ry="6" class="fill-mb-card" /><g class="opacity-0 dark:opacity-100"><polygon points="395.5,231 359.1,252 405.9,298 470,261" class="fill-mb-amber-soft" opacity="0.14" /></g><g v-if="selected === 'van'"><polygon points="345.3,153 409.3,190 409.3,234 359.1,263 295,226 295,182" class="fill-none stroke-(--mb-s-select)" stroke-width="2" stroke-dasharray="6 5" stroke-linejoin="round" /></g></g>
<rect x="83.5" y="161" width="4" height="12" class="fill-(--mb-s-trunk)" /><polygon points="72.5,163 85.5,129 98.5,163" class="fill-(--mb-s-tree)" /><polygon points="85.5,129 98.5,163 85.5,167" class="fill-(--mb-s-tree-dark)" /><rect x="152.7" y="221" width="4" height="12" class="fill-(--mb-s-trunk)" /><polygon points="141.7,223 154.7,189 167.7,223" class="fill-(--mb-s-tree)" /><polygon points="154.7,189 167.7,223 154.7,227" class="fill-(--mb-s-tree-dark)" /><rect x="412.5" y="331" width="4" height="12" class="fill-(--mb-s-trunk)" /><polygon points="401.5,333 414.5,299 427.5,333" class="fill-(--mb-s-tree)" /><polygon points="414.5,299 427.5,333 414.5,337" class="fill-(--mb-s-tree-dark)" />
<!-- Selection -->
<g v-if="selected === 'kitchen'"><polygon points="388.6,-4 480.4,49 480.4,145 397.2,193 305.4,140 305.4,44" class="fill-none stroke-(--mb-s-select)" stroke-width="2" stroke-dasharray="6 5" stroke-linejoin="round" /></g>
<g v-if="selected === 'office'"><polygon points="587.7,70 696.9,133 696.9,260 605.1,313 495.9,250 495.9,123" class="fill-none stroke-(--mb-s-select)" stroke-width="2" stroke-dasharray="6 5" stroke-linejoin="round" /></g>
<!-- Labels -->
<rect x="361" y="3" width="64" height="22" rx="11" class="fill-(--mb-s-label-bg) stroke-(--mb-s-label-line)" /><text x="393" y="18" text-anchor="middle" font-size="11" font-weight="600" class="fill-(--mb-s-label)">Kitchen</text>
<rect x="558" y="41" width="60" height="22" rx="11" class="fill-(--mb-s-label-bg) stroke-(--mb-s-label-line)" /><text x="588" y="56" text-anchor="middle" font-size="11" font-weight="600" class="fill-(--mb-s-label)">Office</text>
<g v-if="isMealDay" :style="{ transform: vanOffset }" class="transition-transform duration-[1200ms] ease-in-out motion-reduce:transition-none"><rect x="236" y="195" width="44" height="22" rx="11" class="fill-(--mb-s-label-bg) stroke-(--mb-s-label-line)" /><text x="258" y="210" text-anchor="middle" font-size="11" font-weight="600" class="fill-(--mb-s-label)">Van</text></g>
<g v-if="isMealDay"><rect x="518" y="315" width="84" height="22" rx="11" class="fill-(--mb-s-label-bg) stroke-(--mb-s-label-line)" /><text x="560" y="330" text-anchor="middle" font-size="11" font-weight="600" class="fill-(--mb-s-label)">{{ tiffinLabel }}</text></g>
            </svg>

            <!-- Status and cutoff -->
            <div class="absolute top-3 left-3 sm:top-4 sm:left-4 flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-mb-card/90 border border-mb-line">
                <svg class="w-9 h-9 shrink-0" viewBox="0 0 36 36" aria-hidden="true">
                    <circle cx="18" cy="18" r="15" fill="none" stroke-width="4" class="stroke-mb-line" />
                    <circle
                        cx="18" cy="18" r="15" fill="none" stroke-width="4" stroke-linecap="round" transform="rotate(-90 18 18)"
                        :stroke-dasharray="RING_CIRCUMFERENCE"
                        :stroke-dashoffset="ringOffset"
                        :class="{
                            open: 'stroke-mb-tint-emerald',
                            locked: 'stroke-mb-cyan',
                            late: 'stroke-mb-tint-amber',
                            idle: 'stroke-mb-line',
                        }[status.tone]"
                    />
                </svg>
                <div class="flex flex-col gap-0.5">
                    <span
                        :class="[
                            'text-[11px] font-bold uppercase',
                            {
                                open: 'text-mb-emerald',
                                locked: 'text-mb-cyan',
                                late: 'text-mb-amber',
                                idle: 'text-mb-text-muted',
                            }[status.tone]
                        ]"
                    >{{ status.label }}</span>
                    <span class="text-[13px] text-mb-text">{{ status.detail }}</span>
                </div>
            </div>

            <!-- The keyboard and screen-reader way in: the drawing itself is hidden from both. -->
            <div role="group" aria-label="Inspect" class="absolute bottom-3 left-3 sm:bottom-4 sm:left-4 flex items-center gap-1 p-1 rounded-full bg-mb-card/90 border border-mb-line">
                <button
                    v-for="(label, key) in panels"
                    :key="key"
                    type="button"
                    :aria-pressed="selected === key"
                    @click="select(key)"
                    :class="[
                        'min-h-8 px-3 rounded-full text-xs font-semibold border cursor-pointer transition-colors',
                        selected === key
                            ? 'bg-mb-tint-cyan/10 border-mb-tint-cyan/40 text-mb-cyan'
                            : 'border-transparent text-mb-text-muted hover:text-mb-text'
                    ]"
                >{{ label }}</button>
            </div>

            <aside
                v-if="selected"
                :aria-label="`${panels[selected]} details`"
                class="absolute top-3 right-3 sm:top-4 sm:right-4 w-66 max-w-[calc(100%-1.5rem)] max-h-[calc(100%-1.5rem)] overflow-y-auto p-4 rounded-xl bg-mb-card/95 border border-mb-line-strong shadow-xl flex flex-col gap-3"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <span class="text-[10px] font-bold uppercase tracking-wide text-mb-cyan">{{ panels[selected] }}</span>
                        <p class="text-base font-bold text-mb-text-strong truncate">
                            {{ selected === 'office' ? company?.name : tiffinService.name }}
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Close details"
                        @click="select(null)"
                        class="w-8 h-8 shrink-0 inline-flex items-center justify-center rounded-lg border border-mb-line text-mb-text-muted hover:text-mb-text cursor-pointer"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>
                </div>

                <template v-if="selected === 'office'">
                    <p v-if="!isMealDay" class="text-[13px] text-mb-text-muted">No meal today.</p>
                    <template v-else>
                        <dl class="grid grid-cols-[minmax(0,1fr)_auto] gap-y-1.5 text-[13px]">
                            <template v-for="row in officeRows" :key="row.label">
                                <dt class="text-mb-text-muted">{{ row.label }}</dt>
                                <dd :class="['font-semibold text-right', row.tone]">{{ row.value }}</dd>
                            </template>
                        </dl>
                        <div class="flex items-baseline justify-between pt-2.5 border-t border-mb-line">
                            <span class="text-xs font-semibold uppercase text-mb-cyan-soft">{{ isLocked ? 'Locked count' : 'Expected today' }}</span>
                            <span class="text-2xl font-extrabold text-mb-cyan-soft">{{ expected }}</span>
                        </div>
                    </template>
                    <Link href="/company-admin/daily" class="text-[13px] font-semibold text-mb-cyan hover:underline">Open Daily Count →</Link>
                </template>

                <template v-else-if="selected === 'kitchen'">
                    <p v-if="!isMealDay" class="text-[13px] text-mb-text-muted">Closed today.</p>
                    <div v-else class="text-[13px] space-y-1">
                        <p class="text-mb-text-muted">Today</p>
                        <p class="font-semibold text-mb-text">{{ todayMeal?.meal_description || 'No menu published for today yet.' }}</p>
                        <p v-if="todayMeal?.is_override" class="text-mb-amber-soft">Changed by the kitchen today.</p>
                    </div>
                    <p v-if="tiffinService.contact_phone" class="text-[13px] text-mb-text-muted">
                        Contact: <span class="font-semibold text-mb-text">{{ tiffinService.contact_phone }}</span>
                    </p>
                </template>

                <template v-else>
                    <p class="text-[13px] text-mb-text">
                        <template v-if="!isMealDay">No delivery today.</template>
                        <template v-else-if="isLocked">On its way: {{ expected }} meals, locked at the {{ cutoffLabel }} cutoff.</template>
                        <template v-else>Waiting at the kitchen. It leaves when today's count locks at {{ cutoffLabel }}.</template>
                    </p>
                </template>
            </aside>
        </section>

        <p class="text-[13px] text-mb-text-muted">{{ summary }}</p>
    </div>
</template>
