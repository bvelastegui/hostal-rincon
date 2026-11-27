<script setup lang="ts">
import type { Errors, FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { Check, DatabaseBackup, Plus } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import AccountIdentifierField from '@/components/AccountIdentifierField.vue';
import InitialSetupEmployees from '@/components/InitialSetupEmployees.vue';
import OpeningBalanceField from '@/components/OpeningBalanceField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    fieldValue,
    reviewAmount,
    reviewSubmission,
    inertiaValidation,
} from '@/lib/record-confirmation';
import { restore, store } from '@/routes/initial-setup';

defineProps<{ employeeAdvanceLimitCents: number }>();

defineOptions({
    layout: {
        title: 'Configuración inicial',
        description:
            'Prepara Hostal Rincón para empezar a trabajar en este equipo.',
        wide: true,
    },
});

const details = {
    start: {
        label: 'Inicio',
        title: 'Elige cómo empezar',
        description:
            'Si es la primera vez que usas el sistema, crea la cuenta administradora y registra los saldos iniciales. Si ya tienes un respaldo, podrás recuperar la información de la instalación anterior.',
    },
    account: {
        label: 'Cuenta',
        title: 'Crea la cuenta administradora',
        description:
            'Esta cuenta permitirá configurar la caja y los bancos, administrar usuarios y generar respaldos. El nombre completo identifica a la persona y el nombre de usuario se usará para iniciar sesión.',
    },
    cash: {
        label: 'Caja',
        title: 'Registra el efectivo inicial',
        description:
            'Cuenta los billetes y monedas disponibles al momento de empezar. Ingresa el total en dólares, por ejemplo 125,50. Si la caja está vacía, ingresa 0,00.',
    },
    banks: {
        label: 'Bancos',
        title: 'Registra las cuentas bancarias',
        description:
            'Agrega cada cuenta que utilizará el hostal. Indica el banco, un alias que permita distinguirla y el saldo acreditado al momento de empezar. Puedes agregar las cuentas más adelante desde Ajustes.',
    },
    employees: {
        label: 'Empleados',
        title: 'Agrega empleados (opcional)',
        description:
            'Crea una cuenta de operador para cada persona que manejará la caja. Define su nombre de usuario, una contraseña inicial y su límite de adelantos. Puedes omitir este paso y agregarlos más adelante desde Adelantos.',
    },
    backup: {
        label: 'Respaldo',
        title: 'Selecciona el respaldo que quieres recuperar',
        description:
            'Elige un ZIP generado desde Ajustes → Respaldos. El sistema comprobará la copia y recuperará los usuarios, saldos e historial. Al terminar, inicia sesión con tus credenciales anteriores.',
    },
} as const;

type Step = keyof typeof details;

const mode = ref<'new' | 'restore'>('new');
const step = ref<Step>('start');
const steps = computed<Step[]>(() =>
    mode.value === 'new'
        ? ['start', 'account', 'cash', 'banks', 'employees']
        : ['start', 'backup'],
);
const stepIndex = computed(() => steps.value.indexOf(step.value));
const heading = useTemplateRef<HTMLHeadingElement>('heading');
const setupForm = useTemplateRef<FormComponentRef>('setupForm');
const accounts = ref<number[]>([]);
const employees = ref<number[]>([]);
let nextAccount = 0;

function goTo(next: Step) {
    step.value = next;
    void nextTick(() => heading.value?.focus());
}

function choose(next: 'new' | 'restore') {
    mode.value = next;
    goTo(next === 'new' ? 'account' : 'backup');
}

const stepValidation = {
    account: ['name', 'username', 'password'],
    cash: ['opening_balance'],
    banks: [
        'accounts',
        'accounts.*',
        'accounts.*.bank',
        'accounts.*.name',
        'accounts.*.opening_balance',
    ],
};

