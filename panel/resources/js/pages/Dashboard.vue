<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import BlockCube from '@/components/BlockCube.vue';
import BlockySky from '@/components/BlockySky.vue';
import CopyButton from '@/components/CopyButton.vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { useLiveReload, useLiveStates } from '@/lib/live';
import type { Server, States } from '@/lib/servers';
import {
    bytesLabel,
    cubeTexture,
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

useLiveStates();
// A server was created, changed or deleted, perhaps by someone else.
useLiveReload((change) => change.what === 'server', ['servers', 'states']);

const page = usePage();
const canManage = computed(() => page.props.auth.user.role === 'admin');
const minecraftName = computed(() => page.props.auth.user.minecraft_name);
const blocky = computed(() => page.props.theme === 'blocky');
const nodeCount = computed(
    () => new Set(props.servers.map((server) => server.node)).size,
);

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

const stopEverything = async () => {
    if (
        await ask(
            'Stop all :count running servers? Players are disconnected.',
            { count: runningCount.value },
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
    <Head :title="t('Servers')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <BlockySky>
            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div class="flex-[1_1_320px]">
                    <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                        {{ t('Servers') }}
                    </h1>
                    <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                        {{
                            t(
                                'Every Minecraft server on this host, each in its own container. Players join on port 25565 by hostname.',
                            )
                        }}
                    </p>
                </div>
                <Button
                    v-if="canManage && runningCount"
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="stopEverything"
                >
                    {{ t('Stop all') }}
                </Button>
                <Button v-if="canManage" as-child>
                    <Link :href="create()">
                        <Plus aria-hidden="true" />
                        {{ t('New server') }}
                    </Link>
                </Button>
            </div>
        </BlockySky>

        <p
            v-if="states && !states.online"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{
                canManage
                    ? t(
                          'The Rust service did not answer, so server states are unknown and controls will fail. Check that it is running and that a node is registered (php artisan node:register).',
                      )
                    : t(
                          'The Rust service did not answer, so server states are unknown.',
                      )
            }}
        </p>

        <div v-if="servers.length" class="flex flex-wrap gap-3">
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div class="text-[0.8125rem] text-muted-foreground">
                    {{ t('Running') }}
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ runningCount }}
                    <span class="text-base font-normal text-muted-foreground">{{
                        t('of :count', { count: servers.length })
                    }}</span>
                </div>
            </div>
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div class="text-[0.8125rem] text-muted-foreground">
                    {{ t('Players online') }}
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
                    {{ t('Last backup') }}
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ lastBackup ? shortTime(lastBackup) : t('None yet') }}
                </div>
            </div>
            <div
                class="flex-[1_1_200px] rounded-md border border-border bg-card px-4 py-3.5"
            >
                <div
                    class="flex justify-between text-[0.8125rem] text-muted-foreground"
                >
                    <span>{{ t('Memory allocated') }}</span>
                    <span v-if="memoryLimitMb" class="tabular-nums">{{
                        t('limit :size GB', {
                            size: Math.round(memoryLimitMb / 1024),
                        })
                    }}</span>
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ memoryGb }} GB
                </div>
                <div
                    v-if="memoryLimitMb"
                    class="mc-meter mt-1 h-1.5 overflow-hidden rounded-full bg-divider"
                    role="img"
                    :aria-label="
                        t(':used of :limit GB allocated', {
                            used: memoryGb,
                            limit: Math.round(memoryLimitMb / 1024),
                        })
                    "
                >
                    <div
                        class="mc-meter-fill h-full bg-primary"
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
                    ? t('No servers yet.')
                    : t('No servers are shared with you yet.')
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
            <label for="filter-text" class="sr-only">{{
                t('Name or hostname')
            }}</label>
            <input
                id="filter-text"
                v-model="search"
                type="search"
                :placeholder="t('Name or hostname')"
                class="h-9 flex-[3_1_220px] rounded-md border border-input bg-card px-3 text-sm"
            />
            <label for="filter-type" class="sr-only">{{
                t('Server type')
            }}</label>
            <select id="filter-type" v-model="typeFilter" :class="selectClass">
                <option value="">{{ t('All types') }}</option>
                <option v-for="type in types" :key="type" :value="type">
                    {{ t(typeLabels[type] ?? type) }}
                </option>
            </select>
            <label for="filter-status" class="sr-only">{{ t('Status') }}</label>
            <select
                id="filter-status"
                v-model="statusFilter"
                :class="selectClass"
            >
                <option value="">{{ t('Any status') }}</option>
                <option value="running">{{ t('Running') }}</option>
                <option value="starting">{{ t('Starting') }}</option>
                <option value="offline">{{ t('Offline') }}</option>
                <option value="error">{{ t('Error') }}</option>
            </select>
            <Button type="reset" variant="outline" class="border-primary">
                {{ t('Clear') }}
            </Button>
        </form>

        <p
            v-if="servers.length && !visibleCards.length"
            role="status"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            {{ t('No server matches these filters.') }}
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
                    <img
                        v-if="server.picture"
                        :src="server.picture"
                        alt=""
                        width="34"
                        height="34"
                        class="mr-1.5 size-[34px] shrink-0 [image-rendering:pixelated]"
                    />
                    <BlockCube
                        v-else-if="blocky"
                        :texture="cubeTexture(server.server_type)"
                        :size="34"
                        :spin="current.state !== 'offline'"
                        :dim="current.state !== 'running'"
                        class="mr-1.5"
                        :class="{ 'mc-bob': current.state === 'running' }"
                    />
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
                        >{{ t('Default') }}</span
                    >
                    <StatusPill :tone="toneOf(current.state)">
                        {{ t(current.state) }}
                    </StatusPill>
                    <span
                        v-if="
                            minecraftName &&
                            current.players?.names.includes(minecraftName)
                        "
                        class="rounded-full border border-brand px-2 py-px text-xs font-semibold text-primary"
                        >{{ t('You are online here') }}</span
                    >
                    <span
                        v-if="nodeCount > 1"
                        class="text-sm text-muted-foreground"
                        >{{ t('on :node', { node: server.node }) }}</span
                    >
                    <span class="flex-1" />
                    <span
                        class="relative z-10 flex items-center gap-1 font-mono text-sm text-primary"
                    >
                        {{ server.hostname }}
                        <CopyButton
                            :text="server.hostname"
                            :label="
                                t('Copy the address of :name', {
                                    name: server.name,
                                })
                            "
                        />
                    </span>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3"
                >
                    <div class="flex-[1_1_150px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            {{ t('Software') }}
                        </div>
                        <div>{{ software(server) }}</div>
                    </div>
                    <div class="flex-[1_1_110px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            {{ t('Players') }}
                        </div>
                        <div v-if="current.players" class="tabular-nums">
                            {{ current.players.online }} /
                            {{ current.players.max }}
                        </div>
                        <div v-else class="text-muted-foreground">—</div>
                    </div>
                    <div class="flex-[1_1_110px]">
                        <div class="text-[0.8125rem] text-muted-foreground">
                            {{ t('Memory') }}
                        </div>
                        <template v-if="current.usage">
                            <div class="tabular-nums">
                                {{ gigabytes(current.usage.memory) }} /
                                {{ server.memory_mb / 1024 }} GB
                            </div>
                            <div
                                class="mc-meter mt-1 h-1 overflow-hidden rounded-full bg-divider"
                                aria-hidden="true"
                            >
                                <div
                                    class="mc-meter-fill h-full bg-primary"
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
                            {{ t('CPU') }}
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
                            <Link :href="show({ server: server.slug })">{{
                                t('Open')
                            }}</Link>
                        </Button>
                    </ServerPowerButtons>
                </div>

                <div
                    v-if="canManage && !current.detail"
                    class="flex flex-wrap gap-x-4 gap-y-1 border-t border-divider px-4 py-2.5 text-sm text-muted-foreground"
                >
                    <span>{{
                        current.last_backup
                            ? t('Last backup :time', {
                                  time: shortTime(current.last_backup),
                              })
                            : t('No backup yet')
                    }}</span>
                    <span v-if="current.disk_bytes">{{
                        t(':size on disk', {
                            size: bytesLabel(current.disk_bytes),
                        })
                    }}</span>
                    <span v-if="current.mods_changed">{{
                        current.mods_changed === 1
                            ? t(':count mod file waiting for a restart', {
                                  count: current.mods_changed,
                              })
                            : t(':count mod files waiting for a restart', {
                                  count: current.mods_changed,
                              })
                    }}</span>
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
