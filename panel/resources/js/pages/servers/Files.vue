<script setup lang="ts">
import { ask, askName } from '@/lib/dialogs';
import { Head, Link, router } from '@inertiajs/vue3';
import { Folder, FileText, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import type { Server, States } from '@/lib/servers';
import { files, power } from '@/routes/servers';
import {
    destroy,
    download,
    folder,
    rename,
    save,
} from '@/routes/servers/files';

type Entry = { name: string; dir: boolean; size: number; modified: number };

const props = defineProps<{
    server: Server;
    states?: States;
    path: string;
    entries: Entry[];
    file: { path: string; content: string } | null;
    error: string | null;
    upload: { url: string };
}>();

const slug = computed(() => ({ server: props.server.slug }));

const modsChanged = computed(
    () => props.states?.servers[props.server.slug]?.mods_changed ?? 0,
);

// Lines the Configuration form owns: the image rewrites them on every start.
const managedKeys = [
    'motd',
    'difficulty',
    'gamemode',
    'max-players',
    'white-list',
    'enforce-whitelist',
    'level-seed',
];
const managedLines = computed(() =>
    props.file?.path === 'server.properties'
        ? managedKeys.filter((key) =>
              new RegExp(`^${key}=`, 'm').test(draft.value),
          )
        : [],
);
const join = (name: string) => (props.path ? `${props.path}/${name}` : name);

const crumbs = computed(() => {
    const parts = props.path ? props.path.split('/') : [];

    return parts.map((name, index) => ({
        name,
        path: parts.slice(0, index + 1).join('/'),
    }));
});

const browse = (path: string, edit?: string) =>
    files.url(slug.value, {
        query: { ...(path && { path }), ...(edit && { edit }) },
    });

const reloadFolder = {
    preserveScroll: true,
    only: ['entries', 'file', 'error'],
};

// The editor works on a copy, so "Revert" and the unsaved mark compare against what was loaded.
const draft = ref(props.file?.content ?? '');
watch(
    () => props.file,
    (file) => (draft.value = file?.content ?? ''),
);
const unsaved = computed(() => draft.value !== (props.file?.content ?? ''));

const saveFile = () => {
    router.put(
        save.url(slug.value),
        { path: props.file!.path, content: draft.value },
        reloadFolder,
    );
};

const newFile = async () => {
    const name = (await askName('New file', 'File name'))?.trim();

    if (name) {
        router.put(
            save.url(slug.value),
            { path: join(name), content: '' },
            {
                preserveScroll: true,
                onSuccess: () => router.visit(browse(props.path, join(name))),
            },
        );
    }
};

const newFolder = async () => {
    const name = (await askName('New folder', 'Folder name'))?.trim();

    if (name) {
        router.post(folder.url(slug.value), { path: join(name) }, reloadFolder);
    }
};

const renameEntry = async (entry: Entry) => {
    const name = (
        await askName('Rename :name', 'New name', entry.name, 'Rename', {
            name: entry.name,
        })
    )?.trim();

    if (name && name !== entry.name) {
        router.post(
            rename.url(slug.value),
            { from: join(entry.name), to: join(name) },
            reloadFolder,
        );
    }
};

const deleteEntry = async (entry: Entry) => {
    const name = entry.name;
    // Each text is written out in the call, so the language scan finds it.
    const sure = entry.dir
        ? await ask(
              'Delete the folder :name and everything in it? This cannot be undone.',
              { name },
          )
        : await ask('Delete :name? This cannot be undone.', { name });

    if (sure) {
        router.delete(destroy.url(slug.value), {
            ...reloadFolder,
            data: { path: join(entry.name) },
        });
    }
};

const picker = ref<HTMLInputElement>();
const uploading = ref('');
const dragging = ref(false);

// Files go straight from the browser to the Rust service, so their size is not limited by PHP.
const uploadFiles = (list: FileList | null | undefined) => {
    const chosen = [...(list ?? [])];

    if (!chosen.length || uploading.value) {
        return;
    }

    uploading.value =
        chosen.length === 1
            ? t('Uploading :count file…', { count: chosen.length })
            : t('Uploading :count files…', { count: chosen.length });

    // The upload token lasts five minutes, so each batch starts with a fresh one.
    router.reload({
        only: ['upload'],
        onSuccess: async () => {
            const failed: string[] = [];

            for (const file of chosen) {
                const target = `${props.upload.url}&path=${encodeURIComponent(props.path)}&name=${encodeURIComponent(file.name)}`;
                const response = await fetch(target, {
                    method: 'PUT',
                    body: file,
                }).catch(() => null);

                if (!response?.ok) {
                    failed.push(file.name);
                }
            }

            if (failed.length) {
                toast.error(
                    t('Could not upload: :names', { names: failed.join(', ') }),
                );
            } else {
                toast.success(
                    chosen.length === 1
                        ? t(
                              'Uploaded :count file. Mods load on the next restart.',
                              { count: chosen.length },
                          )
                        : t(
                              'Uploaded :count files. Mods load on the next restart.',
                              { count: chosen.length },
                          ),
                );
            }

            uploading.value = '';
            router.reload(reloadFolder);
        },
        onError: () => (uploading.value = ''),
    });
};

const size = (entry: Entry) => {
    if (entry.dir) {
        return entry.size === 1
            ? t(':count item', { count: entry.size })
            : t(':count items', { count: entry.size });
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const power = Math.min(
        Math.floor(Math.log(Math.max(entry.size, 1)) / Math.log(1024)),
        units.length - 1,
    );

    return `${(entry.size / 1024 ** power).toFixed(power ? 1 : 0)} ${units[power]}`;
};
</script>

<template>
    <Head :title="t(':name files', { name: server.name })" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="files" />

        <div
            v-if="modsChanged"
            role="status"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-border bg-card px-4 py-3 text-sm"
        >
            <span class="min-w-0 flex-1">
                {{
                    modsChanged === 1
                        ? t(
                              ':count mod or plugin file was added or replaced since the last start. It loads on the next restart.',
                              { count: modsChanged },
                          )
                        : t(
                              ':count mod or plugin files were added or replaced since the last start. They load on the next restart.',
                              { count: modsChanged },
                          )
                }}
            </span>
            <Button
                type="button"
                variant="outline"
                class="border-primary"
                @click="
                    router.post(
                        power.url({ ...slug, action: 'restart' }),
                        {},
                        { preserveScroll: true, only: ['states'] },
                    )
                "
            >
                {{ t('Restart now') }}
            </Button>
        </div>

        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <div class="flex flex-wrap items-start gap-5">
            <section
                :aria-label="t('Files')"
                class="min-w-0 flex-[1_1_480px] overflow-hidden rounded-md border bg-card"
                :class="dragging ? 'border-primary' : 'border-border'"
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="
                    dragging = false;
                    uploadFiles($event.dataTransfer?.files);
                "
            >
                <div
                    class="flex flex-wrap items-center gap-2 bg-muted px-4 py-2.5"
                >
                    <nav
                        :aria-label="t('Folder')"
                        class="min-w-0 flex-1 font-mono text-sm break-all"
                    >
                        <Link :href="browse('')" class="underline">/data</Link
                        ><template v-for="crumb in crumbs" :key="crumb.path"
                            >/<Link
                                :href="browse(crumb.path)"
                                class="underline"
                                >{{ crumb.name }}</Link
                            ></template
                        >
                    </nav>
                    <input
                        ref="picker"
                        type="file"
                        multiple
                        class="sr-only"
                        :aria-label="t('Files to upload')"
                        tabindex="-1"
                        @change="
                            uploadFiles(picker?.files);
                            picker!.value = '';
                        "
                    />
                    <Button
                        type="button"
                        :disabled="!!uploading"
                        @click="picker?.click()"
                    >
                        <Upload aria-hidden="true" />
                        {{ t('Upload') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="newFolder"
                    >
                        {{ t('New folder') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="newFile"
                    >
                        {{ t('New file') }}
                    </Button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[0.8125rem] text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Name') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Size') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Modified') }}
                                </th>
                                <th scope="col" class="px-4 py-2">
                                    <span class="sr-only">{{
                                        t('Actions')
                                    }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="entry in entries"
                                :key="entry.name"
                                class="border-t border-divider"
                            >
                                <td class="px-4 py-2">
                                    <Link
                                        :href="
                                            entry.dir
                                                ? browse(join(entry.name))
                                                : browse(path, join(entry.name))
                                        "
                                        :aria-current="
                                            file?.path === join(entry.name)
                                                ? 'true'
                                                : undefined
                                        "
                                        preserve-scroll
                                        class="flex items-center gap-2 break-all text-primary aria-[current]:font-semibold"
                                    >
                                        <component
                                            :is="entry.dir ? Folder : FileText"
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        {{ entry.name }}
                                    </Link>
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ size(entry) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap text-muted-foreground"
                                >
                                    {{
                                        new Date(
                                            entry.modified * 1000,
                                        ).toLocaleString(undefined, {
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        })
                                    }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                :aria-label="
                                                    t(
                                                        'More actions for :name',
                                                        { name: entry.name },
                                                    )
                                                "
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                v-if="!entry.dir"
                                                as-child
                                            >
                                                <a
                                                    :href="
                                                        download.url(slug, {
                                                            query: {
                                                                path: join(
                                                                    entry.name,
                                                                ),
                                                            },
                                                        })
                                                    "
                                                    >{{ t('Download') }}</a
                                                >
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @select="renameEntry(entry)"
                                            >
                                                {{ t('Rename') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @select="deleteEntry(entry)"
                                            >
                                                {{ t('Delete') }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </td>
                            </tr>
                            <tr v-if="!entries.length">
                                <td
                                    colspan="4"
                                    class="border-t border-divider px-4 py-3 text-sm text-muted-foreground"
                                >
                                    {{ t('This folder is empty.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    role="status"
                    class="border-t border-divider px-4 py-2.5 text-sm text-muted-foreground"
                >
                    {{
                        uploading ||
                        t(
                            'Drop files anywhere on this list to upload them to :folder.',
                            { folder: `/data${path ? `/${path}` : ''}` },
                        )
                    }}
                </div>
            </section>

            <section
                v-if="file"
                :aria-label="t('Editor')"
                class="min-w-0 flex-[1_1_480px] overflow-hidden rounded-md border border-border bg-card lg:sticky lg:top-4"
            >
                <div
                    class="flex flex-wrap items-center gap-2 bg-muted px-4 py-2.5"
                >
                    <h2
                        class="font-mono text-sm font-bold break-all text-primary"
                    >
                        {{ file.path }}
                    </h2>
                    <span
                        v-if="unsaved"
                        class="text-[0.8125rem] text-muted-foreground"
                        >{{ t('Unsaved changes') }}</span
                    >
                    <span class="flex-1" />
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        :disabled="!unsaved"
                        @click="draft = file.content"
                    >
                        {{ t('Revert') }}
                    </Button>
                    <Button
                        type="button"
                        :disabled="!unsaved"
                        @click="saveFile"
                    >
                        {{ t('Save') }}
                    </Button>
                </div>
                <label for="editor" class="sr-only">{{
                    t('Contents of :path', { path: file.path })
                }}</label>
                <textarea
                    id="editor"
                    v-model="draft"
                    spellcheck="false"
                    class="block h-[min(640px,calc(100vh-13rem))] min-h-[240px] w-full resize-y bg-card p-3.5 font-mono text-[0.8125rem] leading-[1.65] whitespace-pre text-foreground"
                />
                <div
                    class="border-t border-divider px-4 py-2.5 text-[0.8125rem] text-muted-foreground"
                >
                    <template v-if="managedLines.length">
                        {{
                            t(
                                'These lines are set in Configuration and rewritten on every start, so change them there:',
                            )
                        }}
                        <code class="font-mono">{{
                            managedLines.join(', ')
                        }}</code
                        >.
                    </template>
                    {{ t('Changes apply on the next restart.') }}
                </div>
            </section>
        </div>
    </div>
</template>
