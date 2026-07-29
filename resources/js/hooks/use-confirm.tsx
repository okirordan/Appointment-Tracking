import { AlertCircle } from 'lucide-react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import ToastStack from '@/components/ats/toast-stack';

export interface ConfirmOptions {
    title: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'default' | 'danger';
}

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * App-wide confirmation dialog. `const confirm = useConfirm()` then
 * `if (await confirm({ ... }))` — replaces window.confirm() with a single
 * styled, keyboard-accessible modal used consistently across every page.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [pending, setPending] = useState<ConfirmOptions | null>(null);
    const confirmButtonRef = useRef<HTMLButtonElement>(null);
    const lastFocused = useRef<HTMLElement | null>(null);
    // The resolver lives in a ref so it is settled outside React's state
    // updater — resolving inside setState is impure and drops promises
    // under StrictMode's double-invocation.
    const resolver = useRef<((result: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((options) => {
        lastFocused.current = document.activeElement as HTMLElement | null;
        return new Promise<boolean>((resolve) => {
            resolver.current = resolve;
            setPending(options);
        });
    }, []);

    const close = useCallback((result: boolean) => {
        setPending(null);
        resolver.current?.(result);
        resolver.current = null;
        // Restore focus to whatever triggered the dialog.
        lastFocused.current?.focus();
    }, []);

    useEffect(() => {
        if (pending === null) {
            return;
        }
        confirmButtonRef.current?.focus();

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                close(true);
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [pending, close]);

    const danger = pending?.variant === 'danger';

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            {/* Always-mounted so every toast-bus event is captured, on any page. */}
            <ToastStack />
            {pending !== null && (
                <div className="modal-backdrop" onClick={() => close(false)}>
                    <div
                        className="modal"
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="confirm-title"
                        aria-describedby="confirm-body"
                        style={{ width: 'min(440px, 100%)' }}
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="modal-body" style={{ gap: 12, paddingTop: 24 }}>
                            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                                <div
                                    style={{
                                        width: 38,
                                        height: 38,
                                        borderRadius: '50%',
                                        flex: 'none',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: danger ? '#fee2e2' : 'var(--pri50)',
                                        color: danger ? 'var(--err)' : 'var(--pri)',
                                    }}
                                >
                                    <AlertCircle aria-hidden="true" style={{ width: 20, height: 20 }} />
                                </div>
                                <div>
                                    <h2 id="confirm-title" style={{ fontSize: 17 }}>
                                        {pending.title}
                                    </h2>
                                    <div id="confirm-body" style={{ fontSize: 13, color: 'var(--body)', lineHeight: 1.55, marginTop: 6 }}>
                                        {pending.message}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="modal-foot">
                            <button type="button" className="btn btn-ghost" onClick={() => close(false)}>
                                {pending.cancelLabel ?? 'Cancel'}
                            </button>
                            <button
                                ref={confirmButtonRef}
                                type="button"
                                className="btn btn-primary"
                                style={danger ? { background: 'var(--err)' } : undefined}
                                onClick={() => close(true)}
                            >
                                {pending.confirmLabel ?? 'Confirm'}
                            </button>
                        </div>
                    </div>
                </div>
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
