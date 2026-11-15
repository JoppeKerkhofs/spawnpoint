<script setup lang="ts">
import { ask } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { destroy, update } from '@/routes/users';

type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'viewer';
    shares: Record<string, string[]>;
};

const props = defineProps<{
    user: User;
    servers: { id: number; name: string }[];
    permissions: string[];
    isMe: boolean;
}>();

const labels: Record<string, [string, string]> = {
    logs: [t('Logs'), t('Read and download the log files')],
    commands: [t('Commands'), t('Type commands in the console')],
    power: [t('Power'), t('Start, stop, restart and kill the server')],
    files: [t('Files'), t('Browse, edit, upload and delete files')],
    backups: [
        t('Backups'),
        t('Make, restore and schedule backups; import a world'),
    ],
    configure: [
        t('Configure'),
        t('Change version, memory, players and other settings'),
    ],
};

const presets = [
    { label: t('Watch only'), permissions: [] as string[] },
    { label: t('Operator'), permissions: ['logs', 'commands', 'power'] },
    { label: t('Everything'), permissions: props.permissions },
];

const form = useForm({
    role: props.user.role,
    // Server id to what the viewer may do there; a server that is missing is not shared.
    shares: { ...props.user.shares },
});

const share = (id: number, on: boolean) => {
    if (on) {
        form.shares[id] = [];
    } else {
        delete form.shares[id];
    }
};

const allow = (id: number, permission: string, on: boolean) => {
    form.shares[id] = on
        ? [...form.shares[id], permission]
        : form.shares[id].filter((held) => held !== permission);
};

const isPreset = (id: number, permissions: string[]) =>
    form.shares[id].length === permissions.length &&
    permissions.every((permission) => form.shares[id].includes(permission));

const save = () => {
    form.transform((data) => ({
        role: data.role,
        shared: Object.keys(data.shares),
        permissions: Object.entries(data.shares).flatMap(([id, held]) =>
            held.map((permission) => `${id}:${permission}`),
        ),
    })).put(update.url({ user: props.user.id }), { preserveScroll: true });
};

