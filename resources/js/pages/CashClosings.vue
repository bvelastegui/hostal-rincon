<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CashClosingHistory from '@/components/CashClosingHistory.vue';
import LogoutLink from '@/components/LogoutLink.vue';
import SelectField from '@/components/SelectField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { formatCashDifference, formatUsd } from '@/lib/money';
import {
    administrativeClose,
    index,
    openOwnShift,
    prepareHandoff,
    receiveShift,
    startShift,
    store,
} from '@/routes/cash-closings';
import { index as movements } from '@/routes/movements';
import type { CashClosingPage, CashHistoryFilters } from '@/types/cash-closing';

const props = defineProps<{
    expectedCashCents: number | null;
    requestId: string;
    canClose: boolean;
    canReceive: boolean;
    canDeliver: boolean;
    isOwner: boolean;
    startReceiving: boolean;
    activeShift: {
        id: number;
        responsible: string;
        openingCashCents: number | null;
        deliveredCashCents: number | null;
    } | null;
    responsibles: { id: number; name: string }[];
    closings: CashClosingPage | null;
    historyFilters?: CashHistoryFilters;
    historyResponsibles?: { id: number; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Turno de caja', href: index() }] },
});

const form = useForm({
    counted_cash: '',
    note: '',
    request_id: '',
    expected_cash_cents: null as number | null,
    cash_shift_id: null as number | null,
});

const assignment = useForm({ responsible_id: '' });
const page = usePage();
const selectedAction = ref<
    'deliver' | 'receive' | 'close' | 'administrative' | null
>(props.startReceiving && props.canReceive ? 'receive' : null);
const action = computed(() => {
    if (!props.activeShift && !props.canClose) {
        return 'opening';
    }

    if (
        props.canReceive &&
        (!props.canClose || selectedAction.value === 'receive')
    ) {
        return 'receive';
    }

    if (props.canDeliver && selectedAction.value === 'deliver') {
        return 'deliver';
    }

    if (
        props.canClose &&
        props.activeShift &&
        selectedAction.value === 'administrative'
    ) {
        return 'administrative';
    }

    return props.canClose && selectedAction.value === 'close' ? 'close' : null;
});
const responsibleOptions = computed(() =>
    props.responsibles.map(({ id, name }) => ({
        value: String(id),
        label: name,
    })),
);

const difference = computed(() => {
    const amount = String(form.counted_cash).trim().replace(',', '.');
    if (
        props.expectedCashCents === null ||
        !/^\d{1,9}(\.\d{0,2})?$/.test(amount)
    ) {
        return null;
    }

    return Math.round(Number(amount) * 100) - props.expectedCashCents;
});

const requiresNote = computed(
    () => action.value === 'administrative' || Boolean(difference.value),
);
const formCopy = {
    opening: {
        title: 'Abrir caja',
        description:
            'Cuenta el efectivo inicial para abrir la caja a tu nombre y empezar el turno.',
        label: 'Efectivo inicial contado (USD)',
        submit: 'Abrir mi caja',
    },
    receive: {
        title: 'Recibir turno',
        description:
            'Cuenta el efectivo recibido. Al confirmar, la caja quedará a tu cargo.',
        label: 'Efectivo recibido (USD)',
        submit: 'Confirmar recepción',
    },
    deliver: {
        title: 'Entregar turno',
        description:
            'Registra el efectivo que entregas. Al confirmar, se cerrará tu sesión.',
        label: 'Efectivo entregado (USD)',
        submit: 'Guardar entrega y cerrar sesión',
    },
    close: {
        title: 'Corte independiente',
        description:
            'Registra el efectivo contado. El responsable conserva el turno.',
        label: 'Efectivo contado (USD)',
        submit: 'Confirmar corte',
    },
    administrative: {
        title: 'Cierre administrativo de turno',
        description:
            'Registra el efectivo contado y el motivo. El turno finalizará y la caja quedará sin responsable hasta que se inicie otro.',
        label: 'Efectivo contado (USD)',
        submit: 'Finalizar turno',
    },
};

function selectAction(next: typeof selectedAction.value) {
    form.resetAndClearErrors();
    selectedAction.value = next;
}

