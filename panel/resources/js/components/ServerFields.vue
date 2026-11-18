<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModpackPicker from '@/components/ModpackPicker.vue';
import MotdEditor from '@/components/MotdEditor.vue';
import { t } from '@/lib/i18n';
import type {
    MemoryBudget,
    PackPlatform,
    Server,
    ServerOptions,
} from '@/lib/servers';
import { NATIVE_TYPES, PACK_TYPES, title, typeLabels } from '@/lib/servers';

const props = defineProps<{
    options: ServerOptions;
    errors: Record<string, string>;
    memory: MemoryBudget;
    server?: Server;
}>();

const javaVersion = defineModel<number>('javaVersion', { default: 25 });
// For a page that shows what the choice leads to.
const serverType = defineModel<string>('serverType');
// LATEST first, then Mojang's releases; a saved version stays selectable even if the list is down.
const mcVersions = computed(() => [
    ...new Set([
        'LATEST',
        ...(props.server ? [props.server.mc_version] : []),
        ...props.options.mcVersions,
    ]),
]);

// The type buttons show the loaders plus one "Modpack" choice; the platform picks which pack type is sent.
const loaderTypes = computed(() =>
    props.options.types.filter((type) => !PACK_TYPES.includes(type)),
);
const initialType = props.server?.server_type ?? 'PAPER';
const isPack = ref(PACK_TYPES.includes(initialType));
const loader = ref(isPack.value ? 'PAPER' : initialType);
const platform = ref<PackPlatform>(
    isPack.value ? (initialType as PackPlatform) : 'MODRINTH',
);
const isNative = computed(
    () => !isPack.value && NATIVE_TYPES.includes(loader.value),
);
watchEffect(() => {
    serverType.value = isPack.value ? platform.value : loader.value;
});
const typeButton =
    'flex h-9 cursor-pointer items-center rounded-md border border-primary px-3 text-sm has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50';

const freeGb = computed(() =>
    props.memory.freeMb === null
        ? null
        : Math.floor(props.memory.freeMb / 1024),
);
// The slider stops at what is left under the limit, but never below what this server already has.
const maxMemoryGb = computed(() =>
    Math.max(
        1,
        (props.server?.memory_mb ?? 0) / 1024,
        Math.min(16, freeGb.value ?? 16),
    ),
);
const memoryGb = ref(
    Math.min((props.server?.memory_mb ?? 4096) / 1024, maxMemoryGb.value),
);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm';
</script>

