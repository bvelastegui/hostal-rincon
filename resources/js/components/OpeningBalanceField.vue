<script setup lang="ts">
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

defineProps<{
    id: string;
    name?: string;
    label?: string;
    balanceCents?: number | null;
    error?: string;
    disabled?: boolean;
    description?: string;
}>();
</script>

<template>
    <Field :data-invalid="!!error" :data-disabled="disabled">
        <FieldLabel :for="id">{{ label ?? 'Saldo inicial (USD)' }}</FieldLabel>
        <Input
            :id="id"
            :name="name ?? 'opening_balance'"
            inputmode="decimal"
            class="min-h-11"
            placeholder="0,00"
            :default-value="
                balanceCents == null
                    ? undefined
                    : (balanceCents / 100).toFixed(2).replace('.', ',')
            "
            :aria-invalid="!!error"
            :aria-describedby="
                [description && id + '-description', error && id + '-error']
                    .filter(Boolean)
                    .join(' ') || undefined
            "
            :disabled="disabled"
            :required="!disabled"
        />
        <FieldDescription v-if="description" :id="id + '-description'">{{
            description
        }}</FieldDescription>
        <FieldError v-if="error" :id="id + '-error'">{{ error }}</FieldError>
    </Field>
</template>
