<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used: string | null;
    created: string;
};

defineProps<{
    tokens: Token[];
    abilities: string[];
    newToken: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API tokens',
                href: index(),
            },
        ],
    },
});

const abilityLabels: Record<string, string> = {
    'servers:read': 'List servers and their state',
    'servers:power': 'Start, stop, restart and kill servers',
    'console:use': 'Open the live console',
    'logs:read': 'Read the logs',
    'backups:manage': 'List and start backups',
    'files:read': 'List folders and read text files',
    'files:write': 'Write, delete, upload and download files',
};

const revoke = (token: Token) => {
    if (
        confirm(`Revoke the token "${token.name}"? Apps using it stop working.`)
    ) {
        router.delete(destroy.url({ token: token.id }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Let another app, such as a phone app, use the panel through /api/v1. A token can never do more than your own role allows."
        />

        <div
            v-if="newToken"
            role="status"
            class="space-y-2 rounded-md border border-brand bg-card p-4"
        >
            <p class="font-medium">
                Copy this token now. It is not shown again.
            </p>
            <code class="block font-mono text-sm break-all">{{
                newToken
            }}</code>
        </div>

        <Form
            v-bind="store.form()"
            :options="{ preserveScroll: true }"
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
                    placeholder="Phone app"
                />
                <InputError :message="errors.name" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">This token may</legend>
                <label
                    v-for="ability in abilities"
                    :key="ability"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        name="abilities[]"
                        :value="ability"
                        class="size-4 accent-primary"
                    />
                    {{ abilityLabels[ability] ?? ability }}
                    <code class="font-mono text-xs text-muted-foreground">{{
                        ability
                    }}</code>
                </label>
                <InputError :message="errors.abilities" />
            </fieldset>

            <Button :disabled="processing">Create token</Button>
        </Form>

        <div class="overflow-hidden rounded-lg border border-border">
            <p v-if="!tokens.length" class="p-4 text-sm text-muted-foreground">
                No tokens yet.
            </p>
            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border p-4 first:border-t-0"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ token.abilities.join(', ') }} · created
                        {{ token.created }} ·
                        {{
                            token.last_used
                                ? `last used ${token.last_used}`
                                : 'never used'
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="revoke(token)"
                >
                    Revoke
                </Button>
            </div>
        </div>
    </div>
</template>
