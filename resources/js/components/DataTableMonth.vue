<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const props = defineProps<{ month: string; disabled?: boolean }>();
const emit = defineEmits<{ apply: [month: string] }>();
const open = ref(false);
const value = ref(props.month);
const id = useId();
const monthFormat = new Intl.DateTimeFormat('es-EC', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
const label = computed(() =>
    props.month
        ? monthFormat.format(new Date(props.month + '-01T00:00:00Z'))
        : '',
);

watch(open, (visible) => {
    if (visible) {
        value.value = props.month;
    }
});

function apply(month: string): void {
    open.value = false;
    if (month !== props.month) {
        emit('apply', month);
    }
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
                Mes
                <Badge v-if="label" variant="secondary">{{ label }}</Badge>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="start" aria-label="Mes calendario">
            <form
                novalidate
                class="flex flex-col gap-3"
                @submit.prevent="apply(value)"
            >
                <Field>
                    <FieldLabel :for="id">Mes calendario</FieldLabel>
                    <Input
                        :id="id"
                        v-model="value"
                        type="month"
                        lang="es-EC"
                        pattern="[0-9]{4}-(0[1-9]|1[0-2])"
                        placeholder="AAAA-MM"
                        required
                    />
                </Field>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="apply('')"
                        >Todos los meses</Button
                    >
                    <Button type="submit" size="sm" :disabled="!value"
                        >Aplicar</Button
                    >
                </div>
            </form>
        </PopoverContent>
    </Popover>
</template>
