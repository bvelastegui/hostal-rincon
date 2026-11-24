<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index, store } from '@/actions/App/Http/Controllers/AdvanceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd } from '@/lib/money';

const props = defineProps<{
    employee: { id: number; name: string };
    pendingCents: number;
    limitCents: number;
    requestToken: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Adelantos', href: index() }] },
});

const availableCents = computed(() =>
    Math.max(0, props.limitCents - props.pendingCents),
);
const form = useForm({ amount: '', note: '', request_id: props.requestToken });

function submit(): void {
    if (form.processing || availableCents.value === 0) {
        return;
    }

    form.transform((data) => ({
        ...data,
        amount: data.amount.trim().replace(',', '.'),
    })).submit(store(props.employee.id));
}
</script>

<template>
    <div class="flex flex-1 flex-col gap-6">
        <Head title="Registrar adelanto" />

        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Registrar adelanto
            </h1>
            <p class="break-words text-muted-foreground">{{ employee.name }}</p>
        </header>

        <dl class="grid grid-cols-2 gap-3 rounded-lg border p-4 text-sm">
            <div>
                <dt class="text-muted-foreground">Pendiente de descuento</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(pendingCents) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Disponible</dt>
                <dd class="text-lg font-semibold tabular-nums">
                    {{ formatUsd(availableCents) }}
                </dd>
            </div>
        </dl>

        <form
            v-if="availableCents"
            class="flex flex-1 flex-col gap-5"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="advance-amount">Monto en USD</Label>
                <Input
                    id="advance-amount"
                    v-model="form.amount"
                    inputmode="decimal"
                    required
                    class="min-h-11 text-base"
                    :aria-invalid="Boolean(form.errors.amount)"
                />
                <InputError :message="form.errors.amount" />
            </div>
            <div class="grid gap-2">
                <Label for="advance-note">Observación opcional</Label>
                <Input
                    id="advance-note"
                    v-model="form.note"
                    maxlength="500"
                    class="min-h-11 text-base"
                    :aria-invalid="Boolean(form.errors.note)"
                />
                <InputError :message="form.errors.note" />
            </div>
            <InputError :message="form.errors.request_id" />

            <div class="mt-auto grid gap-2 sm:mt-2 sm:flex sm:justify-end">
                <Button as-child variant="outline" class="min-h-11">
                    <Link :href="index()">Cancelar</Link>
                </Button>
                <Button
                    type="submit"
                    class="min-h-11"
                    :disabled="form.processing"
                >
                    {{
                        form.processing ? 'Registrando…' : 'Registrar adelanto'
                    }}
                </Button>
            </div>
        </form>
        <div v-else class="space-y-3">
            <p class="text-sm">
                Límite alcanzado. Registra el descuento completo para habilitar
                nuevos adelantos.
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
