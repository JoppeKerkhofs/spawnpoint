<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/appearance';
import { update as updateLocale } from '@/routes/locale';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();

// A full reload, so every text on the page is rendered again in the new language.
const changeLanguage = (event: Event) => {
    router.put(
        updateLocale.url(),
        { locale: (event.target as HTMLSelectElement).value },
        { onSuccess: () => window.location.reload() },
    );
};
</script>

<template>
    <Head :title="t('Appearance settings')" />

    <h1 class="sr-only">{{ t('Appearance settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Appearance settings')"
            :description="t('Update the appearance settings for your account')"
        />
        <AppearanceTabs v-if="page.props.theme === 'default'" />

        <div class="grid max-w-xs gap-2">
            <Label for="locale">{{ t('Language') }}</Label>
            <select
                id="locale"
                class="h-9 rounded-md border border-input bg-card px-2.5 text-sm"
                :disabled="Object.keys(page.props.languages).length < 2"
                aria-describedby="locale_hint"
                @change="changeLanguage"
            >
                <option
                    v-for="(name, code) in page.props.languages"
                    :key="code"
                    :value="code"
                    :selected="code === page.props.locale"
                >
                    {{ name }}
                </option>
            </select>
            <span
                id="locale_hint"
                class="text-[0.8125rem] text-muted-foreground"
            >
                {{
                    Object.keys(page.props.languages).length < 2
                        ? t('Only English is installed.')
                        : t('The language of the panel for your account.')
                }}
            </span>
        </div>
    </div>
</template>
