import { t } from '@/lib/i18n';

export type Modpack = {
    project: string;
    name: string;
    icon: string | null;
    version?: string | null;
    version_name?: string | null;
};

export type PackPlatform = 'MODRINTH' | 'AUTO_CURSEFORGE' | 'FTBA';

export type Server = {
    id: number;
    name: string;
    slug: string;
    hostname: string;
    server_type: string;
    mc_version: string;
    java_version: number;
    memory_mb: number;
    modpack: Modpack | null;
    bluemap_enabled: boolean;
    is_default: boolean;
    has_mods: boolean;
    backup_keep: number;
    backup_retention_days: number | null;
    // What the signed-in user may do here; "admin" on top of everything for an admin.
    can: string[];
    // The name of the host it runs on.
    node: string;
    settings: {
        motd: string;
        difficulty: string;
        mode: string;
        max_players: number;
        // Missing on servers saved before these options existed.
        ops?: string[];
        whitelist?: boolean;
        whitelist_names?: string[];
        jvm_opts?: string;
        cpu_limit?: number | null;
    };
};

export type ServerOptions = {
    types: string[];
    mcVersions: string[];
    javaVersions: number[];
    curseforge: boolean;
    difficulties: string[];
    modes: string[];
};

// Both are null when the host's memory could not be read and no limit is configured.
export type MemoryBudget = { limitMb: number | null; freeMb: number | null };

export type Players = { online: number; max: number; names: string[] };

// One sample: CPU as a percentage of one core, memory in bytes.
export type Usage = {
    at: number;
    cpu: number;
    memory: number;
    memory_limit: number;
    // Average milliseconds per game tick, measured once a minute; missing before Minecraft 1.20.3.
    mspt?: number | null;
};

export type ServerState = {
    state: string;
    detail: string | null;
    players?: Players | null;
    usage?: Usage | null;
    disk_bytes?: number | null;
    // Unix seconds of the newest backup.
    last_backup?: number | null;
    // Mod or plugin jars put in place since the server started, so not loaded yet.
    mods_changed?: number;
};

export type States = {
    online: boolean;
    servers: Record<string, ServerState>;
};

export type PowerAction = 'start' | 'stop' | 'restart' | 'kill';

export const bytesLabel = (bytes: number): string =>
    bytes >= 1024 ** 3
        ? `${(bytes / 1024 ** 3).toFixed(1)} GB`
        : `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`;

export const title = (value: string): string =>
    t(value.charAt(0).toUpperCase() + value.slice(1).toLowerCase());

export const PACK_TYPES = ['MODRINTH', 'AUTO_CURSEFORGE', 'FTBA'];

export const typeLabels: Record<string, string> = {
    VANILLA: 'Vanilla',
    PAPER: 'Paper',
    FABRIC: 'Fabric',
    FORGE: 'Forge',
    NEOFORGE: 'NeoForge',
    PUMPKIN: 'Pumpkin (Rust)',
    MODRINTH: 'Modrinth modpack',
    AUTO_CURSEFORGE: 'CurseForge modpack',
    FTBA: 'FTB modpack',
};

// The block that stands for a server in the blocky theme.
export const cubeTexture = (
    type: string,
): 'grass' | 'wood' | 'machine' | 'sand' | 'wool' | 'pumpkin' =>
    (
        {
            VANILLA: 'grass',
            PAPER: 'wool',
            FABRIC: 'wood',
            PUMPKIN: 'pumpkin',
            FORGE: 'sand',
        } as const
    )[type] ?? 'machine';

// Pumpkin is a native program: it has no Minecraft version to pick and does not use Java.
export const NATIVE_TYPES = ['PUMPKIN'];

export const software = (server: Server): string =>
    NATIVE_TYPES.includes(server.server_type)
        ? `${t(typeLabels[server.server_type])} · ${t('latest release')}`
        : server.modpack
          ? `${t(typeLabels[server.server_type])} · ${server.modpack.name} · Java ${server.java_version}`
          : `${t(typeLabels[server.server_type])} ${server.mc_version === 'LATEST' ? t('latest') : server.mc_version} · Java ${server.java_version}`;

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
                  detail: t(
                      'No container yet. Save the configuration to create it.',
                  ),
              }
            : {
                  state: 'unknown',
                  detail: t('The Rust service did not answer.'),
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
