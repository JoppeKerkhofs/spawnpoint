<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerFields from '@/components/ServerFields.vue';
import ServerPowerButtons from '@/components/ServerPowerButtons.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Server, ServerOptions, States } from '@/lib/servers';
import { serverState, software, toneOf } from '@/lib/servers';
import { dashboard } from '@/routes';
import { destroy, update } from '@/routes/servers';

const props = defineProps<{
    server: Server;
    options: ServerOptions;
    baseDomain: string;
    states?: States;
}>();

usePoll(4000, { only: ['states'] });

const current = computed(() => serverState(props.states, props.server.slug));
const javaVersion = ref(props.server.java_version);

const remove = () => {
    if (
        confirm(
            `Delete ${props.server.name}? Its container is removed; the data folder stays on the host.`,
        )
    ) {
        router.delete(destroy.url({ server: props.server.slug }));
    }
};
</script>

<template>
    <Head :title="server.name" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div class="flex flex-col gap-3">
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
                <ServerPowerButtons
                    :slug="server.slug"
                    :state="current.state"
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
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <Form
                v-bind="update.form({ server: server.slug })"
                :options="{ preserveScroll: true }"
                class="flex min-w-0 flex-[999_1_560px] flex-col gap-3"
                v-slot="{ errors, processing }"
            >
                <SectionCard title="Name">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
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
                    :errors="errors"
                    :server="server"
                />

                <div
                    class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2"
                >
                    <InputError :message="errors.node" />
                    <span class="text-sm text-muted-foreground">
                        Saving recreates the container. The world and all files
                        are kept.
                    </span>
                    <Button :disabled="processing">
                        <Spinner v-if="processing" />
                        Save and recreate
                    </Button>
                </div>
            </Form>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                <SectionCard title="Join">
                    <div class="text-[0.8125rem] text-muted-foreground">
                        Server address
                    </div>
                    <div class="font-mono text-sm break-all text-primary">
                        {{ server.hostname }}
                    </div>
                </SectionCard>

                <SectionCard title="Delete server">
                    <p class="mb-3 text-sm text-muted-foreground">
                        Removes the container and this entry. The data folder
                        stays on the host.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-destructive text-destructive hover:text-destructive"
                        @click="remove"
                    >
                        Delete server
                    </Button>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
