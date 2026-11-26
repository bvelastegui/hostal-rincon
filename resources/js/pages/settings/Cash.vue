<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { updateOpening } from '@/actions/App/Http/Controllers/Settings/CashSettingsController';
import Heading from '@/components/Heading.vue';
import OpeningBalanceField from '@/components/OpeningBalanceField.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { formatUsd } from '@/lib/money';
import {
    fieldValue,
    reviewAmount,
    reviewSubmission,
} from '@/lib/record-confirmation';
import { edit } from '@/routes/cash-settings';

defineProps<{
    cashOpening: { balanceCents: number | null; locked: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Efectivo', href: edit() }],
    },
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Efectivo" />

        <Heading variant="small" title="Efectivo" hide-title-on-mobile />

        <SettingsSection
            title="Apertura de caja"
            description="Ingresa el efectivo disponible al comenzar el registro de movimientos."
        >
            <p v-if="cashOpening.locked && cashOpening.balanceCents !== null">
                {{ formatUsd(cashOpening.balanceCents) }}
                <span class="block text-sm text-muted-foreground">
                    El saldo inicial ya se usó en un movimiento.
                </span>
            </p>
            <Form
                novalidate
                v-else
                v-bind="updateOpening.form()"
                v-slot="{ errors, processing }"
                error-bag="cashOpening"
                @submit.capture="
                    reviewSubmission($event, (data) => ({
                        title: 'Confirmar saldo inicial de caja',
                        description:
                            'Este valor se usará como base para calcular el efectivo disponible.',
                        amounts: [
                            reviewAmount(
                                'Efectivo inicial (USD)',
                                fieldValue(data, 'opening_balance'),
                            ),
                        ],
                    }))
                "
            >
                <FieldGroup
                    class="settings-form-row gap-3 lg:grid lg:max-w-xl lg:grid-cols-[minmax(0,1fr)_auto] lg:gap-y-0"
                >
                    <OpeningBalanceField
                        id="cash-opening"
                        :key="cashOpening.balanceCents ?? 'unconfigured'"
                        :balance-cents="cashOpening.balanceCents"
                        :error="errors.opening_balance"
                    />
                    <Button class="min-h-11 w-full" :disabled="processing">
                        Guardar saldo de caja
                    </Button>
                </FieldGroup>
            </Form>
        </SettingsSection>
    </div>
</template>
