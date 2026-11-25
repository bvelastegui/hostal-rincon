export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    canManageAdvances: boolean;
    canManageCashSettings: boolean;
    canViewMonthlyReport: boolean;
    canManageMovementSensitivity: boolean;
    cashShiftAccess: 'owner' | 'restricted' | null;
    activeCashShift: { responsible: string; awaitingReceipt: boolean } | null;
};
