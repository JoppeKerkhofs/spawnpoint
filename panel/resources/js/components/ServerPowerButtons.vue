<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { PowerAction } from '@/lib/servers';
import { powerActions, title } from '@/lib/servers';
import { power } from '@/routes/servers';

const props = withDefaults(
    defineProps<{
        slug: string;
        state: string;
        canManage?: boolean;
    }>(),
    { canManage: true },
);

const sending = ref(false);

const send = (action: PowerAction) => {
    router.post(
        power.url({ server: props.slug, action }),
        {},
        {
            preserveScroll: true,
            only: ['states'],
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
        },
    );
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <Button
            v-for="action in canManage ? powerActions(state) : []"
            :key="action"
            type="button"
            :variant="action === 'start' ? 'default' : 'outline'"
            :class="{
                'border-primary': action !== 'start' && action !== 'kill',
                'border-destructive text-destructive hover:text-destructive':
                    action === 'kill',
            }"
            :disabled="sending"
            @click="send(action)"
        >
            {{ title(action) }}
        </Button>
        <slot />
    </div>
</template>
