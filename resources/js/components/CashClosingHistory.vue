<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, h, ref } from 'vue';
import DataTable from '@/components/DataTable.vue';
import DataTableDateRange from '@/components/DataTableDateRange.vue';
import DataTableFilter from '@/components/DataTableFilter.vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import SelectField from '@/components/SelectField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { formatCashDifference, formatUsd } from '@/lib/money';
import { index, resolveShortage } from '@/routes/cash-closings';
import type {
    CashClosingPage,
    CashClosingRecord,
    CashHistoryFilters,
} from '@/types/cash-closing';
import type { DataTableColumn } from '@/types/data-table';

const props = defineProps<{
    closings: CashClosingPage;
    filters: CashHistoryFilters;
    responsibles: { id: number; name: string }[];
}>();

const closingLabels = {
    shift: 'Recepción de turno',
    receipt: 'Recepción de turno',
    delivery: 'Entrega de turno',
    independent: 'Corte independiente',
    administrative: 'Cierre administrativo de turno',
    opening: 'Apertura de turno',
};

const historyRows = computed(
    () =>
        props.closings.data.map((closing) => ({
            id: closing.id,
            closedAt: closing.closedAt,
            operation: closingLabels[closing.type],
            shiftId: closing.shiftId,
            responsible: closing.responsible,
            confirmedBy: closing.confirmedByIsResponsible
                ? null
                : closing.closedBy,
            expected: formatUsd(closing.expectedCashCents),
            counted: formatUsd(closing.countedCashCents),
            difference:
                closing.differenceCents === 0
                    ? null
                    : formatCashDifference(closing.differenceCents),
            variant:
                closing.differenceCents < 0
                    ? ('destructive' as const)
                    : ('default' as const),
            unadjusted:
                closing.differenceCents !== 0 && closing.adjustmentCents === 0,
            note: closing.note,
            shortage: closing.differenceCents < 0,
            resolution: closing.resolution,
        })) ?? [],
);

const historyColumns: DataTableColumn<(typeof historyRows.value)[number]>[] = [
    { id: 'date', header: 'Fecha y hora', accessorKey: 'closedAt' },
    {
        id: 'operation',
        header: 'Operación',
        cell: ({ row: { original } }) =>
            h('div', { class: 'max-w-44 whitespace-normal' }, [
                h('span', { class: 'font-medium' }, original.operation),
                original.shiftId
                    ? h(
                          'p',
                          { class: 'text-xs text-muted-foreground' },
                          'Turno #' + original.shiftId,
                      )
                    : null,
            ]),
    },
    {
        id: 'responsible',
        header: 'Responsable del turno',
        cell: ({ row: { original } }) =>
            h('div', { class: 'max-w-48 whitespace-normal wrap-break-word' }, [
                h('p', original.responsible ?? '—'),
                original.confirmedBy
                    ? h(
                          'p',
                          { class: 'text-xs text-muted-foreground' },
                          'Registró ' + original.confirmedBy,
                      )
                    : null,
            ]),
    },
    {
        id: 'expected',
        header: 'Esperado',
        accessorKey: 'expected',
        meta: { align: 'right' },
    },
    {
        id: 'counted',
        header: 'Contado',
        accessorKey: 'counted',
        meta: { align: 'right' },
    },
    {
        id: 'difference',
        header: 'Diferencia',
        meta: { align: 'right' },
        cell: ({ row: { original } }) =>
            h('div', [
                original.difference
                    ? h(
                          Badge,
                          { variant: original.variant },
                          () => original.difference,
                      )
                    : h(
                          'span',
                          {
                              class: 'text-muted-foreground',
                              'aria-label': 'Sin diferencia',
                          },
                          '—',
                      ),
                original.unadjusted
                    ? h(
                          'p',
                          { class: 'text-xs text-muted-foreground' },
                          'Sin ajuste',
                      )
                    : null,
            ]),
    },
    {
        id: 'resolution',
        header: 'Faltante',
        cell: ({ row: { original } }) =>
            original.shortage
                ? h(
                      Button,
                      {
                          variant: original.resolution
                              ? 'secondary'
                              : 'outline',
                          size: 'sm',
                          onClick: () => showShortage(original.id),
                      },
                      () =>
                          original.resolution
                              ? 'Resuelto'
                              : 'Resolver faltante',
                  )
                : '—',
    },
    {
        id: 'note',
        header: 'Observación',
        cell: ({ row: { original } }) =>
            h(
                'p',
                {
                    class: 'min-w-28 max-w-56 whitespace-normal wrap-break-word',
                },
                original.note ?? '—',
            ),
    },
];

