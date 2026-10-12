import Modal from '@/components/ats/modal';
import { useState } from 'react';
import type { ModePreferences } from './mode-preferences-api';
import type { MailMode } from './navigation';

const benefits = {
    basic: [
        'Simple, task-focused interface',
        'Quick access to common mail and correspondence activities',
        'Less clutter and easier navigation',
        'Ideal for everyday work',
    ],
    full: [
        'Complete navigation sidebar',
        'Dashboard statistics and data tables',
        'Access to advanced system functions',
        'Ideal for detailed management and administration',
    ],
};

function ModePreview({ mode }: { mode: MailMode }) {
    return (
        <div
            className={`mode-preview mode-preview-${mode}`}
            role="img"
            aria-label={`Preview of the existing ${mode === 'basic' ? 'Basic' : 'Full'} Mode interface`}
        >
            {mode === 'full' && (
                <div className="mode-preview-sidebar">
                    <b>MoES</b>
                    <span>Home</span>
                    <span>Incoming Mail</span>
                    <span>Outgoing Mail</span>
                    <span>Assignments</span>
                </div>
            )}
            <div className="mode-preview-content">
                <div className="mode-preview-header">
                    MoES <span>Mail Management System</span>
                </div>
                {mode === 'basic' ? (
                    <>
                        <img src="/images/moes-crest.jpg" alt="" />
                        <b>Ministry of Education &amp; Sports</b>
                        <div className="mode-preview-search">Search mail and correspondence</div>
                        <div className="mode-preview-tiles">
                            <span>Incoming mail</span>
                            <span>Outgoing mail</span>
                            <span>Log incoming mail</span>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="mode-preview-tiles">
                            <span>Incoming</span>
                            <span>Outgoing</span>
                            <span>Active</span>
                        </div>
                        <div className="mode-preview-table">
                            <b>Subject</b>
                            <b>From</b>
                            <b>To</b>
                            {Array.from({ length: 9 }, (_, i) => (
                                <i key={i} />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

export default function ModeSelectionDialog({
    preferences,
    busy,
    error,
    onChoose,
    onLater,
}: {
    preferences: ModePreferences;
    busy: boolean;
    error: string;
    onChoose: (mode: MailMode, saveDefault: boolean) => void;
    onLater: () => void;
}) {
    const welcome = preferences.prompt === 'welcome';
    const [selected, setSelected] = useState<MailMode>(preferences.active_mode);
    const [always, setAlways] = useState(true);
    return (
        <Modal
            title={welcome ? 'Introducing Basic Mode!' : 'Choose your mode'}
            description={
                welcome
                    ? "We've introduced a new Basic Mode to make your daily work easier, faster, and more straightforward. You can choose the experience that works best for you and switch between modes whenever you want."
                    : undefined
            }
            size={welcome ? 'wide' : 'default'}
            className="mode-selection-dialog"
            onClose={onLater}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={onLater}>
                        Decide Later
                    </button>
                    <button type="button" className="btn btn-primary" disabled={busy} onClick={() => onChoose(selected, welcome && always)}>
                        {busy ? 'Saving…' : 'Continue'}
                    </button>
                </>
            }
        >
            <fieldset className="mode-cards">
                <legend className="sr-only">Select a working mode</legend>
                {(['basic', 'full'] as const).map((mode) => (
                    <label key={mode} className={`mode-card${selected === mode ? 'is-selected' : ''}`}>
                        <span className="mode-card-heading">
                            <input type="radio" name="working-mode" value={mode} checked={selected === mode} onChange={() => setSelected(mode)} />
                            <strong>{mode === 'basic' ? 'Basic Mode' : 'Full Mode'}</strong>
                        </span>
                        {welcome && (
                            <>
                                <ModePreview mode={mode} />
                                <ul>
                                    {benefits[mode].map((benefit) => (
                                        <li key={benefit}>{benefit}</li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </label>
                ))}
            </fieldset>
            {welcome && (
                <label className="mode-checkbox">
                    <input type="checkbox" checked={always} onChange={(event) => setAlways(event.target.checked)} />
                    Always open in my selected mode
                </label>
            )}
            {error && <p role="alert">{error}</p>}
        </Modal>
    );
}
