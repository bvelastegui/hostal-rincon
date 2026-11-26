<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CashMovementList from '@/components/CashMovementList.vue';
import CashMovementsTable from '@/components/CashMovementsTable.vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import ReportAdvanceDetails from '@/components/ReportAdvanceDetails.vue';
import ReportTotals from '@/components/ReportTotals.vue';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatUsd } from '@/lib/money';
import { monthly } from '@/routes/reports';
import type { CashMovement } from '@/types/cash-movement';
import type { DataTablePage } from '@/types/data-table';
import type {
    ReportAdvance,
    ReportBalances,
    ReportEmployeeTotals,
    ReportMovementTotals,
    ReportSection,
} from '@/types/monthly-report';

const props = defineProps<{
    month: string;
    report: ReportSection;
    balances: ReportBalances;
    movements: ReportMovementTotals[];
    advances: ReportEmployeeTotals[];
    movementDetails: DataTablePage<CashMovement> | null;
    advanceDetails: DataTablePage<ReportAdvance> | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reportes', href: monthly() }] },
});

const reports = {
    saldos: 'Saldos actuales',
    ingresos: 'Ingresos',
    gastos: 'Gastos',
    depositos: 'Depósitos al banco',
    adelantos: 'Adelantos por empleado',
} satisfies Record<ReportSection, string>;

const monthLabel = computed(() =>
    new Intl.DateTimeFormat('es-EC', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(props.month + '-01T00:00:00Z')),
);

const balanceRows = computed(() => [
    { label: 'Efectivo', amount: props.balances.cash },
    { label: 'Bancos', amount: props.balances.bank },
    { label: 'En tránsito', amount: props.balances.transit },
    { label: 'Total actual', amount: props.balances.total },
]);

const isOperationReport = computed(
    () => props.report === 'ingresos' || props.report === 'gastos',
);

const paymentMethods = {
    cash: 'Efectivo',
    transfer: 'Transferencia bancaria',
} as const;

const operationRows = computed(() => {
    const kind = props.report === 'ingresos' ? 'income' : 'expense';

    return Object.entries(paymentMethods).map(([method, payment]) => {
        const totals = props.movements.find(
            (row) => row.kind === kind && row.method === method,
        );

        return {
            payment,
            confirmed: totals?.confirmedCents ?? 0,
            pending: totals?.pendingCents ?? 0,
        };
    });
});

const operationTotals = computed(() => {
    const confirmed = operationRows.value.reduce(
        (sum, row) => sum + row.confirmed,
        0,
    );
    const pending = operationRows.value.reduce(
        (sum, row) => sum + row.pending,
        0,
    );

    return { confirmed, pending, registered: confirmed + pending };
});

const movementDetailTitle = computed(
    () => 'Detalle de ' + reports[props.report].toLowerCase(),
);

const depositRows = computed(() => {
    const deposits = props.movements.filter((row) => row.kind === 'deposit');
    const confirmed = deposits.reduce(
        (sum, row) => sum + row.confirmedCents,
        0,
    );
    const pending = deposits.reduce((sum, row) => sum + row.pendingCents, 0);
    const returned = deposits.reduce((sum, row) => sum + row.returnedCents, 0);
    return [
        { label: 'Confirmados en banco', amount: confirmed },
        { label: 'En tránsito', amount: pending },
        { label: 'Devueltos a caja', amount: returned },
        {
            label: 'Total de retiros registrados',
            amount: confirmed + pending + returned,
        },
    ];
});

const advanceColumns = [
    {
        key: 'issuedCashCents',
        label: 'Efectivo',
        totalLabel: 'Entregado en efectivo',
    },
    {
        key: 'issuedTransferCents',
        label: 'Transferencia',
        totalLabel: 'Entregado por transferencia',
    },
    { key: 'issuedCents', label: 'Entregado', totalLabel: 'Total entregado' },
    {
        key: 'deductedCents',
        label: 'Descontado',
        totalLabel: 'Total descontado',
    },
    {
        key: 'pendingCents',
        label: 'Pendiente actual',
        totalLabel: 'Total pendiente actual',
    },
] as const;

