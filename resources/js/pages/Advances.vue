<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, h, ref } from 'vue';
import {
    create,
    index,
    deduct,
    createEmployee,
    editEmployee,
} from '@/actions/App/Http/Controllers/AdvanceController';
import DataTable from '@/components/DataTable.vue';
import DataTableFilter from '@/components/DataTableFilter.vue';
import DataTableMonth from '@/components/DataTableMonth.vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { paymentOptions } from '@/lib/cash-movement';
import { formatUsd } from '@/lib/money';
import type { DataTableColumn, DataTablePage } from '@/types/data-table';

type Employee = {
    id: number;
    name: string;
    pendingCents: number;
    latestPendingId: number | null;
};

type Advance = {
    id: number;
    employee_name: string;
    amount_cents: number;
    issued_on: string;
    responsible: string;
    note: string | null;
    recorder_name: string;
    cash_movement: {
        payment_method: 'cash' | 'transfer';
        voucher: string | null;
        bank_account: { name: string; bank: { name: string } } | null;
    } | null;
    deducted_at: string | null;
    deductor_name: string | null;
};

const props = defineProps<{
    employees: Employee[];
    history: DataTablePage<Advance>;
    filters: {
        employee?: string | number;
        status?: string;
        month?: string;
        payment_method?: string;
    };
    limitCents: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adelantos', href: index() }] },
});

const date = new Intl.DateTimeFormat('es-EC', {
    dateStyle: 'medium',
    timeZone: 'America/Guayaquil',
});
const available = (employee: Employee) =>
    Math.max(0, props.limitCents - employee.pendingCents);
const all = 'all';
const filter = useForm({
    employee: String(props.filters.employee ?? all),
    status: props.filters.status ?? all,
    payment_method: props.filters.payment_method ?? all,
    month: props.filters.month ?? '',
});
const hasActiveFilters = computed(() =>
    Object.values(props.filters).some(Boolean),
);
const filterFields = computed(() => [
    {
        name: 'employee' as const,
        label: 'Empleado',
        options: [
            { value: all, label: 'Todos los empleados' },
            ...props.employees.map(({ id, name }) => ({
                value: String(id),
                label: name,
            })),
        ],
    },
    {
        name: 'status' as const,
        label: 'Estado',
        options: [
            { value: all, label: 'Todos los estados' },
            { value: 'pending', label: 'Pendiente' },
            { value: 'deducted', label: 'Descontado' },
        ],
    },
    {
        name: 'payment_method' as const,
        label: 'Forma de pago',
        options: [
            { value: all, label: 'Todas las formas de pago' },
            ...paymentOptions,
        ],
    },
]);

function changeFilter(
    name: 'employee' | 'status' | 'payment_method' | 'month',
    value: string,
): void {
    filter[name] = value;
    filter
        .transform((fields) =>
            Object.fromEntries(
                Object.entries(fields).filter(
                    ([, field]) => field && field !== all,
                ),
            ),
        )
        .get(index().url, { preserveScroll: true, preserveState: false });
}

const historyRows = computed(() =>
    props.history.data.map((advance) => {
        const movement = advance.cash_movement;
        const account = movement?.bank_account;

        return {
            id: advance.id,
            employee: advance.employee_name,
            date: advance.issued_on.slice(0, 10).split('-').reverse().join('/'),
            amount: formatUsd(advance.amount_cents),
            status: advance.deducted_at
                ? { variant: 'success' as const, label: 'Descontado' }
                : {
                      variant: 'warning' as const,
                      label: 'Pendiente de descuento',
                  },
            payment: [
                movement?.payment_method === 'transfer'
                    ? 'Transferencia bancaria'
                    : 'Efectivo',
                ...(account ? [account.bank.name + ' · ' + account.name] : []),
                ...(movement?.voucher ? ['Voucher ' + movement.voucher] : []),
            ],
            record: [
                'Registró ' + advance.recorder_name,
                'Entregó ' + advance.responsible,
                ...(advance.deducted_at
                    ? [
                          'Descuento · ' +
                              date.format(new Date(advance.deducted_at)) +
                              (advance.deductor_name
                                  ? ' · ' + advance.deductor_name
                                  : ''),
                      ]
                    : []),
                ...(advance.note ? [advance.note] : []),
            ],
        };
    }),
);

