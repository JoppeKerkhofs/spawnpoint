export * from './auth';
export * from './navigation';
export * from './ui';

export type LauncherRelease = {
    version: string;
    released: string | null;
    size: number | null;
    // Null when the version is listed but may not be downloaded.
    url: string | null;
};