const advanceTotals = computed(() =>
    advanceColumns.map(({ key, totalLabel }) => ({
        label: totalLabel,
        amount: props.advances.reduce(
            (sum, employee) => sum + employee[key],
            0,
        ),
    })),
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-5">
        <Head :title="reports[report]" />

        <h1 class="text-2xl font-semibold tracking-tight">Reportes</h1>
        <nav
            aria-label="Tipos de reporte"
            class="grid grid-cols-2 gap-2 border-b pb-4 sm:flex sm:flex-wrap"
        >
            <Button
                v-for="(label, value) in reports"
                :key="value"
                as-child
                :variant="value === report ? 'default' : 'outline'"
                class="min-h-11 min-w-0 whitespace-normal"
            >
                <Link
                    :href="monthly({ query: { report: value, month } })"
                    :aria-current="value === report ? 'page' : undefined"
                >
                    {{ label }}
                </Link>
            </Button>
        </nav>

        <section
            aria-labelledby="report-title"
            class="flex min-w-0 flex-col gap-5"
        >
            <header
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="grid gap-1">
                    <h2 id="report-title" class="text-xl font-semibold">
                        {{ reports[report] }}
                    </h2>
                    <p
                        v-if="report === 'saldos'"
                        class="text-sm text-muted-foreground"
                    >
                        Disponibilidad al momento de la consulta.
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ monthLabel }}
                    </p>
                </div>
                <Form
                    novalidate
                    v-if="report !== 'saldos'"
                    v-bind="monthly.form()"
                    v-slot="{ errors, processing }"
                    class="sm:w-72 sm:shrink-0"
                >
                    <input type="hidden" name="report" :value="report" />
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
                                    errors.month
                                        ? 'report-month-error'
                                        : undefined
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

            <template v-if="report === 'saldos'">
                <ReportTotals :rows="balanceRows" />
                <h3 class="text-base font-semibold">
                    Saldos por cuenta bancaria
                </h3>
                <Table
                    v-if="balances.accounts.length"
                    aria-label="Saldos por cuenta bancaria"
                >
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Banco</TableHead>
                            <TableHead scope="col">Cuenta</TableHead>
                            <TableHead scope="col" class="text-right"
                                >Saldo actual</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="account in balances.accounts"
                            :key="account.id"
                        >
                            <TableCell class="whitespace-normal">{{
                                account.bank
                            }}</TableCell>
                            <TableCell class="whitespace-normal">{{
                                account.name
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(account.balanceCents)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colspan="2">Total en bancos</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(balances.bank)
                            }}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
                <p v-else class="text-sm text-muted-foreground">
                    No hay cuentas bancarias registradas.
                </p>
            </template>

            <template v-if="isOperationReport">
                <Table :aria-label="reports[report]">
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Forma de pago</TableHead>
                            <TableHead scope="col" class="text-right"
                                >Confirmado</TableHead
                            >
                            <TableHead scope="col" class="text-right"
                                >Pendiente</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in operationRows"
                            :key="row.payment"
                        >
                            <TableCell class="whitespace-normal">{{
                                row.payment
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(row.confirmed)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(row.pending)
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell
                                >Total de
                                {{ reports[report].toLowerCase() }}</TableCell
                            >
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(operationTotals.confirmed)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatUsd(operationTotals.pending)
                            }}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
                <ReportTotals
                    :rows="[
                        {
                            label: 'Total registrado',
                            amount: operationTotals.registered,
                        },
                    ]"
                />
            </template>

            <template v-if="report === 'depositos'">
                <p class="text-sm text-muted-foreground">
                    Retiros de efectivo iniciados en el mes, según su estado
                    actual.
                </p>
                <ReportTotals :rows="depositRows" />
            </template>

            <template v-if="movementDetails">
                <div class="grid gap-1">
                    <h3 class="text-base font-semibold">
                        {{ movementDetailTitle }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Los movimientos anulados se muestran en el detalle y se
                        excluyen de los totales.
                    </p>
                </div>
                <template v-if="movementDetails.data.length">
                    <div class="hidden lg:block">
                        <CashMovementsTable
                            :caption="movementDetailTitle"
                            :movements="movementDetails.data"
                            show-date
                            read-only
                        />
                    </div>
                    <div class="lg:hidden">
                        <CashMovementList
                            :movements="movementDetails.data"
                            show-date
                            read-only
                        />
                    </div>
                </template>
                <p
                    v-else-if="movementDetails.total === 0"
                    class="text-sm text-muted-foreground"
                >
                    No hay movimientos para este mes.
                </p>
                <DataTablePagination
                    label="Páginas del reporte de movimientos"
                    :page="movementDetails"
                />
            </template>

            <template v-if="report === 'adelantos'">
                <p class="text-sm text-muted-foreground">
                    Las entregas y descuentos corresponden al mes seleccionado.
                    El pendiente actual incluye adelantos de cualquier mes.
                </p>
                <ReportTotals :rows="advanceTotals" />
                <h3 class="text-base font-semibold">Totales por empleado</h3>
                <Table
                    v-if="advances.length"
                    aria-label="Totales de adelantos por empleado"
                >
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Empleado</TableHead>
                            <TableHead
                                v-for="column in advanceColumns"
                                :key="column.key"
                                scope="col"
                                class="text-right"
                                >{{ column.label }}</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="(employee, index) in advances"
                            :key="index"
                        >
                            <TableCell class="whitespace-normal">{{
                                employee.name
                            }}</TableCell>
                            <TableCell
                                v-for="column in advanceColumns"
                                :key="column.key"
                                class="text-right tabular-nums"
                            >
                                {{ formatUsd(employee[column.key]) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p v-else class="text-sm text-muted-foreground">
                    No hay adelantos para este reporte.
                </p>

                <template v-if="advanceDetails">
                    <h3 class="text-base font-semibold">
                        Detalle de adelantos
                    </h3>
                    <ReportAdvanceDetails
                        v-if="advanceDetails.data.length"
                        :rows="advanceDetails.data"
                    />
                    <DataTablePagination
                        label="Páginas del reporte de adelantos"
                        :page="advanceDetails"
                    />
                </template>
            </template>
        </section>
    </div>
</template>
