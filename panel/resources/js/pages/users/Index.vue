<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, store, update } from '@/routes/users';

type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'viewer';
    servers: number[];
};

defineProps<{
    users: User[];
    servers: { id: number; name: string }[];
    passwordRules: string;
}>();

const me = usePage().props.auth.user.id;
const selectClass = 'h-9 rounded-md border border-input bg-card px-2.5 text-sm';

const remove = (user: User) => {
    if (
        confirm(
            `Delete ${user.name}? Servers they created stay and pass to you.`,
        )
    ) {
        router.delete(destroy.url({ user: user.id }), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">Users</h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                Admins can do everything. Viewers see only the servers shared
                with them and cannot change anything.
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-3">
                <article
                    v-for="user in users"
                    :key="user.id"
                    class="overflow-hidden rounded-md border border-border bg-card"
                >
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted px-4 py-3"
                    >
                        <h2 class="font-bold text-primary">
                            {{ user.name }}
                            <span
                                v-if="user.id === me"
                                class="font-normal text-muted-foreground"
                                >(you)</span
                            >
                        </h2>
                        <span class="text-sm text-muted-foreground">{{
                            user.email
                        }}</span>
                    </div>

                    <Form
                        v-bind="update.form({ user: user.id })"
                        :options="{ preserveScroll: true }"
                        class="flex flex-col gap-3 px-4 py-3"
                        v-slot="{ errors, processing }"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Label :for="`role-${user.id}`">Role</Label>
                            <select
                                :id="`role-${user.id}`"
                                name="role"
                                :class="selectClass"
                            >
                                <option
                                    value="admin"
                                    :selected="user.role === 'admin'"
                                >
                                    Admin
                                </option>
                                <option
                                    value="viewer"
                                    :selected="user.role === 'viewer'"
                                >
                                    Viewer
                                </option>
                            </select>
                        </div>
                        <InputError :message="errors.role" />

                        <fieldset v-if="servers.length">
                            <legend
                                class="mb-1 text-[0.8125rem] text-muted-foreground"
                            >
                                Servers shared with this user as a viewer
                            </legend>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                <label
                                    v-for="server in servers"
                                    :key="server.id"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        name="servers[]"
                                        :value="server.id"
                                        :checked="
                                            user.servers.includes(server.id)
                                        "
                                        class="size-4 accent-primary"
                                    />
                                    {{ server.name }}
                                </label>
                            </div>
                        </fieldset>

                        <div class="flex flex-wrap gap-2">
                            <Button :disabled="processing">Save</Button>
                            <Button
                                v-if="user.id !== me"
                                type="button"
                                variant="outline"
                                class="border-destructive text-destructive hover:text-destructive"
                                @click="remove(user)"
                            >
                                Delete
                            </Button>
                        </div>
                    </Form>
                </article>
            </div>

            <aside class="min-w-0 flex-[1_1_300px]">
                <SectionCard title="Add a user">
                    <Form
                        v-bind="store.form()"
                        :options="{ preserveScroll: true }"
                        reset-on-success
                        class="flex flex-col gap-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input id="name" name="name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">Email address</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autocomplete="off"
                            />
                            <InputError :message="errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autocomplete="new-password"
                                :passwordrules="passwordRules"
                            />
                            <InputError :message="errors.password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="role">Role</Label>
                            <select id="role" name="role" :class="selectClass">
                                <option value="viewer">Viewer</option>
                                <option value="admin">Admin</option>
                            </select>
                            <InputError :message="errors.role" />
                        </div>
                        <p class="text-[0.8125rem] text-muted-foreground">
                            Share servers with a viewer after creating them.
                        </p>
                        <Button :disabled="processing" class="self-start"
                            >Add user</Button
                        >
                    </Form>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
