<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Banknote,
    Building2,
    CheckCircle2,
    ChevronRight,
    ClipboardCheck,
    CreditCard,
    HandCoins,
    Landmark,
    ReceiptText,
    TriangleAlert,
    UserRoundPlus,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    createEmployee,
    index as advances,
} from '@/actions/App/Http/Controllers/AdvanceController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatUsd } from '@/lib/money';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as banks } from '@/routes/bank-settings';
import { create as createBankAccount } from '@/routes/bank-settings/accounts';
import { index as cashClosings } from '@/routes/cash-closings';
import {
    create as createMovement,
    index as movements,
} from '@/routes/movements';
import type { CashBalances } from '@/types/cash-movement';
import type { DashboardAttention } from '@/types/dashboard';

const props = defineProps<{
    balances: CashBalances;
    attention: DashboardAttention;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

const quickActions = [
    {
        label: 'Nuevo ingreso',
        icon: ArrowDownLeft,
        href: createMovement({ query: { kind: 'income' } }),
        variant: 'default',
    },
    {
        label: 'Nuevo egreso',
        icon: ArrowUpRight,
        href: createMovement({ query: { kind: 'expense' } }),
        variant: 'outline',
    },
    {
        label: 'Retiro para depósito',
        icon: Landmark,
        href: createMovement({ query: { kind: 'deposit' } }),
        variant: 'outline',
    },
    {
        label: 'Registrar adelanto',
        icon: HandCoins,
        href: advances(),
        variant: 'outline',
    },
    {
        label: 'Nuevo empleado',
        icon: UserRoundPlus,
        href: createEmployee(),
        variant: 'secondary',
    },
    {
        label: 'Agregar banco',
        icon: Building2,
        href: banks.url() + '#bank-name',
        variant: 'secondary',
    },
    {
        label: 'Nueva cuenta bancaria',
        icon: CreditCard,
        href: createBankAccount(),
        variant: 'secondary',
    },
    {
        label: 'Cierre independiente',
        icon: ClipboardCheck,
        href: cashClosings({ query: { accion: 'cierre-independiente' } }),
        variant: 'secondary',
    },
] as const;

const balanceCards = computed(() => [
    {
        label: 'Efectivo',
        cents: props.balances.cash,
        icon: Banknote,
        iconClass: 'bg-success text-success-foreground',
    },
    {
        label: 'Bancos',
        cents: props.balances.bank,
        icon: Landmark,
        iconClass: 'bg-warning/35 text-warning-foreground',
    },
    {
        label: 'En tránsito',
        cents: props.balances.transit,
        icon: ArrowLeftRight,
        iconClass: 'bg-info/40 text-info-foreground',
    },
    {
        label: 'Total',
        cents: props.balances.total,
        icon: Wallet,
        iconClass: 'bg-secondary text-secondary-foreground',
    },
]);

const attentionItems = computed(() => {
    const { transfers, deposits, shortages } = props.attention;

    return [
        {
            label: 'Transferencias por verificar',
            icon: ReceiptText,
            ...transfers,
            badgeVariant: 'warning' as const,
            url: movements({
                query: {
                    kind: 'income',
                    status: 'pending',
                    date_from: transfers.dateFrom,
                    date_to: transfers.dateTo,
                },
            }).url,
        },
        {
            label: 'Depósitos en tránsito',
            icon: ArrowLeftRight,
            ...deposits,
            badgeVariant: 'info' as const,
            url: movements({ query: { kind: 'deposit', status: 'in_transit' } })
                .url,
        },
        {
            label: 'Faltantes de caja sin resolver',
            icon: TriangleAlert,
            ...shortages,
            badgeVariant: 'destructive' as const,
            url:
                cashClosings({
                    query: { difference: 'shortage', resolution: 'pending' },
                }).url + '#closing-history',
        },
    ].filter(({ count }) => count > 0);
});
</script>

<template>
    <Head title="Panel" />

    <div class="flex min-w-0 flex-1 flex-col gap-7">
        <header class="lg:sr-only">
            <h1 class="text-2xl font-semibold tracking-tight">Panel</h1>
        </header>
        <section
            aria-labelledby="dashboard-balances"
            class="flex flex-col gap-3"
        >
            <div class="flex flex-col gap-1">
                <h2 id="dashboard-balances" class="text-lg font-semibold">
                    Saldos actuales
                </h2>
                <p class="text-sm text-muted-foreground">
                    El total incluye el dinero en tránsito.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4">
                <Card
                    v-for="balance in balanceCards"
                    :key="balance.label"
                    class="min-w-0 bg-linear-to-br from-card via-card to-secondary/70 py-3 sm:py-4"
                >
                    <CardContent
                        class="flex items-center justify-between gap-2 px-3 sm:gap-4 sm:px-4"
                    >
                        <dl class="min-w-0">
                            <dt class="text-sm text-muted-foreground">
                                {{ balance.label }}
                            </dt>
                            <dd
                                class="text-xl leading-tight font-semibold wrap-anywhere tabular-nums sm:text-2xl"
                            >
                                {{
                                    balance.cents === null
                                        ? '—'
                                        : formatUsd(balance.cents)
                                }}
                            </dd>
                        </dl>
                        <span
                            :class="
                                cn(
                                    'grid size-7 shrink-0 place-items-center rounded-lg sm:size-11 sm:rounded-xl',
                                    balance.iconClass,
                                )
                            "
                            aria-hidden="true"
                        >
                            <component
                                :is="balance.icon"
                                class="size-4 sm:size-6"
                            />
                        </span>
                    </CardContent>
                </Card>
            </div>
            <p
                v-if="balances.cash === null"
                class="text-sm text-muted-foreground"
            >
                Configura el saldo inicial de efectivo para calcular la caja y
                el total.
            </p>
        </section>

        <section
            aria-labelledby="dashboard-actions"
            class="flex flex-col gap-3"
        >
            <h2 id="dashboard-actions" class="text-lg font-semibold">
                Accesos rápidos
            </h2>
            <ul class="grid grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4">
                <li
                    v-for="action in quickActions"
                    :key="action.label"
                    class="min-w-0"
                >
                    <Button
                        as-child
                        :variant="action.variant"
                        :class="
                            cn(
                                'h-full min-h-12 w-full justify-between gap-2 rounded-lg px-3 py-2 text-left whitespace-normal',
                                action.variant !== 'secondary' && 'shadow-sm',
                            )
                        "
                    >
                        <Link :href="action.href">
                            <span>{{ action.label }}</span>
                            <component
                                :is="action.icon"
                                data-icon="inline-end"
                                aria-hidden="true"
                            />
                        </Link>
                    </Button>
                </li>
            </ul>
        </section>

        <section
            aria-labelledby="dashboard-attention"
            class="flex flex-col gap-3"
        >
            <h2 id="dashboard-attention" class="text-lg font-semibold">
                Por atender
            </h2>
            <Card class="gap-0 py-0">
                <ul v-if="attentionItems.length" class="divide-y">
                    <li v-for="item in attentionItems" :key="item.label">
                        <Button
                            as-child
                            variant="ghost"
                            class="h-auto min-h-16 w-full justify-between gap-2 rounded-none px-3 py-3 text-left whitespace-normal sm:px-4"
                        >
                            <Link :href="item.url">
                                <div
                                    class="flex min-w-0 items-center gap-2 sm:gap-3"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <span>{{ item.label }}</span>
                                        <Badge
                                            :variant="item.badgeVariant"
                                            class="w-fit"
                                        >
                                            {{ item.count }}
                                            {{
                                                item.count === 1
                                                    ? 'registro'
                                                    : 'registros'
                                            }}
                                        </Badge>
                                    </div>
                                </div>
                                <span
                                    class="flex shrink-0 items-center gap-1 font-semibold whitespace-nowrap tabular-nums sm:gap-3"
                                >
                                    {{ formatUsd(item.amountCents) }}
                                    <ChevronRight
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </span>
                            </Link>
                        </Button>
                    </li>
                </ul>
                <p
                    v-else
                    class="flex min-h-14 items-center gap-3 px-3 py-3 text-sm sm:px-4"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-full bg-success text-success-foreground"
                    >
                        <CheckCircle2 class="size-4" aria-hidden="true" />
                    </span>
                    No hay transferencias, depósitos ni faltantes por revisar.
                </p>
            </Card>
        </section>
    </div>
</template>