function setupReview(data: FormData) {
    if (step.value !== 'employees') {
        return null;
    }

    return {
        title: 'Confirmar configuración inicial',
        description:
            'Se creará la cuenta administradora y se guardarán los saldos y empleados ingresados.',
        amounts: [
            reviewAmount(
                'Efectivo inicial (USD)',
                fieldValue(data, 'opening_balance'),
            ),
            ...accounts.value.map((_, index) => {
                const name = fieldValue(data, 'accounts[' + index + '][name]');
                return reviewAmount(
                    'Saldo de cuenta ' +
                        (index + 1) +
                        (name ? ' · ' + name : '') +
                        ' (USD)',
                    fieldValue(
                        data,
                        'accounts[' + index + '][opening_balance]',
                    ),
                );
            }),
            ...employees.value.map((_, index) => {
                const name = fieldValue(data, 'employees[' + index + '][name]');
                return reviewAmount(
                    'Límite de empleado ' +
                        (index + 1) +
                        (name ? ' · ' + name : '') +
                        ' (USD)',
                    fieldValue(data, 'employees[' + index + '][advance_limit]'),
                );
            }),
        ],
        confirmLabel: 'Guardar configuración',
    };
}

function restoreReview(data: FormData) {
    const file = data.get('backup');
    return {
        title: 'Confirmar restauración del respaldo',
        description:
            'Se recuperarán los usuarios, saldos e historial del respaldo seleccionado.',
        details: [file instanceof File ? file.name : undefined],
        confirmLabel: 'Restaurar respaldo',
    };
}

function nextOrSubmit() {
    const current = step.value;
    if (current === 'employees') {
        return true;
    }

    if (current === 'account' || current === 'cash' || current === 'banks') {
        const next = steps.value[stepIndex.value + 1];
        if (next) {
            setupForm.value?.validate({
                only: stepValidation[current],
                onPrecognitionSuccess: () => goTo(next),
            });
        }
    }

    return false;
}

function showStepWithErrors(errors: Errors) {
    if (errors.name || errors.username || errors.password) {
        goTo('account');
    } else if (errors.opening_balance) {
        goTo('cash');
    } else if (
        Object.keys(errors).some(
            (key) => key === 'accounts' || key.startsWith('accounts.'),
        )
    ) {
        goTo('banks');
    }
}

const restoreForm = useTemplateRef<FormComponentRef>('restoreForm');
</script>

