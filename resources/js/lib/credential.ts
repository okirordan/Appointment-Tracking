import type { TempCredential } from '@/types';

const EVENT = 'ats:credential';

/**
 * One-time temporary-password delivery. Driven off each action's success
 * callback (which carries the fresh response page) rather than the shared
 * flash prop, so the copyable dialog is never missed due to SPA prop
 * staleness.
 */
export function pushCredential(credential: TempCredential): void {
    window.dispatchEvent(new CustomEvent<TempCredential>(EVENT, { detail: credential }));
}

export function onCredential(handler: (credential: TempCredential) => void): () => void {
    const listener = (event: Event) => handler((event as CustomEvent<TempCredential>).detail);
    window.addEventListener(EVENT, listener);
    return () => window.removeEventListener(EVENT, listener);
}
