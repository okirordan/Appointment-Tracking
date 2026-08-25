import { X } from '@/components/icons';
import { cn } from '@/lib/utils';
import { useEffect, useRef, type ReactNode } from 'react';

interface SlideoverProps {
    header: ReactNode;
    onClose: () => void;
    children: ReactNode;
    size?: 'default' | 'wide';
    className?: string;
    dialogLabel?: string;
}

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export default function Slideover({ header, onClose, children, size = 'default', className, dialogLabel = 'Details' }: SlideoverProps) {
    const panelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const focusFirstControl = window.requestAnimationFrame(() => {
            const focusable = panelRef.current?.querySelectorAll<HTMLElement>(focusableSelector);
            (focusable?.[0] ?? panelRef.current)?.focus({ preventScroll: true });
        });

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
                return;
            }

            if (event.key !== 'Tab' || !panelRef.current) return;

            const focusable = Array.from(panelRef.current.querySelectorAll<HTMLElement>(focusableSelector)).filter(
                (element) =>
                    !element.hasAttribute('disabled') && element.getAttribute('aria-hidden') !== 'true' && element.getClientRects().length > 0,
            );

            if (focusable.length === 0) {
                event.preventDefault();
                panelRef.current.focus();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => {
            window.cancelAnimationFrame(focusFirstControl);
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
            returnFocusTo?.focus({ preventScroll: true });
        };
    }, [onClose]);

    return (
        <div
            className={cn('slideover-backdrop', className?.includes('correspondence-drawer') && 'correspondence-drawer-backdrop')}
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <div
                ref={panelRef}
                className={cn('slideover', size === 'wide' && 'slideover-wide', className)}
                role="dialog"
                aria-modal="true"
                aria-hidden="false"
                aria-label={dialogLabel}
                tabIndex={-1}
            >
                <div className="so-hd">
                    <div className="grow">{header}</div>
                    <button type="button" className="close-btn" onClick={onClose} aria-label="Close">
                        <X aria-hidden="true" />
                    </button>
                </div>
                <div className="so-body">{children}</div>
            </div>
        </div>
    );
}
