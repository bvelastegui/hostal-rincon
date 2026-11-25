<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, h } from 'vue';
import DataTable from '@/components/DataTable.vue';
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
import type { DataTableColumn } from '@/types/data-table';

const props = withDefaults(
    defineProps<{
        caption: string;
        movements: CashMovement[];
        showDate?: boolean;
        canManage?: boolean;
    }>(),
    { canManage: true },
);

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

const columns = computed<DataTableColumn<CashMovement>[]>(() => {
    const visible: DataTableColumn<CashMovement>[] = [];

    if (props.showDate) {
        visible.push({ id: 'date', header: 'Fecha', accessorKey: 'date' });
    }

    visible.push(
        {
            id: 'description',
            header: 'Movimiento',
            cell: ({ row: { original: movement } }) =>
                details(
                    movement.description,
                    movement.kind === 'deposit'
                        ? movementLabels.deposit
                        : movementLabels[movement.kind] +
                              ' · ' +
                              movementMethod(movement),
                ),
        },
        {
            id: 'reference',
            header: 'Cuenta y voucher',
            cell: ({ row: { original: movement } }) => {
                const origin = movement.originBankName
                    ? 'Origen: ' + movement.originBankName
                    : '';
                const account = movement.accountBankName
                    ? (movement.kind === 'expense' ? 'Origen: ' : 'Destino: ') +
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
                details(
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
                const statusDate =
                    movement.returnedOn ??
                    (movement.kind === 'deposit' ? movement.confirmedOn : null);
                return h('div', [
                    h(
                        Badge,
                        {
                            variant: movement.confirmed
                                ? 'outline'
                                : 'secondary',
                        },
                        () => movementStatus(movement),
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

    if (props.canManage && props.movements.some(movementAction)) {
        visible.push({
            id: 'action',
            header: 'Acción',
            meta: { align: 'right' },
            cell: ({ row }) => {
                const label = movementAction(row.original);
                return label
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
                    : null;
            },
        });
    }

    return visible;
});
</script>

<template>
    <DataTable :caption="caption" :rows="movements" :columns="columns" />
</template>
