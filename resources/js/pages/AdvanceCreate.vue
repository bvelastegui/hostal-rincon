<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index, store } from '@/actions/App/Http/Controllers/AdvanceController';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { paymentOptions } from '@/lib/cash-movement';
import { formatUsd } from '@/lib/money';

const props = defineProps<{
    employee: { id: number; name: string };
    pendingCents: number;
    limitCents: number;
    requestToken: string;
    cashBalanceCents: number | null;
    bankAccounts: { id: number; name: string; balanceCents: number }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adelantos', href: index() }] },
});

const employeeCapacity = computed(() =>
    Math.max(0, props.limitCents - props.pendingCents),
);
const form = useForm({
    amount: '',
    note: '',
    request_id: props.requestToken,
    payment_method: 'cash',
    bank_account_id: '',
    voucher: '',
});
const isTransfer = computed(() => form.payment_method === 'transfer');
const bankOptions = computed(() =>
    props.bankAccounts.map(({ id, name }) => ({
        value: String(id),
        label: name,
    })),
);
const sourceBalance = computed(() => {
    const cents = isTransfer.value
        ? props.bankAccounts.find(
              ({ id }) => String(id) === form.bank_account_id,
          )?.balanceCents
        : props.cashBalanceCents;

    return cents == null ? null : Math.max(0, cents);
});
const maximum = computed(() =>
    Math.min(employeeCapacity.value, sourceBalance.value ?? 0),
);
const cannotSubmit = computed(() => form.processing || maximum.value === 0);

function submit(): void {
    if (cannotSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        amount: data.amount.trim().replace(',', '.'),
    })).submit(store(props.employee.id));
}
</script>

<template>
    <div class="flex flex-1 flex-col gap-6">
        <Head title="Registrar adelanto" />

        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Registrar adelanto
            </h1>
            <p class="break-words text-muted-foreground">{{ employee.name }}</p>
        </header>

        <dl class="grid grid-cols-2 gap-3 rounded-lg border p-4 text-sm">
            <div>
                <dt class="text-muted-foreground">Pendiente de descuento</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(pendingCents) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Cupo del empleado</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(employeeCapacity) }}
                </dd>
            </div>
        </dl>

        <form
            v-if="employeeCapacity"
            class="flex flex-1 flex-col gap-5"
            @submit.prevent="submit"
        >
            <FieldGroup class="gap-5">
                <Field :data-invalid="!!form.errors.amount">
                    <FieldLabel for="advance-amount">Monto en USD</FieldLabel>
                    <Input
                        id="advance-amount"
                        v-model="form.amount"
                        inputmode="decimal"
                        required
                        class="min-h-11 text-base"
                        :aria-invalid="Boolean(form.errors.amount)"
                        :aria-describedby="
                            form.errors.amount
                                ? 'advance-available advance-amount-error'
                                : 'advance-available'
                        "
                    />
                    <FieldDescription id="advance-available">
                        <template v-if="sourceBalance !== null">
                            Saldo disponible
                            {{ isTransfer ? 'en la cuenta' : 'en caja' }}:
                            {{ formatUsd(sourceBalance) }}. Máximo para este
                            adelanto: {{ formatUsd(maximum) }}.
                        </template>
                        <template v-else-if="isTransfer">
                            Selecciona la cuenta de origen para ver su saldo.
                        </template>
                        <template v-else>
                            Configura el saldo inicial de efectivo para
                            registrar adelantos en caja.
                        </template>
                    </FieldDescription>
                    <FieldError
                        v-if="form.errors.amount"
                        id="advance-amount-error"
                        >{{ form.errors.amount }}</FieldError
                    >
                </Field>
                <SelectField
                    v-model="form.payment_method"
                    name="advance-payment-method"
                    label="Forma de pago"
                    placeholder="Selecciona la forma de pago"
                    :options="paymentOptions"
                    :error="form.errors.payment_method"
                />
                <template v-if="isTransfer">
                    <SelectField
                        v-if="bankOptions.length"
                        v-model="form.bank_account_id"
                        name="advance-bank-account"
                        label="Cuenta bancaria de origen"
                        placeholder="Selecciona la cuenta bancaria"
                        :options="bankOptions"
                        :error="form.errors.bank_account_id"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        Configura una cuenta de un banco activo para registrar
                        transferencias.
                    </p>
                    <Field
                        v-if="bankOptions.length"
                        :data-invalid="!!form.errors.voucher"
                    >
                        <FieldLabel for="advance-voucher"
                            >Número de voucher</FieldLabel
                        >
                        <Input
                            id="advance-voucher"
                            v-model="form.voucher"
                            maxlength="100"
                            required
                            class="min-h-11"
                            :aria-invalid="!!form.errors.voucher"
                            :aria-describedby="
                                form.errors.voucher
                                    ? 'advance-voucher-error'
                                    : undefined
                            "
                        />
                        <FieldError
                            v-if="form.errors.voucher"
                            id="advance-voucher-error"
                        >
                            {{ form.errors.voucher }}
                        </FieldError>
                    </Field>
                </template>
                <Field :data-invalid="!!form.errors.note">
                    <FieldLabel for="advance-note"
                        >Observación opcional</FieldLabel
                    >
                    <Input
                        id="advance-note"
                        v-model="form.note"
                        maxlength="500"
                        class="min-h-11 text-base"
                        :aria-invalid="Boolean(form.errors.note)"
                    />
                    <FieldError v-if="form.errors.note">{{
                        form.errors.note
                    }}</FieldError>
                </Field>
            </FieldGroup>
            <InputError :message="form.errors.request_id" />

            <div class="mt-auto grid gap-2 sm:mt-2 sm:flex sm:justify-end">
                <Button as-child variant="outline" class="min-h-11">
                    <Link :href="index()">Cancelar</Link>
                </Button>
                <Button type="submit" class="min-h-11" :disabled="cannotSubmit">
                    {{
                        form.processing ? 'Registrando…' : 'Registrar adelanto'
                    }}
                </Button>
            </div>
        </form>
        <div v-else class="space-y-3">
            <p class="text-sm">
                Límite alcanzado. Registra el descuento completo para habilitar
                nuevos adelantos.
            </p>
            <Button
                as-child
                variant="outline"
                class="min-h-11 w-full sm:w-auto"
            >
                <Link :href="index()">Volver a adelantos</Link>
            </Button>
        </div>
    </div>
</template>
