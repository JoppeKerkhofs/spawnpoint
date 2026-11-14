<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentUrl } = useCurrentUrl();

const mainNavItems: NavItem[] = [
    {
        title: 'Servers',
        href: dashboard(),
    },
];
</script>

<template>
    <div>
        <header class="border-b border-header-border bg-header text-white">
            <div
                class="mx-auto flex min-h-14 max-w-7xl flex-wrap items-center gap-x-6 gap-y-1 px-6"
            >
                <Link
                    :href="dashboard()"
                    class="flex items-center gap-2.5 py-3 font-bold"
                >
                    <AppLogoIcon class="size-[22px]" aria-hidden="true" />
                    <span>{{ page.props.name }}</span>
                </Link>

                <nav aria-label="Main" class="flex flex-wrap gap-1">
                    <Link
                        v-for="item in mainNavItems"
                        :key="item.title"
                        :href="item.href"
                        :aria-current="
                            isCurrentUrl(item.href) ? 'page' : undefined
                        "
                        class="border-b-2 px-3 pt-4 pb-3.5 text-[0.9375rem] hover:text-white"
                        :class="
                            isCurrentUrl(item.href)
                                ? 'border-brand text-white'
                                : 'border-transparent text-white/80'
                        "
                    >
                        {{ item.title }}
                    </Link>
                </nav>

                <div class="flex-1" />

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            :aria-label="`Account: ${auth.user.name}`"
                            class="flex cursor-pointer items-center gap-2 py-2 text-sm"
                        >
                            <span
                                class="flex size-8 items-center justify-center rounded-full bg-[#3d434a] text-[0.8125rem] font-semibold"
                            >
                                {{ getInitials(auth.user.name) }}
                            </span>
                            <span>{{ auth.user.name }}</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <div
            v-if="props.breadcrumbs.length > 1"
            class="border-b border-border bg-card"
        >
            <div class="mx-auto flex h-12 max-w-7xl items-center px-6">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
