<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModpackPicker from '@/components/ModpackPicker.vue';
import type { Server, ServerOptions } from '@/lib/servers';
import { PACK_TYPES, title, typeLabels } from '@/lib/servers';

const props = defineProps<{
    options: ServerOptions;
    errors: Record<string, string>;
    server?: Server;
}>();

const javaVersion = defineModel<number>('javaVersion', { default: 25 });
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
const platform = ref<'MODRINTH' | 'AUTO_CURSEFORGE'>(
    initialType === 'AUTO_CURSEFORGE' ? 'AUTO_CURSEFORGE' : 'MODRINTH',
);
const typeButton =
    'flex h-9 cursor-pointer items-center rounded-md border border-primary px-3 text-sm has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50';

const memoryGb = ref((props.server?.memory_mb ?? 4096) / 1024);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm';
</script>

<template>
    <SectionCard title="Software">
        <div class="flex flex-col gap-4">
            <div>
                <div
                    role="radiogroup"
                    aria-label="Server type"
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
                        {{ typeLabels[type] }}
                    </label>
                    <label :class="typeButton">
                        <input
                            type="radio"
                            :checked="isPack"
                            class="sr-only"
                            @change="isPack = true"
                        />
                        Modpack
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

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-if="!isPack" class="grid content-start gap-2">
                    <Label for="mc_version">Minecraft version</Label>
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
                                version === 'LATEST'
                                    ? `Latest${options.mcVersions[0] ? ` (${options.mcVersions[0]})` : ''}`
                                    : version
                            }}
                        </option>
                    </select>
                    <span
                        v-if="!options.mcVersions.length"
                        class="text-[0.8125rem] text-muted-foreground"
                    >
                        Mojang's version list could not be loaded.
                    </span>
                    <InputError :message="errors.mc_version" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="java_version">Java version</Label>
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
                        Minecraft 1.20.5 and newer needs Java 21; 26.1 and newer
                        needs Java 25. A modpack's page says which it needs.
                    </span>
                    <InputError :message="errors.java_version" />
                </div>
            </div>
        </div>
    </SectionCard>

    <SectionCard title="Resources">
        <div class="grid gap-2">
            <div class="flex justify-between">
                <Label for="memory">Memory</Label>
                <span class="text-sm font-semibold tabular-nums"
                    >{{ memoryGb }} GB</span
                >
            </div>
            <input
                id="memory"
                v-model.number="memoryGb"
                type="range"
                min="1"
                max="16"
                class="w-full accent-primary"
            />
            <input type="hidden" name="memory_mb" :value="memoryGb * 1024" />
            <span class="text-[0.8125rem] text-muted-foreground">
                The container limit. Minecraft's heap gets three quarters of it.
            </span>
            <InputError :message="errors.memory_mb" />
        </div>
    </SectionCard>

    <SectionCard title="Game settings">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid content-start gap-2 sm:col-span-3">
                <Label for="motd">MOTD</Label>
                <Input
                    id="motd"
                    name="motd"
                    maxlength="100"
                    :default-value="
                        server?.settings.motd ?? 'A Minecraft server'
                    "
                />
                <InputError :message="errors.motd" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="difficulty">Difficulty</Label>
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
                <Label for="mode">Game mode</Label>
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
                <Label for="max_players">Max players</Label>
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
                    Publish this server's BlueMap web map
                </label>
                <span
                    id="bluemap_hint"
                    class="text-[0.8125rem] text-muted-foreground"
                >
                    The map is public. Install the BlueMap mod or plugin
                    yourself, in Files; this only opens the way to it.
                </span>
                <InputError :message="errors.bluemap" />
            </div>
        </div>
    </SectionCard>
</template>
