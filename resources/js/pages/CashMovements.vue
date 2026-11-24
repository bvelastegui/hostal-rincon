<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, Landmark } from '@lucide/vue';
import { computed } from 'vue';
import CashMovementList from '@/components/CashMovementList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';
import { create, index } from '@/routes/movements';
import type { CashMovement } from '@/types/cash-movement';

const props = defineProps<{
    date: string;
    canManageDeposits: boolean;
    balances: {
        cash: number | null;
        bank: number;
        transit: number | null;
        total: number | null;
    };
    depositsInTransit: CashMovement[];
    movements: {
        data: CashMovement[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Movimientos', href: index() }] },
});

const balances = computed(() => [
    { label: 'Efectivo', cents: props.balances.cash },
    { label: 'Banco', cents: props.balances.bank },
    ...(props.canManageDeposits
        ? [{ label: 'En tránsito', cents: props.balances.transit }]
        : []),
    {
        label: props.canManageDeposits ? 'Total' : 'Total disponible',
        cents: props.balances.total,
    },
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Movimientos" />

        <header
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h1 class="text-2xl font-semibold tracking-tight">Movimientos</h1>
            <div
                class="grid grid-cols-2 gap-2"
                :class="{ 'sm:grid-cols-3': canManageDeposits }"
            >
                <Button as-child class="min-h-11 px-2 sm:px-4">
                    <Link :href="create({ query: { kind: 'income' } })">
                        <ArrowDownLeft
                            data-icon="inline-start"
                            aria-hidden="true"
                        />
                        Nuevo ingreso
                    </Link>
                </Button>
                <Button
                    as-child
                    variant="outline"
                    class="min-h-11 px-2 sm:px-4"
                >
                    <Link :href="create({ query: { kind: 'expense' } })">
                        <ArrowUpRight
                            data-icon="inline-start"
                            aria-hidden="true"
                        />
                        Nuevo egreso
                    </Link>
                </Button>
                <Button
                    as-child
                    variant="outline"
                    v-if="canManageDeposits"
                    class="col-span-2 min-h-11 px-2 sm:col-span-1 sm:px-4"
                >
                    <Link :href="create({ query: { kind: 'deposit' } })">
                        <Landmark data-icon="inline-start" aria-hidden="true" />
                        Nuevo depósito
                    </Link>
                </Button>
            </div>
        </header>

        <section
            aria-labelledby="movement-balances"
            class="flex flex-col gap-3"
        >
            <h2 id="movement-balances" class="text-base font-semibold">
                Saldos actuales
            </h2>
            <dl
                class="grid grid-cols-2 gap-3 border-y py-3 sm:gap-4"
                :class="canManageDeposits ? 'sm:grid-cols-4' : 'sm:grid-cols-3'"
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

        <section
            v-if="depositsInTransit.length"
            aria-labelledby="transit-list"
            class="flex flex-col gap-3"
        >
            <h2 id="transit-list" class="text-base font-semibold">
                Depósitos en tránsito
            </h2>
            <CashMovementList :movements="depositsInTransit" show-date />
        </section>

        <section aria-labelledby="movements-list" class="flex flex-col gap-3">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <h2 id="movements-list" class="text-base font-semibold">
                    Registros del día
                </h2>
                <Form
                    v-bind="index.form()"
                    class="flex items-end gap-2"
                    v-slot="{ processing }"
                >
                    <div class="grid min-w-0 flex-1 gap-1">
                        <Label for="movement-date">Fecha</Label>
                        <Input
                            id="movement-date"
                            :key="date"
                            name="date"
                            type="date"
                            :default-value="date"
                            class="min-h-11"
                            required
                        />
                    </div>
                    <Button
                        variant="outline"
                        type="submit"
                        class="min-h-11"
                        :disabled="processing"
                        >Consultar</Button
                    >
                </Form>
            </div>

            <CashMovementList
                v-if="movements.data.length"
                :movements="movements.data"
            />
            <p v-else class="text-sm text-muted-foreground">
                No hay movimientos registrados en esta fecha.
            </p>

            <nav
                v-if="movements.prev_page_url || movements.next_page_url"
                aria-label="Páginas de movimientos"
                class="flex flex-wrap gap-2"
            >
                <Button
                    v-if="movements.prev_page_url"
                    as-child
                    variant="outline"
                    class="min-h-11"
                >
                    <Link :href="movements.prev_page_url">Anterior</Link>
                </Button>
                <Button
                    v-if="movements.next_page_url"
                    as-child
                    variant="outline"
                    class="min-h-11"
                >
                    <Link :href="movements.next_page_url">Siguiente</Link>
                </Button>
            </nav>
        </section>
    </div>
</template>
