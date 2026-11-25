<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatUsd } from '@/lib/money';
import { monthly } from '@/routes/reports';

type MovementTotals = {
    kind: string;
    method: string;
    confirmedCents: number;
    pendingCents: number;
    returnedCents: number;
};

const props = defineProps<{
    month: string;
    balances: {
        cash: number | null;
        bank: number;
        transit: number;
        total: number | null;
        accounts: {
            id: number;
            name: string;
            bank: string;
            balanceCents: number;
        }[];
    };
    movements: MovementTotals[];
    advances: {
        name: string;
        issuedCents: number;
        issuedCashCents: number;
        issuedTransferCents: number;
        deductedCents: number;
        pendingCents: number;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reporte mensual', href: monthly() }],
    },
});

const balanceRows = computed(() => [
    { label: 'Efectivo', amount: props.balances.cash },
    { label: 'En tránsito', amount: props.balances.transit },
    { label: 'Bancos', amount: props.balances.bank },
    { label: 'Total', amount: props.balances.total },
]);

const movementRows = computed(() =>
    [
        { label: 'Cobros en efectivo', kind: 'income', method: 'cash' },
        {
            label: 'Cobros por transferencia',
            kind: 'income',
            method: 'transfer',
        },
        { label: 'Gastos en efectivo', kind: 'expense', method: 'cash' },
        {
            label: 'Gastos por transferencia',
            kind: 'expense',
            method: 'transfer',
        },
    ].map(({ label, kind, method }) => {
        const totals = props.movements.find(
            (row) => row.kind === kind && row.method === method,
        );

        return {
            label,
            confirmed: totals?.confirmedCents ?? 0,
            pending: totals?.pendingCents ?? 0,
        };
    }),
);

const depositRows = computed(() => {
    const totals = props.movements.find((row) => row.kind === 'deposit');

    return [
        { label: 'Confirmados en banco', amount: totals?.confirmedCents ?? 0 },
        { label: 'En tránsito', amount: totals?.pendingCents ?? 0 },
        { label: 'Devueltos a caja', amount: totals?.returnedCents ?? 0 },
    ];
});

