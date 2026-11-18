<script setup lang="ts">
import { t } from '@/lib/i18n';
</script>

<template>
    <div>
        <p role="status" class="sr-only">{{ t('Loading…') }}</p>
        <ul class="flex flex-col" aria-hidden="true">
            <li
                v-for="n in 10"
                :key="n"
                class="flex animate-pulse items-center gap-3 border-t border-divider py-2 first:border-t-0 motion-reduce:animate-none"
            >
                <span class="size-9 shrink-0 rounded-sm bg-muted" />
                <span class="flex min-w-0 flex-1 flex-col gap-2">
                    <span class="h-4 w-2/5 rounded-sm bg-muted" />
                    <span class="h-3 w-3/4 rounded-sm bg-muted" />
                </span>
            </li>
        </ul>
    </div>
</template>
