<script setup lang="ts">
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
import type { Server, States } from '@/lib/servers';
import { files } from '@/routes/servers';
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

const newFile = () => {
    const name = prompt('Name of the new file')?.trim();

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

const newFolder = () => {
    const name = prompt('Name of the new folder')?.trim();

    if (name) {
        router.post(folder.url(slug.value), { path: join(name) }, reloadFolder);
    }
};

const renameEntry = (entry: Entry) => {
    const name = prompt(`New name for ${entry.name}`, entry.name)?.trim();

    if (name && name !== entry.name) {
        router.post(
            rename.url(slug.value),
            { from: join(entry.name), to: join(name) },
            reloadFolder,
        );
    }
};

const deleteEntry = (entry: Entry) => {
    const what = entry.dir
        ? `the folder ${entry.name} and everything in it`
        : entry.name;

    if (confirm(`Delete ${what}? This cannot be undone.`)) {
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

    uploading.value = `Uploading ${chosen.length} file${chosen.length === 1 ? '' : 's'}…`;

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
                toast.error(`Could not upload: ${failed.join(', ')}`);
            } else {
                toast.success(
                    `Uploaded ${chosen.length} file${chosen.length === 1 ? '' : 's'}. Mods load on the next restart.`,
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
        return `${entry.size} item${entry.size === 1 ? '' : 's'}`;
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
    <Head :title="`${server.name} files`" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="files" />

        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <div class="flex flex-wrap items-start gap-5">
            <section
                aria-label="Files"
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
                        aria-label="Folder"
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
                        aria-label="Files to upload"
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
                        Upload
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="newFolder"
                    >
                        New folder
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="newFile"
                    >
                        New file
                    </Button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[0.8125rem] text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Name
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Size
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Modified
                                </th>
                                <th scope="col" class="px-4 py-2">
                                    <span class="sr-only">Actions</span>
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
                                                :aria-label="`More actions for ${entry.name}`"
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
                                                    >Download</a
                                                >
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @select="renameEntry(entry)"
                                            >
                                                Rename
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @select="deleteEntry(entry)"
                                            >
                                                Delete
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
                                    This folder is empty.
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
                        `Drop files anywhere on this list to upload them to /data${path ? `/${path}` : ''}.`
                    }}
                </div>
            </section>

            <section
                v-if="file"
                aria-label="Editor"
                class="min-w-0 flex-[1_1_480px] overflow-hidden rounded-md border border-border bg-card"
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
                        >Unsaved changes</span
                    >
                    <span class="flex-1" />
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        :disabled="!unsaved"
                        @click="draft = file.content"
                    >
                        Revert
                    </Button>
                    <Button
                        type="button"
                        :disabled="!unsaved"
                        @click="saveFile"
                    >
                        Save
                    </Button>
                </div>
                <label for="editor" class="sr-only"
                    >Contents of {{ file.path }}</label
                >
                <textarea
                    id="editor"
                    v-model="draft"
                    spellcheck="false"
                    class="block h-[520px] w-full resize-y bg-card p-3.5 font-mono text-[0.8125rem] leading-[1.65] whitespace-pre text-foreground"
                />
                <div
                    class="border-t border-divider px-4 py-2.5 text-[0.8125rem] text-muted-foreground"
                >
                    In server.properties, the settings from the Configuration
                    form are rewritten on every start, so change those there.
                    Other changes apply on the next restart.
                </div>
            </section>
        </div>
    </div>
</template>
