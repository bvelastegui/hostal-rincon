import { usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, Clock, LayoutGrid, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { index as advances } from '@/actions/App/Http/Controllers/AdvanceController';
import { dashboard } from '@/routes';
import { index as cashShift } from '@/routes/cash-closings';
import { index as movements } from '@/routes/movements';
import type { NavItem } from '@/types';

export function useMainNavigation() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        ...(!page.props.auth.cashShiftAccess
            ? [{ title: 'Panel', href: dashboard(), icon: LayoutGrid }]
            : []),
        ...(page.props.auth.cashShiftAccess !== 'restricted'
            ? [
                  {
                      title: 'Movimientos',
                      href: movements(),
                      icon: ArrowLeftRight,
                  },
              ]
            : []),
        { title: 'Turno', href: cashShift(), icon: Clock },
        ...(page.props.auth.canManageAdvances
            ? [{ title: 'Adelantos', href: advances(), icon: Wallet }]
            : []),
    ]);
}
