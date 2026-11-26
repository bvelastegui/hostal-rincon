<script setup lang="ts">
import emblem from '../../images/emblema2.png';
</script>

<template>
    <span class="inline-flex items-center gap-4 py-1">
        <img
            :src="emblem"
            alt=""
            width="40"
            height="40"
            class="size-14 shrink-0 rounded-full bg-white"
        />
        <span class="text-left font-sans text-xl leading-none font-semibold">
            <span class="block">Hostal</span>
            <span class="block">Rincón</span>
        </span>
    </span>
</template>
