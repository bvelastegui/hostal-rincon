export type MovementKind = 'income' | 'expense' | 'deposit' | 'advance';
export type ReservedMovementKind = 'income' | 'expense' | 'transaction';
export type PaymentMethod = 'cash' | 'transfer';

export type MovementStatus =
    | 'voided'
    | 'returned'
    | 'confirmed'
    | 'in_transit'
    | 'pending';

export type CashMovement = {
    id: number;
    redacted: false;
    isSensitive: boolean;
    shiftId: number | null;
    kind: MovementKind;
    paymentMethod: PaymentMethod;
    amountCents: number;
    description: string;
    date: string;
    status: MovementStatus;
    originBankName: string | null;
    accountBankName: string | null;
    accountName: string | null;
    voucher: string | null;
    responsibleName: string | null;
    recorderName: string;
    confirmedOn: string | null;
    confirmed: boolean;
    returnedOn: string | null;
    canCorrect: boolean;
    replacesId?: number | null;
    replacementId?: number | null;
    voidedBy?: string | null;
    voidedOn?: string | null;
    voidReason?: string | null;
};

export type MovementRow = (
    | CashMovement
    | {
          id: number;
          redacted: true;
          kind: ReservedMovementKind;
          recorderName: string;
          amountCents: number;
          date: string;
          status: MovementStatus;
          account: string | null;
      }
) & {
    children?: MovementRow[];
    expandHistory?: boolean;
};

export type CashBalances = {
    cash: number | null;
    bank: number;
    transit: number | null;
    total: number | null;
};
