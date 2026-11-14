<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/activity';

type Entry = {
    id: number;
    actor: string;
    event: string;
    subject: string | null;
    properties: Record<string, unknown> | null;
    at: string;
};

defineProps<{
    entries: Entry[];
    server: string;
    newerUrl: string | null;
    olderUrl: string | null;
}>();

const labels: Record<string, string> = {
    'server.create': 'Created the server',
    'server.update': 'Changed the configuration',
    'server.delete': 'Deleted the server',
    'server.start': 'Started the server',
    'server.stop': 'Stopped the server',
    'server.restart': 'Restarted the server',
    'server.kill': 'Killed the server',
    'backup.create': 'Started a backup',
    'backup.restore': 'Restored a backup',
    'backup.delete': 'Deleted a backup',
    'backup.schedule': 'Changed the backup schedule',
    'world.import': 'Imported a world',
    'file.save': 'Saved a file',
    'file.folder': 'Created a folder',
    'file.rename': 'Renamed a file',
    'file.delete': 'Deleted a file',
    'user.create': 'Created a user',
    'user.update': 'Changed a user',
    'user.delete': 'Deleted a user',
    'token.create': 'Created an API token',
    'token.revoke': 'Revoked an API token',
    'settings.update': 'Changed the panel settings',
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
    <Head title="Activity" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                Activity
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                Who started, stopped or changed what, newest first. Console
                commands and file uploads go straight to the Rust service and
                are not listed.
            </p>
        </div>

        <p v-if="server" class="text-sm">
            Showing only
            <span class="font-mono font-semibold text-primary">{{
                server
            }}</span
            >.
            <Link :href="index()" class="underline">Show everything</Link>
        </p>

        <div class="overflow-x-auto rounded-md border border-border bg-card">
            <table class="w-full text-left">
                <thead class="bg-muted text-[0.8125rem] text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            When
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">Who</th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            What
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            Server
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-normal">
                            Details
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
                            {{ labels[entry.event] ?? entry.event }}
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
                            Nothing recorded yet.
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
                <Link :href="newerUrl">Newer</Link>
            </Button>
            <Button
                v-if="olderUrl"
                variant="outline"
                class="border-primary"
                as-child
            >
                <Link :href="olderUrl">Older</Link>
            </Button>
        </div>
    </div>
</template>
