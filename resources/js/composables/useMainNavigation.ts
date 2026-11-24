import { usePage } from '@inertiajs/vue3';
import { LayoutGrid, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { index as advances } from '@/actions/App/Http/Controllers/AdvanceController';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

export function useMainNavigation() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        { title: 'Panel', href: dashboard(), icon: LayoutGrid },
        ...(page.props.auth.canManageAdvances
            ? [{ title: 'Adelantos', href: advances(), icon: Wallet }]
            : []),
    ]);
}
