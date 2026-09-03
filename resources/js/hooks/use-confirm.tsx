import Modal from '@/components/ats/modal';
import ToastStack from '@/components/ats/toast-stack';
import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';

export interface ConfirmOptions {
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'default' | 'danger';
}

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;
const ConfirmContext = createContext<ConfirmFn | null>(null);

/** Shared confirmation: native button activation, with Cancel focused first. */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [pending, setPending] = useState<ConfirmOptions | null>(null);
    const resolver = useRef<((result: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((options) => {
        return new Promise<boolean>((resolve) => {
            resolver.current = resolve;
            setPending(options);
        });
    }, []);

    const close = useCallback((result: boolean) => {
        setPending(null);
        resolver.current?.(result);
        resolver.current = null;
    }, []);

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <ToastStack />
            {pending !== null && (
                <Modal
                    title={pending.title}
                    description={pending.message}
                    role="alertdialog"
                    className="confirm-modal"
                    hideCloseButton
                    onClose={() => close(false)}
                    footer={
                        <>
                            <button type="button" className="btn btn-ghost" onClick={() => close(false)}>
                                {pending.cancelLabel ?? 'Cancel'}
                            </button>
                            <button
                                type="button"
                                className={`btn ${pending.variant === 'danger' ? 'btn-danger' : 'btn-primary'}`}
                                onClick={() => close(true)}
                            >
                                {pending.confirmLabel ?? 'Confirm'}
                            </button>
                        </>
                    }
                />
            )}
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): ConfirmFn {
    const confirm = useContext(ConfirmContext);
    if (confirm === null) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }
    return confirm;
}
