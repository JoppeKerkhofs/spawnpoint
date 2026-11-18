<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Package } from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import ModSkeleton from '@/components/ModSkeleton.vue';
import ModsChangedNotice from '@/components/ModsChangedNotice.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { useLiveReload } from '@/lib/live';
import type { Server, States } from '@/lib/servers';
import { destroy, search, store, update } from '@/routes/servers/mods';

type Installed = {
    file: string;
    size: number;
    project: string | null;
    name: string | null;
    icon: string | null;
    version: string | null;
    update: boolean;
};

type Mod = {
    project: string;
    name: string;
    description: string;
    icon: string | null;
    downloads: number;
};

const props = defineProps<{
    server: Server;
    states?: States;
    // Both arrive after the page; a target of null means it could not be found out.
    target?: { loader: string; version: string } | null;
    list?: { installed: Installed[]; error: string | null };
    missing: { project: string; name: string }[];
}>();

const slug = computed(() => ({ server: props.server.slug }));

// An install answers with this page again, without its late parts: what is shown stays until they come.
const keep = <T>(read: () => T | undefined) => {
    const kept = shallowRef(read());
    watch(read, (now) => {
        if (now !== undefined) {
            kept.value = now;
        }
    });

    return kept;
};

const target = keep(() => props.target);
const list = keep(() => props.list);
const installed = computed(() => list.value?.installed ?? []);

const filter = ref('');
const shown = computed(() => {
    const words = filter.value.trim().toLowerCase();

    return installed.value.filter((mod) =>
        `${mod.name ?? ''} ${mod.file}`.toLowerCase().includes(words),
    );
});

// Each install names what it still needs; the list is kept here so the next visit does not drop it.
const needed = ref(props.missing);
watch(
    () => props.missing,
    (now) =>
        (needed.value = [
            ...needed.value,
            ...now.filter(
                (mod) =>
                    !needed.value.some((kept) => kept.project === mod.project),
            ),
        ]),
);

const loaderNames: Record<string, string> = { neoforge: 'NeoForge' };
const have = computed(
    () => new Set(installed.value.map((mod) => mod.project).filter(Boolean)),
);

// One change at a time: each is a download on the node.
const busy = ref('');

const visit = (key: string) => ({
    preserveScroll: true,
    onStart: () => (busy.value = key),
    onFinish: () => (busy.value = ''),
    onError: (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0]),
});

const install = (project: string) =>
    router.post(store.url(slug.value), { project }, visit(project));

const updateMod = (mod: Installed) =>
    router.put(
        update.url({ ...slug.value, file: mod.file }),
        {},
        visit(mod.file),
    );

const remove = async (mod: Installed) => {
    const sure = await ask(
        'Remove :name? Its file is deleted from the server. It stays loaded until the next restart.',
        { name: mod.name ?? mod.file },
    );

    if (sure) {
        router.delete(
            destroy.url({ ...slug.value, file: mod.file }),
            visit(mod.file),
        );
    }
};

const query = ref('');
const mods = ref<Mod[]>([]);
const more = ref(false);
const loading = ref(false);
const message = ref('');

let latestSearch = 0;

