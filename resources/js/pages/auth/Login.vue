<script setup lang="ts">
import type { Page, SharedPageProps } from '@inertiajs/core';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { UserRound, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AccountIdentifierField from '@/components/AccountIdentifierField.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    loadSavedProfiles,
    storeSavedProfiles,
} from '@/lib/saved-login-profiles';
import type { SavedLoginProfile } from '@/lib/saved-login-profiles';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Ingresa a tu cuenta',
    },
});

defineProps<{
    status?: string;
    handoff?: string;
    canResetPassword: boolean;
}>();

const loginField = usePage().props.auth.loginField;
const profiles = ref<SavedLoginProfile[]>([]);
const selected = ref<SavedLoginProfile | null>(null);
const manual = ref(false);
const saveHere = ref(false);
const passwordKey = ref(0);
const password = useTemplateRef('password');
const choosing = computed(
    () => profiles.value.length > 0 && !manual.value && selected.value === null,
);

onMounted(() => {
    profiles.value = loadSavedProfiles(loginField);
});

function removeProfile(identifier: string) {
    profiles.value = storeSavedProfiles(
        loginField,
        profiles.value.filter((profile) => profile.identifier !== identifier),
    );
}

async function selectProfile(profile: SavedLoginProfile) {
    selected.value = profile;
    passwordKey.value++;
    await nextTick();
    password.value?.focus();
}

function switchAccount(showManual: boolean) {
    selected.value = null;
    manual.value = showManual;
    saveHere.value = false;
    passwordKey.value++;
}

function rememberProfile(page: Page<SharedPageProps>) {
    const user = page.props.auth?.user;
    const identifier = user?.[loginField];
    if (user && identifier && (saveHere.value || selected.value)) {
        profiles.value = storeSavedProfiles(loginField, [
            { name: user.name, identifier },
            ...profiles.value.filter(
                (profile) => profile.identifier !== identifier,
            ),
        ]);
    }
}
</script>

<template>
    <Head title="Iniciar sesión" />

    <p v-if="handoff" class="mb-4 text-center text-sm font-medium">
        {{ handoff }}
    </p>

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <section
        v-if="choosing"
        class="flex flex-col gap-3"
        aria-labelledby="saved-profiles-title"
    >
        <h2 id="saved-profiles-title" class="text-sm font-medium">
            Perfiles guardados
        </h2>
        <ul class="flex flex-col gap-2">
            <li
                v-for="profile in profiles"
                :key="profile.identifier"
                class="flex items-center gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto min-h-12 min-w-0 flex-1 justify-start py-2 text-left"
                    @click="selectProfile(profile)"
                >
                    <UserRound data-icon="inline-start" aria-hidden="true" />
                    <span class="grid min-w-0">
                        <span class="truncate">{{ profile.name }}</span>
                        <span class="truncate text-xs text-muted-foreground">{{
                            profile.identifier
                        }}</span>
                    </span>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-lg"
                    :aria-label="'Quitar el perfil de ' + profile.name"
                    @click="removeProfile(profile.identifier)"
                >
                    <X aria-hidden="true" />
                </Button>
            </li>
        </ul>
        <Button
            type="button"
            variant="outline"
            class="min-h-11"
            @click="switchAccount(true)"
            >Usar otra cuenta</Button
        >
    </section>

    <Form
        novalidate
        v-else
        v-bind="store.form()"
        autocomplete="off"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
        @success="rememberProfile"
    >
        <div class="grid gap-6">
            <div
                v-if="selected"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <div class="min-w-0">
                    <p class="font-medium">{{ selected.name }}</p>
                    <p class="text-sm wrap-break-word text-muted-foreground">
                        {{ selected.identifier }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="switchAccount(false)"
                    >Cambiar perfil</Button
                >
                <input
                    type="hidden"
                    :name="loginField"
                    :value="selected.identifier"
                />
            </div>
            <AccountIdentifierField
                v-else
                id="login-identifier"
                :field="loginField"
                :error="errors[loginField]"
                autofocus
            />

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Contraseña</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                    >
                        ¿Olvidaste tu contraseña?
                    </TextLink>
                </div>
                <PasswordInput
                    :key="passwordKey"
                    ref="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="off"
                    placeholder="Contraseña"
                />
                <InputError
                    :message="
                        errors.password ??
                        (selected ? errors[loginField] : undefined)
                    "
                />
            </div>

            <div v-if="!selected" class="grid gap-1">
                <Label for="save-profile" class="flex items-center gap-2">
                    <Checkbox
                        id="save-profile"
                        :model-value="saveHere"
                        @update:model-value="saveHere = $event === true"
                    />
                    <span>Guardar mi perfil en este navegador</span>
                </Label>
                <p class="text-xs text-muted-foreground">
                    Solo se guardan tu nombre y correo.
                </p>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Iniciar sesión
            </Button>
            <Button
                v-if="manual && profiles.length"
                type="button"
                variant="ghost"
                class="min-h-11"
                @click="switchAccount(false)"
            >
                Volver a perfiles guardados
            </Button>
        </div>
    </Form>
</template>
