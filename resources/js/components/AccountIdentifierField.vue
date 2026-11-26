<script setup lang="ts">
import { computed } from 'vue';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { LoginField } from '@/types/auth';

const props = defineProps<{
    id: string;
    field: LoginField;
    label?: string;
    value?: string | null;
    error?: string;
    autofocus?: boolean;
}>();

const label = computed(
    () =>
        props.label ??
        (props.field === 'username'
            ? 'Nombre de usuario'
            : 'Correo electrónico'),
);
</script>

<template>
    <Field :data-invalid="!!error">
        <FieldLabel :for="id">{{ label }}</FieldLabel>
        <Input
            v-focus="autofocus ?? false"
            :id="id"
            :name="field"
            :type="field === 'email' ? 'email' : 'text'"
            :default-value="value ?? undefined"
            :maxlength="field === 'username' ? 64 : 255"
            class="min-h-11"
            autocomplete="off"
            autocapitalize="none"
            :spellcheck="false"
            :aria-invalid="!!error"
            :aria-describedby="error ? id + '-error' : undefined"
            required
        />
        <FieldError v-if="error" :id="id + '-error'">{{ error }}</FieldError>
    </Field>
</template>
