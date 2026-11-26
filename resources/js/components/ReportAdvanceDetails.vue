<script setup lang="ts">
import { h } from 'vue';
import DataTable from '@/components/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { formatUsd } from '@/lib/money';
import type { DataTableColumn } from '@/types/data-table';
import type { ReportAdvance } from '@/types/monthly-report';

defineProps<{ rows: ReportAdvance[] }>();

function payment(advance: ReportAdvance) {
    return advance.paymentMethod === 'cash' ? 'Efectivo' : 'Transferencia';
}

function status(advance: ReportAdvance) {
    return h(
        Badge,
        { variant: advance.deductedOn ? 'success' : 'warning' },
        () => (advance.deductedOn ? 'Descontado' : 'Pendiente'),
    );
}

function details(text: string, lines: (string | null)[]) {
    return h('div', { class: 'max-w-56 wrap-break-word whitespace-normal' }, [
        h('p', { class: 'font-medium' }, text),
        ...lines
            .filter((line): line is string => Boolean(line))
            .map((line) =>
                h('p', { class: 'text-xs text-muted-foreground' }, line),
            ),
    ]);
}

const columns: DataTableColumn<ReportAdvance>[] = [
    {
        id: 'employee',
        header: 'Empleado',
        cell: ({ row }) => details(row.original.employee, [row.original.note]),
    },
    { id: 'issued', header: 'Entregado', accessorKey: 'issuedOn' },
    {
        id: 'payment',
        header: 'Forma de pago',
        cell: ({ row: { original: advance } }) =>
            details(payment(advance), [
                advance.account,
                advance.voucher ? 'Voucher ' + advance.voucher : null,
            ]),
    },
    {
        id: 'recorder',
        header: 'Registró',
        cell: ({ row }) => row.original.recorder ?? '—',
    },
    {
        id: 'status',
        header: 'Estado y descuento',
        cell: ({ row: { original: advance } }) =>
            h('div', { class: 'grid gap-1' }, [
                status(advance),
                advance.deductedOn
                    ? details(advance.deductedOn, [advance.deductor])
                    : null,
            ]),
    },
    {
        id: 'amount',
        header: 'Importe',
        meta: { align: 'right' },
        cell: ({ row }) => formatUsd(row.original.amountCents),
    },
];
</script>

<template>
    <div class="hidden lg:block">
        <DataTable
            caption="Detalle de adelantos"
            :rows="rows"
            :columns="columns"
        />
    </div>
    <ul aria-label="Detalle de adelantos" class="divide-y lg:hidden">
        <li
            v-for="row in rows"
            :key="row.id"
            class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 py-3"
        >
            <div
                class="grid gap-1 text-xs wrap-break-word text-muted-foreground"
            >
                <p class="text-sm font-medium text-foreground">
                    {{ row.employee }}
                </p>
                <p>Entregado el {{ row.issuedOn }} · {{ payment(row) }}</p>
                <p v-if="row.account">{{ row.account }}</p>
                <p v-if="row.voucher">Voucher {{ row.voucher }}</p>
                <p v-if="row.recorder">Registró {{ row.recorder }}</p>
                <p v-if="row.deductedOn">
                    Descontado el {{ row.deductedOn
                    }}<template v-if="row.deductor">
                        · {{ row.deductor }}</template
                    >
                </p>
                <p v-if="row.note">{{ row.note }}</p>
            </div>
            <div class="grid justify-items-end gap-1">
                <p class="font-semibold tabular-nums">
                    {{ formatUsd(row.amountCents) }}
                </p>
                <Badge :variant="row.deductedOn ? 'success' : 'warning'">
                    {{ row.deductedOn ? 'Descontado' : 'Pendiente' }}
                </Badge>
            </div>
        </li>
    </ul>
</template>
