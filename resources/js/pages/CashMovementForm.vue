<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CashMovementVoidReason from '@/components/CashMovementVoidReason.vue';
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
import { paymentOptions } from '@/lib/cash-movement';
import { formatUsd } from '@/lib/money';
import {
    annul,
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
    banks: { id: number; name: string }[];
    originBanks: { id: number; name: string }[];
    responsibleUsers: { id: number; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Movimientos', href: index() }] },
});

const page = usePage();
const method = ref<string>(props.replacement?.paymentMethod ?? 'cash');
const isDeposit = computed(() => props.kind === 'deposit');
const isExpense = computed(() => props.kind === 'expense');
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
</script>

<template>
    <section class="flex w-full max-w-xl flex-col gap-5 lg:mx-auto">
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
            class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-y py-3 text-sm"
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
                <dt class="text-muted-foreground">Monto</dt>
                <dd class="font-semibold whitespace-nowrap tabular-nums">
                    {{ formatUsd(movement.amountCents) }}
                </dd>
            </div>
        </dl>

        <Form
            v-bind="action"
            v-slot="{ errors, processing }"
            error-bag="movementForm"
        >
            <FieldGroup class="gap-4">
                <template v-if="!movement">
                    <input type="hidden" name="request_id" :value="requestId" />
                    <input type="hidden" name="kind" :value="kind" />

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

                    <Field :data-invalid="!!errors.amount">
                        <FieldLabel for="amount">Monto (USD)</FieldLabel>
                        <Input
                            id="amount"
                            name="amount"
                            class="min-h-11"
                            :default-value="replacement?.amount"
                            inputmode="decimal"
                            placeholder="0,00"
                            :aria-invalid="!!errors.amount"
                            :aria-describedby="
                                [
                                    isDeposit ? 'deposit-cash' : null,
                                    errors.amount ? 'amount-error' : null,
                                ]
                                    .filter(Boolean)
                                    .join(' ') || undefined
                            "
                            required
                        />
                        <FieldDescription v-if="isDeposit" id="deposit-cash">
                            {{
                                depositCashCents == null
                                    ? 'Configura el saldo inicial de efectivo para registrar un retiro.'
                                    : (replacement
                                          ? 'Efectivo disponible para el reemplazo: '
                                          : 'Efectivo disponible en caja: ') +
                                      formatUsd(Math.max(0, depositCashCents)) +
                                      '.'
                            }}
                        </FieldDescription>
                        <FieldError v-if="errors.amount" id="amount-error">{{
                            errors.amount
                        }}</FieldError>
                    </Field>

                    <SelectField
                        v-if="isDeposit"
                        name="responsible_id"
                        label="Responsable del efectivo"
                        placeholder="Selecciona a la persona que recibe el efectivo"
                        :options="userOptions"
                        :default-value="replacement?.responsibleId?.toString()"
                        :error="errors.responsible_id"
                    />
                    <SelectField
                        v-else
                        v-model="method"
                        name="payment_method"
                        :label="
                            isExpense ? 'Origen del dinero' : 'Forma de pago'
                        "
                        :placeholder="
                            isExpense
                                ? 'Selecciona el origen del dinero'
                                : 'Selecciona la forma de pago'
                        "
                        :options="paymentOptions"
                        :error="errors.payment_method"
                    />
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

                <Field
                    v-if="
                        !movement &&
                        page.props.auth.canManageMovementSensitivity
                    "
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
                        <FieldDescription id="movement-sensitive-description">
                            El operador verá una descripción genérica, la fecha,
                            el estado, la cuenta, el importe y quién lo
                            registró.
                        </FieldDescription>
                        <FieldError
                            v-if="errors.is_sensitive"
                            id="movement-sensitive-error"
                            >{{ errors.is_sensitive }}</FieldError
                        >
                    </div>
                </Field>

                <SelectField
                    v-if="selectAccount && banks.length"
                    name="bank_account_id"
                    :label="
                        isExpense ? 'Cuenta de origen' : 'Cuenta de destino'
                    "
                    placeholder="Selecciona la cuenta bancaria"
                    :options="bankOptions"
                    :default-value="replacement?.accountId?.toString()"
                    :error="errors.bank_account_id"
                />
                <p
                    v-else-if="selectAccount"
                    class="text-sm text-muted-foreground"
                >
                    La administradora debe registrar una cuenta de un banco
                    activo.
                </p>

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
                        :disabled="
                            processing || (selectAccount && !banks.length)
                        "
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
                        :disabled="processing || !banks.length"
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
                <Form
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
                v-bind="returnToCash.form(movement.id)"
                v-slot="{ errors, processing }"
                error-bag="returnDeposit"
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
