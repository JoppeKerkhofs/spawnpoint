<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        texture?:
            | 'grass'
            | 'wood'
            | 'machine'
            | 'sand'
            | 'wool'
            | 'pumpkin'
            | 'stone'
            | 'dirt';
        size?: number;
        spin?: boolean;
        // Greyed out, for a server that is not running.
        dim?: boolean;
    }>(),
    { texture: 'grass', size: 26, spin: false, dim: false },
);

// Top, front, the other three sides, bottom.
const textures = {
    grass: [
        'mc-t-grass-top',
        'mc-t-grass-side',
        'mc-t-grass-side',
        'mc-t-dirt',
    ],
    wood: [
        'mc-t-wood-top',
        'mc-t-wood-side',
        'mc-t-wood-side',
        'mc-t-wood-top',
    ],
    machine: ['mc-t-metal', 'mc-t-core', 'mc-t-metal', 'mc-t-metal'],
    sand: ['mc-t-sand', 'mc-t-sand', 'mc-t-sand', 'mc-t-sand'],
    wool: ['mc-t-wool', 'mc-t-wool', 'mc-t-wool', 'mc-t-wool'],
    stone: ['mc-t-stone', 'mc-t-stone', 'mc-t-stone', 'mc-t-stone'],
    dirt: ['mc-t-dirt', 'mc-t-dirt', 'mc-t-dirt', 'mc-t-dirt'],
    pumpkin: [
        'mc-t-pumpkin-top',
        'mc-t-pumpkin-face',
        'mc-t-pumpkin',
        'mc-t-pumpkin-top',
    ],
};

const faces = computed(() => textures[props.texture]);
</script>

<template>
    <span
        class="mc-cube-wrap"
        aria-hidden="true"
        :style="{
            '--s': `${size}px`,
            filter: dim ? 'grayscale(0.75) brightness(0.85)' : undefined,
        }"
    >
        <span class="mc-cube" :class="{ 'mc-spin': spin }">
            <span class="mc-face mc-f-top" :class="faces[0]" />
            <span class="mc-face mc-f-front" :class="faces[1]" />
            <span class="mc-face mc-f-right" :class="faces[2]" />
            <span class="mc-face mc-f-back" :class="faces[2]" />
            <span class="mc-face mc-f-left" :class="faces[2]" />
            <span class="mc-face mc-f-bottom" :class="faces[3]" />
        </span>
    </span>
</template>
