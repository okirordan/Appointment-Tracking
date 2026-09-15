import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';

export default function ImpersonationBanner() {
    const { impersonation } = usePage<SharedData>().props;
    if (!impersonation) return null;
    return (
        <aside className="impersonation-banner" role="status">
            <span>
                You are currently logged in as <strong>{impersonation.user_name}</strong>.
            </span>
            <button type="button" className="btn btn-ghost" onClick={() => router.post(route('impersonation.stop'))}>
                Return to Super Admin
            </button>
        </aside>
    );
}
