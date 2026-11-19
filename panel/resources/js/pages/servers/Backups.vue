<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Download, Lock, LockOpen, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { useLiveReload } from '@/lib/live';
import type { Server, States } from '@/lib/servers';
import { title } from '@/lib/servers';
import { importMethod } from '@/routes/servers';
import {
    cancel,
    destroy,
    download,
    lock,
    restore,
    retention,
    store,
} from '@/routes/servers/backups';
import {
    destroy as destroySchedule,
    store as storeSchedule,
    update as updateSchedule,
} from '@/routes/servers/schedules';

type Backup = {
    name: string;
    size: number;
    created: number;
    locked: boolean;
    status: 'completed' | 'running' | 'failed';
    by: string | null;
    reason: string | null;
    // Made while the backup folder could not be reached, so kept in the node's fallback folder.
    fallback?: boolean;
};
type Schedule = {
    id: number;
    action: string;
    time: string;
    weekday: number | null;
    command: string | null;
    is_active: boolean;
};

const props = defineProps<{
    server: Server;
    states?: States;
    backups: Backup[];
    imports: string[];
    schedules: Schedule[];
    upload: { url: string };
    error: string | null;
    timezone: string;
    autoBackupAt: string | null;
}>();

const slug = computed(() => ({ server: props.server.slug }));
const reloadList = { preserveScroll: true, only: ['backups', 'error'] };
const importSource = ref<'zip' | 'host'>(props.imports.length ? 'host' : 'zip');

const weekdays = [
    t('Sundays'),
    t('Mondays'),
    t('Tuesdays'),
    t('Wednesdays'),
    t('Thursdays'),
    t('Fridays'),
    t('Saturdays'),
];
const scheduleAction = ref('backup');
const reloadSchedules = { preserveScroll: true, only: ['schedules'] };

const when2 = (schedule: Schedule) =>
    t(':day at :time', {
        day:
            schedule.weekday === null
                ? t('Every day')
                : weekdays[schedule.weekday],
        time: schedule.time,
    });

const toggleSchedule = (schedule: Schedule) => {
    router.put(
        updateSchedule.url({ ...slug.value, schedule: schedule.id }),
        { is_active: !schedule.is_active },
        reloadSchedules,
    );
};

const removeSchedule = (schedule: Schedule) => {
    router.delete(
        destroySchedule.url({ ...slug.value, schedule: schedule.id }),
        reloadSchedules,
    );
};

const completed = computed(() =>
    props.backups.filter((backup) => backup.status === 'completed'),
);

const totalSize = computed(() =>
    completed.value.reduce((total, backup) => total + backup.size, 0),
);

// A rough figure: how far the archive is, measured against the size of the last finished one.
const progress = (backup: Backup) => {
    const previous = completed.value[0]?.size;

    return previous
        ? t('about :percent%', {
              percent: Math.min(99, Math.round((backup.size / previous) * 100)),
          })
        : size(backup.size);
};

const size = (bytes: number) =>
    bytes >= 1024 ** 3
        ? `${(bytes / 1024 ** 3).toFixed(2)} GB`
        : `${(bytes / 1024 ** 2).toFixed(1)} MB`;

const when = (backup: Backup) =>
    new Date(backup.created * 1000).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const restoreBackup = async (backup: Backup) => {
    if (
        await ask(
            'Restore the backup from :when? The server stops, its current files are backed up first, then replaced.',
            { when: when(backup) },
        )
    ) {
        router.post(
            restore.url({ ...slug.value, backup: backup.name }),
            {},
            reloadList,
        );
    }
};

