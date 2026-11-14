<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import { dashboard, serviceStatus } from '@/routes';
import { index as activity } from '@/routes/activity';
import { edit as panelSettings } from '@/routes/panel-settings';
import { index as users } from '@/routes/users';
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

const service = ref<{ online: boolean; last_seen_at: string | null }>();
const now = ref(Date.now());

const lastContact = computed(() => {
    if (!service.value?.last_seen_at) {
        return 'never';
    }

    const seconds = Math.max(
        0,
        Math.round((now.value - Date.parse(service.value.last_seen_at)) / 1000),
    );

    return seconds < 120
        ? `${seconds} s ago`
        : `${Math.round(seconds / 60)} min ago`;
});

const checkService = async () => {
    try {
        const response = await fetch(serviceStatus.url(), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            service.value = await response.json();
        }
    } catch {
        // The panel itself is unreachable; the next check tries again.
    }
};

let timers: ReturnType<typeof setInterval>[] = [];

onMounted(() => {
    void checkService();
    timers = [
        setInterval(checkService, 10000),
        setInterval(() => (now.value = Date.now()), 1000),
    ];
});

onBeforeUnmount(() => timers.forEach(clearInterval));

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Servers',
        href: dashboard(),
    },
    ...(auth.value.user.role === 'admin'
        ? [
              { title: 'Users', href: users() },
              { title: 'Activity', href: activity() },
              { title: 'Settings', href: panelSettings() },
          ]
        : []),
]);
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

                <div
                    v-if="service"
                    role="status"
                    class="flex items-center gap-2 py-2 text-[0.8125rem] text-white/85"
                >
                    <span
                        aria-hidden="true"
                        class="size-2 rounded-full"
                        :class="
                            service.online
                                ? 'bg-brand-bright ring-[3px] ring-brand-bright/25'
                                : 'bg-[#ff9a9a] ring-[3px] ring-[#ff9a9a]/25'
                        "
                    />
                    <span
                        >Rust service
                        {{ service.online ? 'online' : 'offline' }}</span
                    >
                    <span class="text-white/60 tabular-nums"
                        >· last contact {{ lastContact }}</span
                    >
                </div>

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
