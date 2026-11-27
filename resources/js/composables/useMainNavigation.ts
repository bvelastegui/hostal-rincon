import { usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ChartNoAxesColumn,
    Clock,
    LayoutGrid,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { index as advances } from '@/actions/App/Http/Controllers/AdvanceController';
import { advancesModuleTitle } from '@/lib/advances';
import { dashboard } from '@/routes';
import { index as cashShift } from '@/routes/cash-closings';
import { index as movements } from '@/routes/movements';
import { monthly as monthlyReport } from '@/routes/reports';
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
        {
            title: 'Turno',
            href: cashShift(),
            icon: Clock,
            mobileMenu: page.props.auth.cashShiftAccess === null,
        },
        ...(page.props.auth.canManageAdvances
            ? [{ title: advancesModuleTitle, href: advances(), icon: Wallet }]
            : []),
        ...(page.props.auth.canViewMonthlyReport
            ? [
                  {
                      title: 'Reporte',
                      href: monthlyReport(),
                      icon: ChartNoAxesColumn,
                      mobileMenu: true,
                  },
              ]
            : []),
    ]);
}
