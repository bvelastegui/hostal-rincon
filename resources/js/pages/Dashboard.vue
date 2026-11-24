<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useStorage } from '@vueuse/core';
import { computed, nextTick, useTemplateRef, watchEffect } from 'vue';
import InitialSetupWidget from '@/components/InitialSetupWidget.vue';
import { dashboard } from '@/routes';
import type { PendingSetting } from '@/types/dashboard';

const props = defineProps<{ pendingSettings: PendingSetting[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

const page = usePage();
const title = useTemplateRef<HTMLHeadingElement>('title');
const hasPendingSettings = computed(() => props.pendingSettings.length > 0);
const dismissed = useStorage(
    `hostal-rincon:initial-setup-dismissed:${page.props.auth.user.id}`,
    false,
    undefined,
    { initOnMounted: true, writeDefaults: false },
);

watchEffect(() => {
    if (hasPendingSettings.value && dismissed.value) {
        dismissed.value = false;
    }
});

async function dismissSetup(): Promise<void> {
    if (hasPendingSettings.value) {
        return;
    }

    dismissed.value = true;
    await nextTick();
    title.value?.focus();
}
</script>

<template>
    <Head title="Panel" />

    <div class="flex flex-1 flex-col gap-4">
        <header class="lg:sr-only">
            <h1
                ref="title"
                tabindex="-1"
                class="text-2xl font-semibold tracking-tight"
            >
                Panel
            </h1>
        </header>
        <InitialSetupWidget
            v-if="
                page.props.auth.canManageCashSettings &&
                (hasPendingSettings || !dismissed)
            "
            :pending-settings="pendingSettings"
            @dismiss="dismissSetup"
        />
        <p v-else class="text-sm text-muted-foreground">
            No hay avisos en el panel.
        </p>
    </div>
</template>
