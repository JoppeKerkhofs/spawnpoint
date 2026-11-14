<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerHeader from '@/components/ServerHeader.vue';
import Sparkline from '@/components/Sparkline.vue';
import type { Players, Server, States, Usage } from '@/lib/servers';
import { bytesLabel } from '@/lib/servers';

type Line = { id: number; text: string; kind: 'log' | 'command' | 'reply' };

const props = defineProps<{
    server: Server;
    states?: States;
    console: { url: string };
    bluemapUrl: string | null;
}>();

const canSend = computed(() => props.server.can.includes('commands'));

const ANSI_CODES = new RegExp(
    `${String.fromCharCode(27)}\\[[0-9;?]*[A-Za-z]`,
    'g',
);
const MAX_LINES = 2000;
// Ten minutes at one point every ten seconds, the rate the service samples at.
const MAX_SAMPLES = 60;
const SAMPLE_SECONDS = 10;

const lines = ref<Line[]>([]);
const samples = ref<Usage[]>([]);
// The number next to each graph follows the live stream; the graph itself keeps the slower pace.
const live = ref<Usage | null>(null);
const players = ref<Players | null>(null);
const connected = ref(false);
const paused = ref(false);
const command = ref('');
const output = ref<HTMLElement>();

const history: string[] = [];
let historyIndex = 0;
let socket: WebSocket | undefined;
let reconnectTimer: ReturnType<typeof setTimeout> | undefined;
let partialLine = '';
let nextId = 0;
let closing = false;

const latest = computed(() => live.value ?? samples.value.at(-1));
const gigabytes = (bytes: number) => (bytes / 1024 ** 3).toFixed(1);

const append = (text: string, kind: Line['kind']) => {
    // Colour codes are dropped; the level decides the colour here.
    const clean = text.replace(ANSI_CODES, '').replaceAll('\r', '');

    for (const line of clean.split('\n')) {
        lines.value.push({ id: nextId++, text: line, kind });
    }

    if (lines.value.length > MAX_LINES) {
        lines.value.splice(0, lines.value.length - MAX_LINES);
    }

    if (!paused.value) {
        void nextTick(() => {
            output.value?.scrollTo({ top: output.value.scrollHeight });
        });
    }
};

const receive = (event: MessageEvent<string>) => {
    const frame = JSON.parse(event.data);

    if (frame.t === 'players') {
        players.value = frame.d;
    } else if (frame.t === 'history') {
        samples.value = frame.d;
    } else if (frame.t === 'stats') {
        live.value = frame;

        if (frame.at - (samples.value.at(-1)?.at ?? 0) >= SAMPLE_SECONDS) {
            samples.value.push(frame);
            samples.value.splice(0, samples.value.length - MAX_SAMPLES);
        }
    } else if (frame.t === 'log') {
        // A chunk can end in the middle of a line; keep the rest for the next one.
        const parts = (partialLine + frame.d).split('\n');
        partialLine = parts.pop() ?? '';

        if (parts.length) {
            append(parts.join('\n'), 'log');
        }
    } else if (frame.d.trim()) {
        append(frame.d.trimEnd(), 'reply');
    }
};

const connect = () => {
    socket = new WebSocket(props.console.url);
    socket.onopen = () => (connected.value = true);
    socket.onmessage = receive;
    socket.onclose = () => {
        connected.value = false;

        if (!closing) {
            reconnectTimer = setTimeout(reconnect, 3000);
        }
    };
};

// A token lasts a minute, so every reconnect asks the panel for a new one.
const reconnect = () => {
    router.reload({
        only: ['console'],
        onSuccess: () => {
            // The service sends the last 200 lines again.
            lines.value = [];
            partialLine = '';
            connect();
        },
        onError: () => (reconnectTimer = setTimeout(reconnect, 3000)),
    });
};

const send = () => {
    const text = command.value.trim();

    if (!text || socket?.readyState !== WebSocket.OPEN) {
        return;
    }

    socket.send(text);
    append(`> ${text}`, 'command');
    history.push(text);
    historyIndex = history.length;
    command.value = '';
};

const recall = (step: number) => {
    historyIndex = Math.min(Math.max(historyIndex + step, 0), history.length);
    command.value = history[historyIndex] ?? '';
};

const lineClass = (line: Line) => {
    if (line.kind === 'command') {
        return 'text-white';
    }

    if (line.kind === 'reply') {
        return 'text-[#a7b1ba]';
    }

    if (/\b(ERROR|FATAL|SEVERE)\b/.test(line.text)) {
        return 'text-[#ff9a9a]';
    }

    if (/\bWARN/.test(line.text)) {
        return 'text-[#f0c36b]';
    }

    return /(joined|left) the game/.test(line.text) ? 'text-[#8fdcaa]' : '';
};

onMounted(connect);

onBeforeUnmount(() => {
    closing = true;
    clearTimeout(reconnectTimer);
    socket?.close();
});
</script>

