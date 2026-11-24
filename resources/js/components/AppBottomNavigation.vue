<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { edit as editProfile } from '@/routes/profile';

const mainNavItems = useMainNavigation();
const { isCurrentOrParentUrl } = useCurrentUrl();

const items = computed(() => [
    ...mainNavItems.value.map((item) => ({
        ...item,
        active: isCurrentOrParentUrl(item.href),
    })),
    {
        title: 'Perfil',
        href: editProfile(),
        icon: UserRound,
        active: isCurrentOrParentUrl('/settings/'),
    },
]);
</script>

<template>
    <nav
        aria-label="Menú principal"
        class="sticky bottom-0 z-30 mt-auto shrink-0 border-t bg-background pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
        <ul class="mx-auto flex w-full max-w-md gap-1 px-3 py-2 sm:gap-2">
            <li v-for="item in items" :key="item.title" class="min-w-0 flex-1">
                <Button
                    as-child
                    :variant="item.active ? 'secondary' : 'ghost'"
                    class="h-auto min-h-12 w-full flex-col gap-1 px-1 py-1 text-xs sm:px-2 sm:text-sm"
                >
                    <Link
                        :href="item.href"
                        :aria-current="item.active ? 'location' : undefined"
                    >
                        <component
                            v-if="item.icon"
                            :is="item.icon"
                            aria-hidden="true"
                            data-icon="inline-start"
                        />
                        {{ item.title }}
                    </Link>
                </Button>
            </li>
        </ul>
    </nav>
</template>
