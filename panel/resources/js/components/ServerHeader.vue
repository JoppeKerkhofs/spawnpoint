<script setup lang="ts">
import { Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import type { Server, States } from '@/lib/servers';
import { serverState, software, toneOf } from '@/lib/servers';
import { dashboard } from '@/routes';
import { backups, configuration, files, logs, show } from '@/routes/servers';

const props = defineProps<{
    server: Server;
    states?: States;
    tab: 'console' | 'configuration' | 'files' | 'logs' | 'backups';
}>();

usePoll(4000, { only: ['states'] });

const current = computed(() => serverState(props.states, props.server.slug));

const tabs = computed(() => [
    {
        key: 'console',
        title: 'Console',
        href: show({ server: props.server.slug }),
    },
    {
        key: 'configuration',
        title: 'Configuration',
        href: configuration({ server: props.server.slug }),
    },
    {
        key: 'files',
        title: 'Files',
        href: files({ server: props.server.slug }),
    },
    { key: 'logs', title: 'Logs', href: logs({ server: props.server.slug }) },
    {
        key: 'backups',
        title: 'Backups',
        href: backups({ server: props.server.slug }),
    },
]);
</script>

<template>
    <div class="flex flex-col gap-3.5 border-b border-border">
        <nav
            aria-label="Breadcrumb"
            class="text-[0.8125rem] text-muted-foreground"
        >
            <Link :href="dashboard()" class="underline">Servers</Link>
            / {{ server.name }}
        </nav>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                {{ server.name }}
            </h1>
            <StatusPill :tone="toneOf(current.state)">
                {{ current.state }}
            </StatusPill>
            <span class="text-sm text-muted-foreground">
                {{ software(server) }}
            </span>
            <span class="flex-1" />
            <ServerPowerButtons :slug="server.slug" :state="current.state" />
        </div>

        <p
            v-if="current.detail"
            class="text-sm"
            :class="
                current.state === 'error'
                    ? 'text-destructive'
                    : 'text-muted-foreground'
            "
        >
            {{ current.detail }}
        </p>

        <nav aria-label="Server sections" class="-mb-px flex flex-wrap gap-1">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="item.href"
                :aria-current="item.key === tab ? 'page' : undefined"
                class="border-b-2 px-3 py-2.5 text-[0.9375rem]"
                :class="
                    item.key === tab
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-muted-foreground hover:text-primary'
                "
            >
                {{ item.title }}
            </Link>
        </nav>
    </div>
</template>
