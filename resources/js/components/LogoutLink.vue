<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { logout } from '@/routes';

function flushPrefetchedPages() {
    router.flushAll();
}
</script>

<template>
    <Link
        :href="logout()"
        as="button"
        type="button"
        @click="flushPrefetchedPages"
    >
        <slot />
    </Link>
</template>
