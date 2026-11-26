<script setup lang="ts">
import { watch } from 'vue';
import RecordAmounts from '@/components/RecordAmounts.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { pendingRecord } from '@/lib/record-confirmation';

let previousFocus: HTMLElement | null = null;
watch(
    pendingRecord,
    (record) => {
        if (record && document.activeElement instanceof HTMLElement) {
            previousFocus = document.activeElement;
        }
    },
    { flush: 'sync' },
);

function restoreFocus(event: Event): void {
    event.preventDefault();
    previousFocus?.focus();
    previousFocus = null;
}

function save(): void {
    const record = pendingRecord.value;
    pendingRecord.value = null;
    record?.save();
}
</script>

<template>
    <Dialog
        :open="!!pendingRecord"
        @update:open="
            (open) => {
                if (!open) pendingRecord = null;
            }
        "
    >
        <DialogContent
            :show-close-button="false"
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto"
            @close-auto-focus="restoreFocus"
        >
            <template v-if="pendingRecord">
                <DialogHeader>
                    <DialogTitle>{{ pendingRecord.title }}</DialogTitle>
                    <DialogDescription>{{
                        pendingRecord.description
                    }}</DialogDescription>
                </DialogHeader>
                <RecordAmounts
                    v-if="pendingRecord.amounts?.length"
                    :amounts="pendingRecord.amounts"
                />
                <ul
                    v-if="pendingRecord.details?.some(Boolean)"
                    class="flex flex-col gap-1 text-sm wrap-break-word text-muted-foreground"
                >
                    <template
                        v-for="(detail, index) in pendingRecord.details"
                        :key="index"
                    >
                        <li v-if="detail">{{ detail }}</li>
                    </template>
                </ul>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        @click="pendingRecord = null"
                        >Volver</Button
                    >
                    <Button type="button" class="min-h-11" @click="save">{{
                        pendingRecord.confirmLabel ?? 'Confirmar y guardar'
                    }}</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
