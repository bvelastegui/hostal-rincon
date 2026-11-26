<script setup lang="ts">
import emblem from '../../images/hostal-rincon-emblema.webp';
</script>

<template>
    <span class="inline-flex items-center gap-1.5 py-1">
        <img
            :src="emblem"
            alt=""
            width="40"
            height="40"
            class="size-10 shrink-0"
        />
        <span class="text-left font-sans text-xl leading-none font-semibold">
            <span class="block">Hostal</span>
            <span class="block">Rincón</span>
        </span>
    </span>
</template>
