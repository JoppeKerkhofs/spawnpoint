import { reactive } from 'vue';
import { t } from '@/lib/i18n';

type Request = {
    title: string;
    text: string;
    action: string;
    destructive: boolean;
    // Present when the dialog asks for a name instead of a yes or no.
    input: { label: string; value: string } | null;
    resolve: (answer: string | null) => void;
};

// One dialog at a time, shown by AppDialog in the layout.
export const dialog = reactive<{ current: Request | null }>({ current: null });

const DESTRUCTIVE = [
    'Delete',
    'Remove',
    'Revoke',
    'Stop',
    'Replace',
    'Restore',
];

/**
 * Ask for a yes or no. Pass the English text, with :name for variables; it is translated here.
 * The question becomes the title, what follows it the explanation, and the first English word
 * the button: "Delete :name? This cannot be undone." gets a Delete button.
 */
export const ask = (
    message: string,
    values: Record<string, string | number> = {},
): Promise<boolean> => {
    const action = message.split(' ')[0];
    const shown = t(message, values);
    const end = shown.indexOf('?') + 1 || shown.length;

    return new Promise((resolve) => {
        dialog.current = {
            title: shown.slice(0, end),
            text: shown.slice(end).trim(),
            action: t(action),
            destructive: DESTRUCTIVE.includes(action),
            input: null,
            resolve: (answer) => resolve(answer !== null),
        };
    });
};

/**
 * Ask for a name; resolves to null when the dialog is dismissed. The title, label and action
 * are English texts and are translated here; the title may hold :name variables.
 */
export const askName = (
    title: string,
    label: string,
    value = '',
    action = 'Create',
    values: Record<string, string | number> = {},
): Promise<string | null> =>
    new Promise((resolve) => {
        dialog.current = {
            title: t(title, values),
            text: '',
            action: t(action),
            destructive: false,
            input: { label: t(label), value },
            resolve,
        };
    });
