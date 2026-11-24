<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
} from '@/components/ui/navigation-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const settingsLinks = [
    { title: 'Perfil', href: editProfile() },
    { title: 'Seguridad', href: editSecurity() },
    { title: 'Apariencia', href: editAppearance() },
];

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <div class="space-y-6">
        <header class="border-b">
            <h1 class="mb-4 text-2xl font-semibold tracking-tight">Ajustes</h1>
            <NavigationMenu
                :viewport="false"
                aria-label="Secciones de ajustes"
                class="w-full max-w-none [&>div]:w-full"
            >
                <NavigationMenuList class="w-full gap-0">
                    <NavigationMenuItem
                        v-for="item in settingsLinks"
                        :key="item.title"
                        class="min-w-0 flex-1"
                    >
                        <NavigationMenuLink
                            as-child
                            :active="isCurrentUrl(item.href)"
                            class="min-h-11 w-full items-center justify-center rounded-none border-b-2 border-transparent font-medium data-active:border-primary"
                        >
                            <Link :href="item.href">{{ item.title }}</Link>
                        </NavigationMenuLink>
                    </NavigationMenuItem>
                </NavigationMenuList>
            </NavigationMenu>
        </header>

        <slot />
    </div>
</template>
