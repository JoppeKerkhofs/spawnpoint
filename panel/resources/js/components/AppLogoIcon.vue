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
    <!-- The same pixel block as the favicon, so it stays sharp at any size. -->
    <img
        src="/favicon.svg"
        alt=""
        class="[image-rendering:pixelated]"
        :class="className"
        v-bind="$attrs"
    />
</template>
