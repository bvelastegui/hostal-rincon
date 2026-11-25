<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LockKeyhole, LockKeyholeOpen } from '@lucide/vue';
import { computed } from 'vue';
import { buttonVariants } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { updateSensitivity } from '@/routes/movements';
import type { CashMovement } from '@/types/cash-movement';

const props = defineProps<{ movement: CashMovement }>();
const page = usePage();
const label = computed(() =>
    props.movement.isSensitive ? 'Quitar marca sensible' : 'Marcar sensible',
);
</script>

<template>
    <Tooltip
        v-if="
            page.props.auth.canManageMovementSensitivity &&
            movement.kind !== 'advance' &&
            movement.status !== 'voided'
        "
    >
        <TooltipTrigger as-child>
            <Link
                :href="updateSensitivity(movement.id)"
                :data="{ is_sensitive: !movement.isSensitive }"
                :aria-label="label"
                :class="
                    cn(
                        buttonVariants({
                            size: 'icon-sm',
                            variant: movement.isSensitive
                                ? 'secondary'
                                : 'ghost',
                        }),
                        'size-11 lg:size-8',
                    )
                "
                as="button"
                preserve-scroll
            >
                <component
                    :is="movement.isSensitive ? LockKeyholeOpen : LockKeyhole"
                    data-icon="inline-start"
                    aria-hidden="true"
                />
            </Link>
        </TooltipTrigger>
        <TooltipContent>{{ label }}</TooltipContent>
    </Tooltip>
</template>
