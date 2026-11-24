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
    // The visitor's system, as the panel read it from the browser.
    platform: string;
    // For someone who manages the releases but cannot open Settings, where admins do it.
    canManage: boolean;
}>();

// Names of products, so not translated.
const systems: Record<string, string> = {
    'windows-x86_64': 'Windows',
    'linux-x86_64': 'Linux',
};
const system = (platform: string) => systems[platform] ?? platform;

const latest = computed(() => props.releases.find((release) => release.url));
// The visitor's own system first.
const latestFiles = computed(() =>
    [...(latest.value?.files ?? [])].sort(
        (a, b) =>
            Number(b.platform === props.platform) -
            Number(a.platform === props.platform),
    ),
);
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
        :description="t('The launcher, to play on the servers of this panel.')"
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
                        [
                            released(latest.released),
                            megabytes(latestFiles[0]?.size ?? null),
                        ]
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
            <Button
                v-for="(file, index) in latestFiles"
                :key="file.platform"
                as-child
                :size="index === 0 ? 'lg' : 'sm'"
                :variant="index === 0 ? 'default' : 'outline'"
            >
                <a :href="file.url ?? undefined">
                    <Download aria-hidden="true" />
                    {{
                        t('Download for :system', {
                            system: system(file.platform),
                        })
                    }}
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
                            {{ released(release.released) }}
                        </span>
                    </div>
                    <LauncherReleaseActions
                        v-if="canManage"
                        :version="release.version"
                        :downloadable="release.url !== null"
                    />
                    <template v-if="release.url">
                        <Button
                            v-for="file in release.files"
                            :key="file.platform"
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <a
                                :href="file.url ?? undefined"
                                :aria-label="
                                    t('Download version :version for :system', {
                                        version: release.version,
                                        system: system(file.platform),
                                    })
                                "
                                :title="megabytes(file.size)"
                            >
                                <Download aria-hidden="true" />
                                {{ system(file.platform) }}
                            </a>
                        </Button>
                    </template>
                    <span v-else class="text-sm text-muted-foreground">{{
                        t('Not downloadable')
                    }}</span>
                </li>
            </ul>
        </section>
    </PublicDocument>
    <AppDialog v-if="canManage" />
</template>
