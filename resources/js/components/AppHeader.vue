<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LogoutLink from '@/components/LogoutLink.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { dashboard } from '@/routes';
import { index as cashShift } from '@/routes/cash-closings';
import { edit as editProfile } from '@/routes/profile';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentOrParentUrl } = useCurrentUrl();
const settingsActive = computed(() => isCurrentOrParentUrl('/settings/'));

const activeItemStyles = 'bg-accent text-accent-foreground';

const mainNavItems = useMainNavigation();
</script>

<template>
    <div class="hidden lg:block">
        <div class="border-b border-sidebar-border/80">
            <div
                class="app-container grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 min-[75rem]:grid-cols-[auto_minmax(0,1fr)_auto] xl:gap-x-6"
            >
                <Link
                    :href="auth.cashShiftAccess ? cashShift() : dashboard()"
                    class="col-start-1 row-start-1 flex h-14 items-center gap-2 justify-self-start min-[75rem]:h-16"
                >
                    <AppLogo />
                </Link>

                <div
                    class="col-span-2 col-start-1 row-start-2 flex h-11 min-w-0 min-[75rem]:col-span-1 min-[75rem]:col-start-2 min-[75rem]:row-start-1 min-[75rem]:h-16"
                >
                    <NavigationMenu
                        aria-label="Menú principal"
                        class="flex h-full items-stretch"
                    >
                        <NavigationMenuList
                            class="flex h-full items-stretch gap-1"
                        >
                            <NavigationMenuItem
                                v-for="item in mainNavItems"
                                :key="item.title"
                                class="relative flex h-full items-center"
                            >
                                <Link
                                    :class="[
                                        navigationMenuTriggerStyle(),
                                        isCurrentOrParentUrl(item.href) &&
                                            activeItemStyles,
                                        'h-9 cursor-pointer px-3',
                                    ]"
                                    :href="item.href"
                                    :aria-current="
                                        isCurrentOrParentUrl(item.href)
                                            ? 'location'
                                            : undefined
                                    "
                                >
                                    <component
                                        v-if="item.icon"
                                        :is="item.icon"
                                        class="mr-2 size-4"
                                        aria-hidden="true"
                                    />
                                    {{ item.title }}
                                </Link>
                                <div
                                    v-if="isCurrentOrParentUrl(item.href)"
                                    class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-primary"
                                ></div>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div
                    class="col-start-2 row-start-1 flex items-center gap-2 min-[75rem]:col-start-3"
                >
                    <div
                        class="flex items-center gap-2 pe-1"
                        :title="auth.user.name"
                    >
                        <Avatar class="size-8" aria-hidden="true">
                            <AvatarImage
                                v-if="auth.user.avatar"
                                :src="auth.user.avatar"
                                alt=""
                            />
                            <AvatarFallback class="font-semibold">
                                {{ getInitials(auth.user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="sr-only xl:not-sr-only">
                            <span
                                class="block max-w-28 truncate text-sm font-medium"
                            >
                                {{ auth.user.name }}
                            </span>
                        </span>
                    </div>

                    <Button
                        v-if="!auth.cashShiftAccess"
                        as-child
                        :variant="settingsActive ? 'secondary' : 'ghost'"
                    >
                        <Link
                            :href="editProfile()"
                            :aria-current="
                                settingsActive ? 'location' : undefined
                            "
                        >
                            <Settings
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            Ajustes
                        </Link>
                    </Button>

                    <Button as-child variant="outline">
                        <LogoutLink data-test="logout-button">
                            <LogOut
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            Cambiar de sesión
                        </LogoutLink>
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="props.breadcrumbs.length > 1"
            class="flex w-full border-b border-sidebar-border/70"
        >
            <div
                class="app-container flex h-12 items-center text-muted-foreground"
            >
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
