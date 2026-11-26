<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import AccountIdentifierField from '@/components/AccountIdentifierField.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LogoutLink from '@/components/LogoutLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { reviewSubmission } from '@/lib/record-confirmation';
import { Separator } from '@/components/ui/separator';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const loginField = page.props.auth.loginField;
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Perfil" />

    <div class="flex flex-col gap-6">
        <Heading
            variant="small"
            title="Perfil"
            hide-title-on-mobile
            :description="
                loginField === 'username'
                    ? 'Actualiza tu nombre y usuario de acceso.'
                    : 'Actualiza tu nombre y correo electrónico.'
            "
        />

        <Form
            novalidate
            v-bind="ProfileController.update.form()"
            @submit.capture="
                reviewSubmission($event, {
                    title: 'Confirmar cambios de perfil',
                    description:
                        'Se actualizarán tus datos de perfil y de acceso.',
                })
            "
            class="grid gap-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Nombre completo"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <AccountIdentifierField
                id="profile-identifier"
                :field="loginField"
                :value="user[loginField]"
                :error="errors[loginField]"
            />

            <Button
                type="submit"
                class="min-h-11 w-full lg:w-auto lg:justify-self-start"
                :disabled="processing"
                data-test="update-profile-button"
            >
                Guardar cambios
            </Button>
        </Form>

        <Separator class="lg:hidden" />

        <Button
            as-child
            variant="outline"
            class="min-h-11 w-full lg:w-auto lg:self-start"
        >
            <LogoutLink data-test="profile-logout-button">
                <LogOut data-icon="inline-start" aria-hidden="true" />
                Cambiar de sesión
            </LogoutLink>
        </Button>
    </div>
</template>
