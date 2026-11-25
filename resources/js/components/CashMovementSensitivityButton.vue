<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LockKeyhole, LockKeyholeOpen } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { updateSensitivity } from '@/routes/movements';
import type { CashMovement } from '@/types/cash-movement';

defineProps<{ movement: CashMovement }>();

const page = usePage();
</script>

<template>
    <Button
        v-if="
            page.props.auth.canManageMovementSensitivity &&
            movement.kind !== 'advance'
        "
        as-child
        size="sm"
        variant="ghost"
    >
        <Link
            :href="updateSensitivity(movement.id)"
            :data="{ is_sensitive: !movement.isSensitive }"
            as="button"
            preserve-scroll
        >
            <component
                :is="movement.isSensitive ? LockKeyholeOpen : LockKeyhole"
                data-icon="inline-start"
                aria-hidden="true"
            />
            {{
                movement.isSensitive
                    ? 'Quitar marca sensible'
                    : 'Marcar sensible'
            }}
        </Link>
    </Button>
</template>