<template>
    <SectionCard :title="t('Software')">
        <div class="flex flex-col gap-4">
            <div>
                <div
                    role="radiogroup"
                    :aria-label="t('Server type')"
                    class="flex flex-wrap gap-2"
                >
                    <label
                        v-for="type in loaderTypes"
                        :key="type"
                        :class="typeButton"
                    >
                        <input
                            type="radio"
                            :checked="!isPack && loader === type"
                            class="sr-only"
                            @change="
                                loader = type;
                                isPack = false;
                            "
                        />
                        {{ t(typeLabels[type] ?? type) }}
                    </label>
                    <label :class="typeButton">
                        <input
                            type="radio"
                            :checked="isPack"
                            class="sr-only"
                            @change="isPack = true"
                        />
                        {{ t('Modpack') }}
                    </label>
                </div>
                <input
                    type="hidden"
                    name="server_type"
                    :value="isPack ? platform : loader"
                />
                <InputError :message="errors.server_type" />
            </div>

            <ModpackPicker
                v-if="isPack"
                v-model:platform="platform"
                :curseforge="options.curseforge"
                :initial="server?.modpack"
                :error="errors.modpack_project"
            />

            <template v-if="isNative">
                <input type="hidden" name="mc_version" value="LATEST" />
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Pumpkin is a Minecraft server written in Rust. It is fast and light, but still in development: not every game feature works yet, plugins and mods for other servers do not load, and its world format can change between releases. The newest release is installed; it follows the newest Minecraft version and does not use Java.',
                        )
                    }}
                </p>
            </template>

            <div class="grid gap-4 sm:grid-cols-2">
                <div
                    v-if="!isPack && !isNative"
                    class="grid content-start gap-2"
                >
                    <Label for="mc_version">{{ t('Minecraft version') }}</Label>
                    <select
                        id="mc_version"
                        name="mc_version"
                        :class="selectClass"
                    >
                        <option
                            v-for="version in mcVersions"
                            :key="version"
                            :value="version"
                            :selected="
                                version === (server?.mc_version ?? 'LATEST')
                            "
                        >
                            {{
                                version !== 'LATEST'
                                    ? version
                                    : options.mcVersions[0]
                                      ? t('Latest (:version)', {
                                            version: options.mcVersions[0],
                                        })
                                      : t('Latest')
                            }}
                        </option>
                    </select>
                    <span
                        v-if="!options.mcVersions.length"
                        class="text-[0.8125rem] text-muted-foreground"
                    >
                        {{ t("Mojang's version list could not be loaded.") }}
                    </span>
                    <InputError :message="errors.mc_version" />
                </div>

                <div v-show="!isNative" class="grid content-start gap-2">
                    <Label for="java_version">{{ t('Java version') }}</Label>
                    <select
                        id="java_version"
                        v-model.number="javaVersion"
                        name="java_version"
                        :class="selectClass"
                        aria-describedby="java_version_hint"
                    >
                        <option
                            v-for="version in options.javaVersions"
                            :key="version"
                            :value="version"
                        >
                            Java {{ version }}
                        </option>
                    </select>
                    <span
                        id="java_version_hint"
                        class="text-[0.8125rem] text-muted-foreground"
                    >
                        {{
                            t(
                                "Minecraft 1.20.5 and newer needs Java 21; 26.1 and newer needs Java 25. A modpack's page says which it needs.",
                            )
                        }}
                    </span>
                    <InputError :message="errors.java_version" />
                </div>
            </div>
        </div>
    </SectionCard>

    <SectionCard :title="t('Resources')">
        <div class="grid gap-2">
            <div class="flex justify-between">
                <Label for="memory">{{ t('Memory') }}</Label>
                <span class="text-sm font-semibold tabular-nums"
                    >{{ memoryGb }} GB</span
                >
            </div>
            <input
                id="memory"
                v-model.number="memoryGb"
                type="range"
                min="1"
                :max="maxMemoryGb"
                class="w-full accent-primary"
                aria-describedby="memory_hint"
            />
            <input type="hidden" name="memory_mb" :value="memoryGb * 1024" />
            <span
                id="memory_hint"
                class="text-[0.8125rem] text-muted-foreground"
            >
                <template v-if="freeGb !== null && memory.limitMb !== null">
                    {{
                        t(
                            ':free GB left for this server under the :limit GB limit for all servers.',
                            {
                                free: Math.max(0, freeGb),
                                limit: Math.round(memory.limitMb / 1024),
                            },
                        )
                    }}
                </template>
                {{
                    t(
                        "The container limit. Minecraft's heap gets three quarters of it.",
                    )
                }}
            </span>
            <InputError :message="errors.memory_mb" />

            <Label for="cpu_limit" class="mt-2">{{
                t('CPU limit (cores)')
            }}</Label>
            <Input
                id="cpu_limit"
                name="cpu_limit"
                type="number"
                min="0.25"
                max="64"
                step="0.25"
                class="max-w-32"
                :placeholder="t('None')"
                :default-value="server?.settings.cpu_limit ?? ''"
                aria-describedby="cpu_limit_hint"
            />
            <span
                id="cpu_limit_hint"
                class="text-[0.8125rem] text-muted-foreground"
            >
                {{ t('Leave empty for no limit.') }}
            </span>
            <InputError :message="errors.cpu_limit" />
        </div>
    </SectionCard>

    <SectionCard :title="t('Players and Java')">
        <div class="flex flex-col gap-4">
            <div class="grid gap-2">
                <Label for="ops">{{ t('Operators') }}</Label>
                <Input
                    id="ops"
                    name="ops"
                    spellcheck="false"
                    :placeholder="t('Player names, separated by commas')"
                    :default-value="(server?.settings.ops ?? []).join(', ')"
                />
                <InputError :message="errors.ops" />
            </div>

            <div class="grid gap-2">
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="whitelist"
                        value="1"
                        :checked="server?.settings.whitelist"
                        class="size-4 accent-primary"
                    />
                    {{ t('Whitelist on: only these players can join') }}
                </label>
                <Label for="whitelist_names" class="sr-only">{{
                    t('Whitelisted players')
                }}</Label>
                <Input
                    id="whitelist_names"
                    name="whitelist_names"
                    spellcheck="false"
                    :placeholder="t('Player names, separated by commas')"
                    :default-value="
                        (server?.settings.whitelist_names ?? []).join(', ')
                    "
                    aria-describedby="players_hint"
                />
                <span
                    id="players_hint"
                    class="text-[0.8125rem] text-muted-foreground"
                >
                    {{
                        t(
                            'Both lists replace ops.json and whitelist.json on every start, so change them here, not in Files or with /op.',
                        )
                    }}
                </span>
                <InputError
                    :message="errors.whitelist_names || errors.whitelist"
                />
            </div>

            <div class="grid gap-2">
                <Label for="jvm_opts">{{ t('Extra JVM flags') }}</Label>
                <Input
                    id="jvm_opts"
                    name="jvm_opts"
                    spellcheck="false"
                    maxlength="500"
                    class="font-mono"
                    placeholder="-XX:+UseZGC"
                    :default-value="server?.settings.jvm_opts ?? ''"
                />
                <InputError :message="errors.jvm_opts" />
            </div>
        </div>
    </SectionCard>

    <SectionCard :title="t('Game settings')">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid content-start gap-2 sm:col-span-3">
                <Label for="motd">{{ t('MOTD') }}</Label>
                <MotdEditor
                    :value="server?.settings.motd ?? 'A Minecraft server'"
                />
                <InputError :message="errors.motd" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="difficulty">{{ t('Difficulty') }}</Label>
                <select id="difficulty" name="difficulty" :class="selectClass">
                    <option
                        v-for="difficulty in options.difficulties"
                        :key="difficulty"
                        :value="difficulty"
                        :selected="
                            difficulty ===
                            (server?.settings.difficulty ?? 'normal')
                        "
                    >
                        {{ title(difficulty) }}
                    </option>
                </select>
                <InputError :message="errors.difficulty" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="mode">{{ t('Game mode') }}</Label>
                <select id="mode" name="mode" :class="selectClass">
                    <option
                        v-for="mode in options.modes"
                        :key="mode"
                        :value="mode"
                        :selected="
                            mode === (server?.settings.mode ?? 'survival')
                        "
                    >
                        {{ title(mode) }}
                    </option>
                </select>
                <InputError :message="errors.mode" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="max_players">{{ t('Max players') }}</Label>
                <Input
                    id="max_players"
                    name="max_players"
                    type="number"
                    min="1"
                    max="1000"
                    required
                    :default-value="server?.settings.max_players ?? 20"
                />
                <InputError :message="errors.max_players" />
            </div>

            <div class="grid content-start gap-1 sm:col-span-3">
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="bluemap"
                        value="1"
                        :checked="server?.bluemap_enabled"
                        class="size-4 accent-primary"
                        aria-describedby="bluemap_hint"
                    />
                    {{ t("Publish this server's BlueMap web map") }}
                </label>
                <span
                    id="bluemap_hint"
                    class="text-[0.8125rem] text-muted-foreground"
                >
                    {{
                        t(
                            "Installs the BlueMap mod or plugin that fits this server and publishes its map; the map is public. BlueMap downloads Minecraft's own textures from Mojang to draw it. Not for Vanilla and Pumpkin, which load no mods; switching it off again hides the map but leaves the mod in Files.",
                        )
                    }}
                </span>
                <InputError :message="errors.bluemap" />
            </div>

            <div
                v-if="!server || server.can.includes('admin')"
                class="grid content-start gap-1 sm:col-span-3"
            >
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="is_default"
                        value="1"
                        :checked="server?.is_default"
                        class="size-4 accent-primary"
                        aria-describedby="is_default_hint"
                    />
                    {{ t('Make this the default server') }}
                </label>
                <span
                    id="is_default_hint"
                    class="text-[0.8125rem] text-muted-foreground"
                >
                    {{
                        t(
                            'Players who use a hostname that no server has, such as the base domain itself, land here. Only one server can be the default; changing it restarts the servers involved.',
                        )
                    }}
                </span>
                <InputError :message="errors.is_default" />
            </div>
        </div>
    </SectionCard>
</template>
