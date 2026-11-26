<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import AccountIdentifierField from '@/components/AccountIdentifierField.vue';
import OpeningBalanceField from '@/components/OpeningBalanceField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSeparator,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/initial-setup';

defineProps<{ passwordRules: string }>();

defineOptions({
    layout: {
        title: 'Configuración inicial',
        description:
            'Crea tu cuenta administradora y registra los saldos con los que empieza el hostal.',
        wide: true,
    },
});

const accounts = ref<number[]>([]);
let nextAccount = 0;
</script>

<template>
    <Head title="Configuración inicial" />

    <Form
        v-bind="store.form()"
        v-slot="{ errors, processing, clearErrors }"
        autocomplete="off"
        :reset-on-success="['password', 'password_confirmation']"
        disable-while-processing
    >
        <FieldGroup>
            <FieldSet>
                <FieldLegend>Cuenta administradora</FieldLegend>
                <FieldGroup class="grid gap-5 sm:grid-cols-2">
                    <Field :data-invalid="!!errors.name">
                        <FieldLabel for="setup-name"
                            >Nombre completo</FieldLabel
                        >
                        <Input
                            id="setup-name"
                            name="name"
                            maxlength="255"
                            :aria-invalid="!!errors.name"
                            required
                        />
                        <FieldError v-if="errors.name">{{
                            errors.name
                        }}</FieldError>
                    </Field>
                    <AccountIdentifierField
                        id="setup-username"
                        field="username"
                        :error="errors.username"
                    />
                    <Field :data-invalid="!!errors.password">
                        <FieldLabel for="setup-secret">Contraseña</FieldLabel>
                        <PasswordInput
                            id="setup-secret"
                            name="password"
                            :passwordrules="passwordRules"
                            :aria-invalid="!!errors.password"
                            required
                        />
                        <FieldError v-if="errors.password">{{
                            errors.password
                        }}</FieldError>
                    </Field>
                    <Field :data-invalid="!!errors.password_confirmation">
                        <FieldLabel for="setup-confirmation"
                            >Confirmar contraseña</FieldLabel
                        >
                        <PasswordInput
                            id="setup-confirmation"
                            name="password_confirmation"
                            :aria-invalid="!!errors.password_confirmation"
                            required
                        />
                        <FieldError v-if="errors.password_confirmation">{{
                            errors.password_confirmation
                        }}</FieldError>
                    </Field>
                </FieldGroup>
                <FieldDescription
                    >Usa al menos 12 caracteres, con mayúsculas, minúsculas, un
                    número y un símbolo.</FieldDescription
                >
            </FieldSet>

            <FieldSeparator />

            <FieldSet>
                <FieldLegend>Efectivo en caja</FieldLegend>
                <OpeningBalanceField
                    id="setup-cash"
                    :error="errors.opening_balance"
                    description="Ingresa el efectivo contado antes de comenzar a registrar movimientos."
                />
            </FieldSet>

            <FieldSeparator />

            <FieldSet>
                <FieldLegend>Cuentas bancarias</FieldLegend>
                <FieldDescription
                    >Puedes continuar sin cuentas y agregarlas después en
                    Bancos.</FieldDescription
                >
                <FieldError v-if="errors.accounts">{{
                    errors.accounts
                }}</FieldError>
                <FieldGroup
                    v-for="(account, index) in accounts"
                    :key="account"
                    class="gap-4 border-b pb-5"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium"
                            >Cuenta {{ index + 1 }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :aria-label="`Quitar cuenta ${index + 1}`"
                            @click="
                                accounts.splice(index, 1);
                                clearErrors();
                            "
                            >Quitar</Button
                        >
                    </div>
                    <FieldGroup class="grid gap-4 sm:grid-cols-2">
                        <Field
                            :data-invalid="!!errors[`accounts.${index}.bank`]"
                        >
                            <FieldLabel :for="`setup-bank-${account}`"
                                >Banco</FieldLabel
                            >
                            <Input
                                :id="`setup-bank-${account}`"
                                :name="`accounts[${index}][bank]`"
                                maxlength="100"
                                :aria-invalid="
                                    !!errors[`accounts.${index}.bank`]
                                "
                                required
                            />
                            <FieldError
                                v-if="errors[`accounts.${index}.bank`]"
                                >{{
                                    errors[`accounts.${index}.bank`]
                                }}</FieldError
                            >
                        </Field>
                        <Field
                            :data-invalid="!!errors[`accounts.${index}.name`]"
                        >
                            <FieldLabel :for="`setup-account-${account}`"
                                >Alias de la cuenta</FieldLabel
                            >
                            <Input
                                :id="`setup-account-${account}`"
                                :name="`accounts[${index}][name]`"
                                maxlength="100"
                                :aria-invalid="
                                    !!errors[`accounts.${index}.name`]
                                "
                                required
                            />
                            <FieldError
                                v-if="errors[`accounts.${index}.name`]"
                                >{{
                                    errors[`accounts.${index}.name`]
                                }}</FieldError
                            >
                        </Field>
                    </FieldGroup>
                    <OpeningBalanceField
                        :id="`setup-account-balance-${account}`"
                        :name="`accounts[${index}][opening_balance]`"
                        :error="errors[`accounts.${index}.opening_balance`]"
                    />
                </FieldGroup>
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 w-full sm:w-fit"
                    :disabled="accounts.length >= 20"
                    @click="accounts.push(nextAccount++)"
                >
                    <Plus data-icon="inline-start" />
                    Agregar cuenta bancaria
                </Button>
            </FieldSet>

            <Button
                type="submit"
                class="min-h-11 w-full"
                :disabled="processing"
            >
                {{
                    processing
                        ? 'Guardando configuración…'
                        : 'Guardar e ingresar'
                }}
            </Button>
        </FieldGroup>
    </Form>
</template>