const remove = async () => {
    if (
        await ask('Delete :name? Servers they created stay and pass to you.', {
            name: props.user.name,
        })
    ) {
        router.delete(destroy.url({ user: props.user.id }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <article class="overflow-hidden rounded-md border border-border bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted px-4 py-3"
        >
            <h2 class="font-bold text-primary">
                {{ user.name }}
                <span v-if="isMe" class="font-normal text-muted-foreground">{{
                    t('(you)')
                }}</span>
            </h2>
            <span class="text-sm text-muted-foreground">{{ user.email }}</span>
        </div>

        <form class="flex flex-col gap-4 px-4 py-3" @submit.prevent="save">
            <div class="flex flex-wrap items-center gap-2">
                <Label :for="`role-${user.id}`">{{ t('Role') }}</Label>
                <select
                    :id="`role-${user.id}`"
                    v-model="form.role"
                    class="h-9 rounded-md border border-input bg-card px-2.5 text-sm"
                >
                    <option value="admin">{{ t('Admin') }}</option>
                    <option value="viewer">{{ t('Viewer') }}</option>
                </select>
                <span
                    v-if="form.role === 'admin'"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Can do everything on every server.') }}
                </span>
            </div>
            <InputError :message="form.errors.role" />

            <div
                v-if="form.role === 'viewer' && servers.length"
                class="flex flex-col gap-2"
            >
                <p class="text-[0.8125rem] text-muted-foreground">
                    {{
                        t(
                            'Switch on the servers this user may see. They can always watch the console of a shared server; choose what else they may do there.',
                        )
                    }}
                </p>

                <fieldset
                    v-for="server in servers"
                    :key="server.id"
                    class="rounded-md border px-3 py-2.5"
                    :class="
                        server.id in form.shares
                            ? 'border-ring'
                            : 'border-border'
                    "
                >
                    <legend class="sr-only">{{ server.name }}</legend>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <label
                            class="flex cursor-pointer items-center gap-2.5 font-semibold text-primary"
                        >
                            <input
                                type="checkbox"
                                role="switch"
                                class="peer sr-only"
                                :checked="server.id in form.shares"
                                @change="
                                    share(
                                        server.id,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            <span
                                aria-hidden="true"
                                class="relative h-5 w-9 rounded-full bg-input transition-colors peer-checked:bg-brand peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50 after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"
                            />
                            {{ server.name }}
                        </label>
                        <span
                            v-if="!(server.id in form.shares)"
                            class="text-sm text-muted-foreground"
                            >{{ t('Not shared') }}</span
                        >
                        <template v-else>
                            <span class="flex-1" />
                            <div
                                role="group"
                                :aria-label="
                                    t('Presets for :name', {
                                        name: server.name,
                                    })
                                "
                                class="flex overflow-hidden rounded-md border border-input text-[0.8125rem]"
                            >
                                <button
                                    v-for="preset in presets"
                                    :key="preset.label"
                                    type="button"
                                    class="cursor-pointer border-l border-input px-2.5 py-1 first:border-l-0"
                                    :class="
                                        isPreset(server.id, preset.permissions)
                                            ? 'bg-muted font-semibold text-primary'
                                            : 'text-muted-foreground hover:text-primary'
                                    "
                                    :aria-pressed="
                                        isPreset(server.id, preset.permissions)
                                    "
                                    @click="
                                        form.shares[server.id] = [
                                            ...preset.permissions,
                                        ]
                                    "
                                >
                                    {{ preset.label }}
                                </button>
                            </div>
                        </template>
                    </div>

                    <div
                        v-if="server.id in form.shares"
                        class="mt-2.5 flex flex-wrap gap-1.5"
                    >
                        <label
                            v-for="permission in permissions"
                            :key="permission"
                            :title="labels[permission]?.[1]"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-input px-3 py-1 text-sm text-muted-foreground select-none has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                type="checkbox"
                                class="sr-only"
                                :checked="
                                    form.shares[server.id].includes(permission)
                                "
                                :aria-describedby="`allows-${user.id}-${permission}`"
                                @change="
                                    allow(
                                        server.id,
                                        permission,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            <Check
                                v-if="
                                    form.shares[server.id].includes(permission)
                                "
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ labels[permission]?.[0] ?? permission }}
                        </label>
                    </div>
                </fieldset>

                <details class="text-sm">
                    <summary class="cursor-pointer text-muted-foreground">
                        {{ t('What each permission allows') }}
                    </summary>
                    <dl
                        class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[0.8125rem]"
                    >
                        <template
                            v-for="permission in permissions"
                            :key="permission"
                        >
                            <dt class="font-semibold text-primary">
                                {{ labels[permission]?.[0] ?? permission }}
                            </dt>
                            <dd
                                :id="`allows-${user.id}-${permission}`"
                                class="text-muted-foreground"
                            >
                                {{ labels[permission]?.[1] }}
                            </dd>
                        </template>
                    </dl>
                    <p class="mt-2 text-[0.8125rem] text-muted-foreground">
                        {{
                            t(
                                'Creating and deleting servers stays with admins.',
                            )
                        }}
                    </p>
                </details>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button :disabled="form.processing">{{ t('Save') }}</Button>
                <Button
                    v-if="!isMe"
                    type="button"
                    variant="outline"
                    class="border-destructive text-destructive hover:text-destructive"
                    @click="remove"
                >
                    {{ t('Delete') }}
                </Button>
                <span
                    v-if="form.isDirty"
                    role="status"
                    class="text-sm text-muted-foreground"
                    >{{ t('Unsaved changes') }}</span
                >
                <span
                    v-else-if="form.recentlySuccessful"
                    role="status"
                    class="text-sm text-muted-foreground"
                    >{{ t('Saved') }}</span
                >
            </div>
        </form>
    </article>
</template>