function submit() {
    if (!action.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        request_id: props.requestId,
        expected_cash_cents: props.expectedCashCents,
        cash_shift_id: props.activeShift?.id ?? null,
    })).submit(
        {
            deliver: prepareHandoff(),
            receive: receiveShift(),
            close: store(),
            administrative: administrativeClose(),
            opening: openOwnShift(),
        }[action.value],
        {
            preserveScroll: true,
            onBefore: () => {
                if (action.value === 'deliver') {
                    router.flushAll();
                }
            },
            onSuccess: () => selectAction(null),
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-8">
        <Head title="Turno de caja" />

        <section
            class="flex min-w-0 flex-col gap-4"
            aria-labelledby="cash-shift-title"
        >
            <header
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <h1
                    id="cash-shift-title"
                    class="text-2xl font-semibold tracking-tight"
                >
                    Turno de caja
                </h1>
                <Button
                    v-if="page.props.auth.cashShiftAccess !== 'restricted'"
                    as-child
                    variant="outline"
                    class="min-h-11"
                >
                    <Link :href="movements()">Volver a movimientos</Link>
                </Button>
            </header>

            <div
                class="grid gap-5 border-y py-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-8 lg:py-5"
            >
                <div class="flex min-w-0 flex-col gap-3">
                    <div v-if="activeShift" class="flex flex-col gap-1">
                        <p class="text-sm text-muted-foreground">
                            Turno activo #{{ activeShift.id }}
                        </p>
                        <p class="font-medium wrap-break-word">
                            {{
                                isOwner
                                    ? 'Tú tienes la caja a tu cargo'
                                    : 'Caja a cargo de ' +
                                      activeShift.responsible
                            }}
                        </p>
                        <p
                            v-if="activeShift.openingCashCents !== null"
                            class="text-sm text-muted-foreground"
                        >
                            Efectivo al abrir:
                            <span class="tabular-nums">{{
                                formatUsd(activeShift.openingCashCents)
                            }}</span>
                        </p>
                        <p
                            v-if="activeShift.deliveredCashCents !== null"
                            class="text-sm text-muted-foreground"
                        >
                            Pendiente de recepción · Efectivo entregado:
                            <span class="tabular-nums">{{
                                formatUsd(activeShift.deliveredCashCents)
                            }}</span>
                        </p>
                    </div>
                    <p v-else class="text-sm font-medium">
                        No hay un turno abierto
                    </p>
                </div>

                <div
                    class="flex min-w-0 flex-col gap-5 border-t pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8"
                >
                    <form
                        v-if="!activeShift && canClose && !action"
                        class="flex flex-col gap-4"
                        @submit.prevent="assignment.submit(startShift())"
                    >
                        <SelectField
                            v-model="assignment.responsible_id"
                            name="cash-responsible"
                            label="Responsable de caja"
                            placeholder="Selecciona quién tiene la caja"
                            :options="responsibleOptions"
                            :error="assignment.errors.responsible_id"
                        />
                        <Button
                            type="submit"
                            class="min-h-11 sm:self-end"
                            :disabled="
                                assignment.processing ||
                                !assignment.responsible_id
                            "
                        >
                            Iniciar turno
                        </Button>
                    </form>
                    <div
                        v-if="
                            activeShift &&
                            !action &&
                            canDeliver &&
                            expectedCashCents !== null
                        "
                        class="flex flex-col gap-2"
                    >
                        <p class="text-sm text-muted-foreground">
                            Cuenta el efectivo que vas a entregar. Después de
                            guardar tu conteo, la otra persona registrará el
                            dinero que recibe.
                        </p>
                        <Button
                            type="button"
                            class="min-h-11 sm:self-start"
                            @click="selectAction('deliver')"
                        >
                            Entregar turno
                        </Button>
                    </div>
                    <div
                        v-if="canClose && !action && expectedCashCents !== null"
                        class="flex flex-col gap-4"
                    >
                        <div v-if="canReceive" class="flex flex-col gap-2">
                            <p class="text-sm text-muted-foreground">
                                Si vas a quedarte a cargo de la caja, confirma
                                el efectivo que recibes.
                            </p>
                            <Button
                                type="button"
                                class="min-h-11 sm:self-start"
                                @click="selectAction('receive')"
                                >Recibir turno</Button
                            >
                        </div>
                        <div class="flex flex-col gap-2">
                            <p class="text-sm text-muted-foreground">
                                Si solo quieres registrar un conteo, la caja
                                seguirá con el mismo responsable.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11 sm:self-start"
                                @click="selectAction('close')"
                                >Hacer cierre independiente</Button
                            >
                        </div>
                        <Button
                            v-if="activeShift"
                            type="button"
                            variant="destructive"
                            class="min-h-11 sm:self-start"
                            @click="selectAction('administrative')"
                        >
                            Cerrar turno como administradora
                        </Button>
                    </div>
                    <p
                        v-if="
                            activeShift && !canClose && !isOwner && !canReceive
                        "
                        class="text-sm text-muted-foreground"
                    >
                        {{ activeShift.responsible }} debe registrar la entrega
                        antes de que puedas recibir la caja. Hasta entonces,
                        solo puedes acceder a esta pantalla.
                    </p>
                    <p
                        v-if="expectedCashCents === null"
                        class="text-sm text-muted-foreground"
                    >
                        La administradora debe configurar el saldo inicial de
                        efectivo para operar la caja.
                    </p>
                    <form
                        v-else-if="expectedCashCents !== null && action"
                        class="flex flex-col gap-5"
                        @submit.prevent="submit"
                    >
                        <div class="flex flex-col gap-1">
                            <h2 class="text-lg font-semibold">
                                {{ formCopy[action].title }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ formCopy[action].description }}
                            </p>
                        </div>
                        <dl>
                            <dt class="text-sm text-muted-foreground">
                                Efectivo esperado
                            </dt>
                            <dd class="text-xl font-semibold tabular-nums">
                                {{ formatUsd(expectedCashCents) }}
                            </dd>
                        </dl>
                        <FieldGroup>
                            <Field
                                :data-invalid="
                                    Boolean(form.errors.counted_cash)
                                "
                            >
                                <FieldLabel for="counted-cash">{{
                                    formCopy[action].label
                                }}</FieldLabel>
                                <Input
                                    id="counted-cash"
                                    v-model="form.counted_cash"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    required
                                    :aria-invalid="
                                        Boolean(form.errors.counted_cash)
                                    "
                                />
                                <FieldError v-if="form.errors.counted_cash">{{
                                    form.errors.counted_cash
                                }}</FieldError>
                            </Field>
                            <div aria-live="polite">
                                <Badge
                                    v-if="difference !== null"
                                    :variant="
                                        difference < 0
                                            ? 'destructive'
                                            : difference > 0
                                              ? 'default'
                                              : 'secondary'
                                    "
                                >
                                    {{ formatCashDifference(difference) }}
                                </Badge>
                            </div>
                            <p
                                v-if="difference"
                                class="text-sm text-muted-foreground"
                            >
                                Al confirmar, el saldo de efectivo se ajustará
                                al monto contado.
                            </p>
                            <Field :data-invalid="Boolean(form.errors.note)">
                                <FieldLabel for="closing-note">{{
                                    action === 'administrative'
                                        ? 'Motivo del cierre (obligatorio)'
                                        : 'Observación (' +
                                          (requiresNote
                                              ? 'obligatoria'
                                              : 'opcional') +
                                          ')'
                                }}</FieldLabel>
                                <Input
                                    id="closing-note"
                                    v-model="form.note"
                                    maxlength="500"
                                    :required="requiresNote"
                                    :aria-invalid="Boolean(form.errors.note)"
                                />
                                <FieldError v-if="form.errors.note">{{
                                    form.errors.note
                                }}</FieldError>
                            </Field>
                        </FieldGroup>
                        <FieldError
                            v-if="
                                form.errors.expected_cash_cents ||
                                form.errors.cash_shift_id ||
                                form.errors.request_id
                            "
                            role="alert"
                        >
                            {{
                                form.errors.expected_cash_cents ||
                                form.errors.cash_shift_id ||
                                form.errors.request_id
                            }}
                        </FieldError>
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:justify-end"
                        >
                            <Button
                                v-if="canClose || action === 'deliver'"
                                type="button"
                                variant="outline"
                                class="min-h-11"
                                :disabled="form.processing"
                                @click="selectAction(null)"
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                class="min-h-11"
                                :disabled="form.processing"
                            >
                                {{ formCopy[action].submit }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>

            <Button
                v-if="page.props.auth.cashShiftAccess"
                as-child
                variant="ghost"
                class="min-h-11 sm:self-start lg:hidden"
            >
                <LogoutLink>Cambiar de sesión</LogoutLink>
            </Button>
        </section>

        <CashClosingHistory
            v-if="closings && historyFilters && historyResponsibles"
            :closings="closings"
            :filters="historyFilters"
            :responsibles="historyResponsibles"
        />
    </div>
</template>
