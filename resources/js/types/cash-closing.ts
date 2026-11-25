import type { DataTablePage } from '@/types/data-table';

export type CashShortageResolution = {
    at: string;
    by: string | null;
    method: 'deduction' | 'other';
    note: string;
};

export type CashClosingRecord = {
    id: number;
    shiftId: number | null;
    responsible: string | null;
    type:
        | 'independent'
        | 'shift'
        | 'delivery'
        | 'receipt'
        | 'administrative'
        | 'opening';
    closedAt: string;
    closedBy: string | null;
    confirmedByIsResponsible: boolean;
    expectedCashCents: number;
    countedCashCents: number;
    differenceCents: number;
    adjustmentCents: number;
    note: string | null;
    resolution: CashShortageResolution | null;
};

export type CashClosingPage = DataTablePage<CashClosingRecord>;

export type CashHistoryFilters = {
    date_from: string | null;
    date_to: string | null;
    type: string | null;
    responsible_id: number | null;
    difference: string | null;
    resolution: string | null;
};