type HistoryRow = (typeof historyRows.value)[number];

function historyDetails(lines: string[]) {
    return h(
        'div',
        {
            class: 'flex max-w-56 flex-col gap-1 whitespace-normal wrap-anywhere',
        },
        lines.map((line, index) =>
            h(
                'p',
                {
                    class:
                        index === 0
                            ? 'font-medium'
                            : 'text-xs text-muted-foreground',
                },
                line,
            ),
        ),
    );
}

const historyColumns: DataTableColumn<HistoryRow>[] = [
    { id: 'date', header: 'Fecha', accessorKey: 'date' },
    {
        id: 'employee',
        header: 'Empleado',
        cell: ({ row }) => historyDetails([row.original.employee]),
    },
    {
        id: 'payment',
        header: 'Forma de pago',
        cell: ({ row }) => historyDetails(row.original.payment),
    },
    {
        id: 'record',
        header: 'Registro',
        cell: ({ row }) => historyDetails(row.original.record),
    },
    {
        id: 'status',
        header: 'Estado',
        cell: ({
            row: {
                original: { status },
            },
        }) => h(Badge, { variant: status.variant }, () => status.label),
    },
    {
        id: 'amount',
        header: 'Importe',
        accessorKey: 'amount',
        meta: { align: 'right' },
    },
];

const selectedEmployee = ref<Employee>();
const dialogOpen = ref(false);
const deductForm = useForm({ latest_pending_id: null as number | null });
function openDeduction(employee: Employee): void {
    selectedEmployee.value = employee;
    deductForm.clearErrors();
    deductForm.latest_pending_id = employee.latestPendingId;
    dialogOpen.value = true;
}

