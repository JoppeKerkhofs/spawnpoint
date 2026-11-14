export type Server = {
    id: number;
    name: string;
    slug: string;
    hostname: string;
    server_type: string;
    mc_version: string;
    java_version: number;
    memory_mb: number;
    settings: {
        motd: string;
        difficulty: string;
        mode: string;
        max_players: number;
    };
};

export type ServerOptions = {
    types: string[];
    mcVersions: string[];
    javaVersions: number[];
    difficulties: string[];
    modes: string[];
};

export type ServerState = { state: string; detail: string | null };

export type States = {
    online: boolean;
    servers: Record<string, ServerState>;
};

export type PowerAction = 'start' | 'stop' | 'restart' | 'kill';

export const title = (value: string): string =>
    value.charAt(0).toUpperCase() + value.slice(1).toLowerCase();

export const software = (server: Server): string =>
    `${title(server.server_type)} ${server.mc_version === 'LATEST' ? 'latest' : server.mc_version} · Java ${server.java_version}`;

// States the Rust service reports, plus two the panel adds: loading and unknown.
export function serverState(
    states: States | undefined,
    slug: string,
): ServerState {
    if (!states) {
        return { state: 'loading', detail: null };
    }

    return (
        states.servers[slug] ??
        (states.online
            ? {
                  state: 'offline',
                  detail: 'No container yet. Save the configuration to create it.',
              }
            : {
                  state: 'unknown',
                  detail: 'The Rust service did not answer.',
              })
    );
}

export function toneOf(state: string): 'ok' | 'busy' | 'idle' | 'error' {
    if (state === 'running') {
        return 'ok';
    }

    if (state === 'error') {
        return 'error';
    }

    return ['offline', 'unknown', 'loading'].includes(state) ? 'idle' : 'busy';
}

export function powerActions(state: string): PowerAction[] {
    if (state === 'running') {
        return ['restart', 'stop'];
    }

    if (['offline', 'error'].includes(state)) {
        return ['start'];
    }

    return ['starting', 'stopping', 'restarting'].includes(state)
        ? ['kill']
        : [];
}
