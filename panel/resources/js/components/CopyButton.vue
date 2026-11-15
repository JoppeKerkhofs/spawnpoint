<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    text: string;
    label: string;
}>();

const copied = ref(false);

const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.text);
    } catch {
        // Without https the clipboard is not available; a selected field can still be copied.
        const field = document.createElement('textarea');
        field.value = props.text;
        document.body.append(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }

    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
};
</script>

<template>
    <button
        type="button"
        class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :aria-label="copied ? t('Copied') : label"
        :title="copied ? t('Copied') : label"
        @click="copy"
    >
        <Check v-if="copied" class="size-4 text-brand" aria-hidden="true" />
        <Copy v-else class="size-4" aria-hidden="true" />
        <span class="sr-only" role="status">{{
            copied ? t('Copied') : ''
        }}</span>
    </button>
</template>