const advancePayments = computed(() => [
    {
        label: 'Entregado en efectivo',
        amount: props.advances.reduce(
            (sum, employee) => sum + employee.issuedCashCents,
            0,
        ),
    },
    {
        label: 'Entregado por transferencia',
        amount: props.advances.reduce(
            (sum, employee) => sum + employee.issuedTransferCents,
            0,
        ),
    },
]);

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('es-EC', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(props.month + '-01T00:00:00Z')),
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-8">
        <Head title="Reporte mensual" />

        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Reporte mensual
                </h1>
                <p class="text-sm text-muted-foreground">
                    Resumen de operaciones de {{ monthLabel }}.
                </p>
            </div>
            <Form
                v-bind="monthly.form()"
                v-slot="{ errors, processing }"
                class="sm:w-72 sm:shrink-0"
            >
                <FieldGroup
                    class="gap-3 sm:grid sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                >
                    <Field class="gap-1.5" :data-invalid="!!errors.month">
                        <FieldLabel for="report-month"
                            >Mes calendario</FieldLabel
                        >
                        <Input
                            id="report-month"
                            :key="month"
                            :default-value="month"
                            name="month"
                            type="month"
                            pattern="[1-9][0-9]{3}-(0[1-9]|1[0-2])"
                            placeholder="AAAA-MM"
                            required
                            :aria-invalid="!!errors.month"
                            :aria-describedby="
                                errors.month ? 'report-month-error' : undefined
                            "
                            class="min-h-11"
                        />
                        <FieldError
                            v-if="errors.month"
                            id="report-month-error"
                            :errors="[errors.month]"
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        class="min-h-11 w-full sm:mt-6 sm:w-auto"
                    >
                        Ver mes
                    </Button>
                </FieldGroup>
            </Form>
        </header>

        <section aria-labelledby="report-balances" class="flex flex-col gap-3">
            <div>
                <h2 id="report-balances" class="text-lg font-semibold">
                    Saldos actuales
                </h2>
                <p class="text-sm text-muted-foreground">
                    Disponibilidad al momento de la consulta, independiente del
                    mes elegido.
                </p>
            </div>
            <dl
                class="grid grid-cols-2 gap-x-4 gap-y-3 border-y py-3 sm:grid-cols-4"
            >
                <div
                    v-for="row in balanceRows"
                    :key="row.label"
                    class="min-w-0"
                >
                    <dt class="text-sm text-muted-foreground">
                        {{ row.label }}
                    </dt>
                    <dd
                        class="text-base font-semibold wrap-anywhere tabular-nums sm:text-lg"
                    >
                        {{
                            row.amount === null
                                ? 'Sin saldo inicial'
                                : formatUsd(row.amount)
                        }}
                    </dd>
                </div>
            </dl>
            <ul
                v-if="balances.accounts.length"
                aria-label="Saldos por cuenta"
                class="divide-y"
            >
                <li
                    v-for="account in balances.accounts"
                    :key="account.id"
                    class="flex items-baseline justify-between gap-3 py-2 text-sm"
                >
                    <span class="min-w-0 wrap-anywhere"
                        >{{ account.bank }} · {{ account.name }}</span
                    >
                    <span class="shrink-0 font-medium tabular-nums">{{
                        formatUsd(account.balanceCents)
                    }}</span>
                </li>
            </ul>
        </section>

        <div
            class="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        >
            <section
                aria-labelledby="report-operations"
                class="flex min-w-0 flex-col gap-3 border-t pt-4"
            >
                <div>
                    <h2 id="report-operations" class="text-lg font-semibold">
                        Cobros y gastos del mes
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Movimientos agrupados por fecha de registro y estado
                        actual.
                    </p>
                </div>
                <Table aria-labelledby="report-operations">
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col" class="px-1.5"
                                >Concepto</TableHead
                            >
                            <TableHead scope="col" class="px-1.5 text-right"
                                >Confirmado</TableHead
                            >
                            <TableHead scope="col" class="px-1.5 text-right"
                                >Pendiente</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in movementRows" :key="row.label">
                            <TableHead
                                scope="row"
                                class="h-auto px-1.5 py-2 whitespace-normal"
                                >{{ row.label }}</TableHead
                            >
                            <TableCell class="px-1.5 text-right tabular-nums">{{
                                formatUsd(row.confirmed)
                            }}</TableCell>
                            <TableCell class="px-1.5 text-right tabular-nums">{{
                                formatUsd(row.pending)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </section>

            <section
                aria-labelledby="report-deposits"
                class="flex min-w-0 flex-col gap-3 border-t pt-4"
            >
                <div>
                    <h2 id="report-deposits" class="text-lg font-semibold">
                        Depósitos al banco
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Traslados de efectivo iniciados en el mes, según su
                        estado actual.
                    </p>
                </div>
                <dl class="divide-y">
                    <div
                        v-for="row in depositRows"
                        :key="row.label"
                        class="flex items-baseline justify-between gap-3 py-2 text-sm"
                    >
                        <dt>{{ row.label }}</dt>
                        <dd class="shrink-0 font-medium tabular-nums">
                            {{ formatUsd(row.amount) }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section
            aria-labelledby="report-advances"
            class="flex min-w-0 flex-col gap-3 border-t pt-4"
        >
            <div>
                <h2 id="report-advances" class="text-lg font-semibold">
                    Adelantos por empleado
                </h2>
                <p class="text-sm text-muted-foreground">
                    Entregas y descuentos del mes. El pendiente actual acumula
                    adelantos de cualquier mes.
                </p>
            </div>
            <dl
                v-if="advances.length"
                class="grid grid-cols-2 gap-3 border-y py-3"
            >
                <div v-for="payment in advancePayments" :key="payment.label">
                    <dt class="text-sm text-muted-foreground">
                        {{ payment.label }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        {{ formatUsd(payment.amount) }}
                    </dd>
                </div>
            </dl>
            <Table
                v-if="advances.length"
                aria-labelledby="report-advances"
                class="table-fixed text-xs sm:table-auto sm:text-sm"
            >
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col" class="px-1.5"
                            >Empleado</TableHead
                        >
                        <TableHead
                            scope="col"
                            class="px-1.5 text-right whitespace-normal"
                            >Entregado</TableHead
                        >
                        <TableHead
                            scope="col"
                            class="px-1.5 text-right whitespace-normal"
                            >Descontado</TableHead
                        >
                        <TableHead
                            scope="col"
                            class="px-1.5 text-right whitespace-normal"
                            >Pendiente actual</TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="(employee, index) in advances"
                        :key="index"
                    >
                        <TableHead
                            scope="row"
                            class="h-auto px-1.5 py-2 wrap-anywhere whitespace-normal"
                            >{{ employee.name }}</TableHead
                        >
                        <TableCell
                            class="px-1.5 text-right wrap-anywhere whitespace-normal tabular-nums"
                            >{{ formatUsd(employee.issuedCents) }}</TableCell
                        >
                        <TableCell
                            class="px-1.5 text-right wrap-anywhere whitespace-normal tabular-nums"
                            >{{ formatUsd(employee.deductedCents) }}</TableCell
                        >
                        <TableCell
                            class="px-1.5 text-right font-medium wrap-anywhere whitespace-normal tabular-nums"
                            >{{ formatUsd(employee.pendingCents) }}</TableCell
                        >
                    </TableRow>
                </TableBody>
            </Table>
            <p v-else class="text-sm text-muted-foreground">
                No hay entregas ni descuentos en el mes, ni adelantos
                pendientes.
            </p>
        </section>
    </div>
</template>
