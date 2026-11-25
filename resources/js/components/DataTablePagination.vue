<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    label: string;
    previous: string | null;
    next: string | null;
}>();
</script>

<template>
    <nav
        v-if="previous || next"
        class="flex flex-wrap gap-2"
        :aria-label="label"
    >
        <Button v-if="previous" as-child variant="outline" class="min-h-11">
            <Link :href="previous" rel="prev">Anterior</Link>
        </Button>
        <Button v-if="next" as-child variant="outline" class="min-h-11">
            <Link :href="next" rel="next">Siguiente</Link>
        </Button>
    </nav>
</template>