<template>
    <Head title="Configuración inicial" />

    <nav aria-label="Pasos de la configuración">
        <ol class="flex gap-1.5 sm:gap-3">
            <li
                v-for="(item, index) in steps"
                :key="item"
                class="flex min-w-0 flex-1 flex-col items-start gap-2 border-b-2 pb-2"
                :class="index <= stepIndex ? 'border-primary' : 'border-border'"
                :aria-current="item === step ? 'step' : undefined"
            >
                <Badge
                    :variant="index <= stepIndex ? 'default' : 'outline'"
                    class="size-6 justify-center p-0"
                >
                    <Check v-if="index < stepIndex" aria-hidden="true" />
                    <template v-else>{{ index + 1 }}</template>
                </Badge>
                <span
                    :class="
                        item === step
                            ? 'font-semibold'
                            : 'text-muted-foreground'
                    "
                    class="text-xs sm:text-sm"
                >
                    {{ details[item].label }}
                </span>
            </li>
        </ol>
    </nav>

    <section class="flex flex-col gap-2">
        <h2
            ref="heading"
            tabindex="-1"
            class="text-lg font-semibold focus:outline-none"
        >
            {{ details[step].title }}
        </h2>
        <p class="text-sm text-muted-foreground">
            {{ details[step].description }}
        </p>
    </section>

    <div v-if="step === 'start'" class="grid gap-3 sm:grid-cols-2">
        <Button class="min-h-11" @click="choose('new')">
            <Plus data-icon="inline-start" />
            Empezar desde cero
        </Button>
        <Button variant="outline" class="min-h-11" @click="choose('restore')">
            <DatabaseBackup data-icon="inline-start" />
            Restaurar desde un respaldo
        </Button>
    </div>

    <Form
        v-show="step !== 'start' && mode === 'new'"
        ref="setupForm"
        v-bind="store.form()"
        v-slot="{ errors, processing, validating, clearErrors }"
        :on-before="nextOrSubmit"
        @submit.capture="
            reviewSubmission(
                $event,
                setupReview,
                inertiaValidation(setupForm, showStepWithErrors),
            )
        "
        @error="showStepWithErrors"
        autocomplete="off"
        novalidate
        reset-on-success
        disable-while-processing
    >
        <FieldGroup>
            <FieldSet v-show="step === 'account'">
                <FieldLegend class="sr-only">Cuenta administradora</FieldLegend>
                <FieldGroup class="grid gap-5 sm:grid-cols-2">
                    <Field :data-invalid="!!errors.name">
                        <FieldLabel for="setup-name"
                            >Nombre completo</FieldLabel
                        >
                        <Input
                            id="setup-name"
                            name="name"
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
                        server-validation-only
                        :error="errors.username"
                    />
                    <Field :data-invalid="!!errors.password">
                        <FieldLabel for="setup-secret">Contraseña</FieldLabel>
                        <PasswordInput
                            id="setup-secret"
                            name="password"
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

            <FieldSet v-show="step === 'cash'">
                <FieldLegend class="sr-only">Efectivo en caja</FieldLegend>
                <OpeningBalanceField
                    id="setup-cash"
                    :error="errors.opening_balance"
                />
            </FieldSet>

            <FieldSet v-show="step === 'banks'">
                <FieldLegend class="sr-only">Cuentas bancarias</FieldLegend>
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
                    @click="accounts.push(nextAccount++)"
                >
                    <Plus data-icon="inline-start" />
                    Agregar cuenta bancaria
                </Button>
            </FieldSet>

            <InitialSetupEmployees
                v-show="step === 'employees'"
                v-model="employees"
                :errors="errors"
                :limit-cents="employeeAdvanceLimitCents"
                @removed="clearErrors()"
            />

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="processing || validating"
                    @click="goTo(steps[stepIndex - 1] ?? 'start')"
                    >Atrás</Button
                >
                <Button
                    type="submit"
                    class="min-h-11"
                    :disabled="processing || validating"
                >
                    {{
                        processing
                            ? 'Guardando configuración…'
                            : validating
                              ? 'Validando…'
                              : step === 'employees'
                                ? employees.length
                                    ? 'Guardar e ingresar'
                                    : 'Omitir y finalizar'
                                : 'Continuar'
                    }}
                </Button>
            </div>
        </FieldGroup>
    </Form>

    <Form
        ref="restoreForm"
        v-if="step === 'backup'"
        v-bind="restore.form()"
        @submit.capture="
            reviewSubmission(
                $event,
                restoreReview,
                inertiaValidation(restoreForm),
            )
        "
        v-slot="{ errors, processing }"
        novalidate
        disable-while-processing
    >
        <FieldGroup>
            <Field :data-invalid="!!errors.backup">
                <FieldLabel for="setup-backup">Archivo de respaldo</FieldLabel>
                <input
                    id="setup-backup"
                    name="backup"
                    type="file"
                    accept=".zip,application/zip"
                    class="min-h-11 w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none file:me-3 file:border-0 file:bg-transparent file:font-medium file:text-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :aria-invalid="!!errors.backup"
                    aria-describedby="setup-backup-description"
                    required
                />
                <FieldDescription id="setup-backup-description">
                    Tamaño máximo del archivo ZIP: 64 MB.
                </FieldDescription>
                <FieldError v-if="errors.backup">{{
                    errors.backup
                }}</FieldError>
            </Field>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="processing"
                    @click="goTo('start')"
                    >Atrás</Button
                >
                <Button type="submit" class="min-h-11" :disabled="processing">
                    {{
                        processing
                            ? 'Validando y restaurando…'
                            : 'Restaurar respaldo'
                    }}
                </Button>
            </div>
        </FieldGroup>
    </Form>
</template>
