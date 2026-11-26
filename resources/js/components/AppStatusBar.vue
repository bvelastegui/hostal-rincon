<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { index as cashShift } from '@/routes/cash-closings';
import { version } from '../../../package.json';

const page = usePage();
const user = computed(() => page.props.auth.user);
const shift = computed(() => page.props.auth.activeCashShift);
const status = computed(() =>
    shift.value?.awaitingReceipt ? 'Recepción pendiente' : 'Turno activo',
);
</script>

<template>
    <footer
        aria-label="Estado de la aplicación"
        class="shrink-0 bg-primary pt-[env(safe-area-inset-top)] text-primary-foreground lg:fixed lg:inset-x-0 lg:bottom-0 lg:z-30 lg:pt-0 lg:pb-[env(safe-area-inset-bottom)]"
    >
        <div
            class="app-container flex h-9 items-center justify-between gap-2 text-xs sm:gap-4"
        >
            <div
                class="flex min-w-0 items-center gap-2 text-primary-foreground/85"
            >
                <UserRound
                    class="hidden size-3.5 shrink-0 sm:block"
                    aria-hidden="true"
                />
                <span
                    class="truncate font-medium text-primary-foreground"
                    :title="user.name"
                >
                    {{ user.name }}
                </span>
                <span
                    class="shrink-0 tabular-nums"
                    :aria-label="`Versión ${version}`"
                >
                    v{{ version }}
                </span>
            </div>

            <div class="max-w-[62%] min-w-0 shrink-0" aria-live="polite">
                <Link
                    v-if="shift"
                    :href="cashShift()"
                    class="flex min-w-0 items-center gap-1.5 rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-primary-foreground"
                    :title="`${status} · ${shift.responsible}`"
                >
                    <Badge variant="warning">
                        <span class="hidden sm:inline">{{ status }}</span>
                        <span class="sm:hidden">{{
                            shift.awaitingReceipt ? 'Pendiente' : 'Activo'
                        }}</span>
                    </Badge>
                    <span class="truncate">{{ shift.responsible }}</span>
                </Link>
                <span v-else class="text-primary-foreground/85"
                    >Sin turno activo</span
                >
            </div>
        </div>
    </footer>
</template>
