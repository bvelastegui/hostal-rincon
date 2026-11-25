import type { ColumnDef } from '@tanstack/vue-table';

export type DataTableColumn<Row> = ColumnDef<Row> & {
    meta?: {
        align?: 'right';
    };
};
