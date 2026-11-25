<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Alert } from '@/components/ui/alert';
import { index as cashShift } from '@/routes/cash-closings';

const page = usePage();
const shift = computed(() => page.props.auth.activeCashShift);
const status = computed(() =>
    shift.value?.awaitingReceipt ? 'Recepción pendiente' : 'Turno activo',
);
</script>

<template>
    <Alert
        v-if="shift"
        role="status"
        class="block rounded-none border-x-0 border-t-0 border-amber-300 bg-amber-100 p-0 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
    >
        <Link
            :href="cashShift()"
            class="app-container flex min-h-11 items-center gap-3 py-2 hover:underline focus-visible:outline-2 focus-visible:-outline-offset-2"
        >
            <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 wrap-break-word">
                <strong>{{ status }}</strong> ·
                {{ shift.awaitingReceipt ? 'Entregó' : 'Responsable:' }}
                {{ shift.responsible }}
            </span>
            <ChevronRight class="size-4 shrink-0" aria-hidden="true" />
        </Link>
    </Alert>
</template>
