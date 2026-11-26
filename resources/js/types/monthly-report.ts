import type { CashBalances, PaymentMethod } from '@/types/cash-movement';

export type ReportSection =
    | 'saldos'
    | 'ingresos'
    | 'gastos'
    | 'depositos'
    | 'adelantos';

export type ReportBalances = CashBalances & {
    accounts: {
        id: number;
        name: string;
        bank: string;
        balanceCents: number;
    }[];
};

export type ReportMovementTotals = {
    kind: 'income' | 'expense' | 'deposit';
    method: PaymentMethod;
    confirmedCents: number;
    pendingCents: number;
    returnedCents: number;
};

export type ReportEmployeeTotals = {
    name: string;
    issuedCents: number;
    issuedCashCents: number;
    issuedTransferCents: number;
    deductedCents: number;
    pendingCents: number;
};

export type ReportAdvance = {
    id: number;
    employee: string;
    issuedOn: string;
    deductedOn: string | null;
    amountCents: number;
    paymentMethod: PaymentMethod;
    account: string | null;
    voucher: string | null;
    note: string | null;
    recorder: string | null;
    deductor: string | null;
};
