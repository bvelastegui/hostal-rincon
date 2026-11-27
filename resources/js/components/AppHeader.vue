<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import LogoutLink from '@/components/LogoutLink.vue';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { cn } from '@/lib/utils';
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
const mainNavItems = useMainNavigation();
const settingsActive = computed(() => isCurrentOrParentUrl('/settings/'));

const menuDividerClass = 'border-l border-primary-foreground/25 pl-4';

const menuItemClass = cn(
    navigationMenuTriggerStyle(),
    'h-16 w-24 shrink-0 cursor-pointer flex-col justify-start gap-2 bg-transparent px-1 py-1.5 text-center text-xs leading-none whitespace-normal text-primary-foreground hover:bg-primary-foreground/15 hover:text-primary-foreground focus:bg-primary-foreground/15 focus:text-primary-foreground focus-visible:ring-primary-foreground/60 aria-[current=location]:bg-primary-foreground aria-[current=location]:text-primary xl:w-28 xl:px-2 [&>svg]:size-6 [&>svg]:shrink-0',
);
</script>

<template>
    <header class="hidden border-b bg-primary text-primary-foreground lg:block">
        <div class="app-container flex items-center gap-4 py-2.5">
            <Link
                :href="auth.cashShiftAccess ? cashShift() : dashboard()"
                aria-label="Hostal Rincón"
                class="flex shrink-0 items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-primary-foreground"
            >
                <AppLogo />
            </Link>

            <NavigationMenu
                aria-label="Menú principal"
                :class="cn('max-w-none justify-start', menuDividerClass)"
            >
                <NavigationMenuList class="justify-start gap-0.5 xl:gap-1">
                    <NavigationMenuItem
                        v-for="item in mainNavItems"
                        :key="item.title"
                    >
                        <Link
                            :href="item.href"
                            :class="menuItemClass"
                            :aria-current="
                                isCurrentOrParentUrl(item.href)
                                    ? 'location'
                                    : undefined
                            "
                        >
                            <component
                                v-if="item.icon"
                                :is="item.icon"
                                aria-hidden="true"
                            />
                            <span>{{ item.title }}</span>
                        </Link>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>

            <NavigationMenu
                aria-label="Ajustes y sesión"
                :class="cn('flex-none', menuDividerClass)"
            >
                <NavigationMenuList class="gap-0.5 xl:gap-1">
                    <NavigationMenuItem v-if="!auth.cashShiftAccess">
                        <Link
                            :href="editProfile()"
                            :class="menuItemClass"
                            :aria-current="
                                settingsActive ? 'location' : undefined
                            "
                        >
                            <Settings aria-hidden="true" />
                            <span>Ajustes</span>
                        </Link>
                    </NavigationMenuItem>
                    <NavigationMenuItem>
                        <LogoutLink
                            :class="menuItemClass"
                            data-test="logout-button"
                        >
                            <LogOut aria-hidden="true" />
                            Cambiar de sesión
                        </LogoutLink>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>
        </div>
    </header>

    <div v-if="props.breadcrumbs.length > 1" class="hidden border-b lg:block">
        <div class="app-container flex h-10 items-center text-muted-foreground">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </div>
</template>
