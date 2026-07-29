export type ToastType = 'success' | 'error' | 'info';

export interface ToastPayload {
    type: ToastType;
    message: string;
}

const EVENT = 'ats:toast';

/**
 * Imperatively raise an in-app toast from anywhere (action handlers,
 * global request-error handlers). ToastStack listens for these and
 * renders them alongside server flash toasts.
 */
export function pushToast(type: ToastType, message: string): void {
    window.dispatchEvent(new CustomEvent<ToastPayload>(EVENT, { detail: { type, message } }));
}

export function onToast(handler: (payload: ToastPayload) => void): () => void {
    const listener = (event: Event) => handler((event as CustomEvent<ToastPayload>).detail);
    window.addEventListener(EVENT, listener);
    return () => window.removeEventListener(EVENT, listener);
}
