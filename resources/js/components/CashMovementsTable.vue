<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, h } from 'vue';
import CashMovementSensitivityButton from '@/components/CashMovementSensitivityButton.vue';
import DataTable from '@/components/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    movementAccountRole,
    movementAction,
    movementAmount,
    movementLabels,
    movementMethod,
    movementStatusLabels,
} from '@/lib/cash-movement';
import { edit } from '@/routes/movements';
import type { MovementRow } from '@/types/cash-movement';
import type { DataTableColumn } from '@/types/data-table';

const props = withDefaults(
    defineProps<{
        caption: string;
        movements: MovementRow[];
        showDate?: boolean;
        canManage?: boolean;
    }>(),
    { canManage: true },
);

const page = usePage();

function details(text: string, ...lines: string[]) {
    return h('div', { class: 'max-w-64 whitespace-normal wrap-break-word' }, [
        h('p', { class: 'font-medium' }, text),
        ...lines
            .filter(Boolean)
            .map((line) =>
                h('p', { class: 'text-xs text-muted-foreground' }, line),
            ),
    ]);
}

const columns = computed<DataTableColumn<MovementRow>[]>(() => {
    const visible: DataTableColumn<MovementRow>[] = [];

    if (props.showDate) {
        visible.push({
            id: 'date',
            header: 'Fecha',
            accessorKey: 'date',
        });
    }

    visible.push(
        {
            id: 'description',
            header: 'Movimiento',
            cell: ({ row: { original: movement } }) =>
                movement.redacted
                    ? details(movementLabels[movement.kind])
                    : details(
                          movement.description,
                          movement.kind === 'deposit'
                              ? movementLabels.deposit
                              : movementLabels[movement.kind] +
                                    ' · ' +
                                    movementMethod(movement),
                          movement.isSensitive ? 'Movimiento sensible' : '',
                      ),
        },
        {
            id: 'reference',
            header: 'Cuenta y voucher',
            cell: ({ row: { original: movement } }) => {
                if (movement.redacted) {
                    return movement.account ? details(movement.account) : '—';
                }

                const origin = movement.originBankName
                    ? 'Origen: ' + movement.originBankName
                    : '';
                const account = movement.accountBankName
                    ? movementAccountRole(movement.kind) +
                      ': ' +
                      movement.accountBankName +
                      (movement.accountName ? ' · ' + movement.accountName : '')
                    : '';
                const voucher = movement.voucher
                    ? 'Voucher ' + movement.voucher
                    : '';
                const [first, ...rest] = [origin, account, voucher].filter(
                    Boolean,
                );
                return first ? details(first, ...rest) : '—';
            },
        },
        {
            id: 'recorder',
            header: 'Registró',
            cell: ({ row: { original: movement } }) =>
                movement.redacted
                    ? details(movement.recorderName)
                    : details(
                          movement.recorderName,
                          movement.shiftId ? 'Turno #' + movement.shiftId : '',
                          movement.responsibleName
                              ? 'Custodia: ' + movement.responsibleName
                              : '',
                      ),
        },
        {
            id: 'status',
            header: 'Estado',
            cell: ({ row: { original: movement } }) => {
                const statusDate = movement.redacted
                    ? null
                    : (movement.returnedOn ??
                      (movement.kind === 'deposit'
                          ? movement.confirmedOn
                          : null));
                return h('div', [
                    h(
                        Badge,
                        {
                            variant:
                                movement.status === 'confirmed'
                                    ? 'outline'
                                    : 'secondary',
                        },
                        () => movementStatusLabels[movement.status],
                    ),
                    statusDate
                        ? h(
                              'p',
                              { class: 'mt-1 text-xs text-muted-foreground' },
                              statusDate,
                          )
                        : null,
                ]);
            },
        },
        {
            id: 'amount',
            header: 'Importe',
            meta: { align: 'right' },
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'font-semibold' },
                    movementAmount(row.original),
                ),
        },
    );

    const canMarkSensitivity =
        page.props.auth.canManageMovementSensitivity &&
        props.movements.some(
            (movement) => !movement.redacted && movement.kind !== 'advance',
        );

    if (
        canMarkSensitivity ||
        (props.canManage && props.movements.some(movementAction))
    ) {
        visible.push({
            id: 'action',
            header: 'Acción',
            meta: { align: 'right' },
            cell: ({ row }) => {
                const movement = row.original;
                if (movement.redacted) {
                    return null;
                }

                const label = props.canManage ? movementAction(movement) : null;
                return h('div', { class: 'flex flex-wrap justify-end gap-2' }, [
                    label
                        ? h(
                              Button,
                              { asChild: true, variant: 'outline', size: 'sm' },
                              {
                                  default: () =>
                                      h(
                                          Link,
                                          { href: edit(row.original.id) },
                                          { default: () => label },
                                      ),
                              },
                          )
                        : null,
                    h(CashMovementSensitivityButton, { movement }),
                ]);
            },
        });
    }

    return visible;
});
</script>

<template>
    <DataTable :caption="caption" :rows="movements" :columns="columns" />
</template>
