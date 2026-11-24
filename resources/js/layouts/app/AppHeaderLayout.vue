<script setup lang="ts">
import AppBottomNavigation from '@/components/AppBottomNavigation.vue';
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="header">
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent variant="header">
            <slot />
        </AppContent>
        <AppBottomNavigation />
        <Toaster
            :mobile-offset="{
                bottom: 'calc(5rem + env(safe-area-inset-bottom))',
            }"
        />
    </AppShell>
</template>
