<script setup lang="ts">
import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, useTemplateRef } from 'vue';
import {
    storeAccount,
    updateAccount,
} from '@/actions/App/Http/Controllers/Settings/CashSettingsController';
import Heading from '@/components/Heading.vue';
import OpeningBalanceField from '@/components/OpeningBalanceField.vue';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    fieldValue,
    reviewAmount,
    reviewSubmission,
    inertiaValidation,
} from '@/lib/record-confirmation';
import { edit } from '@/routes/bank-settings';
const props = defineProps<{
    banks: { id: number; name: string }[];
    account: {
        id: number;
        name: string;
        bankName: string;
        balanceCents: number;
        locked: boolean;
    } | null;
}>();

const title = computed(() =>
    props.account ? 'Editar cuenta bancaria' : 'Nueva cuenta bancaria',
);
const formAction = computed(() =>
    props.account ? updateAccount.form(props.account.id) : storeAccount.form(),
);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Bancos', href: edit() }] },
});
function accountReview(data: FormData) {
    const bank =
        props.account?.bankName ??
        props.banks.find(({ id }) => String(id) === fieldValue(data, 'bank_id'))
            ?.name;
    return {
        title: props.account
            ? 'Confirmar cambios de la cuenta'
            : 'Confirmar nueva cuenta bancaria',
        description: props.account?.locked
            ? 'Se actualizará el alias de la cuenta.'
            : 'Se guardarán los datos de la cuenta con el saldo inicial indicado.',
        amounts: props.account?.locked
            ? []
            : [
                  reviewAmount(
                      'Saldo inicial (USD)',
                      fieldValue(data, 'opening_balance'),
                  ),
              ],
        details: [bank, fieldValue(data, 'name')],
    };
}

const reviewForm = useTemplateRef<FormComponentRef>('reviewForm');
</script>

<template>
    <section class="flex max-w-xl flex-col gap-5">
        <Head :title="title" />
        <Heading variant="small" :title="title" />
        <Form
            ref="reviewForm"
            novalidate
            v-if="account || banks.length"
            :key="account?.id ?? 'new'"
            v-bind="formAction"
            v-slot="{ errors, processing }"
            error-bag="bankAccount"
            @submit.capture="
                reviewSubmission(
                    $event,
                    accountReview,
                    inertiaValidation(reviewForm),
                )
            "
        >
            <FieldGroup class="gap-5">
                <Field
                    :data-invalid="!!errors.bank_id"
                    :data-disabled="!!account"
                >
                    <FieldLabel for="account-bank">Banco</FieldLabel>
                    <Input
                        v-if="account"
                        id="account-bank"
                        class="min-h-11"
                        :model-value="account.bankName"
                        disabled
                    />
                    <Select v-else name="bank_id" required>
                        <SelectTrigger
                            id="account-bank"
                            class="min-h-11 w-full"
                            :aria-invalid="!!errors.bank_id"
                            :aria-describedby="
                                errors.bank_id
                                    ? 'account-bank-error'
                                    : undefined
                            "
                        >
                            <SelectValue placeholder="Selecciona el banco" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="bank in banks"
                                    :key="bank.id"
                                    :value="String(bank.id)"
                                >
                                    {{ bank.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <FieldError v-if="errors.bank_id" id="account-bank-error">{{
                        errors.bank_id
                    }}</FieldError>
                </Field>
                <Field :data-invalid="!!errors.name">
                    <FieldLabel for="account-name"
                        >Alias de la cuenta</FieldLabel
                    >
                    <Input
                        id="account-name"
                        :default-value="account?.name"
                        name="name"
                        class="min-h-11"
                        maxlength="100"
                        :aria-invalid="!!errors.name"
                        :aria-describedby="
                            errors.name ? 'account-name-error' : undefined
                        "
                        required
                    />
                    <FieldError v-if="errors.name" id="account-name-error">{{
                        errors.name
                    }}</FieldError>
                </Field>
                <OpeningBalanceField
                    id="account-opening"
                    :balance-cents="account?.balanceCents"
                    :disabled="account?.locked"
                    :description="
                        account?.locked
                            ? 'El saldo inicial ya se usó en un movimiento.'
                            : undefined
                    "
                    :error="errors.opening_balance"
                />
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="submit"
                        class="min-h-11 w-full sm:w-auto"
                        :disabled="processing"
                    >
                        {{ account ? 'Guardar cambios' : 'Guardar cuenta' }}
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="min-h-11 w-full sm:w-auto"
                    >
                        <Link :href="edit()">Cancelar</Link>
                    </Button>
                </div>
            </FieldGroup>
        </Form>
        <div v-else class="flex flex-col items-start gap-4">
            <p class="text-sm text-muted-foreground">
                Agrega o activa un banco para registrar una cuenta.
            </p>
            <Button
                as-child
                variant="outline"
                class="min-h-11 w-full sm:w-auto"
            >
                <Link :href="edit()">Volver a Bancos</Link>
            </Button>
        </div>
    </section>
</template>
