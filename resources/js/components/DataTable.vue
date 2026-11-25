<script setup lang="ts" generic="Row extends { id: number | string }">
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { DataTableColumn } from '@/types/data-table';

const props = defineProps<{
    caption: string;
    rows: Row[];
    columns: DataTableColumn<Row>[];
}>();

const table = useVueTable({
    get data() {
        return props.rows;
    },
    get columns() {
        return props.columns;
    },
    getRowId: (row) => String(row.id),
    getCoreRowModel: getCoreRowModel(),
});

function alignment(columnId: string) {
    return props.columns.find((column) => column.id === columnId)?.meta?.align;
}
</script>

<template>
    <Table class="border-y">
        <TableCaption class="sr-only">{{ caption }}</TableCaption>
        <TableHeader>
            <TableRow v-for="group in table.getHeaderGroups()" :key="group.id">
                <TableHead
                    v-for="header in group.headers"
                    :key="header.id"
                    scope="col"
                    :colspan="header.colSpan"
                    :class="{
                        'text-right': alignment(header.column.id) === 'right',
                    }"
                >
                    <FlexRender
                        v-if="!header.isPlaceholder"
                        :render="header.column.columnDef.header"
                        :props="header.getContext()"
                    />
                </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="row in table.getRowModel().rows" :key="row.id">
                <TableCell
                    v-for="cell in row.getVisibleCells()"
                    :key="cell.id"
                    class="align-top"
                    :class="{
                        'text-right tabular-nums':
                            alignment(cell.column.id) === 'right',
                    }"
                >
                    <FlexRender
                        :render="cell.column.columnDef.cell"
                        :props="cell.getContext()"
                    />
                </TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
