<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ value: string }>();

// server.properties holds a line break as the two characters \n, and that is what is saved.
const text = ref(props.value.replaceAll('\\n', '\n'));
const field = ref<HTMLTextAreaElement>();

// The server list shows two lines.
watch(text, (value) => {
    text.value = value.split('\n').slice(0, 2).join('\n');
});

const colors = [
    ['0', '#000000', t('Black')],
    ['1', '#0000AA', t('Dark blue')],
    ['2', '#00AA00', t('Dark green')],
    ['3', '#00AAAA', t('Dark aqua')],
    ['4', '#AA0000', t('Dark red')],
    ['5', '#AA00AA', t('Dark purple')],
    ['6', '#FFAA00', t('Gold')],
    ['7', '#AAAAAA', t('Gray')],
    ['8', '#555555', t('Dark gray')],
    ['9', '#5555FF', t('Blue')],
    ['a', '#55FF55', t('Green')],
    ['b', '#55FFFF', t('Aqua')],
    ['c', '#FF5555', t('Red')],
    ['d', '#FF55FF', t('Light purple')],
    ['e', '#FFFF55', t('Yellow')],
    ['f', '#FFFFFF', t('White')],
];
const styles = [
    ['l', 'B', t('Bold'), 'font-bold'],
    ['o', 'I', t('Italic'), 'italic'],
    ['n', 'U', t('Underlined'), 'underline'],
    ['m', 'S', t('Struck through'), 'line-through'],
    ['k', '▒', t('Scrambled'), ''],
    ['r', '↺', t('Back to plain text'), ''],
];
const symbols = [...'★☆❤☠⚔⛏☀☁⚡❄✔✖➜♪♦●▶◀«»✦☘⚑'];
const smallLetters = 'ᴀʙᴄᴅᴇꜰɢʜɪᴊᴋʟᴍɴᴏᴘǫʀꜱᴛᴜᴠᴡxʏᴢ';

function insert(piece: string) {
    const area = field.value!;
    area.setRangeText(piece, area.selectionStart, area.selectionEnd, 'end');
    text.value = area.value;
    area.focus();
}

// Rewrites the selection, or everything without one; the letter after a § is a code and stays.
function smallCaps() {
    const area = field.value!;

    if (area.selectionStart === area.selectionEnd) {
        area.select();
    }

    insert(
        area.value
            .slice(area.selectionStart, area.selectionEnd)
            .replace(
                /(§.)|[a-z]/gi,
                (letter, code) =>
                    code ??
                    smallLetters[letter.toLowerCase().charCodeAt(0) - 97],
            ),
    );
}

// As the game reads it: a colour or §r ends the styles before it, and each line starts plain.
const preview = computed(() =>
    text.value.split('\n').map((line) => {
        let color = '#AAAAAA';
        let on = '';

        return line.split(/(§.)/).flatMap((part) => {
            const code = /^§.$/.test(part) ? part[1].toLowerCase() : null;
            const found = colors.find(([key]) => key === code);

            if (found || code === 'r') {
                color = found?.[1] ?? '#AAAAAA';
                on = '';
            } else if (code && 'klmno'.includes(code)) {
                on += code;
            } else if (part) {
                return [
                    {
                        text: part,
                        style: {
                            color,
                            fontWeight: on.includes('l') ? 'bold' : 'normal',
                            fontStyle: on.includes('o') ? 'italic' : 'normal',
                            textDecoration:
                                [
                                    on.includes('n') ? 'underline' : '',
                                    on.includes('m') ? 'line-through' : '',
                                ]
                                    .join(' ')
                                    .trim() || 'none',
                            filter: on.includes('k') ? 'blur(3px)' : 'none',
                        },
                    },
                ];
            }

            return [];
        });
    }),
);

const tool =
    'flex size-7 cursor-pointer items-center justify-center rounded-sm border border-input bg-card text-sm hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
</script>

<template>
    <div class="grid gap-2">
        <div class="flex flex-wrap gap-1" role="group" :aria-label="t('Color')">
            <button
                v-for="[code, hex, name] in colors"
                :key="code"
                type="button"
                :class="tool"
                :style="{ backgroundColor: hex }"
                :title="name"
                :aria-label="name"
                @click="insert('§' + code)"
            />
        </div>

        <div class="flex flex-wrap gap-1" role="group" :aria-label="t('Style')">
            <button
                v-for="[code, sign, name, look] in styles"
                :key="code"
                type="button"
                :class="[tool, look]"
                :title="name"
                :aria-label="name"
                @click="insert('§' + code)"
            >
                {{ sign }}
            </button>
            <button
                type="button"
                :class="[tool, 'w-auto px-2']"
                :title="t('Small capitals')"
                :aria-label="t('Small capitals')"
                @click="smallCaps"
            >
                ꜱᴍᴀʟʟ
            </button>
            <span class="mx-1 w-px bg-border" />
            <button
                v-for="symbol in symbols"
                :key="symbol"
                type="button"
                :class="tool"
                :aria-label="t('Insert :symbol', { symbol })"
                @click="insert(symbol)"
            >
                {{ symbol }}
            </button>
        </div>

        <textarea
            id="motd"
            ref="field"
            v-model="text"
            rows="2"
            maxlength="250"
            spellcheck="false"
            aria-describedby="motd_hint"
            class="w-full resize-none rounded-md border border-input bg-card px-3 py-2 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        />
        <input
            type="hidden"
            name="motd"
            :value="text.replaceAll('\n', '\\n')"
        />

        <div
            class="min-h-16 overflow-hidden rounded-md bg-[#1b1b1b] px-3 py-2 text-base leading-6 whitespace-pre"
            style="font-family: 'Minecraft', monospace"
            aria-hidden="true"
        >
            <div v-for="(line, index) in preview" :key="index" class="h-6">
                <span
                    v-for="(piece, at) in line"
                    :key="at"
                    :style="piece.style"
                    >{{ piece.text }}</span
                >
            </div>
        </div>
        <span id="motd_hint" class="text-[0.8125rem] text-muted-foreground">
            {{
                t(
                    'Two lines at most. A button puts a code such as §a where the cursor is; it applies to the text after it, and a color ends the styles before it.',
                )
            }}
        </span>
    </div>
</template>
