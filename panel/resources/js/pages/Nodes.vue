<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { destroy, store, update } from '@/routes/nodes';

type Node = {
    id: number;
    name: string;
    base_url: string;
    dns_target: string | null;
    bluemap_url: string | null;
    minecraft_port: number | null;
    maps_port: number | null;
    memory_limit_mb: number | null;
    online: boolean;
    last_seen_at: string | null;
    servers: number;
    allocated_mb: number;
    limit_mb: number | null;
    local: boolean;
};

defineProps<{
    nodes: Node[];
    newNode: { name: string; token: string } | null;
}>();

const hint = 'text-[0.8125rem] text-muted-foreground';
const exampleAddress = 'http://10.0.0.5:8080';
const gigabytes = (megabytes: number) =>
    (megabytes / 1024).toFixed(megabytes % 1024 ? 1 : 0);

const remove = async (node: Node) => {
    if (
        await ask(
            'Delete the node :name? The host and its files are not touched.',
            { name: node.name },
        )
    ) {
        router.delete(destroy.url({ node: node.id }), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="t('Nodes')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                {{ t('Nodes') }}
            </h1>
            <p class="mt-1 max-w-3xl text-[0.9375rem] text-muted-foreground">
                {{
                    t(
                        'A node is a host that runs Minecraft servers. The panel reaches each node over the network and tells it what to do; players connect to the node itself.',
                    )
                }}
            </p>
        </div>

        <div
            v-if="newNode"
            role="status"
            class="max-w-3xl space-y-3 rounded-md border border-brand bg-card p-4"
        >
            <p class="font-semibold">
                {{
                    t(
                        'The node :name is registered. Start it on its host with this token; it is not shown again.',
                        { name: newNode.name },
                    )
                }}
            </p>
            <code class="block font-mono text-sm break-all"
                >SPAWNPOINT_TOKEN={{ newNode.token }}</code
            >
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                <li>
                    {{
                        t(
                            'Copy compose.node.yaml and .env.node.example from the repository to the host.',
                        )
                    }}
                </li>
                <li>
                    {{
                        t(
                            'Save the example as .env.node and put the token and the version in it.',
                        )
                    }}
                </li>
                <li>
                    <code class="font-mono break-all"
                        >docker compose -f compose.node.yaml --env-file
                        .env.node up -d</code
                    >
                </li>
            </ol>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-3">
                <article
                    v-for="node in nodes"
                    :key="node.id"
                    class="overflow-hidden rounded-md border border-border bg-card"
                >
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted px-4 py-3"
                    >
                        <h2 class="font-bold text-primary">{{ node.name }}</h2>
                        <StatusPill :tone="node.online ? 'ok' : 'error'">
                            {{ node.online ? t('online') : t('not answering') }}
                        </StatusPill>
                        <span class="text-sm text-muted-foreground">
                            {{
                                node.servers === 1
                                    ? t(':count server', {
                                          count: node.servers,
                                      })
                                    : t(':count servers', {
                                          count: node.servers,
                                      })
                            }}
                            ·
                            {{
                                node.limit_mb
                                    ? t(':used of :limit GB memory given out', {
                                          used: gigabytes(node.allocated_mb),
                                          limit: gigabytes(node.limit_mb),
                                      })
                                    : t(':used GB memory given out', {
                                          used: gigabytes(node.allocated_mb),
                                      })
                            }}
                        </span>
                    </div>

                    <Form
                        v-bind="update.form({ node: node.id })"
                        :options="{ preserveScroll: true }"
                        class="grid gap-4 px-4 py-4 sm:grid-cols-2"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid content-start gap-2">
                            <Label :for="`name-${node.id}`">{{
                                t('Name')
                            }}</Label>
                            <Input
                                :id="`name-${node.id}`"
                                name="name"
                                maxlength="40"
                                :default-value="node.name"
                                :disabled="node.local"
                                :required="!node.local"
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label :for="`base_url-${node.id}`">{{
                                t('Address of the node')
                            }}</Label>
                            <Input
                                :id="`base_url-${node.id}`"
                                name="base_url"
                                spellcheck="false"
                                :default-value="node.base_url"
                                :disabled="node.local"
                                :required="!node.local"
                                :aria-describedby="`base_url_hint-${node.id}`"
                            />
                            <span
                                :id="`base_url_hint-${node.id}`"
                                :class="hint"
                            >
                                {{
                                    node.local
                                        ? t(
                                              "The node on the panel's own host. Its name and address come from the panel's environment.",
                                          )
                                        : t(
                                              'Where the panel reaches it, such as http://10.0.0.5:8080.',
                                          )
                                }}
                            </span>
                            <InputError :message="errors.base_url" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label :for="`dns_target-${node.id}`">{{
                                t('Point DNS records at')
                            }}</Label>
                            <Input
                                :id="`dns_target-${node.id}`"
                                name="dns_target"
                                spellcheck="false"
                                :default-value="node.dns_target ?? ''"
                                :aria-describedby="`dns_target_hint-${node.id}`"
                            />
                            <span
                                :id="`dns_target_hint-${node.id}`"
                                :class="hint"
                            >
                                {{
                                    t(
                                        "The public address of this host, for the Cloudflare records of its servers. Empty: players come in through the panel's host, which passes them on.",
                                    )
                                }}
                            </span>
                            <InputError :message="errors.dns_target" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label :for="`bluemap_url-${node.id}`">{{
                                t('Address of the web maps')
                            }}</Label>
                            <Input
                                :id="`bluemap_url-${node.id}`"
                                name="bluemap_url"
                                type="url"
                                :default-value="node.bluemap_url ?? ''"
                                :aria-describedby="`bluemap_url_hint-${node.id}`"
                            />
                            <span
                                :id="`bluemap_url_hint-${node.id}`"
                                :class="hint"
                            >
                                {{
                                    t(
                                        "Where this host's maps port is reachable from outside. Empty: the maps are served through the panel's maps address.",
                                    )
                                }}
                            </span>
                            <InputError :message="errors.bluemap_url" />
                        </div>
                        <template v-if="!node.local">
                            <div class="grid content-start gap-2">
                                <Label :for="`minecraft_port-${node.id}`">{{
                                    t('Minecraft port')
                                }}</Label>
                                <Input
                                    :id="`minecraft_port-${node.id}`"
                                    name="minecraft_port"
                                    type="number"
                                    min="1"
                                    max="65535"
                                    step="1"
                                    class="max-w-48"
                                    placeholder="25565"
                                    :default-value="node.minecraft_port ?? ''"
                                    :aria-describedby="`minecraft_port_hint-${node.id}`"
                                />
                                <span
                                    :id="`minecraft_port_hint-${node.id}`"
                                    :class="hint"
                                >
                                    {{
                                        t(
                                            'MINECRAFT_PORT on the host of this node, where players join.',
                                        )
                                    }}
                                </span>
                                <InputError :message="errors.minecraft_port" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label :for="`maps_port-${node.id}`">{{
                                    t('Web maps port')
                                }}</Label>
                                <Input
                                    :id="`maps_port-${node.id}`"
                                    name="maps_port"
                                    type="number"
                                    min="1"
                                    max="65535"
                                    step="1"
                                    class="max-w-48"
                                    placeholder="8100"
                                    :default-value="node.maps_port ?? ''"
                                    :aria-describedby="`maps_port_hint-${node.id}`"
                                />
                                <span
                                    :id="`maps_port_hint-${node.id}`"
                                    :class="hint"
                                >
                                    {{
                                        t(
                                            'BLUEMAP_PORT on the host of this node, where its web maps are.',
                                        )
                                    }}
                                </span>
                                <InputError :message="errors.maps_port" />
                            </div>
                        </template>
                        <div class="grid content-start gap-2">
                            <Label :for="`memory_limit_mb-${node.id}`">{{
                                t('Memory for its servers together (MB)')
                            }}</Label>
                            <Input
                                :id="`memory_limit_mb-${node.id}`"
                                name="memory_limit_mb"
                                type="number"
                                min="1024"
                                step="1"
                                class="max-w-48"
                                :default-value="node.memory_limit_mb ?? ''"
                                :aria-describedby="`memory_hint-${node.id}`"
                            />
                            <span :id="`memory_hint-${node.id}`" :class="hint">
                                {{
                                    t(
                                        "Empty uses the panel's setting, or 90% of this host's memory.",
                                    )
                                }}
                            </span>
                            <InputError :message="errors.memory_limit_mb" />
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-2 sm:col-span-2"
                        >
                            <Button :disabled="processing">{{
                                t('Save')
                            }}</Button>
                            <Button
                                v-if="!node.local && node.servers === 0"
                                type="button"
                                variant="outline"
                                class="border-destructive text-destructive hover:text-destructive"
                                @click="remove(node)"
                            >
                                {{ t('Delete') }}
                            </Button>
                            <InputError :message="errors.node" />
                        </div>
                    </Form>
                </article>
            </div>

            <aside class="min-w-0 flex-[1_1_300px]">
                <SectionCard :title="t('Add a node')">
                    <Form
                        v-bind="store.form()"
                        :options="{ preserveScroll: true }"
                        reset-on-success
                        class="flex flex-col gap-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">{{ t('Name') }}</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                maxlength="40"
                                :placeholder="t('For example: attic')"
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="base_url">{{
                                t('Address of the node')
                            }}</Label>
                            <Input
                                id="base_url"
                                name="base_url"
                                required
                                spellcheck="false"
                                :placeholder="exampleAddress"
                                aria-describedby="base_url_hint"
                            />
                            <span id="base_url_hint" :class="hint">
                                {{
                                    t(
                                        'Use a private network or a VPN between the panel and the node: requests are signed, but not encrypted.',
                                    )
                                }}
                            </span>
                            <InputError :message="errors.base_url" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="minecraft_port">{{
                                t('Minecraft port')
                            }}</Label>
                            <Input
                                id="minecraft_port"
                                name="minecraft_port"
                                type="number"
                                min="1"
                                max="65535"
                                step="1"
                                class="max-w-48"
                                placeholder="25565"
                                aria-describedby="minecraft_port_hint"
                            />
                            <span id="minecraft_port_hint" :class="hint">
                                {{
                                    t(
                                        'MINECRAFT_PORT on the host of this node, where players join.',
                                    )
                                }}
                            </span>
                            <InputError :message="errors.minecraft_port" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="maps_port">{{
                                t('Web maps port')
                            }}</Label>
                            <Input
                                id="maps_port"
                                name="maps_port"
                                type="number"
                                min="1"
                                max="65535"
                                step="1"
                                class="max-w-48"
                                placeholder="8100"
                                aria-describedby="maps_port_hint"
                            />
                            <span id="maps_port_hint" :class="hint">
                                {{
                                    t(
                                        'BLUEMAP_PORT on the host of this node, where its web maps are.',
                                    )
                                }}
                            </span>
                            <InputError :message="errors.maps_port" />
                        </div>
                        <Button :disabled="processing" class="self-start">{{
                            t('Add node')
                        }}</Button>
                    </Form>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
