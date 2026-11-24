<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { apiDocs } from '@/routes';
import { create, destroy, index } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    last_used: string | null;
    created: string;
};

defineProps<{
    tokens: Token[];
    newToken: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API tokens',
                href: index(),
            },
        ],
    },
});

const revoke = async (token: Token) => {
    if (
        await ask('Revoke the token ":name"? Apps using it stop working.', {
            name: token.name,
        })
    ) {
        router.delete(destroy.url({ token: token.id }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head :title="t('API tokens')" />

    <h1 class="sr-only">{{ t('API tokens') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('API tokens')"
            :description="
                t(
                    'Let another app, such as a phone app, use the panel through :path. A token can never do more than your own role allows.',
                    { path: '/api/v1' },
                )
            "
        />

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <Button as-child>
                <Link :href="create()">
                    <Plus aria-hidden="true" />
                    {{ t('New token') }}
                </Link>
            </Button>
            <Link :href="apiDocs()" class="text-sm text-primary underline">{{
                t('Read the API documentation')
            }}</Link>
        </div>

        <div
            v-if="newToken"
            role="status"
            class="space-y-2 rounded-md border border-brand bg-card p-4"
        >
            <p class="font-medium">
                {{ t('Copy this token now. It is not shown again.') }}
            </p>
            <code class="block font-mono text-sm break-all">{{
                newToken
            }}</code>
        </div>

        <div class="overflow-hidden rounded-lg border border-border">
            <p v-if="!tokens.length" class="p-4 text-sm text-muted-foreground">
                {{ t('No tokens yet.') }}
            </p>
            <div
                v-for="token in tokens"
                :key="token.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border p-4 first:border-t-0"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ token.abilities.join(', ') }} ·
                        {{ t('created :date', { date: token.created }) }} ·
                        {{
                            token.last_used
                                ? t('last used :date', {
                                      date: token.last_used,
                                  })
                                : t('never used')
                        }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="revoke(token)"
                >
                    {{ t('Revoke') }}
                </Button>
            </div>
        </div>
    </div>
</template>
