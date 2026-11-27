<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, Landmark, X } from '@lucide/vue';
import { computed } from 'vue';
import CashMovementList from '@/components/CashMovementList.vue';
import CashMovementsTable from '@/components/CashMovementsTable.vue';
import DataTableDateRange from '@/components/DataTableDateRange.vue';
import DataTableFilter from '@/components/DataTableFilter.vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { Button } from '@/components/ui/button';
import { movementLabels, movementStatusLabels } from '@/lib/cash-movement';
import { formatUsd } from '@/lib/money';
import { index as cashClosings } from '@/routes/cash-closings';
import { create, index } from '@/routes/movements';
import type {
    CashBalances,
    CashMovement,
    MovementRow,
} from '@/types/cash-movement';
import type { DataTablePage } from '@/types/data-table';

const props = defineProps<{
    date: string | null;
    dateRange: { from: string; to: string; explicit: boolean } | null;
    shiftId: number | null;
    canManageDeposits: boolean;
    movementRestriction: string | null;
    balances: CashBalances;
    depositsInTransit: DataTablePage<CashMovement> | null;
    filters: {
        kind: string | null;
        recorded_by: number | null;
        status: string | null;
        source: string | null;
    };
    filterOptions: {
        recorders: { id: number; name: string }[];
        accounts: { id: number; name: string }[];
    };
    movements: DataTablePage<MovementRow> | { data: MovementRow[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Movimientos', href: index() }] },
});

const movementActions = [
    { kind: 'income', label: 'Nuevo ingreso', icon: ArrowDownLeft },
    { kind: 'expense', label: 'Nuevo egreso', icon: ArrowUpRight },
    { kind: 'deposit', label: 'Nuevo depósito', icon: Landmark },
] as const;

const all = 'all';
const search = useForm({
    date: props.dateRange?.explicit ? '' : (props.date ?? ''),
    date_from: props.dateRange?.explicit ? props.dateRange.from : '',
    date_to: props.dateRange?.explicit ? props.dateRange.to : '',
    kind: props.filters.kind ?? all,
    recorded_by: props.filters.recorded_by?.toString() ?? all,
    status: props.filters.status ?? all,
    source: props.filters.source ?? all,
});
const hasFilters = computed(
    () =>
        props.dateRange?.explicit ||
        Object.values(props.filters).some((value) => value !== null),
);
const multipleDates = computed(
    () =>
        props.dateRange !== null && props.dateRange.from !== props.dateRange.to,
);
const filterFields = computed(() => [
    {
        name: 'kind' as const,
        label: 'Tipo',
        options: [
            { value: all, label: 'Todos los tipos' },
            { value: 'income', label: movementLabels.income },
            { value: 'expense', label: movementLabels.expense },
            { value: 'deposit', label: 'Depósito' },
            ...(props.canManageDeposits
                ? [{ value: 'advance', label: 'Adelanto' }]
                : [
                      {
                          value: 'transaction',
                          label: movementLabels.transaction,
                      },
                  ]),
        ],
    },
    {
        name: 'recorded_by' as const,
        label: 'Registrado por',
        options: [
            { value: all, label: 'Todas las personas' },
            ...props.filterOptions.recorders.map(({ id, name }) => ({
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
            ...Object.entries(movementStatusLabels).map(([value, label]) => ({
                value,
                label,
            })),
        ],
    },
    {
        name: 'source' as const,
        label: 'Cuenta o efectivo',
        options: [
            { value: all, label: 'Todas las fuentes' },
            { value: 'cash', label: 'Efectivo' },
            ...props.filterOptions.accounts.map(({ id, name }) => ({
                value: 'bank:' + id,
                label: name,
            })),
        ],
    },
]);

function applyFilters() {
    search
        .transform((data) =>
            Object.fromEntries(
                Object.entries(data).filter(
                    ([, value]) => value && value !== all,
                ),
            ),
        )
        .get(index().url, { preserveScroll: true, preserveState: false });
}

function changeFilter(
    name: (typeof filterFields.value)[number]['name'],
    value: string,
) {
    search[name] = value;
    applyFilters();
}

function changeDate(range: { from: string; to: string } | null) {
    search.date = '';
    search.date_from = range?.from ?? '';
    search.date_to = range?.to ?? '';
    applyFilters();
}

const balances = computed(() => [
    { label: 'Efectivo', cents: props.balances.cash },
    {
        label: props.canManageDeposits
            ? 'Banco'
            : 'Mis transferencias del turno',
        cents: props.balances.bank,
    },
    ...(props.canManageDeposits
        ? [
              { label: 'En tránsito', cents: props.balances.transit },
              { label: 'Total', cents: props.balances.total },
          ]
        : []),
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Movimientos" />

        <header
            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <h1 class="text-2xl font-semibold tracking-tight">Movimientos</h1>
            <div
                v-if="!movementRestriction"
                class="grid gap-2 sm:grid-cols-3 lg:w-full lg:max-w-lg"
            >
                <Button
                    v-for="action in movementActions"
                    :key="action.kind"
                    as-child
                    :variant="action.kind === 'income' ? 'default' : 'outline'"
                    class="h-full min-h-11 w-full justify-between py-2 text-left whitespace-normal"
                >
                    <Link :href="create({ query: { kind: action.kind } })">
                        <span>{{ action.label }}</span>
                        <component
                            :is="action.icon"
                            data-icon="inline-end"
                            aria-hidden="true"
                        />
                    </Link>
                </Button>
            </div>
        </header>
        <p
            v-if="movementRestriction"
            class="border-y py-3 text-sm text-muted-foreground"
            role="status"
        >
            {{ movementRestriction }}
            <Link
                :href="cashClosings()"
                class="font-medium text-foreground underline underline-offset-4"
                >Ver turno de caja</Link
            >
        </p>

        <section
            aria-labelledby="movement-balances"
            class="flex flex-col gap-3"
        >
            <h2 id="movement-balances" class="text-base font-semibold">
                Saldos actuales
            </h2>
            <dl
                class="grid grid-cols-2 gap-3 border-y py-3 sm:gap-4"
                :class="{ 'sm:grid-cols-4': canManageDeposits }"
            >
                <div
                    v-for="balance in balances"
                    :key="balance.label"
                    class="min-w-0"
                >
                    <dt class="text-xs text-muted-foreground sm:text-sm">
                        {{ balance.label }}
                    </dt>
                    <dd
                        class="text-sm font-semibold wrap-break-word tabular-nums sm:text-lg"
                    >
                        {{
                            balance.cents === null
                                ? 'Sin apertura'
                                : formatUsd(balance.cents)
                        }}
                    </dd>
                </div>
            </dl>
        </section>

        <div
            v-if="!movementRestriction"
            class="flex flex-wrap items-center gap-2"
            role="group"
            aria-label="Filtros de movimientos"
        >
            <DataTableDateRange
                v-if="dateRange"
                :from="dateRange.from"
                :to="dateRange.to"
                :disabled="search.processing"
                @apply="changeDate"
            />
            <DataTableFilter
                v-for="field in filterFields"
                :key="field.name"
                :model-value="search[field.name]"
                :title="field.label"
                :options="field.options"
                :disabled="search.processing"
                @update:model-value="(value) => changeFilter(field.name, value)"
            />
            <Button
                v-if="hasFilters || date"
                as-child
                variant="ghost"
                size="sm"
                class="min-h-10"
            >
                <Link :href="index()" preserve-scroll>
                    Limpiar
                    <X data-icon="inline-end" aria-hidden="true" />
                </Link>
            </Button>
        </div>

        <section
            v-if="depositsInTransit?.total"
            aria-labelledby="transit-list"
            class="flex flex-col gap-3"
        >
            <h2 id="transit-list" class="text-base font-semibold">
                Depósitos en tránsito
            </h2>
            <CashMovementList
                class="lg:hidden"
                :movements="depositsInTransit.data"
                :can-manage-deposits="canManageDeposits"
                show-date
            />
            <div class="hidden lg:block">
                <CashMovementsTable
                    caption="Depósitos en tránsito"
                    :movements="depositsInTransit.data"
                    :can-manage-deposits="canManageDeposits"
                    show-date
                />
            </div>
            <DataTablePagination
                label="Páginas de depósitos en tránsito"
                :page="depositsInTransit"
            />
        </section>

        <section
            v-if="filters.status !== 'in_transit' || !depositsInTransit?.total"
            aria-labelledby="movements-list"
            class="flex flex-col gap-3"
        >
            <h2 id="movements-list" class="text-base font-semibold">
                {{
                    date
                        ? multipleDates
                            ? 'Registros del período'
                            : 'Registros del día'
                        : shiftId
                          ? 'Movimientos del turno #' + shiftId
                          : 'Movimientos del turno'
                }}
            </h2>

            <template v-if="movements.data.length">
                <CashMovementList
                    class="lg:hidden"
                    :movements="movements.data"
                    :show-date="date === null || multipleDates"
                    :can-manage="!movementRestriction"
                    :can-manage-deposits="canManageDeposits"
                />
                <div class="hidden lg:block">
                    <CashMovementsTable
                        :caption="
                            date
                                ? 'Registros de movimientos'
                                : 'Movimientos del turno'
                        "
                        :movements="movements.data"
                        :show-date="date === null || multipleDates"
                        :can-manage="!movementRestriction"
                        :can-manage-deposits="canManageDeposits"
                    />
                </div>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    hasFilters
                        ? 'No hay movimientos que coincidan con estos filtros.'
                        : date
                          ? 'No hay movimientos registrados en esta fecha.'
                          : 'No hay movimientos para mostrar.'
                }}
            </p>

            <DataTablePagination
                v-if="'total' in movements"
                label="Páginas de movimientos"
                :page="movements"
            />
        </section>
    </div>
</template>
