<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AbilityPicker from '@/components/AbilityPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiDocs } from '@/routes';
import {
    destroy,
    index,
    pairing as pairingRoute,
    store,
} from '@/routes/api-tokens';
import { approve } from '@/routes/api-tokens/pairing';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used: string | null;
    created: string;
};

type Group = {
    key: string;
    label: string;
    abilities: { name: string; label: string; description: string }[];
};

const props = defineProps<{
    tokens: Token[];
    groups: Group[];
    presets: Record<string, string[]>;
    newToken: string | null;
    pairing: { code: string; device_name: string } | null;
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

// A new token starts read-only; the picker widens it.
const abilities = ref<string[]>([...props.presets['read-only']]);
const pairingAbilities = ref<string[]>([...props.presets['read-only']]);

const revoke = async (token: Token) => {
    if (
        await ask('Revoke the token ":name"? Apps using it stop working.', {
            name: token.name,
        })
    ) {
        router.delete(destroy.url({ token: token.id }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="t('API tokens')" />

    <h1 class="sr-only">{{ t('API tokens') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('API tokens')"
            :description="
                t(
                    'Let another app, such as a phone app, use the panel through :path. A token can never do more than your own role allows.',
                    { path: '/api/v1' },
                )
            "
        />

        <p class="text-sm">
            <Link :href="apiDocs()" class="text-primary underline">{{
                t('Read the API documentation')
            }}</Link>
        </p>

        <div
            v-if="newToken"
            role="status"
            class="space-y-2 rounded-md border border-brand bg-card p-4"
        >
            <p class="font-medium">
                {{ t('Copy this token now. It is not shown again.') }}
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
                <Label for="name">{{ t('Name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxlength="60"
                    :placeholder="t('Phone app')"
                />
                <InputError :message="errors.name" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">
                    {{ t('This token may') }}
                </legend>
                <AbilityPicker
                    v-model="abilities"
                    :groups="groups"
                    :presets="presets"
                />
                <InputError :message="errors.abilities" />
            </fieldset>

            <Button :disabled="processing">{{ t('Create token') }}</Button>
        </Form>

        <SectionCard :title="t('Pair a device')">
            <Form
                v-if="!pairing"
                v-bind="pairingRoute.form()"
                :options="{ preserveScroll: true }"
                class="grid gap-2"
                v-slot="{ errors, processing }"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Open the app, choose "Pair with a panel", and type the code it shows here.',
                        )
                    }}
                </p>
                <Label for="pairing_code">{{ t('Code from the app') }}</Label>
                <Input
                    id="pairing_code"
                    name="code"
                    maxlength="12"
                    autocomplete="off"
                    class="max-w-48 font-mono tracking-widest uppercase"
                />
                <InputError :message="errors.code" />
                <Button type="submit" class="mt-1 w-fit" :disabled="processing">
                    {{ t('Look it up') }}
                </Button>
            </Form>

            <Form
                v-else
                v-bind="approve.form()"
                :options="{ preserveScroll: true }"
                class="grid gap-3"
                v-slot="{ errors, processing }"
            >
                <p class="text-sm">
                    {{
                        t(
                            '":device" is asking to be paired. Choose what it may do.',
                            {
                                device: pairing.device_name,
                            },
                        )
                    }}
                </p>
                <input type="hidden" name="code" :value="pairing.code" />
                <AbilityPicker
                    v-model="pairingAbilities"
                    :groups="groups"
                    :presets="presets"
                />
                <InputError
                    :message="
                        errors.code ?? errors.abilities ?? errors['abilities.0']
                    "
                />
                <Button type="submit" class="w-fit" :disabled="processing">
                    {{ t('Approve this device') }}
                </Button>
            </Form>
        </SectionCard>

        <div class="overflow-hidden rounded-lg border border-border">
            <p v-if="!tokens.length" class="p-4 text-sm text-muted-foreground">
                {{ t('No tokens yet.') }}
            </p>
            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border p-4 first:border-t-0"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ token.abilities.join(', ') }} ·
                        {{ t('created :date', { date: token.created }) }} ·
                        {{
                            token.last_used
                                ? t('last used :date', {
                                      date: token.last_used,
                                  })
                                : t('never used')
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="revoke(token)"
                >
                    {{ t('Revoke') }}
                </Button>
            </div>
        </div>
    </div>
</template>
