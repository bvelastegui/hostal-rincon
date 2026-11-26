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

const confirmedForms = new WeakSet<HTMLFormElement>();

export function reviewSubmission(
    event: SubmitEvent,
    review: RecordReview | ((data: FormData) => RecordReview | null),
): void {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement) || confirmedForms.has(form)) {
        return;
    }

    const submitter = event.submitter;
    const summary =
        typeof review === 'function'
            ? review(new FormData(form, submitter))
            : review;
    if (!summary) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    confirmRecord(summary, () => {
        if (!form.isConnected || (submitter && !form.contains(submitter))) {
            return;
        }

        // Reenviar con el mismo botón conserva su nombre y valor.
        confirmedForms.add(form);
        try {
            form.requestSubmit(submitter ?? undefined);
        } finally {
            confirmedForms.delete(form);
        }
    });
}
