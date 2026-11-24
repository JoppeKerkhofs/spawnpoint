<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AbilityPicker from '@/components/AbilityPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    create,
    index,
    pairing as pairingRoute,
    store,
} from '@/routes/api-tokens';
import { approve } from '@/routes/api-tokens/pairing';

type Group = {
    key: string;
    label: string;
    abilities: { name: string; label: string; description: string }[];
};

const props = defineProps<{
    groups: Group[];
    presets: Record<string, string[]>;
    pairing: { code: string; device_name: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'API tokens', href: index() },
            { title: 'New token', href: create() },
        ],
    },
});

// A new token starts read-only; the picker widens it.
const abilities = ref<string[]>([...props.presets['read-only']]);
const pairingAbilities = ref<string[]>([...props.presets['read-only']]);
</script>

<template>
    <Head :title="t('New token')" />

    <h1 class="sr-only">{{ t('New token') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('New token')"
            :description="
                t(
                    'Name the token after the app that will use it. It is shown once, on the list of tokens.',
                )
            "
        />

        <Form
            v-bind="store.form()"
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

            <div class="flex flex-wrap gap-2">
                <Button :disabled="processing">{{ t('Create token') }}</Button>
                <Button as-child variant="outline">
                    <Link :href="index()">{{ t('Cancel') }}</Link>
                </Button>
            </div>
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
    </div>
</template>