<template>
    <Head :title="`${server.name} console`" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <ServerHeader :server="server" :states="states" tab="console" />

        <div class="flex flex-wrap items-start gap-5">
            <section
                aria-label="Live console"
                class="flex min-w-0 flex-[999_1_560px] flex-col overflow-hidden rounded-md bg-header text-white"
            >
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-white/10 px-3.5 py-2.5 text-[0.8125rem] text-white/85"
                >
                    <span
                        aria-hidden="true"
                        class="size-2 rounded-full"
                        :class="connected ? 'bg-brand-bright' : 'bg-[#ff9a9a]'"
                    />
                    <span role="status">
                        {{
                            connected
                                ? "Live · connected to the container's log stream"
                                : 'Not connected · trying again every few seconds'
                        }}
                    </span>
                    <span class="flex-1" />
                    <button
                        type="button"
                        class="h-[30px] cursor-pointer rounded-md border border-white/30 px-2.5"
                        :aria-pressed="paused"
                        @click="paused = !paused"
                    >
                        {{ paused ? 'Resume scroll' : 'Pause scroll' }}
                    </button>
                    <button
                        type="button"
                        class="h-[30px] cursor-pointer rounded-md border border-white/30 px-2.5"
                        @click="lines = []"
                    >
                        Clear view
                    </button>
                </div>

                <div
                    ref="output"
                    tabindex="0"
                    aria-label="Console output"
                    class="h-[500px] overflow-auto p-3.5 font-mono text-[0.8125rem] leading-[1.65] break-words whitespace-pre-wrap text-[#d7dae3]"
                >
                    <div
                        v-for="line in lines"
                        :key="line.id"
                        :class="lineClass(line)"
                    >
                        {{ line.text }}
                    </div>
                </div>

                <p
                    v-if="!canSend"
                    class="border-t border-white/10 bg-white/5 px-3.5 py-2.5 text-[0.8125rem] text-white/75"
                >
                    You can watch this console, but not send commands.
                </p>
                <form
                    v-else
                    class="flex flex-wrap items-center gap-2 border-t border-white/10 bg-white/5 px-3.5 py-2.5"
                    @submit.prevent="send"
                >
                    <label for="command" class="sr-only">Console command</label>
                    <span
                        aria-hidden="true"
                        class="font-mono font-bold text-brand"
                        >&gt;</span
                    >
                    <input
                        id="command"
                        v-model="command"
                        placeholder="Type a command, e.g. whitelist add Steve"
                        autocomplete="off"
                        spellcheck="false"
                        maxlength="500"
                        class="h-[38px] flex-[1_1_240px] rounded-md border border-white/25 bg-transparent px-2.5 font-mono text-sm text-white placeholder:text-white/50"
                        @keydown.up.prevent="recall(-1)"
                        @keydown.down.prevent="recall(1)"
                    />
                    <span class="text-xs text-[#a9afb5]">↑ ↓ for history</span>
                    <button
                        type="submit"
                        :disabled="!connected"
                        class="h-[38px] cursor-pointer rounded-md bg-white px-3.5 text-sm font-semibold text-[#1f2328] disabled:cursor-default disabled:opacity-50"
                    >
                        Send
                    </button>
                </form>
            </section>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                <SectionCard title="Join">
                    <div class="text-[0.8125rem] text-muted-foreground">
                        Server address
                    </div>
                    <div class="font-mono text-sm break-all text-primary">
                        {{ server.hostname }}
                    </div>
                    <template v-if="bluemapUrl">
                        <div
                            class="mt-3 text-[0.8125rem] text-muted-foreground"
                        >
                            BlueMap
                        </div>
                        <a
                            :href="bluemapUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm break-all text-primary underline"
                            >{{ bluemapUrl.replace(/^https?:\/\//, '') }}</a
                        >
                    </template>
                </SectionCard>

                <SectionCard title="Resources">
                    <p v-if="!latest" class="text-sm text-muted-foreground">
                        Waiting for the first measurement.
                    </p>
                    <div v-else class="flex flex-col gap-3.5">
                        <div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">CPU</span>
                                <span
                                    class="font-semibold text-primary tabular-nums"
                                    >{{ latest.cpu.toFixed(0) }}%</span
                                >
                            </div>
                            <Sparkline
                                :values="samples.map((sample) => sample.cpu)"
                                :max="
                                    Math.max(
                                        100,
                                        ...samples.map((sample) => sample.cpu),
                                    )
                                "
                                label="CPU use over the last ten minutes"
                            />
                        </div>
                        <div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground"
                                    >Memory</span
                                >
                                <span
                                    class="font-semibold text-primary tabular-nums"
                                >
                                    {{ gigabytes(latest.memory) }} /
                                    {{ gigabytes(latest.memory_limit) }} GB
                                </span>
                            </div>
                            <Sparkline
                                :values="samples.map((sample) => sample.memory)"
                                :max="latest.memory_limit"
                                label="Memory use over the last ten minutes"
                            />
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Disk</span>
                            <span
                                class="font-semibold text-primary tabular-nums"
                            >
                                {{
                                    states?.servers[server.slug]?.disk_bytes
                                        ? bytesLabel(
                                              states.servers[server.slug]
                                                  .disk_bytes!,
                                          )
                                        : 'Not measured yet'
                                }}
                            </span>
                        </div>
                        <p class="text-[0.8125rem] text-muted-foreground">
                            The last 10 minutes, measured every 10 seconds.
                        </p>
                    </div>
                </SectionCard>

                <SectionCard title="Players">
                    <p v-if="!players" class="text-sm text-muted-foreground">
                        The server is not answering, so nobody can be online.
                    </p>
                    <template v-else>
                        <p class="text-sm text-muted-foreground tabular-nums">
                            {{ players.online }} of {{ players.max }} online
                        </p>
                        <ul v-if="players.names.length" class="mt-2">
                            <li
                                v-for="name in players.names"
                                :key="name"
                                class="border-t border-divider py-1.5 first:border-t-0"
                            >
                                {{ name }}
                            </li>
                        </ul>
                        <p
                            v-if="players.online > players.names.length"
                            class="mt-2 text-[0.8125rem] text-muted-foreground"
                        >
                            The server reports at most a dozen names.
                        </p>
                    </template>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
