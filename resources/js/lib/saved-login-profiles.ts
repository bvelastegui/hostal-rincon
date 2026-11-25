const storageKey = 'hostal-rincon:saved-login-profiles';
const profileLimit = 5;

export type SavedLoginProfile = { name: string; email: string };

function isSavedProfile(value: unknown): value is SavedLoginProfile {
    return (
        typeof value === 'object' &&
        value !== null &&
        'name' in value &&
        typeof value.name === 'string' &&
        'email' in value &&
        typeof value.email === 'string'
    );
}

export function loadSavedProfiles(): SavedLoginProfile[] {
    try {
        const saved: unknown = JSON.parse(
            localStorage.getItem(storageKey) ?? '[]',
        );
        if (Array.isArray(saved)) {
            return saved
                .filter(isSavedProfile)
                .slice(0, profileLimit)
                .map(({ name, email }) => ({ name, email }));
        }
    } catch {
        // Una sesión manual sigue disponible si el navegador bloquea el almacenamiento.
    }
    return [];
}

export function storeSavedProfiles(
    profiles: SavedLoginProfile[],
): SavedLoginProfile[] {
    const saved = profiles
        .slice(0, profileLimit)
        .map(({ name, email }) => ({ name, email }));
    try {
        if (saved.length) {
            localStorage.setItem(storageKey, JSON.stringify(saved));
        } else {
            localStorage.removeItem(storageKey);
        }
    } catch {
        // Guardar perfiles es opcional para el inicio de sesión.
    }
    return saved;
}
