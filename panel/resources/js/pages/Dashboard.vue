<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import type { Server, States } from '@/lib/servers';
import {
    bytesLabel,
    serverState,
    software,
    toneOf,
    typeLabels,
} from '@/lib/servers';
import { dashboard } from '@/routes';
import { create, show, stopAll } from '@/routes/servers';

const props = defineProps<{
    servers: Server[];
    memoryLimitMb: number | null;
    states?: States;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Servers',
                href: dashboard(),
            },
        ],
    },
});

usePoll(4000, { only: ['states'] });

const page = usePage();
const canManage = computed(() => page.props.auth.user.role === 'admin');

const cards = computed(() =>
    props.servers.map((server) => ({
        server,
        current: serverState(props.states, server.slug),
    })),
);

const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');

const types = computed(() => [
    ...new Set(props.servers.map((server) => server.server_type)),
]);

const visibleCards = computed(() => {
    const text = search.value.trim().toLowerCase();

    return cards.value.filter(
        ({ server, current }) =>
            (!text ||
                server.name.toLowerCase().includes(text) ||
                server.hostname.toLowerCase().includes(text)) &&
            (!typeFilter.value || server.server_type === typeFilter.value) &&
            (!statusFilter.value || current.state === statusFilter.value),
    );
});

const lastBackup = computed(() =>
    Math.max(0, ...cards.value.map((card) => card.current.last_backup ?? 0)),
);

const shortTime = (seconds: number) =>
    new Date(seconds * 1000).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const gigabytes = (bytes: number) => (bytes / 1024 ** 3).toFixed(1);
const selectClass =
    'h-9 flex-[2_1_160px] rounded-md border border-input bg-card px-2.5 text-sm';

const runningCount = computed(
    () => cards.value.filter((card) => card.current.state === 'running').length,
);

const playersOnline = computed(() =>
    cards.value.reduce(
        (total, card) => total + (card.current.players?.online ?? 0),
        0,
    ),
);

const stopEverything = () => {
    if (
        confirm(
            `Stop all ${runningCount.value} running servers? Players are disconnected.`,
        )
    ) {
        router.post(stopAll.url(), {}, { preserveScroll: true });
    }
};

const memoryGb = computed(
    () =>
        props.servers.reduce((total, server) => total + server.memory_mb, 0) /
        1024,
);
</script>

