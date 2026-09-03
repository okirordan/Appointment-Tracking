import { X } from '@/components/icons';
import { cn } from '@/lib/utils';
import * as Dialog from '@radix-ui/react-dialog';
import { useState, type ReactNode } from 'react';

interface ModalProps {
    title: string;
    description?: ReactNode;
    onClose: () => void;
    children?: ReactNode;
    footer?: ReactNode;
    className?: string;
    // When false, backdrop click and Escape do not close the dialog — used
    // for content that must be dismissed deliberately (e.g. a one-time
    // password the admin needs to copy first). Defaults to true.
    dismissible?: boolean;
    size?: 'default' | 'wide';
    role?: 'dialog' | 'alertdialog';
    hideCloseButton?: boolean;
}

export default function Modal({
    title,
    description,
    onClose,
    children,
    footer,
    className,
    dismissible = true,
    size = 'default',
    role = 'dialog',
    hideCloseButton = false,
}: ModalProps) {
    // Capture before portal children mount: an autoFocus field can take focus
    // before Radix's open-focus callback runs. Callers do not use Dialog.Trigger.
    const [returnFocusTo] = useState(() =>
        typeof document !== 'undefined' && document.activeElement instanceof HTMLElement ? document.activeElement : null,
    );

    return (
        <Dialog.Root open onOpenChange={(open) => !open && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="modal-backdrop">
                    <Dialog.Content
                        className={cn('modal', size === 'wide' && 'modal-wide', className)}
                        role={role}
                        aria-modal="true"
                        {...(description ? {} : { 'aria-describedby': undefined })}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            if (returnFocusTo?.isConnected) returnFocusTo.focus({ preventScroll: true });
                        }}
                        onEscapeKeyDown={(event) => {
                            if (!dismissible) event.preventDefault();
                        }}
                        onPointerDownOutside={(event) => {
                            if (!dismissible) event.preventDefault();
                        }}
                    >
                        <div className="modal-hd">
                            <div className="modal-title-copy">
                                <Dialog.Title>{title}</Dialog.Title>
                                {description && (
                                    <Dialog.Description asChild>
                                        <div className="modal-description">{description}</div>
                                    </Dialog.Description>
                                )}
                            </div>
                            {!hideCloseButton && (
                                <button type="button" className="close-btn" onClick={onClose} aria-label="Close">
                                    <X aria-hidden="true" />
                                </button>
                            )}
                        </div>
                        {children != null && <div className="modal-body">{children}</div>}
                        {footer !== undefined && <div className="modal-foot">{footer}</div>}
                    </Dialog.Content>
                </Dialog.Overlay>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
