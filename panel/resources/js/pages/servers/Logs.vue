<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ServerHeader from '@/components/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { Server, States } from '@/lib/servers';
import { logs } from '@/routes/servers';
import { download } from '@/routes/servers/logs';

const props = defineProps<{
    server: Server;
    states?: States;
    files: { name: string; size: number; modified: number }[];
    file: string | null;
    q: string;
    content: string;
    error: string | null;
}>();

const lineCount = computed(
    () => props.content.split('\n').filter((line) => line !== '').length,
);

const kilobytes = (bytes: number) =>
    `${Math.max(1, Math.round(bytes / 1024))} KB`;
</script>

<template>
    <Head :title="t(':name logs', { name: server.name })" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="logs" />

        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-destructive bg-card px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <p
            v-else-if="!files.length"
            class="rounded-md border border-border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            {{
                t('No log files yet. They appear once the server has started.')
            }}
        </p>

        <template v-else>
            <Form
                :action="logs.url({ server: server.slug })"
                method="get"
                :options="{ preserveState: true, preserveScroll: true }"
                class="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-4 py-3"
                v-slot="{ processing }"
            >
                <label for="file" class="sr-only">{{ t('Log file') }}</label>
                <select
                    id="file"
                    name="file"
                    class="h-9 flex-[2_1_220px] rounded-md border border-input bg-card px-2.5 text-sm"
                >
                    <option
                        v-for="entry in files"
                        :key="entry.name"
                        :value="entry.name"
                        :selected="entry.name === file"
                    >
                        {{ entry.name }} ·
                        {{ new Date(entry.modified * 1000).toLocaleString() }}
                        · {{ kilobytes(entry.size) }}
                    </option>
                </select>
                <label for="q" class="sr-only">{{
                    t('Search in this log')
                }}</label>
                <Input
                    id="q"
                    name="q"
                    type="search"
                    maxlength="200"
                    :placeholder="t('Search in this log')"
                    :default-value="q"
                    class="flex-[3_1_220px]"
                />
                <Button :disabled="processing">{{ t('Show') }}</Button>
                <Button
                    v-if="file"
                    variant="outline"
                    class="border-primary"
                    as-child
                >
                    <a
                        :href="
                            download.url(
                                { server: server.slug },
                                { query: { file } },
                            )
                        "
                        >{{ t('Download') }}</a
                    >
                </Button>
            </Form>

            <section
                :aria-label="t('Log contents')"
                class="overflow-hidden rounded-md bg-header text-white"
            >
                <div
                    class="border-b border-white/10 px-3.5 py-2.5 text-[0.8125rem] text-white/85"
                    role="status"
                >
                    {{ file }} ·
                    {{
                        q
                            ? t(':count lines contain “:query”', {
                                  count: lineCount,
                                  query: q,
                              })
                            : t(':count lines', { count: lineCount })
                    }}
                </div>
                <pre
                    tabindex="0"
                    class="max-h-[640px] overflow-auto p-3.5 font-mono text-[0.8125rem] leading-[1.65] break-words whitespace-pre-wrap text-[#d7dae3]"
                    >{{ content || t('Nothing to show.') }}</pre>
            </section>
        </template>
    </div>
</template>
