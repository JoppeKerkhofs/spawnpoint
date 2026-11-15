<script setup lang="ts">
import { computed, ref } from 'vue';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Ability = { name: string; label: string; description: string };
type Group = { key: string; label: string; abilities: Ability[] };

const props = defineProps<{
    groups: Group[];
    presets: Record<string, string[]>;
    // Groups that start closed; administration is noise for most tokens.
    collapsed?: string[];
}>();

const model = defineModel<string[]>({ required: true });

const open = ref<Record<string, boolean>>(
    Object.fromEntries(
        props.groups.map((group) => [
            group.key,
            !(props.collapsed ?? ['administration']).includes(group.key),
        ]),
    ),
);

const presetNames: Record<string, string> = {
    'read-only': t('Read-only'),
    operator: t('Operator'),
    full: t('Full access'),
};

const same = (a: string[], b: string[]) =>
    a.length === b.length && [...a].sort().join() === [...b].sort().join();

const activePreset = computed(
    () =>
        Object.keys(props.presets).find((key) =>
            same(props.presets[key], model.value),
        ) ?? null,
);

const choose = (key: string) => {
    model.value = [...props.presets[key]];
};

const granted = (name: string) => model.value.includes(name);

const toggle = (name: string) => {
    model.value = granted(name)
        ? model.value.filter((ability) => ability !== name)
        : [...model.value, name];
};

const countIn = (group: Group) =>
    group.abilities.filter((ability) => granted(ability.name)).length;

const toggleGroup = (group: Group) => {
    const names = group.abilities.map((ability) => ability.name);
    model.value =
        countIn(group) === group.abilities.length
            ? model.value.filter((ability) => !names.includes(ability))
            : [...new Set([...model.value, ...names])];
};

// What the token can do, in one sentence, for the person approving it.
const summary = computed(() => {
    if (model.value.length === 0) {
        return t('This token can do nothing yet. Choose what it may do.');
    }
    if (activePreset.value === 'full') {
        return t('This token can do everything you can do in the panel.');
    }

    const parts = props.groups
        .filter((group) => countIn(group) > 0)
        .map((group) =>
            countIn(group) === group.abilities.length
                ? group.label
                : group.abilities
                      .filter((ability) => granted(ability.name))
                      .map((ability) => ability.label)
                      .join(', '),
        );

    return t('This token can: :what. Nothing else.', {
        what: parts.join('; '),
    });
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-muted-foreground">{{
                t('Start from')
            }}</span>
            <button
                v-for="(abilities, key) in presets"
                :key="key"
                type="button"
                class="rounded-full border px-3 py-1 text-sm"
                :class="
                    activePreset === key
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'border-input text-muted-foreground'
                "
                :aria-pressed="activePreset === key"
                @click="choose(key)"
            >
                {{ presetNames[key] ?? key }}
            </button>
            <span
                v-if="activePreset === null"
                class="rounded-full border border-primary bg-primary/10 px-3 py-1 text-sm text-primary"
            >
                {{ t('Custom') }}
            </span>
        </div>

        <div
            v-for="group in groups"
            :key="group.key"
            class="rounded-lg border border-input"
        >
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium"
                    :aria-expanded="open[group.key]"
                    @click="open[group.key] = !open[group.key]"
                >
                    <span aria-hidden="true">{{
                        open[group.key] ? '▾' : '▸'
                    }}</span>
                    {{ group.label }}
                    <span class="text-xs font-normal text-muted-foreground">
                        {{
                            t(':granted of :total', {
                                granted: countIn(group),
                                total: group.abilities.length,
                            })
                        }}
                    </span>
                </button>
                <button
                    type="button"
                    role="switch"
                    class="size-5 shrink-0 rounded border border-input"
                    :class="
                        countIn(group) === group.abilities.length
                            ? 'bg-primary'
                            : countIn(group) > 0
                              ? 'bg-primary/40'
                              : 'bg-transparent'
                    "
                    :aria-checked="countIn(group) === group.abilities.length"
                    :aria-label="
                        t('Everything under :group', { group: group.label })
                    "
                    @click="toggleGroup(group)"
                />
            </div>

            <div
                v-show="open[group.key]"
                class="flex flex-col gap-3 border-t border-input px-3 py-3"
            >
                <label
                    v-for="ability in group.abilities"
                    :key="ability.name"
                    class="flex items-start gap-3"
                >
                    <input
                        type="checkbox"
                        class="mt-1 size-4 shrink-0 accent-primary"
                        :checked="granted(ability.name)"
                        @change="toggle(ability.name)"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm">{{ ability.label }}</span>
                        <span
                            class="block text-[0.8125rem] text-muted-foreground"
                        >
                            {{ ability.description }}
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <p class="text-[0.8125rem] text-muted-foreground" aria-live="polite">
            {{ summary }}
        </p>

        <!-- So a plain form submit carries the selection. -->
        <input
            v-for="ability in model"
            :key="ability"
            type="hidden"
            name="abilities[]"
            :value="ability"
        />
    </div>
</template>
