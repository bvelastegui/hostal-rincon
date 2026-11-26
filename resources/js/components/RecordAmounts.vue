<script setup lang="ts">
import type { ReviewAmount } from '@/lib/record-confirmation';

defineProps<{ amounts: ReviewAmount[] }>();
</script>

<template>
    <dl
        v-if="amounts.length"
        class="divide-y divide-primary/15 rounded-lg border border-primary/20 bg-primary/5 px-4"
    >
        <div
            v-for="amount in amounts"
            :key="amount.label"
            class="flex flex-col gap-1 py-3"
        >
            <dt class="text-sm font-medium text-muted-foreground">
                {{ amount.label }}
            </dt>
            <dd
                class="text-3xl leading-tight font-semibold wrap-break-word text-primary tabular-nums"
            >
                {{ amount.value }}
            </dd>
        </div>
    </dl>
</template>
