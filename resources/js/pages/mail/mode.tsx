import { Link, usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import './mode.css';
import { mailContextUrl, type MailMode } from './navigation';

export function useMailUrl() {
    const { url, props } = usePage();
    const mode = (props.mailMode ?? 'full') as MailMode;
    return useCallback(
        (target: string, changes: Record<string, string | number | null> = {}) => mailContextUrl(target, url, mode, changes),
        [url, mode],
    );
}

export default function MailModeSwitch() {
    const { url, props } = usePage();
    if (!props.canSwitchMailMode) return null;
    const current = new URL(url, 'http://ats.local');
    const inMail = /^\/(incoming-mail|outgoing-mail|filed-mail|mail)(\/|$)/.test(current.pathname) || current.pathname === '/home';
    const target = inMail ? url : '/home?type=mail';
    return (
        <div className="mail-mode-switch" role="group" aria-label="Mail display mode">
            <span>Mail mode</span>
            {(['basic', 'full'] as const).map((mode) => (
                <Link
                    key={mode}
                    href={mailContextUrl(target, inMail ? url : target, mode, { view: null })}
                    aria-current={props.mailMode === mode ? 'true' : undefined}
                >
                    {mode === 'basic' ? 'Basic' : 'Full'}
                </Link>
            ))}
        </div>
    );
}
