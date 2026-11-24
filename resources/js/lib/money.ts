const usd = new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency: 'USD',
});

export const formatUsd = (cents: number): string => usd.format(cents / 100);
