<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerFields from '@/components/ServerFields.vue';
import { t } from '@/lib/i18n';
import { NATIVE_TYPES } from '@/lib/servers';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { MemoryBudget, ServerOptions } from '@/lib/servers';
import { dashboard } from '@/routes';
import { store } from '@/routes/servers';

defineProps<{
    baseDomain: string;
    options: ServerOptions;
    memory: MemoryBudget;
    imports: string[];
}>();

const slug = ref('');
const javaVersion = ref(25);
const serverType = ref('PAPER');
const worldSource = ref<'new' | 'host' | 'zip'>('new');
</script>

<template>
    <Head :title="t('New server')" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <nav
                :aria-label="t('Breadcrumb')"
                class="text-[0.8125rem] text-muted-foreground"
            >
                <Link :href="dashboard()" class="underline">{{
                    t('Servers')
                }}</Link>
                / {{ t('New server') }}
            </nav>
            <h1 class="mt-2 text-2xl leading-[1.3] font-bold text-primary">
                {{ t('Create a server') }}
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                {{
                    t(
                        'Each server gets its own container from the :image image. Its data folder survives every restart and settings change.',
                        { image: 'itzg/minecraft-server' },
                    )
                }}
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <Form
                v-bind="store.form()"
                class="flex min-w-0 flex-[999_1_560px] flex-col gap-3"
                v-slot="{ errors, processing }"
            >
                <SectionCard :title="t('Name and address')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="name">{{ t('Name') }}</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                maxlength="60"
                                autofocus
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="slug">{{ t('Hostname') }}</Label>
                            <div class="flex items-center gap-2">
                                <Input
                                    id="slug"
                                    v-model="slug"
                                    name="slug"
                                    required
                                    maxlength="63"
                                    pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    aria-describedby="slug_hint"
                                />
                                <span class="font-mono text-sm"
                                    >.{{ baseDomain }}</span
                                >
                            </div>
                            <span
                                id="slug_hint"
                                class="text-[0.8125rem] text-muted-foreground"
                            >
                                {{
                                    t(
                                        "Players join with this address on the default port. It can't change after creation.",
                                    )
                                }}
                            </span>
                            <InputError :message="errors.slug" />
                        </div>
                    </div>
                </SectionCard>

                <ServerFields
                    v-model:java-version="javaVersion"
                    v-model:server-type="serverType"
                    :options="options"
                    :memory="memory"
                    :errors="errors"
                />

                <SectionCard :title="t('World')">
                    <div class="flex flex-col gap-3">
                        <div
                            role="radiogroup"
                            :aria-label="t('World source')"
                            class="flex flex-col gap-2"
                        >
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="worldSource"
                                    type="radio"
                                    value="new"
                                    class="size-4 accent-primary"
                                />
                                {{ t('Generate a new world') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="worldSource"
                                    type="radio"
                                    value="host"
                                    :disabled="!imports.length"
                                    class="size-4 accent-primary"
                                />
                                {{ t('Start from a world folder on the host') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="worldSource"
                                    type="radio"
                                    value="zip"
                                    class="size-4 accent-primary"
                                />
                                {{ t('Upload a world .zip') }}
                            </label>
                        </div>

                        <div v-if="worldSource === 'new'" class="grid gap-2">
                            <Label for="seed"
                                >{{ t('Seed') }}
                                <span
                                    class="font-normal text-muted-foreground"
                                    >{{ t('(optional)') }}</span
                                ></Label
                            >
                            <Input
                                id="seed"
                                name="seed"
                                maxlength="64"
                                :placeholder="t('Random')"
                            />
                            <InputError :message="errors.seed" />
                        </div>
                        <p v-else-if="worldSource === 'zip'" class="text-sm">
                            <input type="hidden" name="world_zip" value="1" />
                            {{
                                t(
                                    'The server is created without starting. On the next page you choose the .zip; the server starts once the world is in.',
                                )
                            }}
                        </p>
                        <div v-else class="grid gap-2">
                            <Label for="import_path">{{
                                t('Folder on the host')
                            }}</Label>
                            <select
                                id="import_path"
                                name="import_path"
                                class="h-9 w-full rounded-md border border-input bg-card px-2.5 text-sm"
                            >
                                <option
                                    v-for="world in imports"
                                    :key="world"
                                    :value="world"
                                >
                                    {{ world }}
                                </option>
                            </select>
                            <InputError :message="errors.import_path" />
                        </div>
                        <span class="text-[0.8125rem] text-muted-foreground">
                            {{
                                imports.length
                                    ? t(
                                          'Only worlds in the mounted import folder are listed as host folders.',
                                      )
                                    : t(
                                          'No worlds are in the import folder on the host.',
                                      )
                            }}
                        </span>
                    </div>
                </SectionCard>

                <section
                    class="flex flex-col gap-3 rounded-md border border-border bg-card px-4 py-4"
                >
                    <label class="flex items-start gap-2.5">
                        <input
                            type="checkbox"
                            name="eula"
                            value="1"
                            required
                            class="mt-1 size-4 accent-primary"
                        />
                        <span>
                            {{ t('I accept the') }}
                            <a
                                href="https://www.minecraft.net/eula"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                                >Minecraft EULA</a
                            >. {{ t("The server won't start without it.") }}
                        </span>
                    </label>
                    <InputError :message="errors.eula" />
                    <InputError :message="errors.node" />

                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            variant="outline"
                            class="border-primary"
                            as-child
                        >
                            <Link :href="dashboard()">{{ t('Cancel') }}</Link>
                        </Button>
                        <Button :disabled="processing">
                            <Spinner v-if="processing" />
                            {{ t('Create server') }}
                        </Button>
                    </div>
                </section>
            </Form>

            <aside class="min-w-0 flex-[1_1_300px]">
                <SectionCard :title="t('What gets created')">
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('Container') }}
                        </dt>
                        <dd class="font-mono break-all">
                            mc-{{ slug || '…' }}
                        </dd>
                        <dt class="text-muted-foreground">{{ t('Image') }}</dt>
                        <dd class="font-mono break-all">
                            itzg/minecraft-server:{{
                                NATIVE_TYPES.includes(serverType)
                                    ? 'latest'
                                    : `java${javaVersion}`
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            {{ t('Address') }}
                        </dt>
                        <dd class="font-mono break-all">
                            {{ slug || '…' }}.{{ baseDomain }}
                        </dd>
                    </dl>
                    <p class="mt-3 text-[0.8125rem] text-muted-foreground">
                        {{
                            t(
                                'Only settings from this form are passed to the container. :variable is set once you accept.',
                                { variable: 'EULA=TRUE' },
                            )
                        }}
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
