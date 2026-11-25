export type PendingSetting = 'cash_opening' | 'active_bank' | 'bank_account';

type PendingAmount = { count: number; amountCents: number };

export type DashboardAttention = {
    transfers: PendingAmount & {
        dateFrom: string | null;
        dateTo: string | null;
    };
    deposits: PendingAmount;
    shortages: PendingAmount;
};
