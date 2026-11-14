<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store, test, update } from '@/routes/webhooks';

type Webhook = {
    id: number;
    name: string;
    host: string | null;
    format: string;
    events: string[];
    is_active: boolean;
    last_status: number | null;
    last_sent: string | null;
};

defineProps<{
    webhooks: Webhook[];
    formats: string[];
    groups: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Webhooks',
                href: index(),
            },
        ],
    },
});

const formatLabels: Record<string, string> = {
    discord: 'Discord',
    slack: 'Slack, Mattermost or Teams',
    json: 'Other service (all fields as JSON)',
};

const groupLabels: Record<string, string> = {
    power: 'Someone starts, stops, restarts or kills a server',
    status: 'A server comes online or goes offline',
    crash: 'A server crashes',
    backups: 'A backup is made or restored, or a schedule runs',
    changes: 'A server is created, changed or deleted',
};

const keep = { preserveScroll: true };

const result = (webhook: Webhook) => {
    if (webhook.last_sent === null) {
        return 'nothing sent yet';
    }

    const answer =
        webhook.last_status === 0
            ? 'no answer'
            : `answered ${webhook.last_status}`;

    return `last sent ${webhook.last_sent}, ${answer}`;
};

const remove = (webhook: Webhook) => {
    if (confirm(`Delete the webhook "${webhook.name}"?`)) {
        router.delete(destroy.url({ webhook: webhook.id }), keep);
    }
};
</script>

<template>
    <Head title="Webhooks" />

    <h1 class="sr-only">Webhooks</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Webhooks"
            description="Have the panel tell another service, such as a Discord channel or an SMS gateway, when something happens on a server you can see."
        />

        <Form
            v-bind="store.form()"
            :options="keep"
            reset-on-success
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxlength="60"
                    placeholder="Discord #minecraft"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="url">Webhook address</Label>
                <Input
                    id="url"
                    name="url"
                    type="url"
                    required
                    autocomplete="off"
                    placeholder="A Discord, Slack or other webhook address"
                    aria-describedby="url_hint"
                />
                <span
                    id="url_hint"
                    class="text-[0.8125rem] text-muted-foreground"
                >
                    Must start with https. It is stored encrypted and not shown
                    again.
                </span>
                <InputError :message="errors.url" />
            </div>

            <div class="grid gap-2">
                <Label for="format">Service</Label>
                <select
                    id="format"
                    name="format"
                    class="h-9 rounded-md border border-input bg-card px-2.5 text-sm"
                >
                    <option
                        v-for="format in formats"
                        :key="format"
                        :value="format"
                    >
                        {{ formatLabels[format] ?? format }}
                    </option>
                </select>
                <InputError :message="errors.format" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Send a message when</legend>
                <label
                    v-for="group in groups"
                    :key="group"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name="events[]"
                        :value="group"
                        :checked="group === 'crash'"
                        class="size-4 accent-primary"
                    />
                    {{ groupLabels[group] ?? group }}
                </label>
                <InputError :message="errors.events" />
            </fieldset>

            <Button :disabled="processing">Add webhook</Button>
        </Form>

        <div class="overflow-hidden rounded-lg border border-border">
            <p
                v-if="!webhooks.length"
                class="p-4 text-sm text-muted-foreground"
            >
                No webhooks yet.
            </p>
            <div
                v-for="webhook in webhooks"
                :key="webhook.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border p-4 first:border-t-0"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ webhook.name }}
                        <span
                            v-if="!webhook.is_active"
                            class="font-normal text-muted-foreground"
                            >(paused)</span
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ formatLabels[webhook.format] ?? webhook.format }} at
                        {{ webhook.host }} · {{ webhook.events.join(', ') }} ·
                        {{ result(webhook) }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!webhook.is_active"
                    @click="
                        router.post(test.url({ webhook: webhook.id }), {}, keep)
                    "
                >
                    Send test
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    @click="
                        router.put(
                            update.url({ webhook: webhook.id }),
                            { is_active: !webhook.is_active },
                            keep,
                        )
                    "
                >
                    {{ webhook.is_active ? 'Pause' : 'Resume' }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="remove(webhook)"
                >
                    Delete
                </Button>
            </div>
        </div>
    </div>
</template>
