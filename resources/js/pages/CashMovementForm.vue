<script setup lang="ts">
import { Form, Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import { createReusableTemplate } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CashMovementVoidReason from '@/components/CashMovementVoidReason.vue';
import HighlightedAmountField from '@/components/HighlightedAmountField.vue';
import RecordAmounts from '@/components/RecordAmounts.vue';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { movementLabels, paymentOptions } from '@/lib/cash-movement';
import { formatUsd, parseUsdCents } from '@/lib/money';
import {
    fieldValue,
    reviewAmount,
    reviewSubmission,
} from '@/lib/record-confirmation';
import {
    annul,
    checkBankFunds,
    index,
    replace,
    returnToCash,
    store,
    update,
} from '@/routes/movements';
import type {
    CashMovement,
    MovementKind,
    PaymentMethod,
} from '@/types/cash-movement';

const props = defineProps<{
    kind: Exclude<MovementKind, 'advance'>;
    requestId: string | null;
    movement: CashMovement | null;
    replacement?: {
        id: number;
        date: string;
        description: string;
        amount: string;
        paymentMethod: PaymentMethod;
        accountId: number | null;
        originBankId: number | null;
        voucher: string | null;
        responsibleId: number | null;
        confirmed: boolean;
        isSensitive: boolean;
    } | null;
    depositCashCents?: number | null;
    expenseCashCents?: number | null;
    banks: { id: number; name: string; balanceCents?: number }[];
    originBanks: { id: number; name: string }[];
    responsibleUsers: { id: number; name: string }[];
    defaultResponsibleId?: number | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Movimientos', href: index() }] },
});

const page = usePage();
const amount = ref(props.replacement?.amount ?? '');
const method = ref<string>(
    props.replacement?.paymentMethod ??
        (props.kind === 'expense' ? '' : 'cash'),
);
const accountId = ref(props.replacement?.accountId?.toString() ?? '');
const isDeposit = computed(() => props.kind === 'deposit');
const isExpense = computed(() => props.kind === 'expense');
const [DefinePaymentMethod, PaymentMethodField] = createReusableTemplate();
const [DefineAccount, AccountField] = createReusableTemplate();
const isTransfer = computed(
    () => (props.movement?.paymentMethod ?? method.value) === 'transfer',
);
const depositConfirmation = computed(
    () => isDeposit.value && props.movement !== null,
);
const confirmedDepositReplacement = computed(
    () => isDeposit.value && props.replacement?.confirmed === true,
);
const selectAccount = computed(
    () =>
        depositConfirmation.value ||
        confirmedDepositReplacement.value ||
        (props.movement === null && isTransfer.value),
);
const needsFunds = computed(
    () => !props.movement && (isDeposit.value || isExpense.value),
);
const selectedBank = computed(() =>
    props.banks.find((bank) => String(bank.id) === accountId.value),
);
const availableCents = computed(() => {
    if (!needsFunds.value || (isExpense.value && !method.value)) {
        return null;
    }

    const balance = isDeposit.value
        ? props.depositCashCents
        : isTransfer.value
          ? selectedBank.value?.balanceCents
          : props.expenseCashCents;

    return balance == null ? null : Math.max(0, balance);
});
const enteredCents = computed(() => parseUsdCents(amount.value));
const requiresBankCheck = computed(
    () =>
        needsFunds.value &&
        isExpense.value &&
        isTransfer.value &&
        selectedBank.value !== undefined &&
        selectedBank.value.balanceCents === undefined,
);
const bankCheckKey = computed(() =>
    requiresBankCheck.value &&
    enteredCents.value !== null &&
    enteredCents.value > 0
        ? accountId.value + ':' + enteredCents.value
        : null,
);
const fundsCheck = useHttp({ amount: '', bank_account_id: '' });
const bankCheckResult = ref<{ key: string; error: string | null } | null>(null);

watch(
    bankCheckKey,
    (key, _, onCleanup) => {
        bankCheckResult.value = null;
        if (key === null) {
            return;
        }

        let active = true;
        const timer = setTimeout(() => {
            fundsCheck.clearErrors();
            fundsCheck.amount = amount.value;
            fundsCheck.bank_account_id = accountId.value;
            void fundsCheck.submit(checkBankFunds()).then(
                () => {
                    if (active) bankCheckResult.value = { key, error: null };
                },
                () => {
                    if (active) {
                        bankCheckResult.value = {
                            key,
                            error:
                                fundsCheck.errors.amount ??
                                fundsCheck.errors.bank_account_id ??
                                'No se pudo verificar el saldo bancario. Revisa el monto o la cuenta e inténtalo de nuevo.',
                        };
                    }
                },
            );
        }, 250);

        onCleanup(() => {
            active = false;
            clearTimeout(timer);
            fundsCheck.cancel();
        });
    },
    { immediate: true },
);

