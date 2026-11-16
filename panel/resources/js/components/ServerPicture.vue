<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { Server } from '@/lib/servers';
import { destroy, update } from '@/routes/servers/icon';

const props = defineProps<{ server: Server }>();

const picker = ref<HTMLInputElement>();
const busy = ref(false);

const visit = {
    preserveScroll: true,
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
    onError: (errors: Record<string, string>) =>
        toast.error(Object.values(errors)[0]),
};

// Minecraft only shows a PNG of exactly 64 by 64, so whatever is chosen is cut to a square
// from its middle and scaled here, before it is sent.
const choose = async () => {
    const file = picker.value?.files?.[0];

    if (!file) {
        return;
    }

    try {
        const image = await createImageBitmap(file);
        const side = Math.min(image.width, image.height);
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 64;
        canvas
            .getContext('2d')!
            .drawImage(
                image,
                (image.width - side) / 2,
                (image.height - side) / 2,
                side,
                side,
                0,
                0,
                64,
                64,
            );

        router.put(
            update.url({ server: props.server.slug }),
            { icon: canvas.toDataURL('image/png') },
            visit,
        );
    } catch {
        toast.error(t('That file could not be read as an image.'));
    } finally {
        picker.value!.value = '';
    }
};
</script>

<template>
    <SectionCard :title="t('Picture')">
        <div class="flex items-start gap-3">
            <img
                v-if="server.picture"
                :src="server.picture"
                alt=""
                width="64"
                height="64"
                class="size-16 shrink-0 border border-border [image-rendering:pixelated]"
            />
            <p class="min-w-0 flex-1 text-sm text-muted-foreground">
                {{
                    t(
                        'Shown to players in their server list, and here in the panel. Any image works; it is cut to a square and scaled to 64 by 64. Players see a new picture after the next restart.',
                    )
                }}
                <template v-if="server.modpack && !server.has_own_picture">
                    {{ t('Without one, the picture of the modpack is used.') }}
                </template>
            </p>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <input
                ref="picker"
                type="file"
                accept="image/*"
                class="sr-only"
                :aria-label="t('Picture file')"
                tabindex="-1"
                @change="choose"
            />
            <Button
                type="button"
                variant="outline"
                class="border-primary"
                :disabled="busy"
                @click="picker?.click()"
            >
                {{
                    server.has_own_picture
                        ? t('Choose another picture')
                        : t('Choose a picture')
                }}
            </Button>
            <Button
                v-if="server.has_own_picture"
                type="button"
                variant="ghost"
                :disabled="busy"
                @click="
                    router.delete(destroy.url({ server: server.slug }), visit)
                "
            >
                {{ t('Remove picture') }}
            </Button>
        </div>
    </SectionCard>
</template>
