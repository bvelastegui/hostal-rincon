<script setup lang="ts">
import RecordConfirmationDialog from '@/components/RecordConfirmationDialog.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const {
    title = '',
    description = '',
    wide = false,
} = defineProps<{
    title?: string;
    description?: string;
    wide?: boolean;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description" :wide="wide">
        <slot />
        <RecordConfirmationDialog />
    </AuthLayout>
</template>
