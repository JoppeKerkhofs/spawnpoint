<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { t } from '@/lib/i18n';

export type DocumentSection = {
    title: string;
    intro?: string;
    points: string[];
};

defineProps<{
    title: string;
    description: string;
    sections: DocumentSection[];
}>();

const page = usePage();
const name = page.props.name;
const signedIn = computed(() => page.props.auth?.user != null);
</script>

<template>
    <Head :title="title" />

    <div class="min-h-svh bg-background text-foreground">
        <header class="border-b border-header-border bg-header text-white">
            <div
                class="mx-auto flex min-h-14 max-w-3xl flex-wrap items-center gap-x-6 px-6"
            >
                <a href="/" class="flex items-center gap-2.5 py-3 font-bold">
                    <AppLogoIcon class="size-[22px]" />
                    <span>{{ name }}</span>
                </a>
                <span class="flex-1" />
                <a
                    href="/"
                    class="py-3 text-[0.9375rem] text-white/85 underline"
                >
                    {{ signedIn ? t('Back to the servers') : t('Log in') }}
                </a>
            </div>
        </header>

        <main class="mx-auto flex max-w-3xl flex-col gap-6 px-6 pt-8 pb-16">
            <div>
                <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                    {{ title }}
                </h1>
                <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                    {{ description }}
                </p>
            </div>

            <slot />

            <section
                v-for="section in sections"
                :key="section.title"
                class="overflow-hidden rounded-md border border-border bg-card"
            >
                <h2 class="bg-muted px-4 pt-3 pb-2 font-bold text-primary">
                    {{ section.title }}
                </h2>
                <div class="flex flex-col gap-2.5 px-4 py-4">
                    <p v-if="section.intro">{{ section.intro }}</p>
                    <ul class="flex list-disc flex-col gap-1.5 pl-5">
                        <li v-for="point in section.points" :key="point">
                            {{ point }}
                        </li>
                    </ul>
                </div>
            </section>

            <p class="text-sm text-muted-foreground">
                <slot name="footer" />
            </p>
        </main>
    </div>
</template>
