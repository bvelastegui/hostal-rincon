import type { BadgeVariants } from '@/components/ui/badge';
import { formatUsd } from '@/lib/money';
import type {
    CashMovement,
    MovementKind,
    MovementRow,
    MovementStatus,
    PaymentMethod,
} from '@/types/cash-movement';

export const movementLabels: Record<MovementKind | 'transaction', string> = {
    income: 'Ingreso',
    expense: 'Egreso',
    deposit: 'Depósito de efectivo',
    advance: 'Adelanto de sueldo',
    transaction: 'Transacción',
};

const movementSigns: Record<MovementKind, string> = {
    income: '+',
    expense: '−',
    deposit: '',
    advance: '−',
};

export const paymentOptions: { value: PaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'transfer', label: 'Transferencia bancaria' },
];

export function movementAccountRole(kind: MovementKind): string {
    return kind === 'expense' || kind === 'advance' ? 'Origen' : 'Destino';
}

export function movementMethod(movement: CashMovement): string {
    return movement.paymentMethod === 'cash' ? 'Efectivo' : 'Transferencia';
}

export function movementAmount(movement: MovementRow): string {
    const amount = formatUsd(movement.amountCents);

    return movement.redacted ? amount : movementSigns[movement.kind] + amount;
}

export const movementStatusLabels: Record<MovementStatus, string> = {
    confirmed: 'Confirmado',
    pending: 'Pendiente',
    in_transit: 'En tránsito',
    returned: 'Devuelto',
};

export const movementStatusVariants = {
    confirmed: 'success',
    pending: 'warning',
    in_transit: 'info',
    returned: 'secondary',
} satisfies Record<MovementStatus, BadgeVariants['variant']>;

export function movementAction(movement: MovementRow): string | null {
    if (movement.redacted || movement.confirmed || movement.returnedOn) {
        return null;
    }
    return movement.kind === 'deposit' ? 'Gestionar depósito' : 'Confirmar';
}
