<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    index,
    storeEmployee,
    updateEmployee,
} from '@/actions/App/Http/Controllers/AdvanceController';
import AccountIdentifierField from '@/components/AccountIdentifierField.vue';
import OpeningBalanceField from '@/components/OpeningBalanceField.vue';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    employee: {
        id: number;
        name: string;
        email: string | null;
        username: string | null;
    } | null;
    limitCents: number;
}>();

const loginField = usePage().props.auth.loginField;
const title = computed(() =>
    props.employee ? 'Editar empleado' : 'Nuevo empleado',
);
const action = computed(() =>
    props.employee
        ? updateEmployee.form(props.employee.id)
        : storeEmployee.form(),
);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adelantos', href: index() }] },
});
</script>

<template>
    <main class="mx-auto w-full max-w-xl space-y-5">
        <Head :title="title" />
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">
                El empleado tendrá una cuenta de operador para iniciar sesión.
            </p>
        </header>

        <Form v-bind="action" v-slot="{ errors, processing }">
            <FieldGroup class="gap-5">
                <Field :data-invalid="!!errors.name">
                    <FieldLabel for="employee-name">Nombre completo</FieldLabel>
                    <Input
                        id="employee-name"
                        name="name"
                        :default-value="employee?.name"
                        autocomplete="name"
                        maxlength="255"
                        class="min-h-11"
                        required
                        :aria-invalid="!!errors.name"
                    />
                    <FieldError v-if="errors.name">{{
                        errors.name
                    }}</FieldError>
                </Field>

                <AccountIdentifierField
                    id="employee-identifier"
                    :field="loginField"
                    :label="
                        loginField === 'email' ? 'Correo de acceso' : undefined
                    "
                    :value="employee?.[loginField]"
                    :error="errors[loginField]"
                />

                <OpeningBalanceField
                    id="employee-advance-limit"
                    name="advance_limit"
                    label="Límite máximo de adelantos (USD)"
                    :balance-cents="limitCents"
                    :error="errors.advance_limit"
                    description="Es el máximo que puede quedar pendiente antes del descuento. Usa 0,00 para bloquear nuevos adelantos."
                />

                <Field :data-invalid="!!errors.password">
                    <FieldLabel for="employee-password">
                        {{
                            employee
                                ? 'Nueva contraseña (opcional)'
                                : 'Contraseña inicial'
                        }}
                    </FieldLabel>
                    <Input
                        id="employee-password"
                        name="password"
                        type="password"
                        minlength="8"
                        class="min-h-11"
                        :required="!employee"
                        :aria-invalid="!!errors.password"
                    />
                    <FieldDescription v-if="employee">
                        Déjala vacía para conservar la contraseña actual.
                    </FieldDescription>
                    <FieldError v-if="errors.password">{{
                        errors.password
                    }}</FieldError>
                </Field>

                <Field :data-invalid="!!errors.password_confirmation">
                    <FieldLabel for="employee-password-confirmation"
                        >Confirmar contraseña</FieldLabel
                    >
                    <Input
                        id="employee-password-confirmation"
                        name="password_confirmation"
                        type="password"
                        minlength="8"
                        class="min-h-11"
                        :aria-invalid="!!errors.password_confirmation"
                    />
                    <FieldError v-if="errors.password_confirmation">{{
                        errors.password_confirmation
                    }}</FieldError>
                </Field>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <Button
                        as-child
                        variant="outline"
                        class="min-h-11 w-full sm:w-auto"
                    >
                        <Link :href="index()">Cancelar</Link>
                    </Button>
                    <Button
                        type="submit"
                        class="min-h-11 w-full sm:w-auto"
                        :disabled="processing"
                    >
                        {{ processing ? 'Guardando…' : 'Guardar empleado' }}
                    </Button>
                </div>
            </FieldGroup>
        </Form>
    </main>
</template>
