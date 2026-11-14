<script setup lang="ts">
import { Deferred, Head } from '@inertiajs/vue3';
import StatusPill from '@/components/StatusPill.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';

type Container = {
    id: string;
    name: string | null;
    image: string | null;
    state: string | null;
    status: string | null;
};

type Node = {
    id: number;
    name: string;
    online: boolean;
    containers: Container[];
};

defineProps<{
    nodes?: Node[];
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
</script>

<template>
    <Head title="Servers" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                Servers
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                Every Minecraft server on this host, each in its own container.
                Players join on port 25565 by hostname.
            </p>
        </div>

        <Deferred data="nodes">
            <template #fallback>
                <Skeleton class="h-32" />
            </template>

            <p
                v-if="!nodes?.length"
                class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
            >
                No node registered yet. Run
                <code class="font-mono text-primary"
                    >php artisan node:register local http://localhost:8080</code
                >
                and start the Rust service with the token it prints.
            </p>

            <div class="flex flex-col gap-3">
                <article
                    v-for="node in nodes"
                    :key="node.id"
                    class="overflow-hidden rounded-md border border-border bg-card"
                >
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-muted px-4 pt-3.5 pb-2.5"
                    >
                        <h2
                            class="text-lg leading-[1.35] font-bold text-primary"
                        >
                            {{ node.name }}
                        </h2>
                        <StatusPill :tone="node.online ? 'ok' : 'error'">
                            {{ node.online ? 'Online' : 'Offline' }}
                        </StatusPill>
                    </div>

                    <p
                        v-if="!node.containers.length"
                        class="px-4 py-3 text-sm"
                        :class="
                            node.online
                                ? 'text-muted-foreground'
                                : 'text-destructive'
                        "
                    >
                        {{
                            node.online
                                ? 'No managed containers yet.'
                                : 'The Rust service did not answer.'
                        }}
                    </p>
                    <div v-else class="overflow-x-auto px-4 py-1">
                        <table class="w-full text-left">
                            <thead
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-2 font-normal">Name</th>
                                    <th class="py-2 font-normal">Image</th>
                                    <th class="py-2 font-normal">State</th>
                                    <th class="py-2 font-normal">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="container in node.containers"
                                    :key="container.id"
                                    class="border-t border-divider"
                                >
                                    <td class="py-2">{{ container.name }}</td>
                                    <td class="py-2 font-mono text-sm">
                                        {{ container.image }}
                                    </td>
                                    <td class="py-2">{{ container.state }}</td>
                                    <td class="py-2 text-muted-foreground">
                                        {{ container.status }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </Deferred>
    </div>
</template>
