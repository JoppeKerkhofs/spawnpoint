<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import type { Server, States } from '@/lib/servers';
import { serverState, software, toneOf } from '@/lib/servers';
import { dashboard } from '@/routes';
import { create, show } from '@/routes/servers';

const props = defineProps<{
    servers: Server[];
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

const runningCount = computed(
    () => cards.value.filter((card) => card.current.state === 'running').length,
);

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
            The Rust service did not answer, so server states are unknown and
            controls will fail. Check that it is running and that a node is
            registered (php artisan node:register).
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
                    Memory allocated
                </div>
                <div class="text-2xl font-bold text-primary tabular-nums">
                    {{ memoryGb }} GB
                </div>
            </div>
        </div>

        <p
            v-if="!servers.length"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            No servers yet.
        </p>

        <div class="flex flex-col gap-3">
            <article
                v-for="{ server, current } in cards"
                :key="server.id"
                class="overflow-hidden rounded-md border border-border bg-card"
            >
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-muted px-4 pt-3.5 pb-2.5"
                >
                    <h2 class="text-lg leading-[1.35] font-bold text-primary">
                        <Link :href="show({ server: server.slug })">
                            {{ server.name }}
                        </Link>
                    </h2>
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
                            Memory
                        </div>
                        <div class="tabular-nums">
                            {{ server.memory_mb / 1024 }} GB
                        </div>
                    </div>
                    <ServerPowerButtons
                        :slug="server.slug"
                        :state="current.state"
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