const all = 'all';
const search = useForm({
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    type: props.filters.type ?? all,
    responsible_id: props.filters.responsible_id?.toString() ?? all,
    difference: props.filters.difference ?? all,
    resolution: props.filters.resolution ?? all,
});
const hasFilters = computed(() =>
    Object.values(props.filters).some((value) => value !== null),
);
const filterFields = computed(() => [
    {
        name: 'type' as const,
        label: 'Operación',
        options: [
            { value: all, label: 'Todas las operaciones' },
            ...Object.entries(closingLabels)
                .filter(([value]) => value !== 'shift')
                .map(([value, label]) => ({ value, label })),
        ],
    },
    {
        name: 'responsible_id' as const,
        label: 'Responsable',
        options: [
            { value: all, label: 'Todas las personas' },
            ...props.responsibles.map(({ id, name }) => ({
                value: String(id),
                label: name,
            })),
        ],
    },
    {
        name: 'difference' as const,
        label: 'Diferencia',
        options: [
            { value: all, label: 'Todas las diferencias' },
            { value: 'shortage', label: 'Faltante' },
            { value: 'surplus', label: 'Sobrante' },
            { value: 'balanced', label: 'Sin diferencia' },
        ],
    },
    {
        name: 'resolution' as const,
        label: 'Faltantes',
        options: [
            { value: all, label: 'Todos los estados' },
            { value: 'pending', label: 'Pendientes' },
            { value: 'resolved', label: 'Resueltos' },
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

function changeRange(range: { from: string; to: string } | null) {
    search.date_from = range?.from ?? '';
    search.date_to = range?.to ?? '';
    applyFilters();
}

function changeFilter(
    name: (typeof filterFields.value)[number]['name'],
    value: string,
) {
    search[name] = value;
    if (name === 'difference' && value !== 'shortage') {
        search.resolution = all;
    }
    if (name === 'resolution' && value !== all) {
        search.difference = 'shortage';
    }
    applyFilters();
}

const resolutionOptions = [
    { value: 'deduction', label: 'Descuento aplicado' },
    { value: 'other', label: 'Otra resolución' },
];
const dialogOpen = ref(false);
const selected = ref<CashClosingRecord | null>(null);
const resolutionForm = useForm({ method: '', note: '' });

function showShortage(id: number) {
    selected.value =
        props.closings.data.find((closing) => closing.id === id) ?? null;
    if (selected.value) {
        resolutionForm.resetAndClearErrors();
        dialogOpen.value = true;
    }
}

function saveResolution() {
    if (!selected.value) {
        return;
    }
    resolutionForm.post(resolveShortage(selected.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="closing-history">
        <h2 id="closing-history" class="text-lg font-semibold">
            Historial de caja
        </h2>
        <div
            class="flex flex-wrap items-center gap-2"
            role="group"
            aria-label="Filtros del historial de caja"
        >
            <DataTableDateRange
                :from="filters.date_from"
                :to="filters.date_to"
                reset-label="Quitar rango"
                :disabled="search.processing"
                @apply="changeRange"
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
                v-if="hasFilters"
                as-child
                variant="ghost"
                size="sm"
                class="min-h-10"
            >
                <Link :href="index()" preserve-scroll
                    >Limpiar <X data-icon="inline-end" aria-hidden="true"
                /></Link>
            </Button>
        </div>
        <p v-if="!historyRows.length" class="text-sm text-muted-foreground">
            {{
                hasFilters
                    ? 'No hay registros que coincidan con estos filtros.'
                    : 'Aún no hay registros de caja.'
            }}
        </p>
        <template v-else>
            <div class="hidden lg:block">
                <DataTable
                    caption="Historial de caja"
                    :rows="historyRows"
                    :columns="historyColumns"
                />
            </div>
            <ul class="divide-y border-y lg:hidden">
                <li
                    v-for="row in historyRows"
                    :key="row.id"
                    class="flex flex-col gap-2 py-3 text-sm"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ row.operation }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.closedAt
                                }}<template v-if="row.shiftId">
                                    · Turno #{{ row.shiftId }}</template
                                >
                            </p>
                        </div>
                        <Badge v-if="row.difference" :variant="row.variant">{{
                            row.difference
                        }}</Badge>
                    </div>
                    <p
                        v-if="row.responsible || row.confirmedBy"
                        class="text-xs wrap-break-word"
                    >
                        <template v-if="row.responsible">{{
                            row.responsible
                        }}</template>
                        <template v-if="row.confirmedBy"
                            ><template v-if="row.responsible"> · </template
                            >Registró {{ row.confirmedBy }}</template
                        >
                    </p>
                    <dl
                        class="flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums"
                    >
                        <div class="flex gap-1">
                            <dt class="text-muted-foreground">Esperado</dt>
                            <dd>{{ row.expected }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt class="text-muted-foreground">Contado</dt>
                            <dd>{{ row.counted }}</dd>
                        </div>
                    </dl>
                    <p
                        v-if="row.unadjusted"
                        class="text-xs text-muted-foreground"
                    >
                        Sin ajuste
                    </p>
                    <p v-if="row.note" class="wrap-break-word">
                        {{ row.note }}
                    </p>
                    <Button
                        v-if="row.shortage"
                        type="button"
                        :variant="row.resolution ? 'secondary' : 'outline'"
                        size="sm"
                        class="min-h-10 self-start"
                        @click="showShortage(row.id)"
                    >
                        {{ row.resolution ? 'Resuelto' : 'Resolver faltante' }}
                    </Button>
                </li>
            </ul>
        </template>
        <DataTablePagination
            label="Páginas del historial de caja"
            :page="closings"
        />
        <Dialog v-model:open="dialogOpen">
            <DialogContent v-if="selected">
                <DialogHeader>
                    <DialogTitle>{{
                        selected.resolution
                            ? 'Resolución del faltante'
                            : 'Resolver faltante'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{ formatCashDifference(selected.differenceCents) }} ·
                        {{ selected.responsible ?? 'Sin responsable de turno' }}
                    </DialogDescription>
                </DialogHeader>
                <div
                    v-if="selected.resolution"
                    class="flex flex-col gap-2 text-sm"
                >
                    <p class="font-medium">
                        {{
                            selected.resolution.method === 'deduction'
                                ? 'Descuento aplicado'
                                : 'Otra resolución'
                        }}
                    </p>
                    <p class="wrap-break-word">
                        {{ selected.resolution.note }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ selected.resolution.at
                        }}<template v-if="selected.resolution.by">
                            · Registró {{ selected.resolution.by }}</template
                        >
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-10 sm:self-end"
                        @click="dialogOpen = false"
                        >Cerrar</Button
                    >
                </div>
                <form
                    v-else
                    class="flex flex-col gap-4"
                    @submit.prevent="saveResolution"
                >
                    <FieldGroup>
                        <SelectField
                            v-model="resolutionForm.method"
                            name="shortage-method"
                            label="Forma de resolución"
                            placeholder="Selecciona una opción"
                            :options="resolutionOptions"
                            :error="resolutionForm.errors.method"
                        />
                        <Field
                            :data-invalid="Boolean(resolutionForm.errors.note)"
                        >
                            <FieldLabel for="shortage-note"
                                >Nota de resolución</FieldLabel
                            >
                            <Input
                                id="shortage-note"
                                v-model="resolutionForm.note"
                                required
                                maxlength="500"
                                :aria-invalid="
                                    Boolean(resolutionForm.errors.note)
                                "
                            />
                            <FieldError v-if="resolutionForm.errors.note">{{
                                resolutionForm.errors.note
                            }}</FieldError>
                        </Field>
                    </FieldGroup>
                    <Button
                        type="submit"
                        class="min-h-11 sm:self-end"
                        :disabled="
                            resolutionForm.processing || !resolutionForm.method
                        "
                    >
                        Marcar como resuelto
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
