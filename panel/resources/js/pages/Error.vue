<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BlockCube from '@/components/BlockCube.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

// The theme and name come as props: an address that matches no route never reaches the
// middleware that shares them with every other page.
const props = defineProps<{
    status: number;
    theme: 'default' | 'blocky';
    name: string;
}>();

const blocky = props.theme === 'blocky';

const plain: Record<number, [string, string]> = {
    403: [
        t('You cannot open this'),
        t(
            'Your account has no access to this page. An admin can share the server with you or give you more permissions on it.',
        ),
    ],
    404: [
        t('Page not found'),
        t(
            'This address does not exist, or the server it belonged to was deleted.',
        ),
    ],
    500: [
        t('Something went wrong'),
        t(
            "The panel hit an error. Try again; if it keeps happening, look in the panel's log.",
        ),
    ],
    503: [
        t('Down for maintenance'),
        t('The panel is being updated. Try again in a minute.'),
    ],
};

const themed: Record<number, [string, string]> = {
    403: [
        t('This area is protected'),
        t(
            'You are not allowed to build here. An admin can share the server with you or give you more permissions on it.',
        ),
    ],
    404: [
        t('This chunk hasn’t been generated yet'),
        t(
            'You walked off the edge of the world. The page you were looking for isn’t here: it may have moved, or the link has a typo.',
        ),
    ],
    500: [
        t('The panel tripped over a block'),
        t(
            "Something went wrong on our side. Try again; if it keeps happening, look in the panel's log.",
        ),
    ],
    503: [
        t('The world is being saved'),
        t('The panel is being updated. Try again in a minute.'),
    ],
};

const texts = blocky ? themed : plain;
const text = computed(() => texts[props.status] ?? texts[500]);

// The status code drawn in blocks, each digit three wide and five high.
const DIGITS: Record<string, string[]> = {
    '0': ['XXX', 'X.X', 'X.X', 'X.X', 'XXX'],
    '3': ['XXX', '..X', 'XXX', '..X', 'XXX'],
    '4': ['X.X', 'X.X', 'XXX', '..X', '..X'],
    '5': ['XXX', 'X..', 'XXX', '..X', 'XXX'],
};
const digits = String(props.status)
    .split('')
    .map((digit) => DIGITS[digit] ?? DIGITS['0']);
const rows = [0, 1, 2, 3, 4].map((row) =>
    digits.map((digit) => digit[row]).join('.'),
);
const columns = rows[0].length;

const CRACKS = [
    'none',
    'linear-gradient(45deg, transparent 44%, rgba(0,0,0,0.65) 44% 52%, transparent 52%)',
    'linear-gradient(45deg, transparent 44%, rgba(0,0,0,0.65) 44% 52%, transparent 52%), linear-gradient(-30deg, transparent 56%, rgba(0,0,0,0.6) 56% 63%, transparent 63%)',
    'linear-gradient(45deg, transparent 44%, rgba(0,0,0,0.7) 44% 52%, transparent 52%), linear-gradient(-30deg, transparent 56%, rgba(0,0,0,0.65) 56% 63%, transparent 63%), linear-gradient(100deg, transparent 30%, rgba(0,0,0,0.6) 30% 37%, transparent 37%)',
];
const HITS_TO_BREAK = 4;

// How often each block was hit; a block breaks on the fourth hit.
const hits = reactive<Record<number, number>>({});

const cells = computed(() =>
    rows.flatMap((row, r) =>
        row.split('').map((mark, c) => {
            const index = r * columns + c;
            const solid = mark === 'X';
            const count = hits[index] ?? 0;
            const broken = solid && count >= HITS_TO_BREAK;
            // Grass on top, stone for the middle digit and the bottom rows, dirt for the rest.
            const middle = c % 4 < 3 && Math.floor(c / 4) === 1;
            const texture =
                r === 0
                    ? 'mc-t-grass-side'
                    : middle || r >= 3
                      ? 'mc-t-stone'
                      : 'mc-t-dirt';

            return {
                index,
                solid,
                broken,
                texture,
                crack: broken ? 'none' : CRACKS[Math.min(count, 3)],
            };
        }),
    ),
);

const total = cells.value.filter((cell) => cell.solid).length;
const mined = computed(() => cells.value.filter((cell) => cell.broken).length);

const hit = (index: number) => {
    hits[index] = (hits[index] ?? 0) + 1;
};

const reset = () => {
    Object.keys(hits).forEach((key) => delete hits[Number(key)]);
};

const hint = computed(() => {
    if (mined.value === 0) {
        return t('Psst: you can mine the blocks. Click one a few times.');
    }

    return mined.value >= total
        ? t(
              'You mined the whole :status. Impressive. Still nothing here, though.',
              { status: props.status },
          )
        : t('Mined :count of :total blocks.', { count: mined.value, total });
});

const stars = [
    [6, 40, 0],
    [14, 180, 1],
    [23, 90, 2],
    [38, 30, 0.5],
    [47, 210, 1.6],
    [55, 70, 2.4],
    [68, 150, 0.8],
    [79, 40, 1.9],
    [88, 220, 0.3],
    [95, 110, 1.2],
    [31, 300, 2.1],
    [84, 330, 0.6],
];

const falling = [
    { texture: 'grass', left: 70, size: 30, seconds: 7, delay: -1 },
    { texture: 'sand', left: 82, size: 22, seconds: 9, delay: -4 },
    { texture: 'stone', left: 90, size: 26, seconds: 8, delay: -6 },
    { texture: 'dirt', left: 76, size: 18, seconds: 11, delay: -8 },
    { texture: 'wood', left: 94, size: 20, seconds: 10, delay: -2 },
    { texture: 'stone', left: 64, size: 16, seconds: 12, delay: -9 },
] as const;