<template>
    <Head title="Servers" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="flex-[1_1_320px]">
                <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                    Servers
                </h1>
                <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                    Every Minecraft server on this host, each in its own
                    container. Players join on port 25565 by hostname.
                </p>
            </div>
            <Button
                v-if="canManage && runningCount"
                type="button"
                variant="outline"
                class="border-destructive text-destructive hover:text-destructive"
                @click="stopEverything"
            >
                Stop all
            </Button>
            <Button v-if="canManage" as-child>
                <Link :href="create()">
                    <Plus aria-hidden="true" />
                    New server
                </Link>
            </Button>
        </div>

        <p
            v-if="states && !states.online"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            The Rust service did not answer, so server states are unknown{{
                canManage
                    ? ' and controls will fail. Check that it is running and that a node is registered (php artisan node:register).'
                    : '.'
            }}
        </p>

        <div v-if="servers.length" class="flex flex-wrap gap-3">
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div class="text-[0.8125rem] text-muted-foreground">
                    Running
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ runningCount }}
                    <span class="text-base font-normal text-muted-foreground"
                        >of {{ servers.length }}</span
                    >
                </div>
            </div>
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div class="text-[0.8125rem] text-muted-foreground">
                    Players online
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ playersOnline }}
                </div>
            </div>
            <div
                v-if="canManage"
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div class="text-[0.8125rem] text-muted-foreground">
                    Last backup
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ lastBackup ? shortTime(lastBackup) : 'None yet' }}
                </div>
            </div>
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div
                    class="flex justify-between text-[0.8125rem] text-muted-foreground"
                >
                    <span>Memory allocated</span>
                    <span v-if="memoryLimitMb" class="tabular-nums"
                        >limit {{ Math.round(memoryLimitMb / 1024) }} GB</span
                    >
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ memoryGb }} GB
                </div>
                <div
                    v-if="memoryLimitMb"
                    class="mt-1 h-1.5 overflow-hidden rounded-full bg-divider"
                    role="img"
                    :aria-label="`${memoryGb} of ${Math.round(memoryLimitMb / 1024)} GB allocated`"
                >
                    <div
                        class="h-full bg-primary"
                        :style="{
                            width: `${Math.min(100, ((memoryGb * 1024) / memoryLimitMb) * 100)}%`,
                        }"
                    />
                </div>
            </div>
        </div>

        <p
            v-if="!servers.length"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            {{
                canManage
                    ? 'No servers yet.'
                    : 'No servers are shared with you yet.'
            }}
        </p>

        <form
            v-if="servers.length > 1"
            role="search"
            class="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-4 py-3"
            @submit.prevent
            @reset="
                search = '';
                typeFilter = '';
                statusFilter = '';
            "
        >
            <label for="filter-text" class="sr-only">Name or hostname</label>
            <input
                id="filter-text"
                v-model="search"
                type="search"
                placeholder="Name or hostname"
                class="h-9 flex-[3_1_220px] rounded-md border border-input bg-card px-3 text-sm"
            />
            <label for="filter-type" class="sr-only">Server type</label>
            <select id="filter-type" v-model="typeFilter" :class="selectClass">
                <option value="">All types</option>
                <option v-for="type in types" :key="type" :value="type">
                    {{ typeLabels[type] ?? type }}
                </option>
            </select>
            <label for="filter-status" class="sr-only">Status</label>
            <select
                id="filter-status"
                v-model="statusFilter"
                :class="selectClass"
            >
                <option value="">Any status</option>
                <option value="running">Running</option>
                <option value="starting">Starting</option>
                <option value="offline">Offline</option>
                <option value="error">Error</option>
            </select>
            <Button type="reset" variant="outline" class="border-primary">
                Clear
            </Button>
        </form>

        <p
            v-if="servers.length && !visibleCards.length"
            role="status"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            No server matches these filters.
        </p>

        <div class="flex flex-col gap-3">
            <article
                v-for="{ server, current } in visibleCards"
                :key="server.id"
                class="relative overflow-hidden rounded-md border border-border bg-card transition-colors hover:border-ring"
            >
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-muted px-4 pt-3.5 pb-2.5"
                >
                    <h2 class="text-lg leading-[1.35] font-bold text-primary">
                        <!-- The link covers the whole card, so a click anywhere on it opens the server. -->
                        <Link
                            :href="show({ server: server.slug })"
                            class="after:absolute after:inset-0"
                        >
                            {{ server.name }}
                        </Link>
                    </h2>
                    <span
                        v-if="server.is_default"
                        class="rounded-full border border-brand px-2 py-px text-xs font-semibold tracking-[0.02em] text-primary uppercase"
                        >Default</span
                    >
                    <StatusPill :tone="toneOf(current.state)">
                        {{ current.state }}
                    </StatusPill>
                    <span class="flex-1" />
                    <span class="font-mono text-sm text-primary">
                        {{ server.hostname }}
                    </span>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3"
                >
                    <div class="flex-[1_1_150px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            Software
                        </div>
                        <div>{{ software(server) }}</div>
                    </div>
                    <div class="flex-[1_1_110px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            Players
                        </div>
                        <div v-if="current.players" class="tabular-nums">
                            {{ current.players.online }} /
                            {{ current.players.max }}
                        </div>
                        <div v-else class="text-muted-foreground">—</div>
                    </div>
                    <div class="flex-[1_1_110px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            Memory
                        </div>
                        <template v-if="current.usage">
                            <div class="tabular-nums">
                                {{ gigabytes(current.usage.memory) }} /
                                {{ server.memory_mb / 1024 }} GB
                            </div>
                            <div
                                class="mt-1 h-1 overflow-hidden rounded-full bg-divider"
                                aria-hidden="true"
                            >
                                <div
                                    class="h-full bg-primary"
                                    :style="{
                                        width: `${Math.min(100, (current.usage.memory / (server.memory_mb * 1024 ** 2)) * 100)}%`,
                                    }"
                                />
                            </div>
                        </template>
                        <div v-else class="tabular-nums">
                            {{ server.memory_mb / 1024 }} GB
                        </div>
                    </div>
                    <div class="flex-[1_1_90px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            CPU
                        </div>
                        <div v-if="current.usage" class="tabular-nums">
                            {{ current.usage.cpu.toFixed(0) }}%
                        </div>
                        <div v-else class="text-muted-foreground">—</div>
                    </div>
                    <ServerPowerButtons
                        class="relative z-10"
                        :slug="server.slug"
                        :state="current.state"
                        :can-manage="server.can.includes('power')"
                    >
                        <Button
                            variant="outline"
                            class="border-primary"
                            as-child
                        >
                            <Link :href="show({ server: server.slug })"
                                >Open</Link
                            >
                        </Button>
                    </ServerPowerButtons>
                </div>

                <div
                    v-if="canManage && !current.detail"
                    class="flex flex-wrap gap-x-4 gap-y-1 border-t border-divider px-4 py-2.5 text-sm text-muted-foreground"
                >
                    <span>{{
                        current.last_backup
                            ? `Last backup ${shortTime(current.last_backup)}`
                            : 'No backup yet'
                    }}</span>
                    <span v-if="current.disk_bytes"
                        >{{ bytesLabel(current.disk_bytes) }} on disk</span
                    >
                    <span v-if="current.mods_changed"
                        >{{ current.mods_changed }} mod
                        {{ current.mods_changed === 1 ? 'file' : 'files' }}
                        waiting for a restart</span
                    >
                </div>

                <div
                    v-if="current.detail"
                    class="border-t border-divider px-4 py-2.5 text-sm"
                    :class="
                        current.state === 'error'
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ current.detail }}
                </div>
            </article>
        </div>
    </div>
</template>
