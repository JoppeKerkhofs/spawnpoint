<script setup lang="ts">
defineProps<{
    tone: 'ok' | 'busy' | 'idle' | 'error';
}>();

const toneClasses = {
    ok: 'bg-[#e1f1e7] text-[#1d6b3f] dark:bg-[#173323] dark:text-[#7fd6a2]',
    busy: 'bg-[#fbefd5] text-[#7a4f00] dark:bg-[#3a2d12] dark:text-[#f0c36b]',
    idle: 'bg-[#eceef1] text-[#4f565d] dark:bg-[#2a2f35] dark:text-[#a9b0b7]',
    error: 'bg-[#fbe3e3] text-[#a12a2f] dark:bg-[#3d1c1e] dark:text-[#ff9a9a]',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-[9px] py-0.5 text-xs font-semibold tracking-[0.02em] uppercase"
        :class="toneClasses[tone]"
    >
        <!-- Each tone has its own mark so status never depends on colour alone -->
        <svg
            v-if="tone === 'error'"
            width="10"
            height="10"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            aria-hidden="true"
        >
            <path d="M12 6v8M12 18v.5" />
        </svg>
        <span
            v-else
            aria-hidden="true"
            class="rounded-full border-[1.5px] border-current"
            :class="{
                'size-1.5': tone !== 'busy',
                'bg-current': tone === 'ok',
                'size-2.5 border-r-transparent motion-safe:animate-spin':
                    tone === 'busy',
            }"
        />
        <slot />
    </span>
</template>