function confirmDeduction(): void {
    if (!selectedEmployee.value || deductForm.processing) {
        return;
    }

    deductForm.submit(deduct(selectedEmployee.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Adelantos" />
        <header
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight">Adelantos</h1>
                <p class="text-sm text-muted-foreground">
                    Consulta y registra adelantos de sueldo. Límite de
                    {{ formatUsd(limitCents) }} pendientes por empleado.
                </p>
            </div>
            <Button as-child class="min-h-11 w-full sm:w-auto">
                <Link :href="createEmployee()">Agregar empleado</Link>
            </Button>
        </header>

        <section
            aria-label="Saldos por empleado"
            class="grid gap-3 sm:grid-cols-2"
        >
            <Card
                v-for="employee in employees"
                :key="employee.id"
                class="min-w-0 gap-4"
            >
                <CardHeader>
                    <div class="flex items-start justify-between gap-3">
                        <CardTitle class="break-words">{{
                            employee.name
                        }}</CardTitle>
                        <Button
                            as-child
                            variant="ghost"
                            size="sm"
                            class="min-h-10 shrink-0"
                        >
                            <Link :href="editEmployee(employee.id)"
                                >Editar</Link
                            >
                        </Button>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">
                                Pendiente de descuento
                            </dt>
                            <dd
                                class="text-lg font-semibold tabular-nums"
                                :class="{
                                    'text-warning-foreground':
                                        employee.pendingCents,
                                }"
                            >
                                {{ formatUsd(employee.pendingCents) }}
                            </dd>
                            <Badge
                                v-if="employee.pendingCents"
                                variant="warning"
                                class="mt-1"
                            >
                                Pendiente de descuento
                            </Badge>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Disponible</dt>
                            <dd class="text-lg font-semibold tabular-nums">
                                {{ formatUsd(available(employee)) }}
                            </dd>
                        </div>
                    </dl>
                    <p v-if="!available(employee)" class="text-sm font-medium">
                        Límite alcanzado. Registra el descuento completo para
                        habilitar nuevos adelantos.
                    </p>
                    <div class="flex flex-col gap-2 lg:flex-row lg:flex-wrap">
                        <Button
                            v-if="available(employee)"
                            class="min-h-11"
                            as-child
                        >
                            <Link :href="create(employee.id)"
                                >Registrar adelanto</Link
                            >
                        </Button>
                        <Button
                            v-if="employee.pendingCents"
                            variant="outline"
                            class="min-h-11"
                            @click="openDeduction(employee)"
                        >
                            Marcar como descontados
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <p v-if="!employees.length" class="text-sm text-muted-foreground">
                Todavía no hay empleados configurados. Usa “Agregar empleado”
                para crear una cuenta.
            </p>
        </section>

        <section
            v-if="employees.length || historyRows.length"
            class="flex flex-col gap-4"
            aria-labelledby="advance-history"
        >
            <h2 id="advance-history" class="text-lg font-semibold">
                Historial de adelantos
            </h2>

            <div
                class="flex flex-wrap items-center gap-2"
                aria-label="Filtros del historial"
            >
                <DataTableMonth
                    :month="filter.month"
                    :disabled="filter.processing"
                    @apply="changeFilter('month', $event)"
                />
                <DataTableFilter
                    v-for="field in filterFields"
                    :key="field.name"
                    :title="field.label"
                    :options="field.options"
                    :model-value="filter[field.name]"
                    :disabled="filter.processing"
                    @update:model-value="changeFilter(field.name, $event)"
                />
                <Button
                    v-if="hasActiveFilters"
                    as-child
                    variant="ghost"
                    size="sm"
                    class="min-h-10"
                >
                    <Link :href="index()" preserve-scroll>
                        Limpiar <X data-icon="inline-end" aria-hidden="true" />
                    </Link>
                </Button>
            </div>

            <p v-if="!historyRows.length" class="text-sm text-muted-foreground">
                No hay adelantos para esta selección.
            </p>
            <template v-else>
                <div class="hidden lg:block">
                    <DataTable
                        caption="Historial de adelantos"
                        :rows="historyRows"
                        :columns="historyColumns"
                    />
                </div>
                <ul class="divide-y border-y lg:hidden">
                    <li
                        v-for="row in historyRows"
                        :key="row.id"
                        class="flex min-w-0 flex-col gap-2 py-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <p class="font-medium wrap-anywhere">
                                    {{ row.employee }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.date }}
                                </p>
                            </div>
                            <span
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ row.amount }}</span
                            >
                        </div>
                        <Badge :variant="row.status.variant">{{
                            row.status.label
                        }}</Badge>
                        <div
                            class="flex min-w-0 flex-col gap-0.5 text-xs wrap-anywhere text-muted-foreground"
                        >
                            <p
                                v-for="(line, index) in row.payment"
                                :key="index"
                            >
                                {{ line }}
                            </p>
                            <p v-for="(line, index) in row.record" :key="index">
                                {{ line }}
                            </p>
                        </div>
                    </li>
                </ul>
            </template>

            <DataTablePagination
                label="Páginas del historial de adelantos"
                :page="history"
            />
        </section>

        <Dialog
            :open="dialogOpen"
            @update:open="
                (open) => {
                    if (!deductForm.processing) dialogOpen = open;
                }
            "
        >
            <DialogContent
                v-if="selectedEmployee"
                class="top-0 left-0 h-dvh w-screen max-w-none translate-x-0 translate-y-0 content-start overflow-y-auto rounded-none p-4 sm:top-1/2 sm:left-1/2 sm:h-auto sm:max-h-[calc(100dvh-2rem)] sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-lg sm:p-6"
                :show-close-button="false"
            >
                <DialogHeader>
                    <DialogTitle>Confirmar descuento completo</DialogTitle>
                    <DialogDescription>
                        {{ selectedEmployee.name }} · Pendiente
                        {{ formatUsd(selectedEmployee.pendingCents) }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="confirmDeduction">
                    <p class="text-sm">
                        Confirma que este importe ya se descontó del sueldo. Se
                        marcarán todos los adelantos pendientes, se conservará
                        el historial y el saldo pendiente quedará en
                        {{ formatUsd(0) }}.
                    </p>
                    <InputError
                        :message="deductForm.errors.latest_pending_id"
                    />
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11"
                                :disabled="deductForm.processing"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            class="min-h-11"
                            :disabled="deductForm.processing"
                        >
                            {{
                                deductForm.processing
                                    ? 'Confirmando…'
                                    : 'Confirmar descuento completo'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
