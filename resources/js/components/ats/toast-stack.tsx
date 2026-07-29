import { AlertCircle, CheckCircle2, Info } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { onToast, type ToastType } from '@/lib/toast';

interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

const ICONS: Record<ToastType, typeof AlertCircle> = {
    success: CheckCircle2,
    error: AlertCircle,
    info: Info,
};

/**
 * Bottom-right toast stack, fed entirely by the toast bus. Server flash
 * messages are routed onto the bus by a global Inertia success handler
 * (see app.tsx); action and network-error feedback push to it directly.
 * Auto-dismisses after ~4.2s like the prototype.
 */
export default function ToastStack() {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const counter = useRef(0);

    useEffect(() => {
        return onToast(({ type, message }) => {
            const id = ++counter.current;
            setToasts((current) => [...current, { id, type, message }]);
            setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 4200);
        });
    }, []);

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="toast-wrap" role="status" aria-live="polite">
            {toasts.map((toast) => {
                const Icon = ICONS[toast.type];
                return (
                    <div key={toast.id} className={`toast ${toast.type}`}>
                        <Icon aria-hidden="true" />
                        <div>{toast.message}</div>
                    </div>
                );
            })}
        </div>
    );
}
