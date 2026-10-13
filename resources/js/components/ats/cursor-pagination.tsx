import { router } from '@inertiajs/react';

export interface CursorPaginationMeta {
    next_cursor: string | null;
    previous_cursor: string | null;
    has_more: boolean;
}

export default function CursorPagination({ meta, only }: { meta: CursorPaginationMeta; only?: string[] }) {
    if (!meta.next_cursor && !meta.previous_cursor) return null;

    const navigate = (cursor: string) => {
        const query = Object.fromEntries(new URLSearchParams(window.location.search).entries());
        router.get(window.location.pathname, { ...query, cursor }, { preserveState: true, preserveScroll: true, only });
    };

    return (
        <nav aria-label="Mail pagination" style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 14 }}>
            <button type="button" className="btn btn-ghost" disabled={!meta.previous_cursor} onClick={() => meta.previous_cursor && navigate(meta.previous_cursor)}>
                Previous
            </button>
            <button type="button" className="btn btn-ghost" disabled={!meta.next_cursor} onClick={() => meta.next_cursor && navigate(meta.next_cursor)}>
                Next
            </button>
        </nav>
    );
}
