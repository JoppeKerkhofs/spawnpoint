<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    confirm,
    disable,
    enable,
    qrCode,
    recoveryCodes as recoveryCodesRoute,
    regenerateRecoveryCodes,
    secretKey,
} from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    twoFactorEnabled: false,
});

// Local only: the server drops an unconfirmed setup when this page is loaded again.
const setup = ref<{ svg: string; secretKey: string } | null>(null);
const recoveryCodes = ref<string[]>([]);

const getJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Request to ${url} failed (${response.status})`);
    }

    return response.json();
};

const showSetup = async () => {
    const [qr, secret] = await Promise.all([
        getJson<{ svg: string }>(qrCode.url()),
        getJson<{ secretKey: string }>(secretKey.url()),
    ]);

    setup.value = { svg: qr.svg, secretKey: secret.secretKey };
};

const showRecoveryCodes = async () => {
    setup.value = null;
    recoveryCodes.value = await getJson<string[]>(recoveryCodesRoute.url());
};
</script>

<template>
    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            title="Two-factor authentication"
            description="Ask for a code from your authenticator app when you log in"
        />

        <template v-if="twoFactorEnabled">
            <Badge>Enabled</Badge>

            <div class="flex flex-wrap gap-4">
                <Button
                    type="button"
                    variant="secondary"
                    @click="showRecoveryCodes"
                >
                    Show recovery codes
                </Button>

                <Form
                    v-bind="regenerateRecoveryCodes.form()"
                    :options="{ preserveScroll: true }"
                    @success="showRecoveryCodes"
                    v-slot="{ processing }"
                >
                    <Button variant="secondary" :disabled="processing">
                        Regenerate recovery codes
                    </Button>
                </Form>

                <Form
                    v-bind="disable.form()"
                    :options="{ preserveScroll: true }"
                    @success="recoveryCodes = []"
                    v-slot="{ processing }"
                >
                    <Button variant="destructive" :disabled="processing">
                        Disable
                    </Button>
                </Form>
            </div>

            <div v-if="recoveryCodes.length" class="space-y-2">
                <p class="text-sm text-muted-foreground">
                    Store these codes somewhere safe. Each one logs you in once
                    if you lose your authenticator.
                </p>
                <ul class="rounded-lg bg-muted p-4 font-mono text-sm">
                    <li v-for="code in recoveryCodes" :key="code">
                        {{ code }}
                    </li>
                </ul>
            </div>
        </template>

        <template v-else-if="setup">
            <p class="text-sm text-muted-foreground">
                Scan this QR code with your authenticator app, or enter the key
                by hand, then confirm with a code from the app.
            </p>

            <!-- SVG rendered by our own server from the user's secret -->
            <div class="w-fit rounded-lg bg-white p-3" v-html="setup.svg" />
            <p class="font-mono text-sm break-all">{{ setup.secretKey }}</p>

            <Form
                v-bind="confirm.form()"
                error-bag="confirmTwoFactorAuthentication"
                :options="{ preserveScroll: true }"
                reset-on-error
                class="space-y-6"
                @success="showRecoveryCodes"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="code">Authentication code</Label>
                    <Input
                        id="code"
                        name="code"
                        required
                        inputmode="numeric"
                        autocomplete="one-time-code"
                    />
                    <InputError :message="errors.code" />
                </div>

                <Button :disabled="processing">Confirm</Button>
            </Form>
        </template>

        <Form
            v-else
            v-bind="enable.form()"
            :options="{ preserveScroll: true }"
            @success="showSetup"
            v-slot="{ processing }"
        >
            <Button :disabled="processing">
                Enable two-factor authentication
            </Button>
        </Form>
    </div>
</template>
