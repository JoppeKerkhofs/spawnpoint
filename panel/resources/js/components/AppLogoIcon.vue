<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Inline fill wins over the fill-current class the starter layouts pass -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        stroke-width="1.8"
        stroke-linejoin="round"
        style="fill: none"
        class="stroke-brand"
        :class="className"
        v-bind="$attrs"
    >
        <path d="M12 2.5 3.5 7v10L12 21.5 20.5 17V7z" />
        <path d="M3.5 7 12 11.5 20.5 7M12 11.5v10" />
    </svg>
</template>
