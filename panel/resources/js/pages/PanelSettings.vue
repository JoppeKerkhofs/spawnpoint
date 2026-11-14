<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update } from '@/routes/panel-settings';

defineProps<{
    settings: {
        base_domain: string;
        bluemap_url: string;
        memory_limit_mb: string | number | null;
        require_two_factor: boolean;
        dns_target: string | null;
    };
    hasCurseforgeKey: boolean;
    hasCloudflareToken: boolean;
    dnsEnabled: boolean;
    effectiveMemoryLimitMb: number | null;
}>();

const hint = 'text-[0.8125rem] text-muted-foreground';
</script>

<template>
    <Head title="Panel settings" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                Settings
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                These apply to the whole panel. A setting you leave empty falls
                back to the value from the server's environment.
            </p>
        </div>

        <Form
            v-bind="update.form()"
            :options="{ preserveScroll: true }"
            class="flex max-w-3xl flex-col gap-3"
            v-slot="{ errors, processing }"
        >
            <SectionCard title="Addresses">
                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="base_domain">Base domain for servers</Label>
                        <Input
                            id="base_domain"
                            name="base_domain"
                            required
                            spellcheck="false"
                            :default-value="settings.base_domain"
                            aria-describedby="base_domain_hint"
                        />
                        <span id="base_domain_hint" :class="hint">
                            A new server named "survival" gets survival.{{
                                settings.base_domain
                            }}. Servers that already exist keep their address.
                        </span>
                        <InputError :message="errors.base_domain" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="bluemap_url">Address of the web maps</Label>
                        <Input
                            id="bluemap_url"
                            name="bluemap_url"
                            type="url"
                            required
                            :default-value="settings.bluemap_url"
                            aria-describedby="bluemap_url_hint"
                        />
                        <span id="bluemap_url_hint" :class="hint">
                            Where port 8100 of this host is reachable from
                            outside. Each server's map is at /server-name/ under
                            it.
                        </span>
                        <InputError :message="errors.bluemap_url" />
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="Memory">
                <div class="grid gap-2">
                    <Label for="memory_limit_mb"
                        >Memory for all servers together (MB)</Label
                    >
                    <Input
                        id="memory_limit_mb"
                        name="memory_limit_mb"
                        type="number"
                        min="1024"
                        step="1"
                        class="max-w-48"
                        :default-value="settings.memory_limit_mb ?? ''"
                        aria-describedby="memory_limit_mb_hint"
                    />
                    <span id="memory_limit_mb_hint" :class="hint">
                        Leave empty to use 90% of the host's memory.
                        <template v-if="effectiveMemoryLimitMb">
                            The limit in effect now is
                            {{ effectiveMemoryLimitMb }} MB.
                        </template>
                        <template v-else>
                            No limit is in effect now, because the host's memory
                            could not be read.
                        </template>
                    </span>
                    <InputError :message="errors.memory_limit_mb" />
                </div>
            </SectionCard>

            <SectionCard title="CurseForge">
                <div class="grid gap-2">
                    <Label for="curseforge_key">API key</Label>
                    <Input
                        id="curseforge_key"
                        name="curseforge_key"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            hasCurseforgeKey
                                ? 'A key is saved. Type a new one to replace it.'
                                : 'No key saved'
                        "
                        aria-describedby="curseforge_key_hint"
                    />
                    <span id="curseforge_key_hint" :class="hint">
                        Needed to search CurseForge and install its packs. It is
                        stored encrypted and never shown again.
                    </span>
                    <label
                        v-if="hasCurseforgeKey"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="remove_curseforge_key"
                            value="1"
                            class="size-4 accent-primary"
                        />
                        Remove the saved key
                    </label>
                    <InputError :message="errors.curseforge_key" />
                </div>
            </SectionCard>

            <SectionCard title="Cloudflare DNS">
                <div class="flex flex-col gap-4">
                    <p role="status" class="text-sm">
                        <template v-if="dnsEnabled">
                            On: every new server gets a DNS record, and loses it
                            when the server is deleted.
                        </template>
                        <template v-else>
                            Off. Fill in both fields to have the panel add a DNS
                            record for every new server.
                        </template>
                    </p>
                    <div class="grid gap-2">
                        <Label for="cloudflare_token">API token</Label>
                        <Input
                            id="cloudflare_token"
                            name="cloudflare_token"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                hasCloudflareToken
                                    ? 'A token is saved. Type a new one to replace it.'
                                    : 'No token saved'
                            "
                            aria-describedby="cloudflare_token_hint"
                        />
                        <span id="cloudflare_token_hint" :class="hint">
                            A Cloudflare API token with the Zone: Read and DNS:
                            Edit permissions for the zone of the base domain. It
                            is stored encrypted and never shown again.
                        </span>
                        <label
                            v-if="hasCloudflareToken"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                name="remove_cloudflare_token"
                                value="1"
                                class="size-4 accent-primary"
                            />
                            Remove the saved token, which turns this off
                        </label>
                        <InputError :message="errors.cloudflare_token" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="dns_target">Point records at</Label>
                        <Input
                            id="dns_target"
                            name="dns_target"
                            spellcheck="false"
                            placeholder="203.0.113.5"
                            :default-value="settings.dns_target ?? ''"
                            aria-describedby="dns_target_hint"
                        />
                        <span id="dns_target_hint" :class="hint">
                            The public IP address of this host, or a hostname
                            that points at it. Records are not proxied, because
                            Cloudflare's proxy does not carry Minecraft.
                        </span>
                        <InputError :message="errors.dns_target" />
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="Security">
                <div class="grid gap-1">
                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            name="require_two_factor"
                            value="1"
                            :checked="settings.require_two_factor"
                            class="size-4 accent-primary"
                            aria-describedby="require_two_factor_hint"
                        />
                        Require two-factor authentication for every account
                    </label>
                    <span id="require_two_factor_hint" :class="hint">
                        Anyone without it, you included, is sent to their
                        security settings to set it up before they can use the
                        panel. API tokens keep working.
                    </span>
                    <InputError :message="errors.require_two_factor" />
                </div>
            </SectionCard>

            <Button :disabled="processing" class="self-start">Save</Button>
        </Form>
    </div>
</template>
