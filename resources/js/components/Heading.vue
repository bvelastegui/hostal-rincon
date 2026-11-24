<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    hideTitleOnMobile?: boolean;
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header
        :class="[
            variant === 'small' ? '' : 'mb-8 space-y-0.5',
            hideTitleOnMobile && !description && 'max-lg:sr-only',
        ]"
    >
        <h2
            :class="[
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight',
                hideTitleOnMobile && description && 'max-lg:sr-only',
            ]"
        >
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
