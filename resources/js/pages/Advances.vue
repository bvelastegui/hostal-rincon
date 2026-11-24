<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    create,
    index,
    deduct,
    createEmployee,
    editEmployee,
} from '@/actions/App/Http/Controllers/AdvanceController';
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
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';

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
    deducted_at: string | null;
    deductor_name: string | null;
};

const props = defineProps<{
    employees: Employee[];
    history: {
        data: Advance[];
        prev_page_url: string | null;
        next_page_url: string | null;
        current_page: number;
        last_page: number;
    };
    filters: { employee?: string | number; status?: string; month?: string };
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
const filter = ref({
    employee: String(props.filters.employee ?? ''),
    status: props.filters.status ?? '',
    month: props.filters.month ?? '',
});
const hasActiveFilters = () =>
    Boolean(filter.value.employee || filter.value.status || filter.value.month);
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

function applyFilters(): void {
    router.get(
        index().url,
        {
            ...(filter.value.employee
                ? { employee: filter.value.employee }
                : {}),
            ...(filter.value.status ? { status: filter.value.status } : {}),
            ...(filter.value.month ? { month: filter.value.month } : {}),
        },
        { preserveState: true, preserveScroll: true },
    );
}

function clearFilters(): void {
    filter.value = { employee: '', status: '', month: '' };
    router.get(index().url, {}, { preserveState: true, preserveScroll: true });
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
                                :class="[
                                    'text-lg font-semibold tabular-nums',
                                    employee.pendingCents
                                        ? 'text-amber-800 dark:text-amber-300'
                                        : '',
                                ]"
                            >
                                {{ formatUsd(employee.pendingCents) }}
                            </dd>
                            <Badge
                                v-if="employee.pendingCents"
                                class="mt-1 border-amber-300 bg-amber-100 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
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
            v-if="employees.length"
            class="space-y-4"
            aria-labelledby="advance-history"
        >
            <div>
                <h2 id="advance-history" class="text-lg font-semibold">
                    Historial
                </h2>
                <p class="text-sm text-muted-foreground">
                    Los filtros se aplican solo al historial.
                </p>
            </div>
            <form
                class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_auto_auto]"
                @submit.prevent="applyFilters"
            >
                <div class="grid gap-1.5">
                    <Label for="advance-employee-filter">Empleado</Label>
                    <select
                        id="advance-employee-filter"
                        v-model="filter.employee"
                        class="min-h-11 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="employee in employees"
                            :key="employee.id"
                            :value="String(employee.id)"
                        >
                            {{ employee.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="advance-month-filter">Mes calendario</Label>
                    <input
                        id="advance-month-filter"
                        v-model="filter.month"
                        type="month"
                        class="min-h-11 rounded-md border bg-background px-3 text-sm"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="advance-status-filter">Estado</Label>
                    <select
                        id="advance-status-filter"
                        v-model="filter.status"
                        class="min-h-11 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Todos</option>
                        <option value="pending">Pendiente de descuento</option>
                        <option value="deducted">Descontado</option>
                    </select>
                </div>
                <Button type="submit" variant="outline" class="min-h-11"
                    >Filtrar historial</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    class="min-h-11"
                    :disabled="!hasActiveFilters()"
                    @click="clearFilters"
                >
                    Limpiar filtros
                </Button>
            </form>
            <p
                v-if="!history.data.length"
                class="rounded-lg border p-4 text-sm text-muted-foreground"
            >
                No hay adelantos para esta selección.
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="advance in history.data"
                    :key="advance.id"
                    :class="[
                        'grid gap-2 border-l-4 p-3 sm:grid-cols-[1fr_auto] sm:p-4',
                        advance.deducted_at
                            ? 'border-l-transparent'
                            : 'border-l-amber-500 bg-amber-50/70 dark:bg-amber-950/20',
                    ]"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium break-words">
                            {{ advance.employee_name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Fecha del adelanto ·
                            {{
                                advance.issued_on
                                    .slice(0, 10)
                                    .split('-')
                                    .reverse()
                                    .join('/')
                            }}
                        </p>
                        <p class="text-sm break-words text-muted-foreground">
                            Entregó {{ advance.responsible }} · Registró
                            {{ advance.recorder_name }}
                        </p>
                        <p v-if="advance.note" class="text-sm break-words">
                            {{ advance.note }}
                        </p>
                        <p
                            v-if="advance.deducted_at"
                            class="text-sm break-words text-muted-foreground"
                        >
                            Descuento ·
                            {{ date.format(new Date(advance.deducted_at)) }} ·
                            {{ advance.deductor_name }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end"
                    >
                        <span class="font-semibold tabular-nums">{{
                            formatUsd(advance.amount_cents)
                        }}</span>
                        <Badge
                            :class="
                                advance.deducted_at
                                    ? 'border-emerald-300 bg-emerald-100 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100'
                                    : 'border-amber-300 bg-amber-100 font-semibold text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100'
                            "
                        >
                            {{
                                advance.deducted_at
                                    ? 'Descontado'
                                    : 'Pendiente de descuento'
                            }}
                        </Badge>
                    </div>
                </li>
            </ul>
            <nav
                v-if="history.last_page > 1"
                aria-label="Páginas del historial"
                class="flex flex-col items-center justify-between gap-2 sm:flex-row"
            >
                <Button
                    v-if="history.prev_page_url"
                    variant="outline"
                    class="min-h-11 w-full sm:w-auto"
                    as-child
                >
                    <Link
                        :href="history.prev_page_url"
                        preserve-scroll
                        preserve-state
                        >Anterior</Link
                    >
                </Button>
                <span class="text-sm text-muted-foreground"
                    >Página {{ history.current_page }} de
                    {{ history.last_page }}</span
                >
                <Button
                    v-if="history.next_page_url"
                    variant="outline"
                    class="min-h-11 w-full sm:w-auto"
                    as-child
                >
                    <Link
                        :href="history.next_page_url"
                        preserve-scroll
                        preserve-state
                        >Siguiente</Link
                    >
                </Button>
            </nav>
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
