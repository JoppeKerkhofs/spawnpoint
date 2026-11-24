<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SectionCard from '@/components/SectionCard.vue';
import UserAccess from '@/components/UserAccess.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { store } from '@/routes/users';

type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'viewer';
    manages_launcher: boolean;
    shares: Record<string, string[]>;
};

defineProps<{
    users: User[];
    servers: { id: number; name: string }[];
    permissions: string[];
    passwordRules: string;
}>();

const me = usePage().props.auth.user.id;
const selectClass = 'h-9 rounded-md border border-input bg-card px-2.5 text-sm';
</script>

<template>
    <Head :title="t('Users')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                {{ t('Users') }}
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                {{
                    t(
                        'Admins can do everything. Viewers see only the servers shared with them, and do there what you allow.',
                    )
                }}
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <div
                class="min-w-0 flex-[999_1_560px] overflow-hidden rounded-md border border-border bg-card"
            >
                <UserAccess
                    v-for="user in users"
                    :key="user.id"
                    :user="user"
                    :servers="servers"
                    :permissions="permissions"
                    :is-me="user.id === me"
                />
            </div>

            <aside class="min-w-0 flex-[1_1_300px]">
                <SectionCard :title="t('Add a user')">
                    <Form
                        v-bind="store.form()"
                        :options="{ preserveScroll: true }"
                        reset-on-success
                        class="flex flex-col gap-4"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">{{ t('Name') }}</Label>
                            <Input id="name" name="name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">{{ t('Email address') }}</Label>
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
                            <Label for="password">{{ t('Password') }}</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autocomplete="new-password"
                                :passwordrules="passwordRules"
                                aria-describedby="password_hint"
                            />
                            <span
                                id="password_hint"
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                {{
                                    t(
                                        'Leave empty to email them a link to choose their own. That needs outgoing mail to be set up.',
                                    )
                                }}
                            </span>
                            <InputError :message="errors.password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="role">{{ t('Role') }}</Label>
                            <select id="role" name="role" :class="selectClass">
                                <option value="viewer">
                                    {{ t('Viewer') }}
                                </option>
                                <option value="admin">{{ t('Admin') }}</option>
                            </select>
                            <InputError :message="errors.role" />
                        </div>
                        <p class="text-[0.8125rem] text-muted-foreground">
                            {{
                                t(
                                    'Share servers with a viewer after creating them.',
                                )
                            }}
                        </p>
                        <Button :disabled="processing" class="self-start">{{
                            t('Add user')
                        }}</Button>
                    </Form>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
