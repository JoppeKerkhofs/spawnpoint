<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerFields from '@/components/ServerFields.vue';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type {
    MemoryBudget,
    Server,
    ServerOptions,
    States,
} from '@/lib/servers';
import { destroy, dns, move, update } from '@/routes/servers';

const props = defineProps<{
    server: Server;
    options: ServerOptions;
    memory: MemoryBudget;
    baseDomain: string;
    states?: States;
    dnsEnabled: boolean;
    otherNodes: { id: number; name: string }[];
}>();

const javaVersion = ref(props.server.java_version);

const remove = async () => {
    if (
        await ask(
            'Delete :name? Its container is removed; the data folder stays on the host.',
            { name: props.server.name },
        )
    ) {
        router.delete(destroy.url({ server: props.server.slug }));
    }
};
</script>

<template>
    <Head :title="t(':name configuration', { name: server.name })" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="configuration" />

        <div class="flex flex-wrap items-start gap-5">
            <Form
                v-bind="update.form({ server: server.slug })"
                :options="{ preserveScroll: true }"
                class="flex min-w-0 flex-[999_1_560px] flex-col gap-3"
                v-slot="{ errors, processing }"
            >
                <SectionCard :title="t('Name')">
                    <div class="grid gap-2">
                        <Label for="name">{{ t('Name') }}</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            maxlength="60"
                            :default-value="server.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                </SectionCard>

                <ServerFields
                    v-model:java-version="javaVersion"
                    :options="options"
                    :memory="memory"
                    :errors="errors"
                    :server="server"
                />

                <div
                    class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2"
                >
                    <InputError :message="errors.node" />
                    <span class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Saving recreates the container. The world and all files are kept.',
                            )
                        }}
                    </span>
                    <Button :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ t('Save and recreate') }}
                    </Button>
                </div>
            </Form>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                <SectionCard
                    v-if="dnsEnabled && server.can.includes('admin')"
                    :title="t('DNS record')"
                >
                    <p class="mb-3 text-sm text-muted-foreground">
                        {{ t('The panel adds a Cloudflare record for') }}
                        <span class="font-mono break-all">{{
                            server.hostname
                        }}</span>
                        {{
                            t(
                                'when a server is created. If that failed, or the record was removed, add it again here.',
                            )
                        }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="
                            router.post(
                                dns.url({ server: server.slug }),
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        {{ t('Add DNS record again') }}
                    </Button>
                </SectionCard>

                <SectionCard
                    v-if="otherNodes.length > 0 && server.can.includes('admin')"
                    :title="t('Move to another node')"
                >
                    <Form
                        v-bind="move.form({ server: server.slug })"
                        :options="{ preserveScroll: true }"
                        class="grid gap-2"
                        v-slot="{ errors, processing }"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'The server is stopped, backed up and unpacked on the other host, which takes as long as its world is big. The old data folder stays behind.',
                                )
                            }}
                        </p>
                        <Label for="move_node_id">{{ t('New node') }}</Label>
                        <select
                            id="move_node_id"
                            name="node_id"
                            class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
                        >
                            <option
                                v-for="each in otherNodes"
                                :key="each.id"
                                :value="each.id"
                            >
                                {{ each.name }}
                            </option>
                        </select>
                        <InputError :message="errors.node_id" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="mt-1 border-primary"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            {{ t('Move server') }}
                        </Button>
                    </Form>
                </SectionCard>

                <SectionCard
                    v-if="server.can.includes('admin')"
                    :title="t('Delete server')"
                >
                    <p class="mb-3 text-sm text-muted-foreground">
                        {{
                            t(
                                'Removes the container and this entry. The data folder stays on the host.',
                            )
                        }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-destructive text-destructive hover:text-destructive"
                        @click="remove"
                    >
                        {{ t('Delete server') }}
                    </Button>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
