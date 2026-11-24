<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="flex flex-col gap-4">
        <header
            class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="flex flex-col gap-1.5">
                <h3 class="font-semibold">{{ title }}</h3>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <slot name="actions" />
        </header>
        <slot />
    </section>
</template>