const load = async (offset = 0) => {
    if (!target.value) {
        return;
    }

    const current = ++latestSearch;
    loading.value = true;
    message.value = '';

    try {
        const response = await fetch(
            search.url(slug.value, { query: { q: query.value, offset } }),
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();

        // A slower, older search must not overwrite a newer one.
        if (current !== latestSearch) {
            return;
        }

        if (response.ok) {
            mods.value = offset ? [...mods.value, ...body.mods] : body.mods;
            more.value = body.more;
        } else {
            message.value = body.message;
        }
    } catch {
        if (current === latestSearch) {
            message.value = t('The mod list could not be loaded.');
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

// The first search waits for the target; a later copy of the same target must not empty the list.
watch(
    target,
    (now, before) => {
        if (now && !before) {
            void load();
        }
    },
    { immediate: true },
);

const compact = new Intl.NumberFormat(undefined, { notation: 'compact' });

// Only for mods: this list is costly, every jar is fingerprinted to make it.
useLiveReload(
    (change) => change.server === props.server.slug && change.what === 'mod',
    ['list'],
);
</script>

<template>
    <Head :title="t(':name mods', { name: server.name })" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="mods" />

        <ModsChangedNotice :slug="server.slug" :states="states" />

        <p
            v-if="list?.error"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{ list.error }}
        </p>

        <SectionCard
            v-if="needed.length"
            :title="t('Also needed')"
            role="status"
        >
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'The mods you installed also need these, and they were not added. On a modpack, install one only if the pack does not already include it.',
                    )
                }}
            </p>
            <ul class="mt-3 flex flex-col gap-2">
                <li
                    v-for="mod in needed"
                    :key="mod.project"
                    class="flex items-center gap-3"
                >
                    <span class="min-w-0 flex-1 font-semibold text-primary">{{
                        mod.name
                    }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        :disabled="!!busy || have.has(mod.project)"
                        :aria-label="
                            have.has(mod.project)
                                ? t(':name is installed', { name: mod.name })
                                : t('Install :name', { name: mod.name })
                        "
                        @click="install(mod.project)"
                    >
                        {{
                            have.has(mod.project)
                                ? t('Installed')
                                : busy === mod.project
                                  ? t('Installing…')
                                  : t('Install')
                        }}
                    </Button>
                </li>
            </ul>
        </SectionCard>

        <div class="flex flex-wrap items-start gap-5">
            <SectionCard
                :title="
                    list
                        ? t('Installed (:count)', { count: installed.length })
                        : t('Installed')
                "
                class="min-w-0 flex-[1_1_420px]"
            >
                <p
                    v-if="server.modpack"
                    class="mb-3 text-[0.8125rem] text-muted-foreground"
                >
                    {{
                        t(
                            'This list includes the mods of the modpack itself. If you remove or update one of those, the pack may put it back.',
                        )
                    }}
                </p>
                <ModSkeleton v-if="!list" />
                <p
                    v-else-if="!installed.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('No mods are installed yet.') }}
                </p>
                <div v-else class="mb-2 grid gap-2">
                    <Label for="installed_search" class="sr-only">{{
                        t('Search installed mods')
                    }}</Label>
                    <Input
                        id="installed_search"
                        v-model="filter"
                        type="search"
                        :placeholder="t('Search installed mods')"
                    />
                    <p
                        v-if="!shown.length"
                        role="status"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('No mods match that search.') }}
                    </p>
                </div>
                <ul v-if="list" class="flex flex-col">
                    <li
                        v-for="mod in shown"
                        :key="mod.file"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-divider py-2 first:border-t-0"
                    >
                        <img
                            v-if="mod.icon"
                            :src="mod.icon"
                            alt=""
                            width="36"
                            height="36"
                            loading="lazy"
                            class="size-9 rounded-sm"
                        />
                        <Package
                            v-else
                            class="size-9 shrink-0 p-1.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block font-semibold break-all text-primary"
                                >{{ mod.name ?? mod.file }}</span
                            >
                            <span
                                class="block text-[0.8125rem] break-all text-muted-foreground"
                            >
                                {{ mod.name ? mod.file : t('Not on Modrinth') }}
                                <template v-if="mod.version">
                                    · {{ mod.version }}</template
                                >
                            </span>
                        </span>
                        <Button
                            v-if="mod.update"
                            type="button"
                            variant="outline"
                            class="border-primary"
                            :disabled="!!busy"
                            :aria-label="
                                t('Update :name', {
                                    name: mod.name ?? mod.file,
                                })
                            "
                            @click="updateMod(mod)"
                        >
                            {{
                                busy === mod.file ? t('Updating…') : t('Update')
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="!!busy"
                            :aria-label="
                                t('Remove :name', {
                                    name: mod.name ?? mod.file,
                                })
                            "
                            @click="remove(mod)"
                        >
                            {{ t('Remove') }}
                        </Button>
                    </li>
                </ul>
            </SectionCard>

            <SectionCard
                :title="
                    target
                        ? t('Add mods for :loader :version', {
                              loader:
                                  loaderNames[target.loader] ??
                                  target.loader[0].toUpperCase() +
                                      target.loader.slice(1),
                              version: target.version,
                          })
                        : t('Add mods')
                "
                class="min-w-0 flex-[1_1_420px]"
            >
                <ModSkeleton v-if="target === undefined" />
                <p v-else-if="!target" class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Could not find out which loader and version this server runs, so mods cannot be added right now. Try again in a moment.',
                        )
                    }}
                </p>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="mod_search" class="sr-only">{{
                            t('Search mods')
                        }}</Label>
                        <Input
                            id="mod_search"
                            v-model="query"
                            type="search"
                            :placeholder="t('Search Modrinth')"
                        />
                    </div>

                    <p role="status" class="my-2 text-sm text-muted-foreground">
                        {{
                            loading
                                ? t('Searching…')
                                : message ||
                                  (mods.length
                                      ? ''
                                      : t('No mods match that search.'))
                        }}
                    </p>

                    <ModSkeleton v-if="loading && !mods.length" />
                    <ul v-else class="flex flex-col">
                        <li
                            v-for="mod in mods"
                            :key="mod.project"
                            class="flex items-center gap-3 border-t border-divider py-2 first:border-t-0"
                        >
                            <img
                                v-if="mod.icon"
                                :src="mod.icon"
                                alt=""
                                width="36"
                                height="36"
                                loading="lazy"
                                class="size-9 rounded-sm"
                            />
                            <Package
                                v-else
                                class="size-9 shrink-0 p-1.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-primary"
                                    >{{ mod.name }}
                                    <span
                                        class="text-[0.8125rem] font-normal text-muted-foreground tabular-nums"
                                        >·
                                        {{
                                            t(':count downloads', {
                                                count: compact.format(
                                                    mod.downloads,
                                                ),
                                            })
                                        }}</span
                                    ></span
                                >
                                <span
                                    class="line-clamp-2 text-[0.8125rem] text-muted-foreground"
                                    >{{ mod.description }}</span
                                >
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                class="border-primary"
                                :disabled="!!busy || have.has(mod.project)"
                                :aria-label="
                                    have.has(mod.project)
                                        ? t(':name is installed', {
                                              name: mod.name,
                                          })
                                        : t('Install :name', { name: mod.name })
                                "
                                @click="install(mod.project)"
                            >
                                {{
                                    have.has(mod.project)
                                        ? t('Installed')
                                        : busy === mod.project
                                          ? t('Installing…')
                                          : t('Install')
                                }}
                            </Button>
                        </li>
                    </ul>

                    <Button
                        v-if="more"
                        type="button"
                        variant="outline"
                        class="mt-3 border-primary"
                        :disabled="loading"
                        @click="load(mods.length)"
                    >
                        {{ t('Load more') }}
                    </Button>
                </template>
            </SectionCard>
        </div>
    </div>
</template>
