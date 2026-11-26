<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

defineProps<{
    id: string;
    label: string;
    name?: string;
    error?: string | null;
    alertError?: boolean;
    disabled?: boolean;
}>();

const amount = defineModel<string>({ required: true });
</script>

<template>
    <Field :data-invalid="!!error" :data-disabled="disabled">
        <div
            class="flex flex-col gap-2 rounded-lg border border-primary/25 bg-primary/5 p-4"
        >
            <FieldLabel :for="id" class="text-base font-semibold">{{
                label
            }}</FieldLabel>
            <div v-if="$slots.context" :id="id + '-context'">
                <slot name="context" />
            </div>
            <div class="relative">
                <span
                    class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm font-semibold text-muted-foreground"
                    aria-hidden="true"
                    >USD</span
                >
                <Input
                    :id="id"
                    v-model="amount"
                    :name="name"
                    inputmode="decimal"
                    placeholder="0,00"
                    required
                    :disabled="disabled"
                    class="h-16 pl-14 text-3xl font-semibold tabular-nums md:text-3xl"
                    :aria-invalid="!!error"
                    :aria-describedby="
                        [
                            $slots.context && id + '-context',
                            error && id + '-error',
                        ]
                            .filter(Boolean)
                            .join(' ') || undefined
                    "
                />
            </div>
        </div>
        <Alert
            v-if="error && alertError"
            :id="id + '-error'"
            variant="destructive"
        >
            <TriangleAlert aria-hidden="true" />
            <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
        <FieldError v-else-if="error" :id="id + '-error'">{{
            error
        }}</FieldError>
    </Field>
</template>
