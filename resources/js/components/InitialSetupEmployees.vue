<script setup lang="ts">
import type { Errors } from '@inertiajs/core';
import { Plus } from '@lucide/vue';
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
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

defineProps<{ errors: Errors; limitCents: number }>();
const emit = defineEmits<{ removed: [] }>();
const employees = defineModel<number[]>({ required: true });
let nextEmployee = 0;

function remove(index: number) {
    employees.value.splice(index, 1);
    emit('removed');
}

const passwords = [
    { field: 'password', label: 'Contraseña inicial' },
    { field: 'password_confirmation', label: 'Confirmar contraseña' },
] as const;

function fieldName(index: number, field: string) {
    return 'employees[' + index + '][' + field + ']';
}

function errorFor(errors: Errors, index: number, field: string) {
    return errors['employees.' + index + '.' + field];
}
</script>

<template>
    <FieldSet>
        <FieldLegend class="sr-only">Empleados</FieldLegend>
        <FieldError v-if="errors.employees">{{ errors.employees }}</FieldError>
        <FieldGroup
            v-for="(employee, index) in employees"
            :key="employee"
            class="gap-4 border-b pb-5"
        >
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-medium"
                    >Empleado {{ index + 1 }}</span
                >
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :aria-label="'Quitar empleado ' + (index + 1)"
                    @click="remove(index)"
                    >Quitar</Button
                >
            </div>
            <FieldError v-if="errors['employees.' + index]">{{
                errors['employees.' + index]
            }}</FieldError>
            <FieldGroup class="grid gap-4 sm:grid-cols-2">
                <Field :data-invalid="!!errorFor(errors, index, 'name')">
                    <FieldLabel :for="'setup-employee-name-' + employee"
                        >Nombre completo</FieldLabel
                    >
                    <Input
                        :id="'setup-employee-name-' + employee"
                        :name="fieldName(index, 'name')"
                        :aria-invalid="!!errorFor(errors, index, 'name')"
                        required
                    />
                    <FieldError v-if="errorFor(errors, index, 'name')">{{
                        errorFor(errors, index, 'name')
                    }}</FieldError>
                </Field>
                <AccountIdentifierField
                    :id="'setup-employee-username-' + employee"
                    :name="fieldName(index, 'username')"
                    field="username"
                    server-validation-only
                    :error="errorFor(errors, index, 'username')"
                />
                <Field
                    v-for="{ field, label } in passwords"
                    :key="field"
                    :data-invalid="!!errorFor(errors, index, field)"
                >
                    <FieldLabel
                        :for="'setup-employee-' + field + '-' + employee"
                        >{{ label }}</FieldLabel
                    >
                    <PasswordInput
                        :id="'setup-employee-' + field + '-' + employee"
                        :name="fieldName(index, field)"
                        :aria-invalid="!!errorFor(errors, index, field)"
                        required
                    />
                    <FieldError v-if="errorFor(errors, index, field)">{{
                        errorFor(errors, index, field)
                    }}</FieldError>
                </Field>
                <OpeningBalanceField
                    :id="'setup-employee-limit-' + employee"
                    :name="fieldName(index, 'advance_limit')"
                    label="Límite máximo de adelantos (USD)"
                    :balance-cents="limitCents"
                    :error="errorFor(errors, index, 'advance_limit')"
                />
            </FieldGroup>
        </FieldGroup>
        <FieldDescription
            >La contraseña inicial de cada empleado debe tener al menos 8
            caracteres.</FieldDescription
        >
        <Button
            type="button"
            variant="outline"
            class="min-h-11 w-full sm:w-fit"
            @click="employees.push(nextEmployee++)"
        >
            <Plus data-icon="inline-start" />
            Agregar empleado
        </Button>
    </FieldSet>
</template>
