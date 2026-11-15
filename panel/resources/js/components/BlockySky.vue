<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

defineProps<{
    // A lower band without sun, stars and ground, for the top of a server's pages.
    compact?: boolean;
}>();

const blocky = usePage().props.theme === 'blocky';
</script>

<template>
    <!-- In the default theme this is only a wrapper; in the blocky theme a sunset sky behind the page's heading. -->
    <div v-if="!blocky"><slot /></div>
    <section v-else class="mc-sky" :class="{ 'mc-sky-compact': compact }">
        <template v-if="!compact">
            <span class="mc-star" style="left: 8%; top: 18px" />
            <span
                class="mc-star"
                style="left: 22%; top: 42px; animation-delay: 1.1s"
            />
            <span
                class="mc-star"
                style="left: 47%; top: 14px; animation-delay: 0.6s"
            />
            <span
                class="mc-star"
                style="left: 66%; top: 36px; animation-delay: 1.8s"
            />
            <span
                class="mc-star"
                style="left: 88%; top: 20px; animation-delay: 2.4s"
            />
            <span class="mc-sun" aria-hidden="true" />
            <span class="mc-ground" aria-hidden="true" />
        </template>
        <span
            class="mc-cloud"
            aria-hidden="true"
            style="top: 20px; animation-duration: 80s; animation-delay: -30s"
        >
            <span style="width: 140px" /><span
                style="width: 80px; margin-left: 28px"
            />
        </span>
        <span
            class="mc-cloud"
            aria-hidden="true"
            style="top: 64px; animation-duration: 110s; animation-delay: -70s"
        >
            <span style="width: 180px" />
        </span>
        <div class="mc-sky-content"><slot /></div>
    </section>
</template>
