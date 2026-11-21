<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import AppDialog from '@/components/AppDialog.vue';
import LauncherReleaseActions from '@/components/LauncherReleaseActions.vue';
import PublicDocument from '@/components/PublicDocument.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { LauncherRelease } from '@/types';

const props = defineProps<{
    releases: LauncherRelease[];
    // For someone who manages the releases but cannot open Settings, where admins do it.
    canManage: boolean;
}>();

const latest = computed(() => props.releases.find((release) => release.url));
const previous = computed(() =>
    props.releases.filter((release) => release !== latest.value),
);

const released = (at: string | null) =>
    at
        ? new Date(at).toLocaleDateString(undefined, { dateStyle: 'medium' })
        : '';

const megabytes = (size: number | null) =>
    size ? t(':size MB', { size: (size / 1048576).toFixed(1) }) : '';
</script>

<template>
    <PublicDocument
        :title="t('Download the launcher')"
        :description="
            t('The launcher for Windows, to play on the servers of this panel.')
        "
        :sections="[]"
    >
        <p v-if="!releases.length" class="text-muted-foreground">
            {{ t('No version has been published yet.') }}
        </p>

        <section
            v-if="latest"
            class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-md border border-border bg-card px-4 py-4"
        >
            <div class="min-w-0 flex-1">
                <h2 class="font-bold text-primary tabular-nums">
                    {{
                        t('Newest version: :version', {
                            version: latest.version,
                        })
                    }}
                </h2>
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{
                        [released(latest.released), megabytes(latest.size)]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
            </div>
            <LauncherReleaseActions
                v-if="canManage"
                :version="latest.version"
                downloadable
            />
            <Button as-child size="lg">
                <a :href="latest.url ?? undefined">
                    <Download aria-hidden="true" />
                    {{ t('Download now') }}
                </a>
            </Button>
        </section>

        <section
            v-if="previous.length"
            class="overflow-hidden rounded-md border border-border bg-card"
        >
            <h2 class="bg-muted px-4 pt-3 pb-2 font-bold text-primary">
                {{ t('Previous versions') }}
            </h2>
            <ul>
                <li
                    v-for="release in previous"
                    :key="release.version"
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-border px-4 py-2.5"
                >
                    <div class="min-w-0 flex-1 tabular-nums">
                        <span class="font-semibold">{{ release.version }}</span>
                        <span class="ml-3 text-sm text-muted-foreground">
                            {{
                                [
                                    released(release.released),
                                    megabytes(release.size),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </span>
                    </div>
                    <LauncherReleaseActions
                        v-if="canManage"
                        :version="release.version"
                        :downloadable="release.url !== null"
                    />
                    <Button
                        v-if="release.url"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <a
                            :href="release.url"
                            :aria-label="
                                t('Download version :version', {
                                    version: release.version,
                                })
                            "
                        >
                            <Download aria-hidden="true" />
                            {{ t('Download') }}
                        </a>
                    </Button>
                    <span v-else class="text-sm text-muted-foreground">{{
                        t('Not downloadable')
                    }}</span>
                </li>
            </ul>
        </section>
    </PublicDocument>
    <AppDialog v-if="canManage" />
</template>
