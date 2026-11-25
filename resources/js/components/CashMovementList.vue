<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import CashMovementCorrectionLink from '@/components/CashMovementCorrectionLink.vue';
import CashMovementSensitivityButton from '@/components/CashMovementSensitivityButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    movementAccountRole,
    movementAction,
    movementAmount,
    movementAuditLines,
    movementLabels,
    movementMethod,
    movementStatusLabels,
    movementStatusVariants,
} from '@/lib/cash-movement';
import { edit } from '@/routes/movements';
import type { MovementRow } from '@/types/cash-movement';

withDefaults(
    defineProps<{
        movements: MovementRow[];
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
            <div v-if="movement.redacted" class="flex min-w-0 flex-col gap-1">
                <p class="font-medium">{{ movementLabels[movement.kind] }}</p>
                <p
                    v-if="movement.account"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Cuenta: {{ movement.account }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Fecha: {{ movement.date }}
                </p>
                <p class="text-xs wrap-break-word text-muted-foreground">
                    Registró {{ movement.recorderName }}
                </p>
            </div>
            <div v-else class="flex min-w-0 flex-col gap-1">
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
                    {{ movementAccountRole(movement.kind) }}:
                    {{ movement.accountBankName }} ·
                    {{ movement.accountName }}
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
                <p
                    v-for="line in movementAuditLines(movement)"
                    :key="line"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    {{ line }}
                </p>
                <p
                    v-if="movement.isSensitive"
                    class="text-xs text-muted-foreground"
                >
                    Movimiento sensible
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
                <Badge :variant="movementStatusVariants[movement.status]">
                    {{ movementStatusLabels[movement.status] }}
                </Badge>
                <Button
                    v-if="canManage && movementAction(movement)"
                    as-child
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                >
                    <Link :href="edit(movement.id)">
                        {{ movementAction(movement) }}
                    </Link>
                </Button>
                <CashMovementCorrectionLink
                    v-if="!movement.redacted"
                    :movement="movement"
                />
                <CashMovementSensitivityButton
                    v-if="!movement.redacted"
                    :movement="movement"
                />
            </div>
            <Collapsible
                v-if="movement.children?.length"
                v-slot="{ open }"
                :default-open="movement.expandHistory"
                class="col-span-full"
            >
                <CollapsibleTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="min-h-10"
                        :aria-label="
                            'Historial de correcciones del movimiento #' +
                            movement.id
                        "
                    >
                        <component
                            :is="open ? ChevronDown : ChevronRight"
                            data-icon="inline-start"
                            aria-hidden="true"
                        />
                        Historial de correcciones
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent class="border-s ps-3">
                    <CashMovementList
                        :movements="movement.children"
                        :show-date="showDate"
                        :can-manage="false"
                    />
                </CollapsibleContent>
            </Collapsible>
        </li>
    </ul>
</template>
