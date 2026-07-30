import Modal from '@/components/ats/modal';
import PasswordInput from '@/components/ats/password-input';
import { onCredential } from '@/lib/credential';
import { pushToast } from '@/lib/toast';
import type { TempCredential } from '@/types';
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * One-time temporary password dialog. Unlike a toast, it stays open until
 * the administrator closes it deliberately (after setting/sharing the
 * password) and offers copy-to-clipboard. The password is shown once and is
 * never persisted anywhere retrievable (PWD-006). Fed by the credential bus,
 * which the reset/create actions raise from their success callbacks.
 */
export default function TempCredentialModal() {
    const [credential, setCredential] = useState<TempCredential | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(
        () =>
            onCredential((incoming) => {
                setCredential(incoming);
                setCopied(false);
            }),
        [],
    );

    if (credential === null) {
        return null;
    }

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(credential.password);
            setCopied(true);
            pushToast('success', 'Temporary password copied to clipboard.');
        } catch {
            pushToast('error', 'Could not copy automatically — select the password and copy it manually.');
        }
    };

    const heading = credential.context === 'created' ? 'Account created' : 'Password reset';

    return (
        <Modal
            title={heading}
            dismissible={false}
            onClose={() => setCredential(null)}
            footer={
                <>
                    <button type="button" className="btn btn-ghost" onClick={copy}>
                        {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                        {copied ? 'Copied' : 'Copy password'}
                    </button>
                    <button type="button" className="btn btn-primary" onClick={() => setCredential(null)}>
                        Done
                    </button>
                </>
            }
        >
            <p style={{ fontSize: 13, lineHeight: 1.55, margin: 0 }}>
                Share this <strong>one-time temporary password</strong> with <strong>{credential.name}</strong> securely. It is shown only once — copy
                it now before closing. They will be required to set a new password at their next sign-in.
            </p>
            <div className="field">
                <label>Username</label>
                <input type="text" readOnly value={credential.username} onFocus={(event) => event.target.select()} />
            </div>
            <div className="field">
                <label>Temporary password</label>
                <PasswordInput
                    readOnly
                    value={credential.password}
                    style={{ fontFamily: 'ui-monospace, Menlo, monospace', letterSpacing: '0.02em' }}
                    onFocus={(event) => event.target.select()}
                />
            </div>
        </Modal>
    );
}
