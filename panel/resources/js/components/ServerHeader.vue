<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BlockCube from '@/components/BlockCube.vue';
import BlockySky from '@/components/BlockySky.vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import { t } from '@/lib/i18n';
import { useLiveStates } from '@/lib/live';
import type { Server, States } from '@/lib/servers';
import { cubeTexture, serverState, software, toneOf } from '@/lib/servers';
import { dashboard } from '@/routes';
import {
    backups,
    configuration,
    files,
    logs,
    mods,
    show,
} from '@/routes/servers';

const props = defineProps<{
    server: Server;
    states?: States;
    tab: 'console' | 'configuration' | 'files' | 'mods' | 'logs' | 'backups';
}>();

useLiveStates(() => props.server.slug);

const blocky = usePage().props.theme === 'blocky';

const current = computed(() => serverState(props.states, props.server.slug));

const tabs = computed(() =>
    [
        {
            key: 'console',
            title: t('Console'),
            href: show({ server: props.server.slug }),
        },
        {
            key: 'configuration',
            title: t('Configuration'),
            href: configuration({ server: props.server.slug }),
        },
        {
            key: 'files',
            title: t('Files'),
            href: files({ server: props.server.slug }),
        },
        {
            key: 'mods',
            title: t('Mods'),
            href: mods({ server: props.server.slug }),
        },
        {
            key: 'logs',
            title: t('Logs'),
            href: logs({ server: props.server.slug }),
        },
        {
            key: 'backups',
            title: t('Backups'),
            href: backups({ server: props.server.slug }),
        },
    ].filter(
        // Everyone who sees the server gets its console; the other tabs go by permission.
        (item) =>
            item.key === 'console' ||
            (item.key === 'mods'
                ? props.server.has_mods && props.server.can.includes('files')
                : props.server.can.includes(
                      item.key === 'configuration' ? 'configure' : item.key,
                  )),
    ),
);
</script>

<template>
    <div class="flex flex-col gap-3.5 border-b border-border">
        <BlockySky compact class="flex flex-col gap-3.5">
            <nav
                :aria-label="t('Breadcrumb')"
                class="text-[0.8125rem] text-muted-foreground"
            >
                <Link :href="dashboard()" class="underline">{{
                    t('Servers')
                }}</Link>
                / {{ server.name }}
            </nav>

            <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                <img
                    v-if="server.picture"
                    :src="server.picture"
                    alt=""
                    width="44"
                    height="44"
                    class="size-11 shrink-0 object-cover"
                    @error="
                        ($event.target as HTMLImageElement).style.display =
                            'none'
                    "
                />
                <BlockCube
                    v-else-if="blocky"
                    :texture="cubeTexture(server.server_type)"
                    :size="44"
                    :spin="current.state !== 'offline'"
                    :dim="current.state !== 'running'"
                />
                <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                    {{ server.name }}
                </h1>
                <StatusPill :tone="toneOf(current.state)">
                    {{ t(current.state) }}
                </StatusPill>
                <span class="text-sm text-muted-foreground">
                    {{ software(server) }}
                </span>
                <span class="flex-1" />
                <ServerPowerButtons
                    :slug="server.slug"
                    :state="current.state"
                    :can-manage="server.can.includes('power')"
                />
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
        </BlockySky>

        <nav
            :aria-label="t('Server sections')"
            class="-mb-px flex flex-wrap gap-1"
        >
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
