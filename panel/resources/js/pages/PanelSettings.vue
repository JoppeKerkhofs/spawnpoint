<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { privacy } from '@/routes';
import { update } from '@/routes/panel-settings';

defineProps<{
    settings: {
        base_domain: string;
        bluemap_url: string;
        memory_limit_mb: string | number | null;
        require_two_factor: boolean;
        activity_retention_days: string | number | null;
        dns_target: string | null;
        privacy_contact: string | null;
    };
    hasCurseforgeKey: boolean;
    hasCloudflareToken: boolean;
    dnsEnabled: boolean;
    effectiveMemoryLimitMb: number | null;
    // Arrives after the page. A folder of null: the node did not answer, or is older than 1.6.7.
    backupFolders?: {
        node: string;
        folder: {
            path: string;
            reachable: boolean;
            fallback: string | null;
        } | null;
    }[];
}>();

const hint = 'text-[0.8125rem] text-muted-foreground';
</script>

<template>
    <Head :title="t('Panel settings')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                {{ t('Settings') }}
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                {{
                    t(
                        "These apply to the whole panel. A setting you leave empty falls back to the value from the server's environment.",
                    )
                }}
            </p>
        </div>

        <Form
            v-bind="update.form()"
            :options="{ preserveScroll: true }"
            class="flex max-w-3xl flex-col gap-3"
            v-slot="{ errors, processing }"
        >
            <SectionCard :title="t('Addresses')">
                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="base_domain">{{
                            t('Base domain for servers')
                        }}</Label>
                        <Input
                            id="base_domain"
                            name="base_domain"
                            required
                            spellcheck="false"
                            :default-value="settings.base_domain"
                            aria-describedby="base_domain_hint"
                        />
                        <span id="base_domain_hint" :class="hint">
                            {{
                                t(
                                    'A new server named "survival" gets survival.:domain. Servers that already exist keep their address.',
                                    { domain: settings.base_domain },
                                )
                            }}
                        </span>
                        <InputError :message="errors.base_domain" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="bluemap_url">{{
                            t('Address of the web maps')
                        }}</Label>
                        <Input
                            id="bluemap_url"
                            name="bluemap_url"
                            type="url"
                            required
                            :default-value="settings.bluemap_url"
                            aria-describedby="bluemap_url_hint"
                        />
                        <span id="bluemap_url_hint" :class="hint">
                            {{
                                t(
                                    "Where port 8100 of this host is reachable from outside. Each server's map is at /server-name/ under it.",
                                )
                            }}
                        </span>
                        <InputError :message="errors.bluemap_url" />
                    </div>
                </div>
            </SectionCard>

            <SectionCard :title="t('Memory')">
                <div class="grid gap-2">
                    <Label for="memory_limit_mb">{{
                        t('Memory for all servers together (MB)')
                    }}</Label>
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
                        {{ t("Leave empty to use 90% of the host's memory.") }}
                        <template v-if="effectiveMemoryLimitMb">
                            {{
                                t('The limit in effect now is :size MB.', {
                                    size: effectiveMemoryLimitMb,
                                })
                            }}
                        </template>
                        <template v-else>
                            {{
                                t(
                                    "No limit is in effect now, because the host's memory could not be read.",
                                )
                            }}
                        </template>
                    </span>
                    <InputError :message="errors.memory_limit_mb" />
                </div>
            </SectionCard>

            <SectionCard :title="t('Activity log')">
                <div class="grid gap-2">
                    <Label for="activity_retention_days">{{
                        t('Keep entries for (days)')
                    }}</Label>
                    <Input
                        id="activity_retention_days"
                        name="activity_retention_days"
                        type="number"
                        min="0"
                        step="1"
                        class="max-w-48"
                        :default-value="settings.activity_retention_days ?? ''"
                        aria-describedby="activity_retention_days_hint"
                    />
                    <span id="activity_retention_days_hint" :class="hint">
                        {{
                            t(
                                'Older entries are deleted every night. 0 keeps the whole log.',
                            )
                        }}
                    </span>
                    <InputError :message="errors.activity_retention_days" />
                </div>
            </SectionCard>

            <SectionCard :title="t('Backup folders')">
                <p :class="hint">
                    {{
                        t(
                            'Where each node keeps its backups. Set it on the node itself with SPAWNPOINT_BACKUP_DIR, and with SPAWNPOINT_BACKUP_FALLBACK_DIR a folder on another disk to use while the first cannot be reached.',
                        )
                    }}
                </p>
                <p v-if="!backupFolders" role="status" :class="hint">
                    {{ t('Asking the nodes…') }}
                </p>
                <ul v-else class="flex flex-col">
                    <li
                        v-for="entry in backupFolders"
                        :key="entry.node"
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-t border-divider py-2 text-sm first:border-t-0"
                    >
                        <span class="font-semibold text-primary">{{
                            entry.node
                        }}</span>
                        <template v-if="entry.folder">
                            <code class="break-all">{{
                                entry.folder.path
                            }}</code>
                            <span
                                v-if="entry.folder.reachable"
                                class="text-muted-foreground"
                            >
                                {{
                                    entry.folder.fallback
                                        ? t('Reachable. Fallback: :folder', {
                                              folder: entry.folder.fallback,
                                          })
                                        : t('Reachable')
                                }}
                            </span>
                            <span v-else role="alert" class="text-destructive">
                                {{
                                    entry.folder.fallback
                                        ? t(
                                              'Cannot be reached. Backups are saved in :folder until it can.',
                                              {
                                                  folder: entry.folder.fallback,
                                              },
                                          )
                                        : t(
                                              'Cannot be reached, and no fallback folder is set. Backups fail until it can.',
                                          )
                                }}
                            </span>
                        </template>
                        <span v-else class="text-muted-foreground">
                            {{
                                t(
                                    'Not known: the node did not answer, or is older than 1.6.7.',
                                )
                            }}
                        </span>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard title="CurseForge">
                <div class="grid gap-2">
                    <Label for="curseforge_key">{{ t('API key') }}</Label>
                    <Input
                        id="curseforge_key"
                        name="curseforge_key"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            hasCurseforgeKey
                                ? t(
                                      'A key is saved. Type a new one to replace it.',
                                  )
                                : t('No key saved')
                        "
                        aria-describedby="curseforge_key_hint"
                    />
                    <span id="curseforge_key_hint" :class="hint">
                        {{
                            t(
                                'Needed to search CurseForge and install its packs. It is stored encrypted and never shown again.',
                            )
                        }}
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
                        {{ t('Remove the saved key') }}
                    </label>
                    <InputError :message="errors.curseforge_key" />
                </div>
            </SectionCard>

            <SectionCard title="Cloudflare DNS">
                <div class="flex flex-col gap-4">
                    <p role="status" class="text-sm">
                        <template v-if="dnsEnabled">
                            {{
                                t(
                                    'On: every new server gets a DNS record, and loses it when the server is deleted.',
                                )
                            }}
                        </template>
                        <template v-else>
                            {{
                                t(
                                    'Off. Fill in both fields to have the panel add a DNS record for every new server.',
                                )
                            }}
                        </template>
                    </p>
                    <div class="grid gap-2">
                        <Label for="cloudflare_token">{{
                            t('API token')
                        }}</Label>
                        <Input
                            id="cloudflare_token"
                            name="cloudflare_token"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                hasCloudflareToken
                                    ? t(
                                          'A token is saved. Type a new one to replace it.',
                                      )
                                    : t('No token saved')
                            "
                            aria-describedby="cloudflare_token_hint"
                        />
                        <span id="cloudflare_token_hint" :class="hint">
                            {{
                                t(
                                    'A Cloudflare API token with the Zone: Read and DNS: Edit permissions for the zone of the base domain. It is stored encrypted and never shown again.',
                                )
                            }}
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
                            {{
                                t(
                                    'Remove the saved token, which turns this off',
                                )
                            }}
                        </label>
                        <InputError :message="errors.cloudflare_token" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="dns_target">{{
                            t('Point records at')
                        }}</Label>
                        <Input
                            id="dns_target"
                            name="dns_target"
                            spellcheck="false"
                            placeholder="203.0.113.5"
                            :default-value="settings.dns_target ?? ''"
                            aria-describedby="dns_target_hint"
                        />
                        <span id="dns_target_hint" :class="hint">
                            {{
                                t(
                                    "The public IP address of this host, or a hostname that points at it. Records are not proxied, because Cloudflare's proxy does not carry Minecraft.",
                                )
                            }}
                        </span>
                        <InputError :message="errors.dns_target" />
                    </div>
                </div>
            </SectionCard>

            <SectionCard :title="t('Privacy policy')">
                <div class="grid gap-2">
                    <Label for="privacy_contact">{{
                        t('Who runs this installation')
                    }}</Label>
                    <Input
                        id="privacy_contact"
                        name="privacy_contact"
                        maxlength="200"
                        :placeholder="t('A name and an email address')"
                        :default-value="settings.privacy_contact ?? ''"
                        aria-describedby="privacy_contact_hint"
                    />
                    <span id="privacy_contact_hint" :class="hint">
                        {{
                            t(
                                'Shown on the public privacy policy and support page as the one people can turn to about their data and for help. The policy describes what the panel stores and which services it contacts; check it against your own situation and the law that applies to you.',
                            )
                        }}
                        <a
                            :href="privacy.url()"
                            class="text-primary underline"
                            >{{ t('Read the privacy policy') }}</a
                        >
                    </span>
                    <InputError :message="errors.privacy_contact" />
                </div>
            </SectionCard>

            <SectionCard :title="t('Security')">
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
                        {{
                            t(
                                'Require two-factor authentication for every account',
                            )
                        }}
                    </label>
                    <span id="require_two_factor_hint" :class="hint">
                        {{
                            t(
                                'Anyone without it, you included, is sent to their security settings to set it up before they can use the panel. API tokens keep working.',
                            )
                        }}
                    </span>
                    <InputError :message="errors.require_two_factor" />
                </div>
            </SectionCard>

            <Button :disabled="processing" class="self-start">{{
                t('Save')
            }}</Button>
        </Form>
    </div>
</template>
