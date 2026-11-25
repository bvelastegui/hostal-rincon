<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { correction } from '@/routes/movements';
import type { CashMovement } from '@/types/cash-movement';

defineProps<{ movement: CashMovement }>();
</script>

<template>
    <Button
        v-if="movement.canCorrect"
        as-child
        variant="outline"
        size="sm"
        class="min-h-10 lg:min-h-8"
    >
        <Link :href="correction(movement.id)">Corregir</Link>
    </Button>
</template>
