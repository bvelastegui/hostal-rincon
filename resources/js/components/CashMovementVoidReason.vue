<script setup lang="ts">
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

defineProps<{ id: string; error?: string }>();
</script>

<template>
    <Field :data-invalid="!!error">
        <FieldLabel :for="id">Motivo de anulación</FieldLabel>
        <Input
            :id="id"
            name="void_reason"
            class="min-h-11"
            maxlength="255"
            required
            :aria-invalid="!!error"
            :aria-describedby="error ? id + '-error' : undefined"
        />
        <FieldError v-if="error" :id="id + '-error'">{{ error }}</FieldError>
    </Field>
</template>
