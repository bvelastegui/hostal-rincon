<script setup lang="ts">
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

defineProps<{
    name: string;
    label: string;
    placeholder: string;
    options: { value: string; label: string }[];
    defaultValue?: string;
    error?: string;
}>();

const model = defineModel<string>();
</script>

<template>
    <Field :data-invalid="!!error">
        <FieldLabel :for="name">{{ label }}</FieldLabel>
        <Select
            :name="name"
            :model-value="model"
            :default-value="defaultValue"
            required
            @update:model-value="model = String($event)"
        >
            <SelectTrigger
                :id="name"
                class="min-h-11 w-full"
                :aria-invalid="!!error"
                :aria-describedby="error ? name + '-error' : undefined"
            >
                <SelectValue :placeholder="placeholder" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>
        <FieldError v-if="error" :id="name + '-error'">{{ error }}</FieldError>
    </Field>
</template>