const sparks = [
    ['#866043', '#4a3114', -30, -34, 0],
    ['#8b8b8b', '#3a3a3a', 32, -28, 0.05],
    ['#5c9e31', '#2c5a16', -26, 30, 0.1],
    ['#866043', '#4a3114', 28, 32, 0],
] as const;
</script>

<template>
    <Head :title="text[0]" />

    <main
        v-if="!blocky"
        class="flex min-h-svh flex-col items-center justify-center gap-5 bg-background p-6 text-center"
    >
        <AppLogoIcon class="size-10 fill-current text-primary" />
        <p class="font-mono text-sm text-muted-foreground">
            {{ t('Error :status', { status }) }}
        </p>
        <h1 class="text-2xl leading-[1.3] font-bold text-primary">
            {{ text[0] }}
        </h1>
        <p class="max-w-md text-[0.9375rem] text-muted-foreground">
            {{ text[1] }}
        </p>
        <Button as-child>
            <a href="/">{{ t('Back to the servers') }}</a>
        </Button>
    </main>

    <!-- The blocky theme: the status code built from blocks that can be mined, at the edge of the world. -->
    <div v-else class="mc-void">
        <header class="bg-header">
            <div
                class="mx-auto flex min-h-16 max-w-7xl items-center gap-x-6 px-6"
            >
                <a
                    href="/"
                    class="flex items-center gap-3 py-3 text-xl font-bold text-white"
                >
                    <AppLogoIcon class="size-[30px]" />
                    <span>{{ name }}</span>
                </a>
            </div>
        </header>

        <main class="relative flex-auto">
            <span
                v-for="[left, top, delay] in stars"
                :key="`${left}-${top}`"
                class="mc-star"
                :style="{
                    left: `${left}%`,
                    top: `${top}px`,
                    animationDelay: `${delay}s`,
                }"
            />
            <BlockCube
                v-for="block in falling"
                :key="block.left"
                class="mc-fall"
                :texture="block.texture"
                :size="block.size"
                :style="{
                    left: `${block.left}%`,
                    animationDuration: `${block.seconds}s`,
                    animationDelay: `${block.delay}s`,
                }"
            />
            <div class="mc-cliff" aria-hidden="true" />
            <BlockCube
                texture="wood"
                :size="40"
                class="mc-float absolute bottom-[210px] left-[30%]"
            />
            <BlockCube
                texture="grass"
                :size="40"
                class="absolute bottom-[210px] left-[8%]"
            />
            <BlockCube
                texture="stone"
                :size="40"
                class="absolute bottom-[210px] left-[calc(8%+40px)]"
            />

            <div
                class="relative z-10 mx-auto flex max-w-[820px] flex-col items-center gap-[18px] px-6 pt-14 pb-[260px] text-center"
            >
                <div
                    class="mc-digits"
                    aria-hidden="true"
                    :style="{
                        gridTemplateColumns: `repeat(${columns}, var(--cell))`,
                    }"
                >
                    <div
                        v-for="cell in cells"
                        :key="cell.index"
                        :class="{ 'mc-cell': cell.solid && !cell.broken }"
                        :style="{
                            visibility: cell.solid ? 'visible' : 'hidden',
                        }"
                        @click="cell.solid && !cell.broken && hit(cell.index)"
                    >
                        <div
                            class="mc-cell-face"
                            :class="cell.texture"
                            :style="{ opacity: cell.broken ? 0 : 1 }"
                        />
                        <div
                            class="absolute inset-0"
                            :style="{ backgroundImage: cell.crack }"
                        />
                        <template v-if="cell.broken">
                            <span
                                v-for="[fill, edge, dx, dy, delay] in sparks"
                                :key="`${dx}${dy}`"
                                class="mc-spark"
                                :style="{
                                    background: fill,
                                    outlineColor: edge,
                                    '--dx': `${dx}px`,
                                    '--dy': `${dy}px`,
                                    animationDelay: `${delay}s`,
                                }"
                            />
                        </template>
                    </div>
                </div>

                <p class="mt-1.5 text-base tracking-[0.06em] text-[#b9b0c9]">
                    {{ t('ERROR :status', { status }) }}
                </p>
                <h1
                    class="text-[2.25rem] leading-[1.15] text-white [text-shadow:4px_4px_0_#000] sm:text-[2.75rem]"
                >
                    {{ text[0] }}
                </h1>
                <p class="max-w-[560px] text-[1.1875rem] text-[#d9d2e6]">
                    {{ text[1] }}
                </p>
                <div class="mt-1.5 flex flex-wrap justify-center gap-2.5">
                    <Button as-child size="lg" class="h-[52px] px-5 text-xl">
                        <a href="/">
                            <AppLogoIcon class="size-[26px]" />
                            {{ t('Back to spawn') }}
                        </a>
                    </Button>
                    <Button
                        v-if="mined > 0"
                        type="button"
                        variant="outline"
                        size="lg"
                        class="h-[52px] px-4 text-lg"
                        @click="reset"
                    >
                        {{ t('Put the blocks back') }}
                    </Button>
                </div>
                <p class="mc-hint mt-1 text-base text-[#b9b0c9]" role="status">
                    {{ hint }}
                </p>
            </div>
        </main>
    </div>
</template>
