import { usePage } from '@inertiajs/vue3';

/**
 * The text in the user's language. The English text is the key, so English needs no file and a
 * text without a translation simply stays English. Variables are written as :name in the text:
 * t('Delete :name?', { name: server.name }).
 *
 * Call it in templates, in a component's setup or inside functions; not at the top level of a
 * plain .ts module, which runs before the page and its translations exist.
 */
export const t = (
    text: string,
    values: Record<string, string | number> = {},
): string =>
    Object.entries(values).reduce(
        (result, [name, value]) => result.replaceAll(`:${name}`, String(value)),
        usePage().props.translations?.[text] ?? text,
    );
