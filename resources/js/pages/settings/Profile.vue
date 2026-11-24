<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LogoutLink from '@/components/LogoutLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Perfil" />

    <div class="flex flex-col gap-6">
        <Heading
            variant="small"
            title="Perfil"
            description="Actualiza tu nombre y correo electrónico"
        />

        <Form
            v-bind="ProfileController.update.form()"
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

            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Correo electrónico"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <Button
                type="submit"
                class="min-h-11 w-full lg:w-auto lg:justify-self-start"
                :disabled="processing"
                data-test="update-profile-button"
            >
                Guardar cambios
            </Button>
        </Form>

        <Button
            as-child
            variant="outline"
            class="min-h-11 w-full lg:w-auto lg:self-start"
        >
            <LogoutLink data-test="profile-logout-button">
                <LogOut data-icon="inline-start" aria-hidden="true" />
                Cerrar sesión
            </LogoutLink>
        </Button>
    </div>
</template>
