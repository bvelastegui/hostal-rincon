<script setup lang="ts">
import RecordConfirmationDialog from '@/components/RecordConfirmationDialog.vue';
import AppLayout from '@/layouts/app/AppHeaderLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <RecordConfirmationDialog />
    </AppLayout>
</template>
