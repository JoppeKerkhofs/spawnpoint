import { router, usePage, usePoll } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationCallback } from 'pusher-js';
import { onUnmounted, ref, watch } from 'vue';

// What the panel announces: never the new data, only that something changed.
export type Change = { server: string | null; what: string };

type Live = {
    key: string;
    host: string | null;
    port: string | number | null;
} | null;

// False without a WebSocket server, or while the connection is down: pages then poll.
export const connected = ref(false);

const handlers = new Set<(change: Change) => void>();
let echo: Echo<'reverb'> | null = null;
// Whose channel the socket is on: another user in the same tab must not inherit it.
let listener: number | null = null;

// Laravel checks this cookie's value on the request that signs a channel subscription.
const xsrf = () =>
    decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    );

const connect = (live: NonNullable<Live>, user: number) => {
    const secure = window.location.protocol === 'https:';
    // The panel's own address unless told otherwise (development).
    const port =
        Number(live.port ?? window.location.port) || (secure ? 443 : 80);

    echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: live.key,
        wsHost: live.host ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: { name: string }) => ({
            authorize: (
                socketId: string,
                callback: ChannelAuthorizationCallback,
            ) => {
                fetch('/broadcasting/auth', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrf(),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                })
                    .then(async (response) =>
                        response.ok
                            ? callback(null, await response.json())
                            : callback(
                                  new Error(String(response.status)),
                                  null,
                              ),
                    )
                    .catch((error) => callback(error, null));
            },
        }),
    });

    listener = user;
    // Connected only once the channel is joined: an open socket that hears nothing must not
    // stop the polling.
    echo.connector.pusher.connection.bind(
        'state_change',
        (states: { current: string }) => {
            if (states.current !== 'connected') {
                connected.value = false;
            }
        },
    );
    echo.private(`users.${user}`)
        .subscribed(() => (connected.value = true))
        .error(() => (connected.value = false))
        .listen('.changed', (change: Change) =>
            handlers.forEach((handler) => handler(change)),
        );
};

// Signing out, or in as someone else, ends the connection; the next page that listens opens its own.
router.on('navigate', (event) => {
    const user = event.detail.page.props.auth?.user?.id ?? null;

    if (echo && user !== listener) {
        echo.disconnect();
        echo = null;
        listener = null;
        connected.value = false;
    }
});

/** Hear what changes for as long as the component lives. */
export const useLive = (handler: (change: Change) => void) => {
    const page = usePage();
    const live = page.props.live as Live;

    const user = page.props.auth?.user?.id;

    if (live && user && !echo) {
        connect(live, user);
    }

    handlers.add(handler);
    onUnmounted(() => handlers.delete(handler));
};

/**
 * Reload parts of the page when a change that matters to it is announced. Announcements that
 * come in a burst cause one reload, and none starts while the page is sending something itself.
 */
export const useLiveReload = (
    matters: (change: Change) => boolean,
    only: string[],
    allowed: () => boolean = () => true,
) => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let sending = 0;
    let quietUntil = 0;
    const stops = [
        router.on('start', (event) => {
            sending += event.detail.visit.method === 'get' ? 0 : 1;
        }),
        router.on('finish', (event) => {
            if (event.detail.visit.method !== 'get') {
                // A page reached by a form never saw that form's start.
                sending = Math.max(0, sending - 1);
                // Its answer brought the new data, so the announcement of it is not acted on.
                quietUntil = Date.now() + 1000;
                clearTimeout(timer);
            }
        }),
    ];

    useLive((change) => {
        if (matters(change) && sending === 0 && Date.now() > quietUntil) {
            clearTimeout(timer);
            timer = setTimeout(() => {
                if (allowed()) {
                    router.reload({ only });
                }
            }, 300);
        }
    });
    onUnmounted(() => {
        clearTimeout(timer);
        stops.forEach((stop) => stop());
    });
};

/**
 * Keep the `states` prop fresh: on announcement while connected, by asking every four seconds
 * while not. Connected, it still asks now and then, in case the watcher is not running.
 */
export const useLiveStates = (slug?: () => string) => {
    const fast = usePoll(4000, { only: ['states'] }, { autoStart: false });
    const slow = usePoll(30000, { only: ['states'] }, { autoStart: false });

    watch(
        connected,
        (live) => {
            (live ? fast : slow).stop();
            (live ? slow : fast).start();
        },
        { immediate: true },
    );

    useLiveReload(
        (change) =>
            change.what === 'state' && (!slug || change.server === slug()),
        ['states'],
    );
};
