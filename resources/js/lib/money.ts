const usd = new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency: 'USD',
});

export const formatUsd = (cents: number): string => usd.format(cents / 100);

export function formatCashDifference(cents: number): string {
    return cents === 0
        ? 'Sin diferencia'
        : (cents < 0 ? 'Faltante: ' : 'Sobrante: ') +
              formatUsd(Math.abs(cents));
}
