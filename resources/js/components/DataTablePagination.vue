<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { DataTablePagination } from '@/types/data-table';

const props = defineProps<{ label: string; page: DataTablePagination }>();
const pageLinks = computed(() => props.page.links.slice(1, -1));
</script>

<template>
    <div
        v-if="page.total"
        class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
    >
        <p
            aria-live="polite"
            class="text-sm text-muted-foreground tabular-nums"
        >
            <template v-if="page.from !== null && page.to !== null">
                {{ page.from }}–{{ page.to }} de {{ page.total }} registros
            </template>
            <template v-else>No hay registros en esta página.</template>
        </p>

        <nav
            v-if="page.last_page > 1"
            class="flex items-center gap-1"
            :aria-label="label"
        >
            <Button
                v-if="page.prev_page_url"
                as-child
                variant="outline"
                size="icon"
                class="min-h-10 min-w-10"
            >
                <Link
                    :href="page.prev_page_url"
                    rel="prev"
                    preserve-scroll
                    aria-label="Página anterior"
                >
                    <ChevronLeft aria-hidden="true" />
                </Link>
            </Button>
            <Button
                v-else
                type="button"
                variant="outline"
                size="icon"
                class="min-h-10 min-w-10"
                aria-label="Página anterior"
                disabled
            >
                <ChevronLeft aria-hidden="true" />
            </Button>

            <span
                class="px-2 text-sm text-muted-foreground tabular-nums md:hidden"
            >
                Página {{ page.current_page }} de {{ page.last_page }}
            </span>
            <ul class="hidden items-center gap-1 md:flex">
                <li
                    v-for="(link, index) in pageLinks"
                    :key="link.page ?? index + '-' + link.label"
                >
                    <Button
                        v-if="link.url && link.page"
                        as-child
                        :variant="link.active ? 'secondary' : 'ghost'"
                        size="icon"
                        class="min-h-10 min-w-10"
                    >
                        <Link
                            :href="link.url"
                            preserve-scroll
                            :aria-label="'Página ' + link.page"
                            :aria-current="link.active ? 'page' : undefined"
                        >
                            {{ link.page }}
                        </Link>
                    </Button>
                    <span
                        v-else
                        class="px-2 text-muted-foreground"
                        aria-hidden="true"
                        >…</span
                    >
                </li>
            </ul>

            <Button
                v-if="page.next_page_url"
                as-child
                variant="outline"
                size="icon"
                class="min-h-10 min-w-10"
            >
                <Link
                    :href="page.next_page_url"
                    rel="next"
                    preserve-scroll
                    aria-label="Página siguiente"
                >
                    <ChevronRight aria-hidden="true" />
                </Link>
            </Button>
            <Button
                v-else
                type="button"
                variant="outline"
                size="icon"
                class="min-h-10 min-w-10"
                aria-label="Página siguiente"
                disabled
            >
                <ChevronRight aria-hidden="true" />
            </Button>
        </nav>
    </div>
</template>
