import { formatUsd } from '@/lib/money';
import type { CashMovement, MovementKind } from '@/types/cash-movement';

export const movementLabels: Record<MovementKind, string> = {
    income: 'Ingreso',
    expense: 'Egreso',
    deposit: 'Depósito de efectivo',
};

const movementSigns: Record<MovementKind, string> = {
    income: '+',
    expense: '−',
    deposit: '',
};

export function movementMethod(movement: CashMovement): string {
    return movement.paymentMethod === 'cash' ? 'Efectivo' : 'Transferencia';
}

export function movementAmount(movement: CashMovement): string {
    return movementSigns[movement.kind] + formatUsd(movement.amountCents);
}

export function movementStatus(movement: CashMovement): string {
    if (movement.returnedOn) {
        return 'Devuelto';
    }
    if (movement.confirmed) {
        return 'Confirmado';
    }
    return movement.kind === 'deposit' ? 'En tránsito' : 'Pendiente';
}

export function movementAction(movement: CashMovement): string | null {
    if (movement.confirmed || movement.returnedOn) {
        return null;
    }
    return movement.kind === 'deposit' ? 'Gestionar depósito' : 'Confirmar';
}
