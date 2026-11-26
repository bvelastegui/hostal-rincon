export type LoginField = 'email' | 'username';

export type User = {
    id: number;
    name: string;
    email: string | null;
    username?: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    loginField: LoginField;
    canManageAdvances: boolean;
    canManageCashSettings: boolean;
    canManageLocalBackups: boolean;
    canViewMonthlyReport: boolean;
    canManageMovementSensitivity: boolean;
    cashShiftAccess: 'owner' | 'restricted' | null;
    activeCashShift: { responsible: string; awaitingReceipt: boolean } | null;
};
