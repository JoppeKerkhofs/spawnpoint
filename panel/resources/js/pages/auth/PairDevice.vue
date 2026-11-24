<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { approve, refuse } from '@/routes/pair';

type Ability = { name: string; label: string; description: string };

defineProps<{
    pairing: {
        code: string;
        device_name: string;
        abilities: Ability[];
    } | null;
    outcome: { state: 'linked' | 'refused'; device: string } | null;
}>();

defineOptions({
    layout: {
        title: 'Link a device',
        description: 'An app is asking to use this panel as you.',
    },
});

const grouped = (code: string) => code.match(/.{1,4}/g)?.join(' ') ?? code;
</script>

<template>
    <Head :title="t('Link a device')" />

    <p v-if="outcome?.state === 'linked'" role="status" class="text-center">
        {{
            t('":device" is linked. You can close this tab.', {
                device: outcome.device,
            })
        }}
    </p>

    <p
        v-else-if="outcome?.state === 'refused'"
        role="status"
        class="text-center"
    >
        {{ t('Nothing was linked. You can close this tab.') }}
    </p>

    <Form
        v-else-if="pairing"
        v-bind="approve.form({ code: pairing.code })"
        class="grid gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2 text-center">
            <p
                class="font-mono text-3xl font-bold tracking-widest"
                data-test="pair-code"
            >
                {{ grouped(pairing.code) }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Only allow this if the app on your device shows the same code.',
                    )
                }}
            </p>
        </div>

        <div class="grid gap-2">
            <p class="text-sm font-medium">{{ t('It will be able to') }}</p>
            <ul class="grid gap-2 text-sm">
                <li v-for="ability in pairing.abilities" :key="ability.name">
                    <span class="font-medium">{{ ability.label }}</span>
                    <span class="block text-muted-foreground">{{
                        ability.description
                    }}</span>
                </li>
            </ul>
        </div>

        <div class="grid gap-2">
            <Label for="device_name">{{ t('Name of this device') }}</Label>
            <Input
                id="device_name"
                name="device_name"
                maxlength="60"
                required
                autocomplete="off"
                :default-value="pairing.device_name"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('Shown in your API tokens, where you can remove it.') }}
            </p>
            <InputError :message="errors.device_name" />
        </div>

        <div class="grid gap-2">
            <Button :disabled="processing" data-test="pair-allow">
                <Spinner v-if="processing" />
                {{ t('Allow') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="processing"
                @click="router.delete(refuse.url({ code: pairing.code }))"
            >
                {{ t('Not my device') }}
            </Button>
        </div>
    </Form>

    <p v-else role="alert" class="text-center">
        {{
            t(
                'No device is waiting with that code. Codes expire after ten minutes. Start again in the app.',
            )
        }}
    </p>
</template>
