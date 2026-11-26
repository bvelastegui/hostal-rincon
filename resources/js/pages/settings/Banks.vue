<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { computed } from 'vue';
import {
    storeBank,
    updateBankStatus,
} from '@/actions/App/Http/Controllers/Settings/CashSettingsController';
import Heading from '@/components/Heading.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { formatUsd } from '@/lib/money';
import {
    confirmRecord,
    fieldValue,
    reviewSubmission,
} from '@/lib/record-confirmation';
import { edit } from '@/routes/bank-settings';
import { create, edit as editAccount } from '@/routes/bank-settings/accounts';

type Bank = { id: number; name: string; active: boolean };
type BankAccount = {
    id: number;
    name: string;
    bankName: string;
    bankActive: boolean;
    balanceCents: number;
    locked: boolean;
};

const props = defineProps<{
    banks: Bank[];
    accounts: BankAccount[];
}>();

const canAddAccount = computed(() => props.banks.some((bank) => bank.active));

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bancos', href: edit() }],
    },
});
</script>

<template>
    <div class="flex flex-col gap-6 lg:gap-8">
        <Head title="Bancos" />

        <Heading variant="small" title="Bancos" hide-title-on-mobile />

        <SettingsSection
            title="Cuentas bancarias"
            :description="
                canAddAccount
                    ? undefined
                    : 'Agrega o activa un banco para registrar una cuenta.'
            "
        >
            <template v-if="canAddAccount" #actions>
                <Button as-child class="min-h-11 w-full lg:w-auto">
                    <Link :href="create()">Agregar cuenta bancaria</Link>
                </Button>
            </template>
            <ul v-if="accounts.length" class="divide-y">
                <li v-for="account in accounts" :key="account.id" class="py-2">
                    <div
                        class="grid min-h-11 grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-3 sm:gap-5"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium wrap-break-word">
                                {{ account.name }}
                            </p>
                            <p
                                class="text-xs wrap-break-word text-muted-foreground"
                            >
                                {{ account.bankName }}
                                <span v-if="!account.bankActive"
                                    >· Inactivo</span
                                >
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-muted-foreground">
                                {{
                                    account.locked
                                        ? 'Saldo inicial fijo'
                                        : 'Saldo inicial'
                                }}
                            </p>
                            <p
                                class="text-sm font-medium whitespace-nowrap tabular-nums"
                            >
                                {{ formatUsd(account.balanceCents) }}
                            </p>
                        </div>
                        <Button
                            as-child
                            variant="ghost"
                            size="icon-lg"
                            class="size-11"
                        >
                            <Link
                                :href="editAccount(account.id)"
                                :aria-label="`Editar cuenta ${account.name}, ${account.bankName}`"
                                title="Editar cuenta"
                            >
                                <Pencil aria-hidden="true" />
                            </Link>
                        </Button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                Todavía no hay cuentas bancarias registradas.
            </p>
        </SettingsSection>

        <Separator class="lg:hidden" />

        <SettingsSection
            title="Bancos admitidos"
            description="Agrega las entidades habilitadas para transferencias inmediatas según Banco Pichincha."
        >
            <div class="flex flex-col gap-4">
                <Form
                    novalidate
                    v-bind="storeBank.form()"
                    v-slot="{ errors, processing }"
                    error-bag="createBank"
                    @submit.capture="
                        reviewSubmission($event, (data) => ({
                            title: 'Confirmar registro del banco',
                            description:
                                'El banco quedará habilitado para seleccionarlo al registrar transferencias.',
                            details: [fieldValue(data, 'name')],
                        }))
                    "
                    reset-on-success
                >
                    <FieldGroup
                        class="settings-form-row gap-3 lg:grid lg:grid-cols-[minmax(0,1fr)_auto] lg:gap-y-0"
                    >
                        <Field :data-invalid="!!errors.name">
                            <FieldLabel for="bank-name"
                                >Nombre del banco</FieldLabel
                            >
                            <Input
                                id="bank-name"
                                name="name"
                                class="min-h-11"
                                maxlength="100"
                                :aria-invalid="!!errors.name"
                                :aria-describedby="
                                    errors.name ? 'bank-name-error' : undefined
                                "
                                required
                            />
                            <FieldError
                                v-if="errors.name"
                                id="bank-name-error"
                                >{{ errors.name }}</FieldError
                            >
                        </Field>
                        <Button class="min-h-11 w-full" :disabled="processing">
                            Agregar banco
                        </Button>
                    </FieldGroup>
                </Form>

                <ul v-if="banks.length" class="w-full divide-y">
                    <li v-for="bank in banks" :key="bank.id">
                        <Form
                            novalidate
                            v-bind="updateBankStatus.form(bank.id)"
                            v-slot="{ errors, processing, submit }"
                            :error-bag="`bankStatus-${bank.id}`"
                            :options="{ preserveScroll: true }"
                            class="flex flex-col gap-2 py-2"
                        >
                            <input
                                type="hidden"
                                name="is_active"
                                :value="bank.active ? '0' : '1'"
                            />
                            <div
                                class="flex min-h-11 items-center justify-between gap-4"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="text-sm font-medium wrap-break-word"
                                    >
                                        {{ bank.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            bank.active ? 'Activo' : 'Inactivo'
                                        }}
                                    </p>
                                </div>
                                <Switch
                                    :model-value="bank.active"
                                    :disabled="processing"
                                    :aria-label="`${bank.name} admitido`"
                                    :aria-describedby="
                                        errors.is_active
                                            ? `bank-status-error-${bank.id}`
                                            : undefined
                                    "
                                    class="relative mr-1 before:absolute before:-inset-3.5"
                                    @update:model-value="
                                        confirmRecord(
                                            {
                                                title: bank.active
                                                    ? 'Desactivar banco'
                                                    : 'Activar banco',
                                                description: bank.active
                                                    ? 'El banco dejará de estar habilitado para nuevas operaciones.'
                                                    : 'El banco volverá a estar habilitado para nuevas operaciones.',
                                                details: [bank.name],
                                                confirmLabel: bank.active
                                                    ? 'Desactivar banco'
                                                    : 'Activar banco',
                                            },
                                            () => submit(),
                                        )
                                    "
                                />
                            </div>
                            <FieldError
                                v-if="errors.is_active"
                                :id="`bank-status-error-${bank.id}`"
                            >
                                {{ errors.is_active }}
                            </FieldError>
                        </Form>
                    </li>
                </ul>
            </div>
        </SettingsSection>
    </div>
</template>
