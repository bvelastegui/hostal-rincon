<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    movementAction,
    movementAmount,
    movementLabels,
    movementMethod,
    movementStatus,
} from '@/lib/cash-movement';
import { edit } from '@/routes/movements';
import type { CashMovement } from '@/types/cash-movement';

withDefaults(
    defineProps<{
        movements: CashMovement[];
        showDate?: boolean;
        canManage?: boolean;
    }>(),
    { canManage: true },
);
</script>

<template>
    <ul class="divide-y">
        <li
            v-for="movement in movements"
            :key="movement.id"
            class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 py-3 sm:gap-4"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <p class="font-medium wrap-break-word">
                    {{ movement.description }}
                </p>
                <p class="text-xs wrap-break-word text-muted-foreground">
                    {{ movementLabels[movement.kind] }}
                    <template v-if="movement.kind !== 'deposit'">
                        ·
                        {{ movementMethod(movement) }}
                    </template>
                </p>
                <p
                    v-if="movement.originBankName"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Origen: {{ movement.originBankName }}
                </p>
                <p
                    v-if="movement.accountBankName"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    {{ movement.kind === 'expense' ? 'Origen' : 'Destino' }}:
                    {{ movement.accountBankName }} · {{ movement.accountName }}
                </p>
                <p v-if="showDate" class="text-xs text-muted-foreground">
                    {{ movement.kind === 'deposit' ? 'Retiro del' : 'Fecha:' }}
                    {{ movement.date }}
                </p>
                <p
                    v-if="movement.kind === 'deposit' && movement.confirmedOn"
                    class="text-xs text-muted-foreground"
                >
                    Acreditado el {{ movement.confirmedOn }}
                </p>
                <p
                    v-if="movement.returnedOn"
                    class="text-xs text-muted-foreground"
                >
                    Devuelto a efectivo el {{ movement.returnedOn }}
                </p>
                <p
                    v-if="movement.voucher"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Voucher {{ movement.voucher }}
                </p>
                <p
                    v-if="movement.responsibleName"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Responsable del efectivo: {{ movement.responsibleName }}
                </p>
                <p class="text-xs wrap-break-word text-muted-foreground">
                    Registró {{ movement.recorderName }}
                    <template v-if="movement.shiftId">
                        · Turno #{{ movement.shiftId }}</template
                    >
                </p>
            </div>
            <div class="flex flex-col items-end gap-2">
                <p class="font-semibold whitespace-nowrap tabular-nums">
                    {{ movementAmount(movement) }}
                </p>
                <Badge
                    v-if="!movement.confirmed && !movement.returnedOn"
                    variant="secondary"
                >
                    {{ movementStatus(movement) }}
                </Badge>
                <Button
                    v-if="
                        canManage && !movement.confirmed && !movement.returnedOn
                    "
                    as-child
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                >
                    <Link :href="edit(movement.id)">
                        {{ movementAction(movement) }}
                    </Link>
                </Button>
            </div>
        </li>
    </ul>
</template>
