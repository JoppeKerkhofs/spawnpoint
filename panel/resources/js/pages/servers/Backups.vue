<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Download, Lock, LockOpen, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Server, States } from '@/lib/servers';
import { importMethod } from '@/routes/servers';
import {
    destroy,
    download,
    lock,
    restore,
    schedule,
    store,
} from '@/routes/servers/backups';

type Backup = { name: string; size: number; created: number; locked: boolean };

const props = defineProps<{
    server: Server;
    states?: States;
    backups: Backup[];
    imports: string[];
    error: string | null;
    timezone: string;
}>();

const slug = computed(() => ({ server: props.server.slug }));
const reloadList = { preserveScroll: true, only: ['backups', 'error'] };
const importSource = ref<'zip' | 'host'>(props.imports.length ? 'host' : 'zip');

const totalSize = computed(() =>
    props.backups.reduce((total, backup) => total + backup.size, 0),
);

const size = (bytes: number) =>
    bytes >= 1024 ** 3
        ? `${(bytes / 1024 ** 3).toFixed(2)} GB`
        : `${(bytes / 1024 ** 2).toFixed(1)} MB`;

const when = (backup: Backup) =>
    new Date(backup.created * 1000).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const restoreBackup = (backup: Backup) => {
    if (
        confirm(
            `Restore the backup from ${when(backup)}? The server stops, its current files are backed up first, then replaced.`,
        )
    ) {
        router.post(
            restore.url({ ...slug.value, backup: backup.name }),
            {},
            reloadList,
        );
    }
};

const deleteBackup = (backup: Backup) => {
    if (
        confirm(
            `Delete the backup from ${when(backup)}? This cannot be undone.`,
        )
    ) {
        router.delete(
            destroy.url({ ...slug.value, backup: backup.name }),
            reloadList,
        );
    }
};

const toggleLock = (backup: Backup) => {
    router.post(
        lock.url({ ...slug.value, backup: backup.name }),
        { locked: !backup.locked },
        reloadList,
    );
};

const confirmImport = (event: Event) => {
    if (
        !confirm(
            "Replace this server's world? The server stops, the current state is backed up first, then the world is replaced.",
        )
    ) {
        event.preventDefault();
    }
};
</script>

