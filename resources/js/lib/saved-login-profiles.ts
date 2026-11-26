import type { LoginField } from '@/types/auth';

const profileLimit = 5;

export type SavedLoginProfile = { name: string; identifier: string };

function profilesKey(field: LoginField) {
    return field === 'email'
        ? 'hostal-rincon:saved-login-profiles'
        : 'hostal-rincon:saved-username-profiles';
}

function readProfile(value: unknown, field: LoginField): SavedLoginProfile[] {
    if (
        typeof value !== 'object' ||
        value === null ||
        !('name' in value) ||
        typeof value.name !== 'string' ||
        !(field in value)
    ) {
        return [];
    }
    const identifier: unknown = Reflect.get(value, field);
    return typeof identifier === 'string' && identifier.length
        ? [{ name: value.name, identifier }]
        : [];
}

export function loadSavedProfiles(field: LoginField): SavedLoginProfile[] {
    try {
        const saved: unknown = JSON.parse(
            localStorage.getItem(profilesKey(field)) ?? '[]',
        );
        return Array.isArray(saved)
            ? saved
                  .flatMap((profile) => readProfile(profile, field))
                  .slice(0, profileLimit)
            : [];
    } catch {
        // El inicio manual sigue disponible si el almacenamiento está bloqueado.
        return [];
    }
}

export function storeSavedProfiles(
    field: LoginField,
    profiles: SavedLoginProfile[],
): SavedLoginProfile[] {
    const saved = profiles.slice(0, profileLimit);
    try {
        if (saved.length) {
            localStorage.setItem(
                profilesKey(field),
                JSON.stringify(
                    saved.map(({ name, identifier }) => ({
                        name,
                        [field]: identifier,
                    })),
                ),
            );
        } else {
            localStorage.removeItem(profilesKey(field));
        }
    } catch {
        // Guardar perfiles es opcional para el inicio de sesión.
    }
    return saved;
}
