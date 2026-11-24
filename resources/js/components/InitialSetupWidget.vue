<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ChevronRight, LockKeyhole, X } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit as editBanks } from '@/routes/bank-settings';
import { create as createBankAccount } from '@/routes/bank-settings/accounts';
import { edit as editCash } from '@/routes/cash-settings';
import type { PendingSetting } from '@/types/dashboard';

const props = defineProps<{ pendingSettings: PendingSetting[] }>();
const emit = defineEmits<{ dismiss: [] }>();

const settings = [
    {
        key: 'cash_opening',
        title: 'Saldo inicial de efectivo',
        href: editCash(),
    },
    { key: 'active_bank', title: 'Banco admitido', href: editBanks() },
    { key: 'bank_account', title: 'Cuenta bancaria', href: editBanks() },
] satisfies {
    key: PendingSetting;
    title: string;
    href: ReturnType<typeof editCash>;
}[];

const steps = computed(() => {
    const nextKey = settings.find(({ key }) =>
        props.pendingSettings.includes(key),
    )?.key;
    const needsBank = props.pendingSettings.includes('active_bank');

    return settings.map((setting) => {
        const complete = !props.pendingSettings.includes(setting.key);
        const isAccount = setting.key === 'bank_account';

        return {
            ...setting,
            complete,
            next: setting.key === nextKey,
            waitingForBank: isAccount && needsBank,
            href:
                isAccount && !complete && !needsBank
                    ? createBankAccount()
                    : setting.href,
        };
    });
});

const completedCount = computed(
    () => steps.value.filter((step) => step.complete).length,
);
const isComplete = computed(() => completedCount.value === steps.value.length);
</script>

<template>
    <Card
        role="region"
        aria-labelledby="initial-setup-title"
        class="w-full max-w-xl gap-4 py-4 sm:py-5"
    >
        <CardHeader class="flex items-start justify-between gap-2 px-4 sm:px-5">
            <div
                class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2 pt-1"
            >
                <CardTitle id="initial-setup-title"
                    >Configuración inicial</CardTitle
                >
                <Badge :variant="isComplete ? 'default' : 'secondary'">
                    {{ completedCount }} de {{ steps.length }} completados
                </Badge>
            </div>
            <Button
                v-if="isComplete"
                type="button"
                variant="ghost"
                size="icon-lg"
                class="-my-2 size-11"
                aria-label="Ocultar configuración inicial"
                title="Ocultar configuración inicial"
                @click="emit('dismiss')"
            >
                <X aria-hidden="true" />
            </Button>
        </CardHeader>

        <CardContent class="px-4 sm:px-5">
            <ol>
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    :data-complete="step.complete"
                    class="relative grid min-h-14 grid-cols-[2rem_minmax(0,1fr)] gap-2 before:absolute before:top-10 before:-bottom-2 before:left-4 before:border-l last:before:hidden data-[complete=true]:before:border-primary/50"
                >
                    <span
                        aria-hidden="true"
                        :data-complete="step.complete"
                        :data-next="step.next"
                        class="z-10 mt-2 grid size-8 place-items-center rounded-full border bg-card text-xs font-semibold text-muted-foreground data-[complete=true]:border-primary data-[complete=true]:bg-primary data-[complete=true]:text-primary-foreground data-[next=true]:border-primary data-[next=true]:text-foreground data-[next=true]:ring-2 data-[next=true]:ring-primary/15"
                    >
                        <Check v-if="step.complete" class="size-4" />
                        <LockKeyhole
                            v-else-if="step.waitingForBank"
                            class="size-3.5"
                        />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <Button
                        as-child
                        variant="ghost"
                        class="h-auto min-h-12 w-full justify-between self-start px-2 py-2 text-left whitespace-normal"
                    >
                        <Link
                            :href="step.href"
                            :aria-current="step.next ? 'step' : undefined"
                        >
                            <span class="flex min-w-0 flex-col gap-1">
                                <span>{{ step.title }}</span>
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                >
                                    {{
                                        step.complete
                                            ? 'Completado'
                                            : step.waitingForBank
                                              ? 'Primero activa un banco'
                                              : step.next
                                                ? 'Siguiente paso'
                                                : 'Pendiente'
                                    }}
                                </span>
                            </span>
                            <ChevronRight
                                data-icon="inline-end"
                                aria-hidden="true"
                            />
                        </Link>
                    </Button>
                </li>
            </ol>
        </CardContent>
    </Card>
</template>