const checkingBankFunds = computed(
    () =>
        bankCheckKey.value !== null &&
        bankCheckResult.value?.key !== bankCheckKey.value,
);
const fundsError = computed(() => {
    if (
        bankCheckKey.value !== null &&
        bankCheckResult.value?.key === bankCheckKey.value
    ) {
        return bankCheckResult.value.error;
    }

    if (
        enteredCents.value === null ||
        availableCents.value === null ||
        enteredCents.value <= availableCents.value
    ) {
        return null;
    }

    const source =
        isExpense.value && isTransfer.value ? 'la cuenta seleccionada' : 'caja';
    return 'El monto supera el saldo disponible en ' + source + '.';
});
const fundsHint = computed(() => {
    if (isExpense.value && !method.value) {
        return 'Selecciona la forma de pago para comprobar la disponibilidad.';
    }
    if (!(isExpense.value && isTransfer.value)) {
        return 'Configura el saldo inicial de efectivo para registrar este movimiento.';
    }
    if (!selectedBank.value) {
        return props.banks.length
            ? 'Selecciona la cuenta de origen para comprobar la disponibilidad.'
            : 'Configura una cuenta de un banco activo para registrar el egreso.';
    }
    if (checkingBankFunds.value) {
        return 'Verificando disponibilidad…';
    }
    if (
        bankCheckResult.value?.key === bankCheckKey.value &&
        bankCheckResult.value?.error === null
    ) {
        return 'Monto dentro del saldo disponible.';
    }
    return 'El saldo se comprueba al ingresar el monto.';
});
const canConfirmOnSave = computed(
    () => !props.movement && props.kind === 'income' && isTransfer.value,
);
const preferConfirmation = computed(
    () => canConfirmOnSave.value && props.replacement?.confirmed === true,
);
const title = computed(() => {
    if (props.replacement) {
        return 'Corregir movimiento';
    }

    if (props.movement) {
        return isDeposit.value ? 'Gestionar depósito' : 'Confirmar cobro';
    }

    return {
        income: 'Nuevo ingreso',
        expense: 'Nuevo egreso',
        deposit: 'Nuevo depósito',
    }[props.kind];
});
const submitLabel = computed(() =>
    depositConfirmation.value
        ? 'Confirmar depósito'
        : props.movement
          ? 'Confirmar cobro'
          : preferConfirmation.value
            ? 'Reemplazar y confirmar'
            : props.replacement
              ? 'Anular y reemplazar'
              : 'Guardar',
);
const action = computed(() => {
    if (props.replacement) {
        return replace.form(props.replacement.id);
    }

    return props.movement ? update.form(props.movement.id) : store.form();
});
const back = computed(() => {
    const date = props.replacement?.date ?? props.movement?.date;

    return index({ query: date ? { date } : {} });
});
const originOptions = computed(() =>
    props.originBanks.map(({ id, name }) => ({
        value: String(id),
        label: name,
    })),
);
const bankOptions = computed(() =>
    props.banks.map(({ id, name }) => ({ value: String(id), label: name })),
);
const userOptions = computed(() =>
    props.responsibleUsers.map(({ id, name }) => ({
        value: String(id),
        label: name,
    })),
);
function movementReview(data: FormData) {
    const kind = props.kind;
    const transfer = isTransfer.value;
    const confirms =
        !!props.movement ||
        confirmedDepositReplacement.value ||
        (kind === 'income' &&
            (!transfer || fieldValue(data, 'confirm') === '1'));
    const bank = props.banks.find(
        ({ id }) => String(id) === fieldValue(data, 'bank_account_id'),
    );
    const origin = props.originBanks.find(
        ({ id }) => String(id) === fieldValue(data, 'origin_bank_id'),
    );
    const responsible = props.responsibleUsers.find(
        ({ id }) => String(id) === fieldValue(data, 'responsible_id'),
    );
    const voucher = fieldValue(data, 'voucher');
    const paymentMethod = props.movement?.paymentMethod ?? method.value;
    const descriptions = {
        income: confirms
            ? 'El ingreso se acreditará en el saldo correspondiente.'
            : 'El ingreso quedará pendiente y se acreditará cuando se confirme.',
        expense: 'El monto se descontará del saldo de origen.',
        deposit: confirms
            ? 'El monto se acreditará en la cuenta bancaria de destino.'
            : 'El efectivo saldrá de caja y quedará en tránsito hasta confirmar el depósito.',
    };
    const movement = movementLabels[kind].toLowerCase();
    return {
        title: props.replacement
            ? 'Confirmar corrección de ' + movement
            : props.movement
              ? 'Confirmar ' + movement
              : 'Registrar ' + movement,
        description:
            (props.replacement ? 'Se anulará el registro original. ' : '') +
            descriptions[kind],
        amounts: [
            ...(props.replacement
                ? [
                      reviewAmount(
                          'Monto original (USD)',
                          props.replacement.amount,
                      ),
                  ]
                : []),
            reviewAmount(
                props.replacement ? 'Monto nuevo (USD)' : 'Monto (USD)',
                props.movement?.amountCents ?? fieldValue(data, 'amount'),
            ),
        ],
        details: [
            !isDeposit.value
                ? paymentOptions.find(
                      (option) => option.value === paymentMethod,
                  )?.label
                : undefined,
            origin && 'Banco de origen · ' + origin.name,
            bank &&
                (isExpense.value
                    ? 'Cuenta de origen · '
                    : 'Cuenta de destino · ') + bank.name,
            responsible && 'Efectivo a cargo de ' + responsible.name,
            voucher && 'Voucher · ' + voucher,
        ],
    };
}
</script>

