import { router } from '@inertiajs/react';

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
}

interface PaginationProps {
    meta: PaginationMeta;
    /** Override navigation (defaults to a ?page= visit on the current URL). */
    onNavigate?: (page: number) => void;
    /** Inertia partial-reload props to request when using default navigation. */
    only?: string[];
}

/**
 * Windowed page list: first and last page always visible, up to two pages
 * around the current one, ellipsis where pages are skipped.
 */
export function pageWindow(current: number, last: number): (number | '…')[] {
    const wanted = new Set<number>([1, last, current - 1, current, current + 1]);
    const pages: (number | '…')[] = [];
    let previous = 0;

    for (let page = 1; page <= last; page++) {
        if (!wanted.has(page)) {
            continue;
        }
        if (previous !== 0 && page - previous > 1) {
            pages.push('…');
        }
        pages.push(page);
        previous = page;
    }

    return pages;
}

/**
 * Server-side pagination controls (a PRD addition — the prototype loaded
 * everything client-side), styled with the existing ghost-button pattern.
 * Numbered pages make the range visible at a glance instead of hiding it
 * behind Previous/Next alone.
 */
export default function Pagination({ meta, onNavigate, only }: PaginationProps) {
    if (meta.last_page <= 1) {
        return null;
    }

    const routeQuery = () => Object.fromEntries(new URLSearchParams(window.location.search).entries());

    const go =
        onNavigate ??
        ((page: number) => {
            router.get(window.location.pathname, { ...routeQuery(), page }, { preserveState: true, preserveScroll: true, ...(only ? { only } : {}) });
        });

    return (
        <nav
            aria-label="Pagination"
            style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 10, marginTop: 14 }}
        >
            <span style={{ fontSize: 12, color: 'var(--label)' }}>
                Page {meta.current_page} of {meta.last_page} · {meta.total} records
            </span>
            <button
                type="button"
                className="btn btn-ghost"
                style={{ padding: '6px 14px', fontSize: 12 }}
                disabled={meta.current_page <= 1}
                onClick={() => go(meta.current_page - 1)}
            >
                Previous
            </button>
            {pageWindow(meta.current_page, meta.last_page).map((page, index) =>
                page === '…' ? (
                    <span key={`gap-${index}`} className="pagination-ellipsis" aria-hidden="true">
                        …
                    </span>
                ) : (
                    <button
                        key={page}
                        type="button"
                        className={page === meta.current_page ? 'pagination-page active' : 'pagination-page'}
                        aria-current={page === meta.current_page ? 'page' : undefined}
                        aria-label={`Page ${page}`}
                        disabled={page === meta.current_page}
                        onClick={() => go(page)}
                    >
                        {page}
                    </button>
                ),
            )}
            <button
                type="button"
                className="btn btn-ghost"
                style={{ padding: '6px 14px', fontSize: 12 }}
                disabled={meta.current_page >= meta.last_page}
                onClick={() => go(meta.current_page + 1)}
            >
                Next
            </button>
        </nav>
    );
}
