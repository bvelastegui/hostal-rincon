<script setup lang="ts">
import { useTemplateRef } from 'vue';
import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { reviewSubmission, inertiaValidation } from '@/lib/record-confirmation';
import { edit } from '@/routes/security';

// oxfmt-ignore
type Props = {
    passwordRules: string;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Seguridad',
                href: edit(),
            },
        ],
    },
});

const reviewForm = useTemplateRef<FormComponentRef>('reviewForm');
</script>

<template>
    <Head title="Seguridad" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Cambiar contraseña"
            description="Usa una contraseña larga y difícil de adivinar"
        />

        <Form
            ref="reviewForm"
            novalidate
            v-bind="SecurityController.update.form()"
            @submit.capture="
                reviewSubmission(
                    $event,
                    {
                        title: 'Confirmar cambio de contraseña',
                        description:
                            'Usarás la nueva contraseña la próxima vez que inicies sesión.',
                    },
                    inertiaValidation(reviewForm),
                )
            "
            autocomplete="off"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Contraseña actual</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    placeholder="Contraseña actual"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nueva contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    placeholder="Nueva contraseña"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    placeholder="Confirmar contraseña"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="min-h-11 w-full lg:w-auto"
                :disabled="processing"
                data-test="update-password-button"
            >
                Guardar cambios
            </Button>
        </Form>
    </div>
</template>
