<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { destroy, update } from '@/routes/portal/versions';

const props = defineProps<{ version: string; downloadable: boolean }>();

// The state is kept so Settings stays on its Launcher section.
const options = { preserveScroll: true, preserveState: true };

const mark = () =>
    router.put(
        update.url({ version: props.version }),
        { downloadable: !props.downloadable },
        options,
    );

const remove = async () => {
    if (
        await ask(
            'Remove version :version? Its installer is deleted and cannot be brought back.',
            { version: props.version },
        )
    ) {
        router.delete(destroy.url({ version: props.version }), options);
    }
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            :aria-label="
                downloadable
                    ? t('Make version :version not downloadable', { version })
                    : t('Make version :version downloadable', { version })
            "
            @click="mark"
        >
            {{
                downloadable
                    ? t('Make not downloadable')
                    : t('Make downloadable')
            }}
        </Button>
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="border-destructive text-destructive hover:text-destructive"
            :aria-label="t('Remove version :version', { version })"
            @click="remove"
        >
            {{ t('Remove') }}
        </Button>
    </div>
</template>
