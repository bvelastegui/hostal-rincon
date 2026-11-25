<script setup lang="ts">
import { CirclePlus } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const props = defineProps<{
    title: string;
    options: { value: string; label: string }[];
    disabled?: boolean;
}>();

const selection = defineModel<string>({ required: true });
const selectedLabel = computed(() =>
    selection.value === 'all'
        ? null
        : props.options.find((option) => option.value === selection.value)
              ?.label,
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="min-h-10 max-w-full border-dashed"
                :disabled="disabled"
            >
                <CirclePlus data-icon="inline-start" aria-hidden="true" />
                {{ title }}
                <Badge
                    v-if="selectedLabel"
                    variant="secondary"
                    class="max-w-40 min-w-0"
                    :title="selectedLabel"
                >
                    <span class="truncate">{{ selectedLabel }}</span>
                </Badge>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="start"
            class="max-h-72 max-w-[calc(100vw-2rem)] overflow-y-auto"
        >
            <DropdownMenuRadioGroup v-model="selection" :aria-label="title">
                <DropdownMenuRadioItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
