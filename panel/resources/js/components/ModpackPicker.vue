<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Modpack, PackPlatform } from '@/lib/servers';
import { search, versions as versionsRoute } from '@/routes/modpacks';

type Pack = Modpack & { description: string; downloads: number };

const props = defineProps<{
    curseforge: boolean;
    initial?: Modpack | null;
    error?: string;
}>();

const platform = defineModel<PackPlatform>('platform', {
    required: true,
});

const platforms = [
    { value: 'MODRINTH', label: 'Modrinth' },
    { value: 'AUTO_CURSEFORGE', label: 'CurseForge' },
    { value: 'FTBA', label: 'FTB' },
] as const;

const platformLabel = computed(
    () => platforms.find((option) => option.value === platform.value)!.label,
);

const query = ref('');
const packs = ref<Pack[]>([]);
const loading = ref(false);
const message = ref('');
const picked = ref<Modpack | null>(props.initial ?? null);
const link = ref('');

let latestSearch = 0;

const load = async () => {
    const current = ++latestSearch;
    loading.value = true;
    message.value = '';

    try {
        const response = await fetch(
            search.url({ query: { platform: platform.value, q: query.value } }),
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();

        // A slower, older search must not overwrite a newer one.
        if (current === latestSearch) {
            packs.value = response.ok ? body.packs : [];
            message.value = response.ok ? '' : body.message;
        }
    } catch {
        if (current === latestSearch) {
            packs.value = [];
            message.value = 'The modpack list could not be loaded.';
        }
    } finally {
        if (current === latestSearch) {
            loading.value = false;
        }
    }
};

let debounce: ReturnType<typeof setTimeout>;

watch(query, () => {
    clearTimeout(debounce);
    debounce = setTimeout(load, 300);
});

watch(
    platform,
    (now, before) => {
        if (before) {
            picked.value = null;
            link.value = '';
        }

        void load();
    },
    { immediate: true },
);

watch(link, (url) => {
    if (url.trim()) {
        picked.value = { project: url.trim(), name: url.trim(), icon: null };
    }
});

const pick = (pack: Pack) => {
    picked.value = { project: pack.project, name: pack.name, icon: pack.icon };
    link.value = '';
};

// CurseForge has no version choice here, and a pasted link already names its version.
const versions = ref<{ id: string; name: string }[]>([]);
const version = ref(props.initial?.version ?? '');

watch(
    () => picked.value?.project,
    async (project, before) => {
        if (before !== undefined) {
            version.value = '';
        }
        versions.value = [];

        if (
            !project ||
            platform.value === 'AUTO_CURSEFORGE' ||
            project.startsWith('https://')
        ) {
            return;
        }

        try {
            const response = await fetch(
                versionsRoute.url({
                    query: { platform: platform.value, project },
                }),
                { headers: { Accept: 'application/json' } },
            );

            if (response.ok && picked.value?.project === project) {
                versions.value = (await response.json()).versions;
            }
        } catch {
            // Without the list the newest version is installed, which is the default anyway.
        }
    },
    { immediate: true },
);

const compact = new Intl.NumberFormat(undefined, { notation: 'compact' });
</script>

<template>
    <div class="flex flex-col gap-3">
        <input type="hidden" name="modpack_project" :value="picked?.project" />
        <input type="hidden" name="modpack_name" :value="picked?.name" />
        <input type="hidden" name="modpack_icon" :value="picked?.icon" />
        <input
            type="hidden"
            name="modpack_version_name"
            :value="versions.find((entry) => entry.id === version)?.name"
        />
        <input type="hidden" name="mc_version" value="LATEST" />

        <div
            role="radiogroup"
            aria-label="Modpack source"
            class="-mb-px flex flex-wrap gap-1 border-b border-border"
        >
            <label
                v-for="option in platforms"
                :key="option.value"
                class="cursor-pointer border-b-2 border-transparent px-3 py-2 text-[0.9375rem] text-muted-foreground has-checked:border-primary has-checked:font-semibold has-checked:text-primary has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
            >
                <input
                    v-model="platform"
                    type="radio"
                    :value="option.value"
                    class="sr-only"
                />
                {{ option.label }}
            </label>
        </div>

        <p
            v-if="platform === 'AUTO_CURSEFORGE' && !curseforge"
            class="text-sm text-muted-foreground"
        >
            CurseForge needs an API key. Set CURSEFORGE_API_KEY in the panel's
            environment, then reload this page.
        </p>

        <template v-else>
            <div class="grid gap-2">
                <Label for="pack_search" class="sr-only">Search modpacks</Label>
                <Input
                    id="pack_search"
                    v-model="query"
                    type="search"
                    :placeholder="`Search ${platformLabel}`"
                />
            </div>

            <p role="status" class="text-sm text-muted-foreground">
                {{
                    loading
                        ? 'Searching…'
                        : message ||
                          (packs.length ? '' : 'No packs match that search.')
                }}
            </p>

            <div
                role="radiogroup"
                aria-label="Modpack"
                class="flex flex-col gap-2"
            >
                <label
                    v-for="pack in packs"
                    :key="pack.project"
                    class="flex cursor-pointer items-center gap-3 rounded-md border border-input px-3 py-2 has-checked:border-primary has-checked:bg-muted has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                >
                    <input
                        type="radio"
                        :checked="picked?.project === pack.project"
                        class="size-4 accent-primary"
                        @change="pick(pack)"
                    />
                    <img
                        v-if="pack.icon"
                        :src="pack.icon"
                        alt=""
                        width="36"
                        height="36"
                        loading="lazy"
                        class="size-9 rounded-sm"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-primary">{{
                            pack.name
                        }}</span>
                        <span
                            class="block truncate text-[0.8125rem] text-muted-foreground"
                            >{{ pack.description }}</span
                        >
                    </span>
                    <span
                        class="text-[0.8125rem] text-muted-foreground tabular-nums"
                        >{{ compact.format(pack.downloads) }} downloads</span
                    >
                </label>
            </div>

            <div v-if="platform === 'MODRINTH'" class="grid gap-2">
                <Label for="pack_link"
                    >Or paste a Modrinth project or version link</Label
                >
                <Input
                    id="pack_link"
                    v-model="link"
                    type="url"
                    placeholder="https://modrinth.com/modpack/…"
                />
            </div>

            <p
                v-if="platform === 'AUTO_CURSEFORGE'"
                class="text-[0.8125rem] text-muted-foreground"
            >
                Some CurseForge packs block third-party downloads. If one does,
                the console says which files to fetch, and you upload them in
                Files.
            </p>
        </template>

        <div v-if="picked && versions.length" class="grid gap-2">
            <Label for="modpack_version">Pack version</Label>
            <select
                id="modpack_version"
                v-model="version"
                name="modpack_version"
                class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
            >
                <option value="">Newest</option>
                <option
                    v-for="entry in versions"
                    :key="entry.id"
                    :value="entry.id"
                >
                    {{ entry.name }}
                </option>
            </select>
        </div>

        <p v-if="picked" class="text-sm">
            <span class="text-muted-foreground">Selected:</span>
            <span class="font-semibold text-primary"> {{ picked.name }}</span>
        </p>
        <InputError :message="error" />
    </div>
</template>
