<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Server, ServerOptions } from '@/lib/servers';
import { title } from '@/lib/servers';

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
                        v-for="type in options.types"
                        :key="type"
                        class="flex h-9 cursor-pointer items-center rounded-md border border-primary px-3 text-sm has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                    >
                        <input
                            type="radio"
                            name="server_type"
                            :value="type"
                            :checked="
                                type ===
                                (server?.server_type ?? options.types[0])
                            "
                            class="sr-only"
                        />
                        {{ title(type) }}
                    </label>
                </div>
                <InputError :message="errors.server_type" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
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
                        needs Java 25.
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
        </div>
    </SectionCard>
</template>
