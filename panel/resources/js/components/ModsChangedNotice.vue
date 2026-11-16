<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { States } from '@/lib/servers';
import { power } from '@/routes/servers';

const props = defineProps<{ slug: string; states?: States }>();

const changed = computed(
    () => props.states?.servers[props.slug]?.mods_changed ?? 0,
);
</script>

<template>
    <div
        v-if="changed"
        role="status"
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-border bg-card px-4 py-3 text-sm"
    >
        <span class="min-w-0 flex-1">
            {{
                changed === 1
                    ? t(
                          ':count mod or plugin file changed since the last start. The change takes effect on the next restart.',
                          { count: changed },
                      )
                    : t(
                          ':count mod or plugin files changed since the last start. The changes take effect on the next restart.',
                          { count: changed },
                      )
            }}
        </span>
        <Button
            type="button"
            variant="outline"
            class="border-primary"
            @click="
                router.post(
                    power.url({ server: slug, action: 'restart' }),
                    {},
                    { preserveScroll: true, only: ['states'] },
                )
            "
        >
            {{ t('Restart now') }}
        </Button>
    </div>
</template>
