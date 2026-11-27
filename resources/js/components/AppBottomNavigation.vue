<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Ellipsis, UserRound } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMainNavigation } from '@/composables/useMainNavigation';
import { cn } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';

const mainNavItems = useMainNavigation();
const page = usePage();
const { isCurrentOrParentUrl } = useCurrentUrl();
const menuOpen = ref(false);
const desktop = useMediaQuery('(min-width: 64rem)');

watch(desktop, (isDesktop) => {
    if (isDesktop) {
        menuOpen.value = false;
    }
});

const items = computed(() => [
    ...mainNavItems.value.map((item) => ({
        ...item,
        active: isCurrentOrParentUrl(item.href),
    })),
    ...(!page.props.auth.cashShiftAccess
        ? [
              {
                  title: 'Perfil',
                  href: editProfile(),
                  icon: UserRound,
                  active: isCurrentOrParentUrl('/settings/'),
                  mobileMenu: true,
              },
          ]
        : []),
]);
const mainItems = computed(() =>
    items.value.filter(({ mobileMenu }) => !mobileMenu),
);
const moreItems = computed(() =>
    items.value.filter(({ mobileMenu }) => mobileMenu),
);
const moreIsActive = computed(() =>
    moreItems.value.some(({ active }) => active),
);
const tabClass =
    'h-auto min-h-12 w-full flex-col justify-start gap-1 px-0 py-1 text-xs has-[>svg]:px-0 sm:text-sm';
</script>

<template>
    <Sheet v-model:open="menuOpen">
        <nav
            aria-label="Menú principal"
            class="sticky bottom-0 z-30 mt-auto shrink-0 border-t bg-background pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <ul
                class="mx-auto flex w-full max-w-md gap-1 px-2 py-2 sm:gap-2 sm:px-3"
            >
                <li
                    v-for="item in mainItems"
                    :key="item.title"
                    class="flex min-w-0 flex-1"
                >
                    <Button
                        as-child
                        :variant="item.active ? 'secondary' : 'ghost'"
                        :class="tabClass"
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
                            <span
                                class="max-w-full text-center leading-tight whitespace-normal"
                                >{{ item.title }}</span
                            >
                        </Link>
                    </Button>
                </li>
                <li v-if="moreItems.length" class="flex min-w-0 flex-1">
                    <SheetTrigger as-child>
                        <Button
                            type="button"
                            :variant="moreIsActive ? 'secondary' : 'ghost'"
                            :class="tabClass"
                        >
                            <Ellipsis
                                aria-hidden="true"
                                data-icon="inline-start"
                            />
                            Más
                        </Button>
                    </SheetTrigger>
                </li>
            </ul>
        </nav>

        <SheetContent
            v-if="moreItems.length"
            side="bottom"
            class="max-h-[calc(100dvh-env(safe-area-inset-top))] gap-0 overflow-y-auto rounded-t-xl pb-[env(safe-area-inset-bottom)]"
        >
            <SheetHeader class="mx-auto w-full max-w-md px-4 pt-4 pr-14 pb-2">
                <SheetTitle>Más opciones</SheetTitle>
                <SheetDescription class="sr-only">
                    Accesos adicionales del menú principal.
                </SheetDescription>
            </SheetHeader>
            <ul class="mx-auto flex w-full max-w-md flex-col gap-1 px-4 pb-3">
                <li v-for="item in moreItems" :key="item.title">
                    <SheetClose as-child>
                        <Link
                            :href="item.href"
                            :aria-current="item.active ? 'location' : undefined"
                            :class="
                                cn(
                                    buttonVariants({
                                        variant: item.active
                                            ? 'secondary'
                                            : 'ghost',
                                    }),
                                    'min-h-12 w-full justify-start gap-3 text-base',
                                )
                            "
                        >
                            <component
                                v-if="item.icon"
                                :is="item.icon"
                                aria-hidden="true"
                                data-icon="inline-start"
                            />
                            {{ item.title }}
                        </Link>
                    </SheetClose>
                </li>
            </ul>
        </SheetContent>
    </Sheet>
</template>
