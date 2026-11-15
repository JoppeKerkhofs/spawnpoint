<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/two-factor/login';

defineOptions({
    layout: {
        title: 'Two-factor authentication',
        description:
            'Enter the code from your authenticator app, or one of your recovery codes.',
    },
});

const useRecoveryCode = ref(false);
</script>

<template>
    <Head :title="t('Two-factor authentication')" />

    <Form v-bind="store.form()" reset-on-error v-slot="{ errors, processing }">
        <div class="space-y-6">
            <div v-if="useRecoveryCode" class="grid gap-2">
                <Label for="recovery_code">{{ t('Recovery code') }}</Label>
                <Input
                    id="recovery_code"
                    name="recovery_code"
                    required
                    autofocus
                    autocomplete="off"
                />
                <InputError :message="errors.recovery_code" />
            </div>

            <div v-else class="grid gap-2">
                <Label for="code">{{ t('Authentication code') }}</Label>
                <Input
                    id="code"
                    name="code"
                    required
                    autofocus
                    inputmode="numeric"
                    autocomplete="one-time-code"
                />
                <InputError :message="errors.code" />
            </div>

            <Button class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('Continue') }}
            </Button>

            <Button
                type="button"
                variant="link"
                class="w-full"
                @click="useRecoveryCode = !useRecoveryCode"
            >
                {{
                    useRecoveryCode
                        ? t('Use an authentication code')
                        : t('Use a recovery code')
                }}
            </Button>
        </div>
    </Form>
</template>
