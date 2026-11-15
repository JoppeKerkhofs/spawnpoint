<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    status: number;
}>();

const texts: Record<number, [string, string]> = {
    403: [
        'You cannot open this',
        'Your account has no access to this page. An admin can share the server with you or give you more permissions on it.',
    ],
    404: [
        'Page not found',
        'This address does not exist, or the server it belonged to was deleted.',
    ],
    500: [
        'Something went wrong',
        "The panel hit an error. Try again; if it keeps happening, look in the panel's log.",
    ],
    503: [
        'Down for maintenance',
        'The panel is being updated. Try again in a minute.',
    ],
};

const text = computed(() => texts[props.status] ?? texts[500]);
</script>

<template>
    <Head :title="text[0]" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-5 bg-background p-6 text-center"
    >
        <AppLogoIcon class="size-10 fill-current text-primary" />
        <p class="font-mono text-sm text-muted-foreground">
            Error {{ status }}
        </p>
        <h1 class="text-2xl leading-[1.3] font-bold text-primary">
            {{ text[0] }}
        </h1>
        <p class="max-w-md text-[0.9375rem] text-muted-foreground">
            {{ text[1] }}
        </p>
        <Button as-child>
            <a href="/">Back to the servers</a>
        </Button>
    </main>
</template>
