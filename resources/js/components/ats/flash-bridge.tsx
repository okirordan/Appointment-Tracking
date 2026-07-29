import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { pushToast } from '@/lib/toast';
import type { SharedData } from '@/types';

// The last flash delivery handled, tracked at module scope so it survives
// page/AppShell remounts on Inertia navigations.
let lastNonce = '';

/**
 * Bridges server flash messages onto the toast bus. Keyed on the server's
 * per-delivery nonce so each flash fires exactly once — regardless of render
 * timing or whether two consecutive messages happen to be identical. The
 * ToastStack that renders the toast is mounted at the root, so feedback
 * persists even as pages swap.
 */
export default function FlashBridge() {
    const { flash } = usePage<SharedData>().props;

    useEffect(() => {
        if (!flash.nonce || flash.nonce === lastNonce) {
            return;
        }
        lastNonce = flash.nonce;
        if (flash.success) {
            pushToast('success', flash.success);
        }
        if (flash.error) {
            pushToast('error', flash.error);
        }
    }, [flash.nonce, flash.success, flash.error]);

    return null;
}
