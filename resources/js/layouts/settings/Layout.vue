<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
} from '@/components/ui/navigation-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { index as backups } from '@/routes/backups';
import { edit as editBanks } from '@/routes/bank-settings';
import { edit as editCash } from '@/routes/cash-settings';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const page = usePage();
const settingsLinks = computed(() => [
    { title: 'Perfil', href: editProfile() },
    { title: 'Seguridad', href: editSecurity() },
    ...(page.props.auth.canManageCashSettings
        ? [
              { title: 'Efectivo', href: editCash() },
              { title: 'Bancos', href: editBanks() },
          ]
        : []),
    ...(page.props.auth.canManageLocalBackups
        ? [{ title: 'Respaldos', href: backups() }]
        : []),
]);

const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex flex-col gap-6">
        <header class="max-lg:border-b">
            <h1 class="text-2xl font-semibold tracking-tight max-lg:mb-4">
                Ajustes
            </h1>
            <NavigationMenu
                :viewport="false"
                aria-label="Secciones de ajustes"
                class="w-full max-w-none lg:hidden [&>div]:w-full"
            >
                <NavigationMenuList class="w-full flex-wrap gap-0">
                    <NavigationMenuItem
                        v-for="item in settingsLinks"
                        :key="item.title"
                        class="flex-auto sm:flex-1"
                    >
                        <NavigationMenuLink
                            as-child
                            :active="isCurrentOrParentUrl(item.href)"
                            class="min-h-11 w-full items-center justify-center rounded-none border-b-2 border-transparent px-1 text-xs font-medium data-active:border-primary sm:px-4 sm:text-sm"
                        >
                            <Link :href="item.href">{{ item.title }}</Link>
                        </NavigationMenuLink>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>
        </header>

        <div class="lg:flex lg:items-start lg:gap-12">
            <aside class="hidden w-48 shrink-0 lg:block">
                <nav
                    aria-label="Secciones de ajustes"
                    class="flex flex-col gap-1"
                >
                    <Button
                        v-for="item in settingsLinks"
                        :key="item.title"
                        variant="ghost"
                        class="w-full justify-start [&[aria-current]]:bg-muted"
                        as-child
                    >
                        <Link
                            :href="item.href"
                            :aria-current="
                                isCurrentUrl(item.href)
                                    ? 'page'
                                    : isCurrentOrParentUrl(item.href)
                                      ? 'location'
                                      : undefined
                            "
                        >
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <slot />
            </div>
        </div>
    </div>
</template>
