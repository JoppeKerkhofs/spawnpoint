<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    values: number[];
    max: number;
    label: string;
}>();

const points = computed(() => {
    const step = 260 / Math.max(props.values.length - 1, 1);

    return props.values
        .map((value, index) => {
            const y = 46 - (Math.min(value, props.max) / (props.max || 1)) * 44;

            return `${(index * step).toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg
        viewBox="0 0 260 48"
        width="100%"
        height="48"
        preserveAspectRatio="none"
        role="img"
        :aria-label="label"
    >
        <path d="M0 47.5H260" class="stroke-divider" fill="none" />
        <polyline
            :points="points"
            fill="none"
            class="stroke-primary"
            stroke-width="2"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
