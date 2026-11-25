<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useStorage } from '@vueuse/core';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Banknote,
    CheckCircle2,
    ChevronRight,
    HandCoins,
    Landmark,
    ReceiptText,
    TriangleAlert,
    Wallet,
} from '@lucide/vue';
import { computed, nextTick, useTemplateRef, watchEffect } from 'vue';
import { index as advances } from '@/actions/App/Http/Controllers/AdvanceController';
import InitialSetupWidget from '@/components/InitialSetupWidget.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatUsd } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as cashClosings } from '@/routes/cash-closings';
import {
    create as createMovement,
    index as movements,
} from '@/routes/movements';
import type { CashBalances } from '@/types/cash-movement';
import type { DashboardAttention, PendingSetting } from '@/types/dashboard';

const props = defineProps<{
    balances: CashBalances;
    attention: DashboardAttention;
    pendingSettings: PendingSetting[];
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
] as const;

const balanceCards = computed(() => [
    { label: 'Efectivo', cents: props.balances.cash, icon: Banknote },
    { label: 'Bancos', cents: props.balances.bank, icon: Landmark },
    {
        label: 'En tránsito',
        cents: props.balances.transit,
        icon: ArrowLeftRight,
    },
    { label: 'Total', cents: props.balances.total, icon: Wallet },
]);

const attentionItems = computed(() => {
    const { transfers, deposits, shortages } = props.attention;

    return [
        {
            label: 'Transferencias por verificar',
            icon: ReceiptText,
            ...transfers,
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
            url: movements({ query: { kind: 'deposit', status: 'in_transit' } })
                .url,
        },
        {
            label: 'Faltantes de caja sin resolver',
            icon: TriangleAlert,
            ...shortages,
            url:
                cashClosings({
                    query: { difference: 'shortage', resolution: 'pending' },
                }).url + '#closing-history',
        },
    ].filter(({ count }) => count > 0);
});

const page = usePage();
const title = useTemplateRef<HTMLHeadingElement>('title');
const hasPendingSettings = computed(() => props.pendingSettings.length > 0);
const dismissed = useStorage(
    `hostal-rincon:initial-setup-dismissed:${page.props.auth.user.id}`,
    false,
    undefined,
    { initOnMounted: true, writeDefaults: false },
);

watchEffect(() => {
    if (hasPendingSettings.value && dismissed.value) {
        dismissed.value = false;
    }
});

async function dismissSetup(): Promise<void> {
    if (hasPendingSettings.value) {
        return;
    }

    dismissed.value = true;
    await nextTick();
    title.value?.focus();
}
</script>

<template>
    <Head title="Panel" />

    <div class="flex min-w-0 flex-1 flex-col gap-7">
        <header class="lg:sr-only">
            <h1
                ref="title"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight"
            >
                Panel
            </h1>
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
                    class="min-w-0 py-3 shadow-none sm:py-4"
                >
                    <CardContent class="px-3 sm:px-4">
                        <dl class="flex flex-col gap-1.5">
                            <dt
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <component
                                    :is="balance.icon"
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ balance.label }}
                            </dt>
                            <dd
                                class="leading-tight font-semibold wrap-anywhere tabular-nums sm:text-xl"
                            >
                                {{
                                    balance.cents === null
                                        ? '—'
                                        : formatUsd(balance.cents)
                                }}
                            </dd>
                        </dl>
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
                        class="h-auto min-h-12 w-full justify-start gap-2 px-3 py-2 text-left whitespace-normal"
                    >
                        <Link :href="action.href">
                            <component
                                :is="action.icon"
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            <span>{{ action.label }}</span>
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
            <Card class="gap-0 py-0 shadow-none">
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
                                            variant="secondary"
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
                    class="flex items-center gap-2 px-3 py-4 text-sm text-muted-foreground sm:px-4"
                >
                    <CheckCircle2 class="size-4 shrink-0" aria-hidden="true" />
                    No hay transferencias, depósitos ni faltantes por revisar.
                </p>
            </Card>
        </section>

        <InitialSetupWidget
            v-if="
                page.props.auth.canManageCashSettings &&
                (hasPendingSettings || !dismissed)
            "
            :pending-settings="pendingSettings"
            @dismiss="dismissSetup"
        />
    </div>
</template>
