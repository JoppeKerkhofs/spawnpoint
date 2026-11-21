<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { useLiveReload } from '@/lib/live';
import { index } from '@/routes/activity';
import { dns } from '@/routes/servers';

type Entry = {
    id: number;
    actor: string;
    event: string;
    subject: string | null;
    properties: Record<string, unknown> | null;
    at: string;
};

const props = defineProps<{
    entries: Entry[];
    server: string;
    dnsRetry: string[];
    newerUrl: string | null;
    olderUrl: string | null;
}>();

// New entries appear by themselves, but only on the newest page: older pages would shift.
useLiveReload(
    (change) => change.what !== 'state',
    ['entries', 'olderUrl'],
    () => props.newerUrl === null,
);

const labels: Record<string, string> = {
    'server.create': t('Created the server'),
    'server.update': t('Changed the configuration'),
    'server.delete': t('Deleted the server'),
    'server.icon': t('Changed the picture'),
    'server.start': t('Started the server'),
    'server.stop': t('Stopped the server'),
    'server.restart': t('Restarted the server'),
    'server.kill': t('Killed the server'),
    'backup.create': t('Started a backup'),
    'backup.restore': t('Restored a backup'),
    'backup.delete': t('Deleted a backup'),
    'backup.schedule': t('Changed the backup schedule'),
    'world.import': t('Imported a world'),
    'file.save': t('Saved a file'),
    'file.folder': t('Created a folder'),
    'file.rename': t('Renamed a file'),
    'file.delete': t('Deleted a file'),
    'mod.install': t('Installed a mod'),
    'mod.update': t('Updated a mod'),
    'mod.remove': t('Removed a mod'),
    'user.create': t('Created a user'),
    'user.update': t('Changed a user'),
    'user.delete': t('Deleted a user'),
    'token.create': t('Created an API token'),
    'token.revoke': t('Revoked an API token'),
    'settings.update': t('Changed the panel settings'),
    'portal.release': t('Published a launcher release'),
    'portal.withhold': t('Made a launcher version not downloadable'),
    'portal.allow': t('Made a launcher version downloadable'),
    'portal.remove': t('Removed a launcher version'),
    'node.create': t('Added a node'),
    'node.update': t('Changed a node'),
    'node.delete': t('Removed a node'),
    'dns.create': t('Added the DNS record'),
    'dns.failed': t('Could not add the DNS record'),
    'backup.cancel': t('Cancelled a backup'),
    'backup.fallback': t('Backup folder unreachable, used the fallback folder'),
    'backup.retention': t('Changed the backup settings'),
    'schedule.create': t('Added a schedule'),
    'schedule.delete': t('Removed a schedule'),
    'schedule.run': t('A schedule ran'),
    'modpack.outdated': t('A new version of the modpack is out'),
    'modpack.update': t('Updated the modpack'),
    'modpack.update.failed': t('Could not update the modpack'),
};

// Red for what failed or was removed, amber for what interrupts play, green for what was added or started.
const tones: Record<string, 'bad' | 'warn' | 'good'> = {
    'dns.failed': 'bad',
    'server.kill': 'bad',
    'server.delete': 'bad',
    'backup.delete': 'bad',
    'file.delete': 'bad',
    'mod.remove': 'bad',
    'user.delete': 'bad',
    'token.revoke': 'bad',
    'server.stop': 'warn',
    'server.restart': 'warn',
    'backup.restore': 'warn',
    'backup.cancel': 'warn',
    'backup.fallback': 'bad',
    'world.import': 'warn',
    'modpack.outdated': 'warn',
    'modpack.update': 'good',
    'modpack.update.failed': 'bad',
    'server.create': 'good',
    'server.start': 'good',
    'dns.create': 'good',
    'backup.create': 'good',
    'user.create': 'good',
    'token.create': 'good',
};

const toneClasses = {
    bad: 'text-destructive',
    warn: 'text-[#7a4f00] dark:text-[#f0c36b]',
    good: 'text-[#1d6b3f] dark:text-[#7fd6a2]',
};

const details = (entry: Entry) =>
    Object.entries(entry.properties ?? {})
        .map(
            ([key, value]) =>
                `${key}: ${Array.isArray(value) ? value.join(', ') : String(value)}`,
        )
        .join(' · ');
</script>

<template>
    <Head :title="t('Activity')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                {{ t('Activity') }}
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                {{
                    t(
                        'Who started, stopped or changed what, newest first. Console commands and file uploads go straight to the Rust service and are not listed.',
                    )
                }}
            </p>
        </div>

        <p v-if="server" class="text-sm">
            {{ t('Showing only') }}
            <span class="font-mono font-semibold text-primary">{{
                server
            }}</span
            >.
            <Link :href="index()" class="underline">{{
                t('Show everything')
            }}</Link>
        </p>

        <div class="overflow-x-auto rounded-md border border-border bg-card">
            <table class="w-full text-left">
                <thead class="bg-muted text-[0.8125rem] text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            {{ t('When') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            {{ t('Who') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            {{ t('What') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            {{ t('Server') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            {{ t('Details') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t border-divider"
                    >
                        <td
                            class="px-4 py-2 text-sm whitespace-nowrap text-muted-foreground tabular-nums"
                        >
                            {{
                                new Date(entry.at).toLocaleString(undefined, {
                                    dateStyle: 'medium',
                                    timeStyle: 'medium',
                                })
                            }}
                        </td>
                        <td class="px-4 py-2">{{ entry.actor }}</td>
                        <td class="px-4 py-2">
                            <span
                                class="inline-flex items-center gap-2"
                                :class="
                                    tones[entry.event]
                                        ? `font-semibold ${toneClasses[tones[entry.event]]}`
                                        : ''
                                "
                            >
                                <span
                                    aria-hidden="true"
                                    class="size-2 shrink-0 rounded-full"
                                    :class="
                                        tones[entry.event]
                                            ? 'bg-current'
                                            : 'border border-muted-foreground'
                                    "
                                />
                                {{ labels[entry.event] ?? entry.event }}
                            </span>
                            <Button
                                v-if="
                                    entry.event === 'dns.failed' &&
                                    entry.subject &&
                                    dnsRetry.includes(entry.subject)
                                "
                                type="button"
                                variant="outline"
                                size="sm"
                                class="ml-3 border-primary"
                                @click="
                                    router.post(
                                        dns.url({ server: entry.subject }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                "
                            >
                                {{ t('Try again') }}
                            </Button>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="entry.subject"
                                :href="
                                    index({ query: { server: entry.subject } })
                                "
                                class="font-mono text-sm underline"
                                >{{ entry.subject }}</Link
                            >
                        </td>
                        <td
                            class="px-4 py-2 text-sm break-all text-muted-foreground"
                        >
                            {{ details(entry) }}
                        </td>
                    </tr>
                    <tr v-if="!entries.length">
                        <td
                            colspan="5"
                            class="border-t border-divider px-4 py-3 text-sm text-muted-foreground"
                        >
                            {{ t('Nothing recorded yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="newerUrl || olderUrl" class="flex gap-2">
            <Button
                v-if="newerUrl"
                variant="outline"
                class="border-primary"
                as-child
            >
                <Link :href="newerUrl">{{ t('Newer') }}</Link>
            </Button>
            <Button
                v-if="olderUrl"
                variant="outline"
                class="border-primary"
                as-child
            >
                <Link :href="olderUrl">{{ t('Older') }}</Link>
            </Button>
        </div>
    </div>
</template>