<template>
    <Head :title="`${server.name} backups`" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="backups" />

        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <div class="flex flex-wrap items-start gap-5">
            <section
                aria-labelledby="backups-title"
                class="min-w-0 flex-[999_1_560px] overflow-hidden rounded-md border border-border bg-card"
            >
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-muted px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <h2 id="backups-title" class="font-bold text-primary">
                            Backups
                        </h2>
                        <p class="text-[0.8125rem] text-muted-foreground">
                            The world is saved and paused for writing while it
                            is archived, so players can keep playing.
                        </p>
                    </div>
                    <Button
                        type="button"
                        @click="router.post(store.url(slug), {}, reloadList)"
                    >
                        <Plus aria-hidden="true" />
                        Back up now
                    </Button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[0.8125rem] text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Created
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Size
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="backup in backups"
                                :key="backup.name"
                                class="border-t border-divider"
                            >
                                <td class="px-4 py-2">
                                    {{ when(backup) }}
                                    <span
                                        v-if="backup.locked"
                                        class="ml-2 inline-flex items-center gap-1 text-[0.8125rem] text-muted-foreground"
                                    >
                                        <Lock
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        Locked
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ size(backup.size) }}
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="border-primary"
                                            @click="restoreBackup(backup)"
                                        >
                                            Restore
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            as-child
                                        >
                                            <a
                                                :href="
                                                    download.url({
                                                        ...slug,
                                                        backup: backup.name,
                                                    })
                                                "
                                                :aria-label="`Download backup from ${when(backup)}`"
                                            >
                                                <Download aria-hidden="true" />
                                            </a>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :aria-label="`${backup.locked ? 'Unlock' : 'Lock'} backup from ${when(backup)}`"
                                            @click="toggleLock(backup)"
                                        >
                                            <component
                                                :is="
                                                    backup.locked
                                                        ? LockOpen
                                                        : Lock
                                                "
                                                aria-hidden="true"
                                            />
                                        </Button>
                                        <Button
                                            v-if="!backup.locked"
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            class="text-destructive hover:text-destructive"
                                            :aria-label="`Delete backup from ${when(backup)}`"
                                            @click="deleteBackup(backup)"
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!backups.length">
                                <td
                                    colspan="3"
                                    class="border-t border-divider px-4 py-3 text-sm text-muted-foreground"
                                >
                                    No backups yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="border-t border-divider px-4 py-2.5 text-sm text-muted-foreground"
                >
                    Restoring stops the server, backs up its current files
                    first, then puts the chosen backup in their place. A running
                    backup shows in the status above.
                </div>
            </section>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                <SectionCard title="Schedule and retention">
                    <Form
                        v-bind="schedule.form(slug)"
                        :options="{ preserveScroll: true }"
                        class="flex flex-col gap-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="backup_at">Daily backup at</Label>
                            <Input
                                id="backup_at"
                                name="backup_at"
                                type="time"
                                :default-value="server.backup_at ?? ''"
                                aria-describedby="backup_at_hint"
                            />
                            <span
                                id="backup_at_hint"
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                In the panel's time zone ({{ timezone }}). Leave
                                empty for no scheduled backup.
                            </span>
                            <InputError :message="errors.backup_at" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="backup_keep">Keep the newest</Label>
                            <div class="flex items-center gap-2">
                                <Input
                                    id="backup_keep"
                                    name="backup_keep"
                                    type="number"
                                    min="1"
                                    max="365"
                                    required
                                    class="w-24"
                                    :default-value="server.backup_keep"
                                />
                                <span class="text-sm text-muted-foreground"
                                    >backups, plus every locked one</span
                                >
                            </div>
                            <InputError :message="errors.backup_keep" />
                        </div>

                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
                        >
                            <dt class="text-muted-foreground">Using</dt>
                            <dd class="tabular-nums">
                                {{ size(totalSize) }} for
                                {{ backups.length }} backup{{
                                    backups.length === 1 ? '' : 's'
                                }}
                            </dd>
                        </dl>

                        <Button :disabled="processing" class="self-start"
                            >Save</Button
                        >
                    </Form>
                </SectionCard>

                <SectionCard title="Import a world">
                    <Form
                        v-bind="importMethod.form(slug)"
                        :options="{ preserveScroll: true }"
                        class="flex flex-col gap-3"
                        v-slot="{ errors, processing }"
                        @submit.capture="confirmImport"
                    >
                        <div
                            role="radiogroup"
                            aria-label="World source"
                            class="flex flex-col gap-2"
                        >
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="importSource"
                                    type="radio"
                                    name="source"
                                    value="zip"
                                    class="size-4 accent-primary"
                                />
                                A .zip uploaded in Files
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="importSource"
                                    type="radio"
                                    name="source"
                                    value="host"
                                    class="size-4 accent-primary"
                                />
                                A folder on the host
                            </label>
                        </div>

                        <div v-if="importSource === 'zip'" class="grid gap-2">
                            <Label for="import_zip">Path of the zip</Label>
                            <Input
                                id="import_zip"
                                name="path"
                                required
                                placeholder="world.zip"
                            />
                        </div>
                        <div v-else class="grid gap-2">
                            <Label for="import_host">Folder on the host</Label>
                            <select
                                id="import_host"
                                name="path"
                                required
                                class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
                            >
                                <option
                                    v-for="world in imports"
                                    :key="world"
                                    :value="world"
                                >
                                    {{ world }}
                                </option>
                            </select>
                            <span
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                {{
                                    imports.length
                                        ? 'Only worlds in the mounted import folder are listed.'
                                        : 'No worlds found. Mount a folder with a world as the import folder (MCPANEL_IMPORT_DIR).'
                                }}
                            </span>
                        </div>
                        <InputError :message="errors.path || errors.source" />

                        <Button
                            variant="outline"
                            class="self-start border-primary"
                            :disabled="
                                processing ||
                                (importSource === 'host' && !imports.length)
                            "
                        >
                            Import and replace world
                        </Button>
                    </Form>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
