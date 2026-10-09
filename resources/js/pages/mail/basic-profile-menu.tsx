import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function BasicProfileMenu() {
    const user = usePage<SharedData>().props.auth.user;
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const closeOutside = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        const closeEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', closeOutside);
        document.addEventListener('keydown', closeEscape);
        return () => {
            document.removeEventListener('mousedown', closeOutside);
            document.removeEventListener('keydown', closeEscape);
        };
    }, []);

    if (!user) return null;

    return (
        <div className="basic-profile" ref={root}>
            <button
                type="button"
                className="basic-profile-trigger"
                aria-label={`Open profile menu for ${user.full_name}`}
                aria-expanded={open}
                aria-controls="basic-profile-menu"
                onClick={() => setOpen((value) => !value)}
            >
                <span aria-hidden="true">{user.initials}</span>
            </button>
            {open && (
                <div className="basic-profile-dropdown" id="basic-profile-menu">
                    <div className="basic-profile-identity">
                        <strong>{user.full_name}</strong>
                        <span>{user.title ?? user.role_label}</span>
                    </div>
                    <Link href={route('password.change')} onClick={() => setOpen(false)}>
                        Change password
                    </Link>
                    <Link href={route('security.show')} onClick={() => setOpen(false)}>
                        Security &amp; two-factor
                    </Link>
                    <button type="button" onClick={() => router.post(route('logout'))}>
                        Sign out
                    </button>
                </div>
            )}
        </div>
    );
}
