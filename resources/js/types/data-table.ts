import type { ColumnDef } from '@tanstack/vue-table';

export type DataTableColumn<Row> = ColumnDef<Row> & {
    meta?: {
        align?: 'right';
    };
};

export type DataTablePagination = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: {
        url: string | null;
        label: string;
        page?: number | null;
        active: boolean;
    }[];
};

export type DataTablePage<Row> = DataTablePagination & { data: Row[] };
