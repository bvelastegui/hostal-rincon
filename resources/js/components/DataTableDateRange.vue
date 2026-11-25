<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const props = withDefaults(
    defineProps<{
        from: string | null;
        to: string | null;
        disabled?: boolean;
        resetLabel?: string;
    }>(),
    { resetLabel: 'Volver a hoy' },
);

const emit = defineEmits<{
    apply: [range: { from: string; to: string } | null];
}>();

const open = ref(false);
const id = useId();
const from = ref(props.from ?? '');
const to = ref(props.to ?? '');
const invalid = computed(() =>
    Boolean(from.value && to.value && to.value < from.value),
);

watch(open, (visible) => {
    if (visible) {
        from.value = props.from ?? '';
        to.value = props.to ?? '';
    }
});

function displayDate(value: string) {
    return value.split('-').reverse().join('/');
}

function apply() {
    if (!from.value || !to.value || invalid.value) {
        return;
    }
    open.value = false;
    emit('apply', { from: from.value, to: to.value });
}

function reset() {
    open.value = false;
    emit('apply', null);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="min-h-10 border-dashed"
                :disabled="disabled"
            >
                <CalendarDays data-icon="inline-start" aria-hidden="true" />
                Fecha
                <Badge v-if="props.from && props.to" variant="secondary">
                    {{ displayDate(props.from) }}
                    <template v-if="props.from !== props.to">
                        – {{ displayDate(props.to) }}</template
                    >
                </Badge>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="start" aria-label="Rango de fechas">
            <form class="flex flex-col gap-3" @submit.prevent="apply">
                <Field>
                    <FieldLabel :for="id + '-from'">Desde</FieldLabel>
                    <Input
                        :id="id + '-from'"
                        v-model="from"
                        type="date"
                        required
                    />
                </Field>
                <Field :data-invalid="invalid">
                    <FieldLabel :for="id + '-to'">Hasta</FieldLabel>
                    <Input
                        :id="id + '-to'"
                        v-model="to"
                        type="date"
                        required
                        :aria-invalid="invalid"
                    />
                    <FieldError v-if="invalid"
                        >La fecha final debe ser igual o posterior a la
                        inicial.</FieldError
                    >
                </Field>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="reset"
                        >{{ resetLabel }}</Button
                    >
                    <Button type="submit" size="sm" :disabled="invalid"
                        >Aplicar</Button
                    >
                </div>
            </form>
        </PopoverContent>
    </Popover>
</template>