const deleteBackup = async (backup: Backup) => {
    if (
        await ask('Delete the backup from :when? This cannot be undone.', {
            when: when(backup),
        })
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

const page = usePage();
const zipFile = ref<HTMLInputElement>();
const hostWorld = ref(props.imports[0] ?? '');
const importing = ref('');

const startImport = (path: string) => {
    router.post(
        importMethod.url(slug.value),
        // A server that has never run was made to receive this world, so it is started afterwards.
        { source: importSource.value, path, start: true },
        { preserveScroll: true, onFinish: () => (importing.value = '') },
    );
};

const submitImport = async () => {
    const file = zipFile.value?.files?.[0];

    if (importSource.value === 'zip' && !file) {
        return;
    }
    if (
        !(await ask(
            "Replace this server's world? The server stops, its current files are backed up first, then the world is replaced.",
        ))
    ) {
        return;
    }
    if (importSource.value === 'host') {
        importing.value = t('Starting the import…');
        startImport(hostWorld.value);

        return;
    }

    // The zip goes straight to the Rust service, so its size is not limited by PHP.
    importing.value = t('Uploading :name…', { name: file!.name });
    router.reload({
        only: ['upload'],
        onSuccess: async () => {
            const response = await fetch(
                `${props.upload.url}&path=&name=world-import.zip`,
                { method: 'PUT', body: file },
            ).catch(() => null);

            if (response?.ok) {
                startImport('world-import.zip');
            } else {
                importing.value = '';
                toast.error(t('The zip could not be uploaded.'));
            }
        },
        onError: () => (importing.value = ''),
    });
};
// A backup that started, finished or was removed, here or in another tab.
useLiveReload(
    (change) =>
        change.server === props.server.slug &&
        ['backup', 'world', 'schedule', 'state'].includes(change.what),
    ['backups', 'schedules', 'autoBackupAt', 'error'],
);
</script>

<template>
    <Head :title="t(':name backups', { name: server.name })" />

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
                            {{ t('Backups') }}
                        </h2>
                        <p class="text-[0.8125rem] text-muted-foreground">
                            {{
                                t(
                                    'The world is saved and paused for writing while it is archived, so players can keep playing.',
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        @click="router.post(store.url(slug), {}, reloadList)"
                    >
                        <Plus aria-hidden="true" />
                        {{ t('Back up now') }}
                    </Button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[0.8125rem] text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Created') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Status') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Size') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('By') }}
                                </th>
                                <th scope="col" class="px-4 py-2 font-normal">
                                    {{ t('Actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="backup in backups"
                                :key="backup.name"
                                class="border-t border-divider"
                            >
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ when(backup) }}
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <template
                                        v-if="backup.status === 'running'"
                                    >
                                        {{
                                            t('Running, :progress', {
                                                progress: progress(backup),
                                            })
                                        }}
                                    </template>
                                    <template
                                        v-else-if="backup.status === 'failed'"
                                    >
                                        <span
                                            class="font-semibold text-destructive"
                                            >{{ t('Failed') }}</span
                                        >
                                        <span
                                            class="block break-words text-muted-foreground"
                                            >{{ backup.reason }}</span
                                        >
                                    </template>
                                    <template v-else>
                                        {{ t('Completed') }}
                                        <span
                                            v-if="backup.locked"
                                            class="ml-1 inline-flex items-center gap-1 text-[0.8125rem] text-muted-foreground"
                                        >
                                            <Lock
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{ t('Locked') }}
                                        </span>
                                        <span
                                            v-if="backup.fallback"
                                            class="ml-1 text-[0.8125rem] text-muted-foreground"
                                            :title="
                                                t(
                                                    'Made while the backup folder could not be reached. It is kept in the fallback folder of the node.',
                                                )
                                            "
                                        >
                                            · {{ t('In the fallback folder') }}
                                        </span>
                                    </template>
                                </td>
                                <td
                                    class="px-4 py-2 text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{
                                        backup.status === 'completed'
                                            ? size(backup.size)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2 text-sm text-muted-foreground"
                                >
                                    {{ backup.by ?? '—' }}
                                </td>
                                <td class="px-4 py-2">
                                    <Button
                                        v-if="backup.status === 'running'"
                                        type="button"
                                        variant="outline"
                                        class="border-primary"
                                        @click="
                                            router.delete(
                                                cancel.url(slug),
                                                reloadList,
                                            )
                                        "
                                    >
                                        {{ t('Cancel') }}
                                    </Button>
                                    <Button
                                        v-else-if="backup.status === 'failed'"
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        :aria-label="
                                            t(
                                                'Remove the failed backup from :when',
                                                { when: when(backup) },
                                            )
                                        "
                                        @click="deleteBackup(backup)"
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                    <div v-else class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            class="border-primary"
                                            @click="restoreBackup(backup)"
                                        >
                                            {{ t('Restore') }}
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
                                                :aria-label="
                                                    t(
                                                        'Download backup from :when',
                                                        { when: when(backup) },
                                                    )
                                                "
                                            >
                                                <Download aria-hidden="true" />
                                            </a>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            :aria-label="
                                                backup.locked
                                                    ? t(
                                                          'Unlock backup from :when',
                                                          {
                                                              when: when(
                                                                  backup,
                                                              ),
                                                          },
                                                      )
                                                    : t(
                                                          'Lock backup from :when',
                                                          {
                                                              when: when(
                                                                  backup,
                                                              ),
                                                          },
                                                      )
                                            "
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
                                            :aria-label="
                                                t('Delete backup from :when', {
                                                    when: when(backup),
                                                })
                                            "
                                            @click="deleteBackup(backup)"
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!backups.length">
                                <td
                                    colspan="5"
                                    class="border-t border-divider px-4 py-3 text-sm text-muted-foreground"
                                >
                                    {{ t('No backups yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="border-t border-divider px-4 py-2.5 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Restoring stops the server, backs up its current files first, then puts the chosen backup in their place. A running backup shows in the status above.',
                        )
                    }}
                </div>
            </section>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                <SectionCard :title="t('Schedules')">
                    <ul v-if="schedules.length" class="mb-4">
                        <li
                            v-for="schedule in schedules"
                            :key="schedule.id"
                            class="flex items-center gap-3 border-t border-divider py-2 first:border-t-0 first:pt-0"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">
                                    {{
                                        schedule.action === 'command'
                                            ? t('Command')
                                            : t(title(schedule.action))
                                    }}
                                </div>
                                <div
                                    class="text-[0.8125rem] break-words text-muted-foreground"
                                >
                                    {{ when2(schedule) }}
                                    <template v-if="schedule.command">
                                        · {{ t('runs') }}
                                        <code class="font-mono">{{
                                            schedule.command
                                        }}</code>
                                    </template>
                                    <template v-if="!schedule.is_active">
                                        · {{ t('paused') }}
                                    </template>
                                </div>
                            </div>
                            <label class="flex items-center gap-1.5 text-sm">
                                <input
                                    type="checkbox"
                                    role="switch"
                                    :checked="schedule.is_active"
                                    :aria-label="
                                        t(':action :when active', {
                                            action: t(title(schedule.action)),
                                            when: when2(schedule),
                                        })
                                    "
                                    class="size-4 accent-primary"
                                    @change="toggleSchedule(schedule)"
                                />
                                {{ t('On') }}
                            </label>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="text-destructive hover:text-destructive"
                                :aria-label="
                                    t('Delete the schedule :action :when', {
                                        action: t(title(schedule.action)),
                                        when: when2(schedule),
                                    })
                                "
                                @click="removeSchedule(schedule)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="mb-4 text-sm text-muted-foreground">
                        {{ t('Nothing is scheduled.') }}
                    </p>

                    <Form
                        v-bind="storeSchedule.form(slug)"
                        :options="reloadSchedules"
                        reset-on-success
                        class="flex flex-col gap-3 border-t border-divider pt-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1.5">
                                <Label for="schedule_action">{{
                                    t('Do')
                                }}</Label>
                                <select
                                    id="schedule_action"
                                    v-model="scheduleAction"
                                    name="action"
                                    class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
                                >
                                    <option value="backup">
                                        {{ t('Backup') }}
                                    </option>
                                    <option value="restart">
                                        {{ t('Restart') }}
                                    </option>
                                    <option value="stop">
                                        {{ t('Stop') }}
                                    </option>
                                    <option value="start">
                                        {{ t('Start') }}
                                    </option>
                                    <option value="command">
                                        {{ t('Run a command') }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="schedule_time">{{ t('At') }}</Label>
                                <Input
                                    id="schedule_time"
                                    name="time"
                                    type="time"
                                    required
                                    default-value="04:00"
                                />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="schedule_weekday">{{ t('On') }}</Label>
                            <select
                                id="schedule_weekday"
                                name="weekday"
                                class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
                            >
                                <option value="">{{ t('Every day') }}</option>
                                <option
                                    v-for="(day, index) in weekdays"
                                    :key="day"
                                    :value="index"
                                >
                                    {{ day }}
                                </option>
                            </select>
                        </div>
                        <div
                            v-if="scheduleAction === 'command'"
                            class="grid gap-1.5"
                        >
                            <Label for="schedule_command">{{
                                t('Command')
                            }}</Label>
                            <Input
                                id="schedule_command"
                                name="command"
                                required
                                maxlength="500"
                                class="font-mono"
                                placeholder="say Backup in 5 minutes"
                            />
                        </div>
                        <InputError
                            :message="
                                errors.action ||
                                errors.time ||
                                errors.weekday ||
                                errors.command
                            "
                        />
                        <span class="text-[0.8125rem] text-muted-foreground">
                            {{
                                t(
                                    "Times are in the panel's time zone (:timezone).",
                                    { timezone },
                                )
                            }}
                        </span>
                        <Button :disabled="processing" class="self-start">{{
                            t('Add schedule')
                        }}</Button>
                    </Form>
                </SectionCard>

                <SectionCard :title="t('Automatic backup and retention')">
                    <Form
                        v-bind="retention.form(slug)"
                        :options="{ preserveScroll: true }"
                        class="flex flex-col gap-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="auto_backup"
                                    value="1"
                                    :checked="autoBackupAt !== null"
                                    class="size-4 accent-primary"
                                />
                                {{ t('Back up automatically every day') }}
                            </label>
                            <div class="flex items-center gap-2">
                                <Label for="auto_backup_at">{{
                                    t('at')
                                }}</Label>
                                <Input
                                    id="auto_backup_at"
                                    name="auto_backup_at"
                                    type="time"
                                    class="w-32"
                                    :default-value="autoBackupAt ?? '04:00'"
                                />
                                <span class="text-sm text-muted-foreground">{{
                                    timezone
                                }}</span>
                            </div>
                            <InputError :message="errors.auto_backup_at" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="backup_keep">{{
                                t('Keep the newest')
                            }}</Label>
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
                                <span class="text-sm text-muted-foreground">{{
                                    t('backups, plus every locked one')
                                }}</span>
                            </div>
                            <InputError :message="errors.backup_keep" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="backup_retention_days">{{
                                t('Delete backups older than')
                            }}</Label>
                            <div class="flex items-center gap-2">
                                <Input
                                    id="backup_retention_days"
                                    name="backup_retention_days"
                                    type="number"
                                    min="1"
                                    max="3650"
                                    class="w-24"
                                    :default-value="
                                        server.backup_retention_days ?? ''
                                    "
                                />
                                <span class="text-sm text-muted-foreground">{{
                                    t('days; empty for no age limit')
                                }}</span>
                            </div>
                            <span
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                {{
                                    t(
                                        'Checked after each new backup. Locked backups and the newest one are never deleted.',
                                    )
                                }}
                            </span>
                            <InputError
                                :message="errors.backup_retention_days"
                            />
                        </div>

                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('Using') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    completed.length === 1
                                        ? t(':size for :count backup', {
                                              size: size(totalSize),
                                              count: completed.length,
                                          })
                                        : t(':size for :count backups', {
                                              size: size(totalSize),
                                              count: completed.length,
                                          })
                                }}
                            </dd>
                        </dl>

                        <Button :disabled="processing" class="self-start">{{
                            t('Save')
                        }}</Button>
                    </Form>
                </SectionCard>

                <SectionCard :title="t('Import a world')">
                    <form
                        class="flex flex-col gap-3"
                        @submit.prevent="submitImport"
                    >
                        <div
                            role="radiogroup"
                            :aria-label="t('World source')"
                            class="flex flex-col gap-2"
                        >
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="importSource"
                                    type="radio"
                                    value="zip"
                                    class="size-4 accent-primary"
                                />
                                {{ t('A world .zip from this computer') }}
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="importSource"
                                    type="radio"
                                    value="host"
                                    class="size-4 accent-primary"
                                />
                                {{ t('A folder on the host') }}
                            </label>
                        </div>

                        <div v-if="importSource === 'zip'" class="grid gap-2">
                            <Label for="import_zip">{{
                                t('World .zip')
                            }}</Label>
                            <input
                                id="import_zip"
                                ref="zipFile"
                                type="file"
                                accept=".zip,application/zip"
                                required
                                class="text-sm file:mr-3 file:h-9 file:cursor-pointer file:rounded-md file:border file:border-solid file:border-primary file:bg-card file:px-3 file:text-sm file:text-primary"
                            />
                        </div>
                        <div v-else class="grid gap-2">
                            <Label for="import_host">{{
                                t('Folder on the host')
                            }}</Label>
                            <select
                                id="import_host"
                                v-model="hostWorld"
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
                                        ? t(
                                              'Only worlds in the mounted import folder are listed.',
                                          )
                                        : t(
                                              'No worlds found. Mount a folder with a world as the import folder (SPAWNPOINT_IMPORT_DIR).',
                                          )
                                }}
                            </span>
                        </div>
                        <InputError
                            :message="
                                page.props.errors.path ||
                                page.props.errors.source
                            "
                        />
                        <p
                            v-if="importing"
                            role="status"
                            class="text-sm text-muted-foreground"
                        >
                            {{ importing }}
                        </p>

                        <Button
                            variant="outline"
                            class="self-start border-primary"
                            :disabled="
                                !!importing ||
                                (importSource === 'host' && !imports.length)
                            "
                        >
                            {{ t('Import and replace world') }}
                        </Button>
                    </form>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
