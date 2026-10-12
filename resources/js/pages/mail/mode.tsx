import { Link, usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import { allowModeSwitch, modeTarget, rememberModeSwitch, useModePreferences } from './mode-preferences-api';
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
    const preferences = useModePreferences();
    if (!props.canSwitchMailMode) return null;
    return (
        <div className="mode-header-controls">
            <div className="mail-mode-switch" role="group" aria-label="Mail display mode">
                <span>Mail mode</span>
                {(['basic', 'full'] as const).map((mode) => (
                    <Link
                        key={mode}
                        href={modeTarget(url, mode)}
                        onClick={(event) => {
                            if (!allowModeSwitch()) event.preventDefault();
                            else if (props.mailMode !== mode) rememberModeSwitch(preferences?.user_id, mode);
                        }}
                        aria-current={props.mailMode === mode ? 'true' : undefined}
                    >
                        {mode === 'basic' ? 'Basic' : 'Full'}
                    </Link>
                ))}
            </div>
            {preferences && !preferences.basic_tried && preferences.tips_enabled && props.mailMode !== 'basic' && (
                <Link
                    className="mode-try-chip"
                    href={modeTarget(url, 'basic')}
                    onClick={(event) => {
                        if (!allowModeSwitch()) event.preventDefault();
                        else rememberModeSwitch(preferences.user_id, 'basic');
                    }}
                >
                    Try Basic mode
                </Link>
            )}
        </div>
    );
}
