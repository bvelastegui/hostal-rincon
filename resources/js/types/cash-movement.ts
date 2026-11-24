export type MovementKind = 'income' | 'expense' | 'deposit';
export type PaymentMethod = 'cash' | 'transfer';

export type CashMovement = {
    id: number;
    kind: MovementKind;
    paymentMethod: PaymentMethod;
    amountCents: number;
    description: string;
    date: string;
    originBankName: string | null;
    accountBankName: string | null;
    accountName: string | null;
    voucher: string | null;
    responsibleName: string | null;
    recorderName: string;
    confirmedOn: string | null;
    confirmed: boolean;
    returnedOn: string | null;
};
