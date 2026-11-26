<script setup lang="ts">
import AppBottomNavigation from '@/components/AppBottomNavigation.vue';
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import AppStatusBar from '@/components/AppStatusBar.vue';
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
        <div
            class="flex flex-1 flex-col [--app-status-bar-height:calc(2.25rem+env(safe-area-inset-bottom))] lg:pb-(--app-status-bar-height)"
        >
            <AppStatusBar />
            <AppHeader :breadcrumbs="breadcrumbs" />
            <AppContent variant="header">
                <slot />
            </AppContent>
            <AppBottomNavigation />
            <Toaster
                :offset="{
                    bottom: 'calc(1rem + var(--app-status-bar-height))',
                }"
                :mobile-offset="{
                    bottom: 'calc(5rem + env(safe-area-inset-bottom))',
                }"
            />
        </div>
    </AppShell>
</template>
