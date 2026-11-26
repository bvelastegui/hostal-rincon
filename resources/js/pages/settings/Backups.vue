<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { download, index } from '@/routes/backups';

defineProps<{ backupError: string | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Respaldos', href: index() }],
    },
});
</script>

<template>
    <Head title="Respaldos" />

    <div class="flex flex-col gap-6">
        <Heading
            variant="small"
            title="Respaldos"
            hide-title-on-mobile
            description="Guarda un archivo ZIP con la información necesaria para recuperar el sistema en otro equipo."
        />

        <Alert v-if="backupError" variant="destructive" role="alert">
            <AlertDescription>{{ backupError }}</AlertDescription>
        </Alert>

        <Button as-child class="w-full sm:w-fit">
            <a :href="download.url()">
                <Download data-icon="inline-start" />
                Generar respaldo
            </a>
        </Button>
    </div>
</template>
