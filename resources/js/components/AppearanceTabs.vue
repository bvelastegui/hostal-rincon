<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Claro' },
    { value: 'dark', Icon: Moon, label: 'Oscuro' },
    { value: 'system', Icon: Monitor, label: 'Sistema' },
] as const;
</script>

<template>
    <div
        class="grid gap-2 sm:grid-cols-3 lg:inline-grid"
        role="group"
        aria-label="Tema de la aplicación"
    >
        <Button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :variant="appearance === value ? 'default' : 'outline'"
            :aria-pressed="appearance === value"
            class="min-h-11 w-full"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" aria-hidden="true" data-icon="inline-start" />
            {{ label }}
        </Button>
    </div>
</template>
