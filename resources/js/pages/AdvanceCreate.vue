<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import {
    index,
    replace,
    store,
} from '@/actions/App/Http/Controllers/AdvanceController';
import HighlightedAmountField from '@/components/HighlightedAmountField.vue';
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
import { formatUsd, parseUsdCents } from '@/lib/money';
import { reviewAmount, reviewSubmission } from '@/lib/record-confirmation';

const props = defineProps<{
    employee: { id: number; name: string };
    pendingCents: number;
    limitCents: number;
    requestToken: string;
    cashBalanceCents: number | null;
    bankAccounts: { id: number; name: string; balanceCents: number }[];
    replacement: {
        id: number;
        amountCents: number;
        note: string | null;
        paymentMethod: 'cash' | 'transfer' | null;
        accountId: number | null;
        voucher: string | null;
    } | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adelantos', href: index() }] },
});

const employeeCapacity = computed(() =>
    Math.max(0, props.limitCents - props.pendingCents),
);
const title = props.replacement ? 'Corregir adelanto' : 'Registrar adelanto';
const originalAmount = props.replacement?.amountCents;
const form = useForm({
    amount:
        originalAmount == null
            ? ''
            : (originalAmount / 100).toFixed(2).replace('.', ','),
    note: props.replacement?.note ?? '',
    void_reason: '',
    request_id: props.requestToken,
    payment_method: props.replacement?.paymentMethod ?? '',
    bank_account_id: props.replacement?.accountId
        ? String(props.replacement.accountId)
        : '',
    voucher: props.replacement?.voucher ?? '',
});
const isTransfer = computed(() => form.payment_method === 'transfer');
const bankOptions = computed(() =>
    props.bankAccounts.map(({ id, name }) => ({
        value: String(id),
        label: name,
    })),
);
const sourceBalance = computed(() => {
    if (!form.payment_method) {
        return null;
    }

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
const normalizedAmount = computed(() => form.amount.trim().replace(',', '.'));
const amountCents = computed(() => parseUsdCents(form.amount));
const amountLabel = computed(() =>
    amountCents.value !== null && amountCents.value > 0
        ? formatUsd(amountCents.value)
        : null,
);
const limitError = computed(() => {
    if (
        amountCents.value === null ||
        sourceBalance.value === null ||
        amountCents.value <= maximum.value
    ) {
        return null;
    }

    if (amountCents.value > sourceBalance.value) {
        const source = isTransfer.value ? 'la cuenta seleccionada' : 'caja';
        return 'El monto supera el saldo disponible en ' + source + '.';
    }

    return 'El monto supera el cupo disponible del empleado.';
});
const amountError = computed(() => form.errors.amount ?? limitError.value);
watch([() => form.payment_method, () => form.bank_account_id], () =>
    form.clearErrors('amount'),
);
const submitLabel = computed(() => {
    const action = props.replacement
        ? 'Confirmar corrección'
        : 'Registrar adelanto';
    return amountLabel.value ? action + ' · ' + amountLabel.value : action;
});
const page = usePage();
const submitError = computed(
    () =>
        page.props.errors.advance ??
        page.props.errors.movement ??
        form.errors.request_id,
);

function advanceReview() {
    const bank = props.bankAccounts.find(
        ({ id }) => String(id) === form.bank_account_id,
    );
    return {
        title: props.replacement
            ? 'Confirmar corrección de adelanto'
            : 'Confirmar adelanto de sueldo',
        description: props.replacement
            ? 'Se anulará el adelanto original y se registrará la corrección para ' +
              props.employee.name +
              '.'
            : 'El adelanto de ' +
              props.employee.name +
              ' quedará pendiente de descuento.',
        amounts: [
            ...(originalAmount == null
                ? []
                : [reviewAmount('Monto original (USD)', originalAmount)]),
            reviewAmount(
                props.replacement
                    ? 'Monto nuevo (USD)'
                    : 'Monto del adelanto (USD)',
                form.amount,
            ),
        ],
        details: [
            paymentOptions.find(
                (option) => option.value === form.payment_method,
            )?.label,
            isTransfer.value && bank
                ? 'Cuenta de origen · ' + bank.name
                : undefined,
        ],
    };
}

function submit(): void {
    if (form.processing) {
        return;
    }

    form.transform((data) => ({
        ...data,
        amount: normalizedAmount.value,
    })).submit(
        props.replacement
            ? replace(props.replacement.id)
            : store(props.employee.id),
    );
}
</script>

<template>
    <div class="flex flex-1 flex-col gap-6">
        <Head :title="title" />

        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ title }}
            </h1>
            <p class="break-words text-muted-foreground">{{ employee.name }}</p>
        </header>

        <p v-if="replacement" class="text-sm text-muted-foreground">
            Importe original:
            <strong class="text-foreground">{{
                formatUsd(replacement.amountCents)
            }}</strong
            >. Al guardar se anula el registro original y queda el motivo de la
            corrección en el historial.
        </p>

        <dl
            class="grid grid-cols-2 gap-3 rounded-lg border p-4 text-sm sm:grid-cols-3"
        >
            <div>
                <dt class="text-muted-foreground">
                    {{
                        replacement
                            ? 'Otros adelantos pendientes'
                            : 'Pendiente de descuento'
                    }}
                </dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(pendingCents) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Límite del empleado</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(limitCents) }}
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
            novalidate
            v-if="employeeCapacity"
            class="flex flex-1 flex-col gap-5"
            @submit.capture="reviewSubmission($event, advanceReview)"
            @submit.prevent="submit"
        >
            <FieldGroup class="gap-5">
                <SelectField
                    v-model="form.payment_method"
                    name="advance-payment-method"
                    label="Forma de pago"
                    placeholder="Selecciona la forma de pago"
                    :options="paymentOptions"
                    :error="form.errors.payment_method"
                />
                <SelectField
                    v-if="isTransfer && bankOptions.length"
                    v-model="form.bank_account_id"
                    name="advance-bank-account"
                    label="Cuenta bancaria de origen"
                    placeholder="Selecciona la cuenta bancaria"
                    :options="bankOptions"
                    :error="form.errors.bank_account_id"
                />
                <HighlightedAmountField
                    id="advance-amount"
                    v-model="form.amount"
                    :label="
                        replacement ? 'Monto corregido' : 'Monto del adelanto'
                    "
                    :error="amountError"
                    :alert-error="!!limitError"
                    :disabled="sourceBalance === null"
                    @update:model-value="form.clearErrors('amount')"
                >
                    <template #context>
                        <dl
                            v-if="sourceBalance !== null"
                            class="grid grid-cols-2 gap-3 text-sm"
                        >
                            <div>
                                <dt class="text-muted-foreground">
                                    {{
                                        isTransfer
                                            ? 'Disponible en cuenta'
                                            : 'Disponible en caja'
                                    }}
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ formatUsd(sourceBalance) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Máximo para este adelanto
                                </dt>
                                <dd
                                    class="text-lg font-semibold text-primary tabular-nums"
                                >
                                    {{ formatUsd(maximum) }}
                                </dd>
                            </div>
                        </dl>
                        <FieldDescription v-else>
                            {{
                                !form.payment_method
                                    ? 'Elige la forma de pago para conocer el máximo antes de ingresar el monto.'
                                    : isTransfer
                                      ? bankOptions.length
                                          ? 'Selecciona la cuenta de origen para ver su saldo.'
                                          : 'Configura una cuenta de un banco activo para registrar transferencias.'
                                      : 'Configura el saldo inicial de efectivo para registrar adelantos en caja.'
                            }}
                        </FieldDescription>
                    </template>
                </HighlightedAmountField>
                <template v-if="isTransfer">
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
                <Field
                    v-if="replacement"
                    :data-invalid="!!form.errors.void_reason"
                >
                    <FieldLabel for="advance-reason"
                        >Motivo de la corrección</FieldLabel
                    >
                    <Input
                        id="advance-reason"
                        v-model="form.void_reason"
                        required
                        maxlength="255"
                        class="min-h-11"
                        :aria-invalid="!!form.errors.void_reason"
                    />
                    <FieldError v-if="form.errors.void_reason">{{
                        form.errors.void_reason
                    }}</FieldError>
                </Field>
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
            <InputError :message="submitError" />

            <div class="mt-auto grid gap-2 sm:mt-2 sm:flex sm:justify-end">
                <Button as-child variant="outline" class="min-h-11">
                    <Link :href="index()">Cancelar</Link>
                </Button>
                <Button
                    type="submit"
                    class="min-h-11"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Guardando…' : submitLabel }}
                </Button>
            </div>
        </form>
        <div v-else class="space-y-3">
            <p class="text-sm">
                {{
                    limitCents === 0
                        ? 'Los adelantos están deshabilitados. Edita el límite del empleado para habilitarlos.'
                        : 'Sin cupo disponible. Registra el descuento completo o aumenta el límite.'
                }}
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
