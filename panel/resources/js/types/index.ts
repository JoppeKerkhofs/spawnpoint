export * from './auth';
export * from './navigation';
export * from './ui';

export type LauncherRelease = {
    version: string;
    released: string | null;
    size: number | null;
    // Null when the version is listed but may not be downloaded.
    url: string | null;
    // One installer per system the version was built for; size and url above are the first's.
    files: { platform: string; size: number | null; url: string | null }[];
};
