import type { FormComponentRef } from '@inertiajs/core';
import { toast } from 'vue-sonner';
import { shallowRef } from 'vue';
import { formatUsd, parseUsdCents } from '@/lib/money';

export type ReviewAmount = { label: string; value: string };

type RecordReview = {
    title: string;
    description: string;
    amounts?: ReviewAmount[];
    details?: (string | undefined)[];
    confirmLabel?: string;
};

export const pendingRecord = shallowRef<
    (RecordReview & { save: () => void }) | null
>(null);

export function reviewAmount(
    label: string,
    amount: number | string | null,
): ReviewAmount {
    const raw = typeof amount === 'string' ? amount.trim() : '';
    const cents = typeof amount === 'string' ? parseUsdCents(raw) : amount;
    const value =
        cents === null
            ? raw
                ? 'Revisa el valor ingresado'
                : 'Sin ingresar'
            : formatUsd(cents);

    return { label, value };
}

export function fieldValue(data: FormData, key: string): string {
    const value = data.get(key);
    return typeof value === 'string' ? value.trim() : '';
}

export function confirmRecord(review: RecordReview, save: () => void): void {
    pendingRecord.value ??= { ...review, save };
}

type ValidationErrors = Record<string, string>;

type SubmissionValidation = {
    route?: { url: string; method: string };
    data?: Record<string, string | number | null>;
    showErrors: (errors: ValidationErrors) => void;
};

export function showValidationErrors(
    clearErrors: () => void,
    setErrors: (errors: ValidationErrors) => void,
    navigate?: (errors: ValidationErrors) => void,
): SubmissionValidation {
    return {
        showErrors(errors) {
            clearErrors();
            setErrors(errors);
            navigate?.(errors);
        },
    };
}

export function inertiaValidation(
    form: FormComponentRef | null,
    navigate?: (errors: ValidationErrors) => void,
): SubmissionValidation {
    return showValidationErrors(
        () => form?.clearErrors(),
        (errors) => form?.setError(errors),
        navigate,
    );
}

const confirmedForms = new WeakSet<HTMLFormElement>();
const validatingForms = new WeakSet<HTMLFormElement>();

export async function reviewSubmission(
    event: SubmitEvent,
    review: RecordReview | ((data: FormData) => RecordReview | null),
    validation: SubmissionValidation,
): Promise<void> {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement) || confirmedForms.has(form)) {
        return;
    }

    const submitter = event.submitter;
    const fields = new FormData(form, submitter);
    const summary = typeof review === 'function' ? review(fields) : review;
    if (!summary) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    if (validatingForms.has(form) || pendingRecord.value) {
        return;
    }

    const url = validation.route?.url ?? form.action;
    const method = validation.route?.method ?? form.getAttribute('method');
    const canSubmit = () =>
        form.isConnected && (!submitter || form.contains(submitter));

    const data = validation.data ? new FormData() : fields;
    for (const [key, value] of Object.entries(validation.data ?? {})) {
        if (value !== null) {
            data.set(key, String(value));
        }
    }
    const body = new URLSearchParams();
    let containsFile = false;
    for (const [key, value] of data) {
        if (typeof value === 'string') {
            body.append(key, value);
        } else if (value.name) {
            containsFile = true;
        }
    }
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));
    const csrfToken = xsrfCookie
        ? decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length))
        : '';

    let changed = false;
    const markChanged = () => {
        changed = true;
    };
    form.addEventListener('input', markChanged);
    form.addEventListener('change', markChanged);
    validatingForms.add(form);
    form.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, {
            method: method?.toUpperCase(),
            mode: 'same-origin',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                Precognition: 'true',
                'X-XSRF-TOKEN': csrfToken,
            },
            body: containsFile ? data : body,
        });
        if (!canSubmit() || changed) {
            return;
        }
        if (response.status === 422) {
            const result: { errors: Record<string, string[]> } =
                await response.json();
            validation.showErrors(
                Object.fromEntries(
                    Object.entries(result.errors).map(([field, messages]) => [
                        field,
                        messages[0],
                    ]),
                ),
            );
            return;
        }
        if (
            response.status !== 204 ||
            response.headers.get('Precognition-Success') !== 'true'
        ) {
            throw new Error('No se pudo validar el formulario.');
        }
        validation.showErrors({});

        confirmRecord(summary, () => {
            if (!canSubmit()) {
                return;
            }

            confirmedForms.add(form);
            try {
                form.requestSubmit(submitter ?? undefined);
            } finally {
                confirmedForms.delete(form);
            }
        });
    } catch {
        if (canSubmit()) {
            toast.error(
                'No se pudo validar el formulario. Inténtalo de nuevo.',
            );
        }
    } finally {
        validatingForms.delete(form);
        form.removeEventListener('input', markChanged);
        form.removeEventListener('change', markChanged);
        form.removeAttribute('aria-busy');
    }
}
