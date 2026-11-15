<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionCard from '@/components/SectionCard.vue';
import { spec as specRoute } from '@/routes/api-docs';
import { index as apiTokens } from '@/routes/api-tokens';

type Parameter = {
    name: string;
    in: string;
    required?: boolean;
    description?: string;
    schema?: { enum?: (string | number)[] };
};

type Operation = {
    summary: string;
    description?: string;
    'x-ability': string;
    parameters: Parameter[];
    requestBody?: {
        content: {
            'application/json': {
                schema: {
                    properties: Record<
                        string,
                        { description?: string; example?: string }
                    >;
                };
            };
        };
    };
    responses: Record<string, { description: string }>;
};

const props = defineProps<{
    spec: {
        info: { description: string };
        paths: Record<string, Record<string, Operation>>;
    };
    baseUrl: string;
}>();

const operations = computed(() =>
    Object.entries(props.spec.paths).flatMap(([path, methods]) =>
        Object.entries(methods).map(([method, operation]) => ({
            id: `${method}-${path}`.replace(/[^a-z]+/gi, '-'),
            method: method.toUpperCase(),
            path,
            operation,
        })),
    ),
);

const methodClass: Record<string, string> = {
    GET: 'bg-[#e1f1e7] text-[#1d6b3f] dark:bg-[#173323] dark:text-[#7fd6a2]',
    POST: 'bg-[#e3ecfb] text-[#1f4f9c] dark:bg-[#182a47] dark:text-[#9cc0ff]',
    PUT: 'bg-[#fbefd5] text-[#7a4f00] dark:bg-[#3a2d12] dark:text-[#f0c36b]',
    DELETE: 'bg-[#fbe3e3] text-[#a12a2f] dark:bg-[#3d1c1e] dark:text-[#ff9a9a]',
};

const example = (method: string, path: string, operation: Operation) => {
    const address =
        props.baseUrl +
        path.replace('{server}', 'survival').replace('{action}', 'restart');
    const fields =
        operation.requestBody?.content['application/json'].schema.properties;
    const body = fields
        ? ` \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify(
              Object.fromEntries(
                  Object.entries(fields).map(([name, field]) => [
                      name,
                      field.example ?? '...',
                  ]),
              ),
          )}'`
        : '';

    return `curl -X ${method} "${address}" \\\n  -H "Authorization: Bearer $TOKEN" \\\n  -H "Accept: application/json"${body}`;
};
</script>

<template>
    <Head title="API documentation" />

    <div class="flex flex-col gap-5 px-6 pt-7 pb-12">
        <div>
            <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                API documentation
            </h1>
            <p class="mt-1 max-w-3xl text-[0.9375rem] text-muted-foreground">
                {{ spec.info.description }}
            </p>
            <p class="mt-2 text-sm">
                <Link :href="apiTokens()" class="text-primary underline"
                    >Create a token</Link
                >
                ·
                <a :href="specRoute.url()" class="text-primary underline"
                    >OpenAPI document (JSON)</a
                >
                · Base address
                <code class="font-mono text-primary">{{ baseUrl }}</code>
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-5">
            <nav
                aria-label="Endpoints"
                class="top-4 min-w-0 flex-[1_1_260px] rounded-md border border-border bg-card px-4 py-3 text-sm lg:sticky"
            >
                <ul class="flex flex-col gap-1.5">
                    <li v-for="item in operations" :key="item.id">
                        <a
                            :href="`#${item.id}`"
                            class="flex items-baseline gap-2 hover:underline"
                        >
                            <span
                                class="w-14 shrink-0 font-mono text-xs font-semibold"
                                >{{ item.method }}</span
                            >
                            <span>{{ item.operation.summary }}</span>
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-3">
                <SectionCard
                    v-for="{ id, method, path, operation } in operations"
                    :id="id"
                    :key="id"
                    :title="operation.summary"
                    class="scroll-mt-4"
                >
                    <div class="flex flex-col gap-3 text-sm">
                        <p class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 font-mono text-xs font-semibold"
                                :class="methodClass[method]"
                                >{{ method }}</span
                            >
                            <code class="font-mono break-all text-primary">{{
                                path
                            }}</code>
                            <span class="text-muted-foreground"
                                >needs the ability
                                <code class="font-mono text-primary">{{
                                    operation['x-ability']
                                }}</code></span
                            >
                        </p>

                        <p
                            v-if="operation.description"
                            class="text-muted-foreground"
                        >
                            {{ operation.description }}
                        </p>

                        <dl
                            v-if="
                                operation.parameters.length ||
                                operation.requestBody
                            "
                            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1"
                        >
                            <template
                                v-for="parameter in operation.parameters"
                                :key="parameter.name"
                            >
                                <dt class="font-mono text-primary">
                                    {{ parameter.name }}
                                    <span
                                        class="font-sans text-xs text-muted-foreground"
                                        >{{ parameter.in }}</span
                                    >
                                </dt>
                                <dd class="text-muted-foreground">
                                    {{
                                        parameter.description ??
                                        (parameter.schema?.enum
                                            ? `One of: ${parameter.schema.enum.join(', ')}.`
                                            : '')
                                    }}
                                </dd>
                            </template>
                            <template
                                v-for="(field, name) in operation.requestBody
                                    ?.content['application/json'].schema
                                    .properties"
                                :key="name"
                            >
                                <dt class="font-mono text-primary">
                                    {{ name }}
                                    <span
                                        class="font-sans text-xs text-muted-foreground"
                                        >body</span
                                    >
                                </dt>
                                <dd class="text-muted-foreground">
                                    {{ field.description }}
                                </dd>
                            </template>
                        </dl>

                        <pre
                            class="overflow-x-auto rounded-md bg-header p-3 font-mono text-[0.8125rem] leading-[1.6] text-[#d7dae3]"
                            tabindex="0"
                            :aria-label="`Example request for ${operation.summary}`"
                            >{{ example(method, path, operation) }}</pre>

                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                            <template
                                v-for="(
                                    response, status
                                ) in operation.responses"
                                :key="status"
                            >
                                <dt class="font-mono tabular-nums">
                                    {{ status }}
                                </dt>
                                <dd class="text-muted-foreground">
                                    {{ response.description }}
                                </dd>
                            </template>
                        </dl>
                    </div>
                </SectionCard>
            </div>
        </div>
    </div>
</template>
