<script setup lang="ts">
defineProps<{
    title: string;
}>();
</script>

<template>
    <section class="overflow-hidden rounded-md border border-border bg-card">
        <h2 class="bg-muted px-4 pt-3 pb-2 font-bold text-primary">
            {{ title }}
        </h2>
        <div class="px-4 py-4">
            <slot />
        </div>
    </section>
</template>
