<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import AppDialog from '@/components/AppDialog.vue';
import PublicDocument from '@/components/PublicDocument.vue';
import { Button } from '@/components/ui/button';
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { destroy, update } from '@/routes/portal/versions';

type Release = {
    version: string;
    released: string | null;
    size: number | null;
    // Null when the version is listed but may not be downloaded.
    url: string | null;
};

defineProps<{ releases: Release[]; canManage: boolean }>();

const released = (at: string | null) =>
    at
        ? new Date(at).toLocaleDateString(undefined, { dateStyle: 'medium' })
        : '';

const megabytes = (size: number | null) =>
    size ? t(':size MB', { size: (size / 1048576).toFixed(1) }) : '';

const mark = (release: Release, downloadable: boolean) =>
    router.put(
        update.url({ version: release.version }),
        { downloadable },
        { preserveScroll: true },
    );

const remove = async (release: Release) => {
    if (
        await ask(
            'Remove version :version? Its installer is deleted and cannot be brought back.',
            { version: release.version },
        )
    ) {
        router.delete(destroy.url({ version: release.version }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <PublicDocument
        :title="t('Launcher versions')"
        :description="
            t('Every version of the launcher for Windows, newest first.')
        "
        :sections="[]"
    >
        <p v-if="!releases.length" class="text-muted-foreground">
            {{ t('No version has been published yet.') }}
        </p>
        <div
            v-else
            class="overflow-x-auto rounded-md border border-border bg-card"
        >
            <table class="w-full text-left text-[0.9375rem]">
                <thead class="bg-muted text-primary">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">
                            {{ t('Version') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5">
                            {{ t('Released') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5">
                            {{ t('Size') }}
                        </th>
                        <th scope="col" class="px-4 py-2.5">
                            {{ t('Download') }}
                        </th>
                        <th v-if="canManage" scope="col" class="px-4 py-2.5">
                            {{ t('Manage') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(release, index) in releases"
                        :key="release.version"
                        class="border-t border-border"
                    >
                        <th
                            scope="row"
                            class="px-4 py-2.5 font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ release.version }}
                            <span
                                v-if="
                                    release.url &&
                                    releases.findIndex((r) => r.url) === index
                                "
                                class="ml-1.5 rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground"
                                >{{ t('Newest') }}</span
                            >
                        </th>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            {{ released(release.released) }}
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">
                            {{ megabytes(release.size) }}
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            <a
                                v-if="release.url"
                                :href="release.url"
                                :aria-label="
                                    t('Download version :version', {
                                        version: release.version,
                                    })
                                "
                                class="inline-flex items-center gap-1.5 text-primary underline"
                            >
                                <Download aria-hidden="true" class="size-4" />
                                {{ t('Download') }}
                            </a>
                            <span v-else class="text-muted-foreground">{{
                                t('Not downloadable')
                            }}</span>
                        </td>
                        <td v-if="canManage" class="px-4 py-2.5">
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :aria-label="
                                        release.url
                                            ? t(
                                                  'Make version :version not downloadable',
                                                  { version: release.version },
                                              )
                                            : t(
                                                  'Make version :version downloadable',
                                                  { version: release.version },
                                              )
                                    "
                                    @click="mark(release, !release.url)"
                                >
                                    {{
                                        release.url
                                            ? t('Make not downloadable')
                                            : t('Make downloadable')
                                    }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="border-destructive text-destructive hover:text-destructive"
                                    :aria-label="
                                        t('Remove version :version', {
                                            version: release.version,
                                        })
                                    "
                                    @click="remove(release)"
                                >
                                    {{ t('Remove') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="canManage" #footer>
            {{
                t(
                    'The launcher updates itself to the newest downloadable version. A version that is not downloadable stays listed here without a link.',
                )
            }}
        </template>
    </PublicDocument>
    <AppDialog v-if="canManage" />
</template>
