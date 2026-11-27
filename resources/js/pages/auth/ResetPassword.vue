<script setup lang="ts">
import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { reviewSubmission, inertiaValidation } from '@/lib/record-confirmation';
import { Spinner } from '@/components/ui/spinner';
import { review as reviewPassword, update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Restablecer contraseña',
        description: 'Ingresa tu nueva contraseña',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);

const reviewForm = useTemplateRef<FormComponentRef>('reviewForm');
</script>

<template>
    <Head title="Restablecer contraseña" />

    <Form
        ref="reviewForm"
        novalidate
        v-bind="update.form()"
        @submit.capture="
            reviewSubmission(
                $event,
                {
                    title: 'Confirmar nueva contraseña',
                    description:
                        'Usarás la nueva contraseña la próxima vez que inicies sesión.',
                },
                { ...inertiaValidation(reviewForm), route: reviewPassword() },
            )
        "
        autocomplete="off"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <input type="hidden" name="token" :value="token" />
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    Confirmar contraseña
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    placeholder="Confirmar contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Restablecer contraseña
            </Button>
        </div>
    </Form>
</template>
