import { X } from '@/components/icons';
import { cn } from '@/lib/utils';
import { useEffect, type ReactNode } from 'react';

interface ModalProps {
    title: string;
    description?: string;
    onClose: () => void;
    children: ReactNode;
    footer?: ReactNode;
    className?: string;
    // When false, backdrop click and Escape do not close the dialog — used
    // for content that must be dismissed deliberately (e.g. a one-time
    // password the admin needs to copy first). Defaults to true.
    dismissible?: boolean;
    size?: 'default' | 'wide';
}

export default function Modal({ title, description, onClose, children, footer, className, dismissible = true, size = 'default' }: ModalProps) {
    // Keyboard accessibility: Escape closes dismissible dialogs.
    useEffect(() => {
        if (!dismissible) {
            return;
        }
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [onClose, dismissible]);

    return (
        <div className="modal-backdrop" onClick={dismissible ? onClose : undefined}>
            <div
                className={cn('modal', size === 'wide' && 'modal-wide', className)}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(event) => event.stopPropagation()}
            >
                <div className="modal-hd">
                    <div className="modal-title-copy">
                        <h2>{title}</h2>
                        {description && <p>{description}</p>}
                    </div>
                    <button type="button" className="close-btn" onClick={onClose} aria-label="Close">
                        <X aria-hidden="true" />
                    </button>
                </div>
                <div className="modal-body">{children}</div>
                {footer !== undefined && <div className="modal-foot">{footer}</div>}
            </div>
        </div>
    );
}