<template>
    <section class="flex w-full flex-1 flex-col gap-6">
        <Head :title="title" />
        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>

        <p v-if="replacement" class="text-sm text-muted-foreground">
            Movimiento #{{ replacement.id }} · {{ replacement.description }} ·
            {{ replacement.date }}. Al guardar, se anula el original y se
            registra el reemplazo.
        </p>
        <p
            v-else-if="isDeposit && !movement"
            class="text-sm text-muted-foreground"
        >
            Registra el retiro de efectivo. El monto quedará en tránsito hasta
            confirmar el depósito bancario.
        </p>

        <dl
            v-if="movement"
            class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 rounded-lg border border-primary/25 bg-primary/5 p-4 text-sm"
        >
            <div class="min-w-0">
                <dt class="text-muted-foreground">
                    {{ isDeposit ? 'Retiro de efectivo' : 'Cobro' }}
                </dt>
                <dd class="font-medium wrap-break-word">
                    {{
                        isDeposit
                            ? movement.responsibleName
                            : movement.description
                    }}
                </dd>
                <dd class="text-xs wrap-break-word text-muted-foreground">
                    {{
                        isDeposit
                            ? movement.date
                            : isTransfer
                              ? 'Transferencia'
                              : 'Efectivo'
                    }}
                </dd>
                <dd
                    v-if="movement.originBankName"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Origen: {{ movement.originBankName }}
                </dd>
                <dd
                    v-if="movement.accountBankName"
                    class="text-xs wrap-break-word text-muted-foreground"
                >
                    Destino: {{ movement.accountBankName }} ·
                    {{ movement.accountName }}
                </dd>
            </div>
            <div class="text-right">
                <dt class="text-muted-foreground">Monto (USD)</dt>
                <dd
                    class="text-2xl font-semibold whitespace-nowrap tabular-nums sm:text-3xl"
                >
                    {{ formatUsd(movement.amountCents) }}
                </dd>
            </div>
        </dl>

        <Form
            novalidate
            v-bind="action"
            v-slot="{ errors, processing, clearErrors }"
            error-bag="movementForm"
            @submit.capture="reviewSubmission($event, movementReview)"
        >
            <DefinePaymentMethod>
                <SelectField
                    v-model="method"
                    name="payment_method"
                    :label="
                        isExpense ? 'Forma de pago del egreso' : 'Forma de pago'
                    "
                    :placeholder="
                        isExpense
                            ? 'Selecciona el origen del dinero'
                            : 'Selecciona la forma de pago'
                    "
                    :options="paymentOptions"
                    :error="errors.payment_method"
                    @update:model-value="clearErrors('amount')"
                />
            </DefinePaymentMethod>
            <DefineAccount>
                <SelectField
                    v-if="selectAccount && banks.length"
                    v-model="accountId"
                    name="bank_account_id"
                    :label="
                        isExpense ? 'Cuenta de origen' : 'Cuenta de destino'
                    "
                    placeholder="Selecciona la cuenta bancaria"
                    :options="bankOptions"
                    :error="errors.bank_account_id"
                    @update:model-value="clearErrors('amount')"
                />
                <p
                    v-else-if="selectAccount"
                    class="text-sm text-muted-foreground"
                >
                    La administradora debe registrar una cuenta de un banco
                    activo.
                </p>
                <FieldError
                    v-if="
                        selectAccount && !banks.length && errors.bank_account_id
                    "
                >
                    {{ errors.bank_account_id }}
                </FieldError>
            </DefineAccount>

            <FieldGroup class="gap-4">
                <template v-if="!movement">
                    <input type="hidden" name="request_id" :value="requestId" />
                    <input type="hidden" name="kind" :value="kind" />

                    <template v-if="isExpense">
                        <PaymentMethodField />
                        <AccountField />
                    </template>

                    <HighlightedAmountField
                        id="amount"
                        v-model="amount"
                        name="amount"
                        :label="
                            {
                                income: 'Monto del ingreso',
                                expense: 'Monto del egreso',
                                deposit: 'Monto del depósito',
                            }[kind]
                        "
                        :error="errors.amount ?? fundsError"
                        :alert-error="!!fundsError"
                        @update:model-value="clearErrors('amount')"
                    >
                        <template v-if="needsFunds" #context>
                            <dl v-if="availableCents !== null" class="text-sm">
                                <dt class="text-muted-foreground">
                                    {{
                                        isExpense && isTransfer
                                            ? 'Saldo disponible en la cuenta de origen'
                                            : 'Efectivo disponible en caja'
                                    }}
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{ formatUsd(availableCents) }}
                                </dd>
                            </dl>
                            <FieldDescription v-else aria-live="polite">{{
                                fundsHint
                            }}</FieldDescription>
                        </template>
                    </HighlightedAmountField>

                    <Field
                        v-if="!isDeposit"
                        :data-invalid="!!errors.description"
                    >
                        <FieldLabel for="description">Descripción</FieldLabel>
                        <Input
                            id="description"
                            name="description"
                            class="min-h-11"
                            :default-value="replacement?.description"
                            maxlength="255"
                            :aria-invalid="!!errors.description"
                            :aria-describedby="
                                errors.description
                                    ? 'description-error'
                                    : undefined
                            "
                            required
                        />
                        <FieldError
                            v-if="errors.description"
                            id="description-error"
                            >{{ errors.description }}</FieldError
                        >
                    </Field>

                    <Field
                        v-if="page.props.auth.canManageMovementSensitivity"
                        orientation="horizontal"
                        :data-invalid="!!errors.is_sensitive"
                    >
                        <Checkbox
                            id="movement-sensitive"
                            name="is_sensitive"
                            value="1"
                            :default-value="replacement?.isSensitive"
                            :aria-invalid="!!errors.is_sensitive"
                            :aria-describedby="
                                errors.is_sensitive
                                    ? 'movement-sensitive-error'
                                    : 'movement-sensitive-description'
                            "
                        />
                        <div class="flex flex-col gap-1">
                            <FieldLabel for="movement-sensitive"
                                >Movimiento sensible</FieldLabel
                            >
                            <FieldDescription
                                id="movement-sensitive-description"
                            >
                                El operador verá una descripción genérica, la
                                fecha, el estado, la cuenta, el importe y quién
                                lo registró.
                            </FieldDescription>
                            <FieldError
                                v-if="errors.is_sensitive"
                                id="movement-sensitive-error"
                                >{{ errors.is_sensitive }}</FieldError
                            >
                        </div>
                    </Field>

                    <SelectField
                        v-if="isDeposit"
                        name="responsible_id"
                        label="Custodio del depósito"
                        placeholder="Selecciona a la persona que recibe el efectivo"
                        :options="userOptions"
                        :default-value="
                            (
                                replacement?.responsibleId ??
                                defaultResponsibleId
                            )?.toString()
                        "
                        :error="errors.responsible_id"
                    />
                    <PaymentMethodField v-else-if="!isExpense" />
                </template>

                <SelectField
                    v-if="
                        !movement &&
                        kind === 'income' &&
                        isTransfer &&
                        originBanks.length
                    "
                    name="origin_bank_id"
                    label="Banco de origen"
                    placeholder="Selecciona el banco del huésped"
                    :options="originOptions"
                    :default-value="replacement?.originBankId?.toString()"
                    :error="errors.origin_bank_id"
                />

                <FieldError v-else-if="errors.origin_bank_id">
                    {{ errors.origin_bank_id }}
                </FieldError>

                <AccountField v-if="!isExpense" />

                <Field
                    v-if="
                        isTransfer ||
                        depositConfirmation ||
                        confirmedDepositReplacement
                    "
                    :data-invalid="!!errors.voucher"
                >
                    <FieldLabel for="voucher">Número de voucher</FieldLabel>
                    <Input
                        id="voucher"
                        name="voucher"
                        class="min-h-11"
                        maxlength="100"
                        :default-value="
                            movement?.voucher ??
                            replacement?.voucher ??
                            undefined
                        "
                        :required="
                            !!movement ||
                            isExpense ||
                            confirmedDepositReplacement
                        "
                        :aria-invalid="!!errors.voucher"
                        :aria-describedby="
                            errors.voucher ? 'voucher-error' : undefined
                        "
                    />
                    <FieldDescription v-if="!isExpense">
                        {{
                            canConfirmOnSave
                                ? 'El voucher se exige al confirmar. Verifica la acreditación antes de confirmar.'
                                : 'Verifica la acreditación en el banco antes de confirmar.'
                        }}
                    </FieldDescription>
                    <FieldError v-if="errors.voucher" id="voucher-error">{{
                        errors.voucher
                    }}</FieldError>
                </Field>

                <CashMovementVoidReason
                    v-if="replacement"
                    id="replace-reason"
                    :error="errors.void_reason"
                />
                <FieldError v-if="movement && errors.amount">{{
                    errors.amount
                }}</FieldError>
                <FieldError v-if="errors.movement">{{
                    errors.movement
                }}</FieldError>
                <FieldError v-if="errors.request_id">{{
                    errors.request_id
                }}</FieldError>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="submit"
                        :name="canConfirmOnSave ? 'confirm' : undefined"
                        :value="preferConfirmation ? '1' : '0'"
                        class="min-h-11 w-full sm:w-auto"
                        :disabled="processing"
                    >
                        {{ submitLabel }}
                    </Button>
                    <Button
                        v-if="canConfirmOnSave"
                        type="submit"
                        name="confirm"
                        :value="preferConfirmation ? '0' : '1'"
                        variant="secondary"
                        class="min-h-11 w-full sm:w-auto"
                        :disabled="processing"
                    >
                        {{
                            preferConfirmation
                                ? 'Reemplazar como pendiente'
                                : replacement
                                  ? 'Reemplazar y confirmar'
                                  : 'Guardar y confirmar'
                        }}
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="min-h-11 w-full sm:w-auto"
                    >
                        <Link :href="back">Cancelar</Link>
                    </Button>
                </div>
            </FieldGroup>
        </Form>
        <Dialog v-if="replacement">
            <DialogTrigger as-child>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 w-full sm:w-fit"
                >
                    Anular sin reemplazo
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Anular movimiento #{{ replacement.id }}</DialogTitle
                    >
                    <DialogDescription>
                        El movimiento seguirá en el historial como anulado y su
                        importe dejará de contar en el saldo.
                    </DialogDescription>
                </DialogHeader>
                <RecordAmounts
                    :amounts="[
                        reviewAmount(
                            'Monto a anular (USD)',
                            replacement.amount,
                        ),
                    ]"
                />
                <Form
                    novalidate
                    v-bind="annul.form(replacement.id)"
                    v-slot="{ errors, processing }"
                    error-bag="annulMovement"
                    class="flex flex-col gap-4"
                >
                    <FieldGroup>
                        <CashMovementVoidReason
                            id="annul-reason"
                            :error="errors.void_reason"
                        />
                    </FieldGroup>
                    <FieldError v-if="errors.movement">{{
                        errors.movement
                    }}</FieldError>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            class="min-h-11"
                            :disabled="processing"
                        >
                            Anular movimiento
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
        <template v-if="movement && isDeposit">
            <Separator />
            <Form
                novalidate
                v-bind="returnToCash.form(movement.id)"
                v-slot="{ errors, processing }"
                error-bag="returnDeposit"
                @submit.capture="
                    reviewSubmission($event, {
                        title: 'Confirmar devolución a caja',
                        description:
                            'El dinero saldrá de tránsito y volverá al saldo de efectivo.',
                        amounts: [
                            reviewAmount(
                                'Efectivo devuelto (USD)',
                                movement.amountCents,
                            ),
                        ],
                        confirmLabel: 'Confirmar devolución',
                    })
                "
                class="flex flex-col items-start gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Usa esta opción cuando el efectivo ya volvió a caja.
                </p>
                <Button
                    type="submit"
                    variant="outline"
                    class="min-h-11 w-full sm:w-auto"
                    :disabled="processing"
                >
                    Devolver a efectivo
                </Button>
                <FieldError v-if="errors.movement">{{
                    errors.movement
                }}</FieldError>
            </Form>
        </template>
    </section>
</template>
