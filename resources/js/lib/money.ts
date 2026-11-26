const usd = new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency: 'USD',
});

export const formatUsd = (cents: number): string => usd.format(cents / 100);

export function parseUsdCents(value: string): number | null {
    const decimal = value.trim().replace(',', '.');
    if (!/^(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/.test(decimal)) {
        return null;
    }

    const amount = Number(decimal);
    return Number.isFinite(amount) ? Math.round(amount * 100) : null;
}

export function formatCashDifference(cents: number): string {
    return cents === 0
        ? 'Sin diferencia'
        : (cents < 0 ? 'Faltante: ' : 'Sobrante: ') +
              formatUsd(Math.abs(cents));
}
