<script setup lang="ts">
import { formatUsd } from '@/lib/money';

defineProps<{ rows: { label: string; amount: number | null }[] }>();
</script>

<template>
    <dl
        class="grid grid-cols-2 gap-x-4 gap-y-3 border-y py-3 sm:flex sm:flex-wrap sm:gap-x-10"
    >
        <div v-for="row in rows" :key="row.label" class="min-w-0">
            <dt class="text-sm text-muted-foreground">{{ row.label }}</dt>
            <dd class="text-base font-semibold wrap-anywhere tabular-nums">
                {{
                    row.amount === null
                        ? 'Sin saldo inicial'
                        : formatUsd(row.amount)
                }}
            </dd>
        </div>
    </dl>
</template>
