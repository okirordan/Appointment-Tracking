import { X } from '@/components/icons';
import { cn } from '@/lib/utils';
import { useEffect, type ReactNode } from 'react';

interface SlideoverProps {
    header: ReactNode;
    onClose: () => void;
    children: ReactNode;
    size?: 'default' | 'wide';
}

export default function Slideover({ header, onClose, children, size = 'default' }: SlideoverProps) {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    return (
        <div className="slideover-backdrop" onClick={onClose}>
            <div
                className={cn('slideover', size === 'wide' && 'slideover-wide')}
                role="dialog"
                aria-modal="true"
                onClick={(event) => event.stopPropagation()}
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
