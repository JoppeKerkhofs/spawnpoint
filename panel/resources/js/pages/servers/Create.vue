<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SectionCard from '@/components/SectionCard.vue';
import ServerFields from '@/components/ServerFields.vue';
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
}>();

const slug = ref('');
const javaVersion = ref(25);
</script>

<template>
    <Head title="New server" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <nav
                aria-label="Breadcrumb"
                class="text-[0.8125rem] text-muted-foreground"
            >
                <Link :href="dashboard()" class="underline">Servers</Link>
                / New server
            </nav>
            <h1 class="mt-2 text-2xl leading-[1.3] font-bold text-primary">
                Create a server
            </h1>
            <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                Each server gets its own container from the
                itzg/minecraft-server image. Its data folder survives every
                restart and settings change.
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <Form
                v-bind="store.form()"
                class="flex min-w-0 flex-[999_1_560px] flex-col gap-3"
                v-slot="{ errors, processing }"
            >
                <SectionCard title="Name and address">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="name">Name</Label>
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
                            <Label for="slug">Hostname</Label>
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
                                Players join with this address on the default
                                port. It can't change after creation.
                            </span>
                            <InputError :message="errors.slug" />
                        </div>
                    </div>
                </SectionCard>

                <ServerFields
                    v-model:java-version="javaVersion"
                    :options="options"
                    :memory="memory"
                    :errors="errors"
                />

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
                            I accept the
                            <a
                                href="https://www.minecraft.net/eula"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                                >Minecraft EULA</a
                            >. The server won't start without it.
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
                            <Link :href="dashboard()">Cancel</Link>
                        </Button>
                        <Button :disabled="processing">
                            <Spinner v-if="processing" />
                            Create server
                        </Button>
                    </div>
                </section>
            </Form>

            <aside class="min-w-0 flex-[1_1_300px]">
                <SectionCard title="What gets created">
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                    >
                        <dt class="text-muted-foreground">Container</dt>
                        <dd class="font-mono break-all">
                            mc-{{ slug || '…' }}
                        </dd>
                        <dt class="text-muted-foreground">Image</dt>
                        <dd class="font-mono break-all">
                            itzg/minecraft-server:java{{ javaVersion }}
                        </dd>
                        <dt class="text-muted-foreground">Address</dt>
                        <dd class="font-mono break-all">
                            {{ slug || '…' }}.{{ baseDomain }}
                        </dd>
                    </dl>
                    <p class="mt-3 text-[0.8125rem] text-muted-foreground">
                        Only settings from this form are passed to the
                        container. EULA=TRUE is set once you accept.
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
