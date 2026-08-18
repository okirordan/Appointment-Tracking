import { SearchLoader } from '@/components/ats/search-loader';
import { AlertTriangle, ArrowUpRight, FileText, RefreshCw } from '@/components/icons';
import { useEffect, useRef, useState } from 'react';

export interface MailDuplicateInput {
    subject: string;
    sender_name: string;
    recipient_name: string;
    correspondence_reference: string;
    mail_date: string;
}

interface DuplicateMail {
    id: number;
    subject: string;
    register_number: string;
    reference_number: string | null;
    direction: 'incoming' | 'outgoing';
    sender: string;
    recipient: string;
    mail_date: string | null;
    recorded_at: string | null;
    status: string;
    recorded_by: string | null;
    similarity: number;
    match_strength: number;
    matching_fields: string[];
    url: string;
}

export default function MailDuplicateSuggestions({ input }: { input: MailDuplicateInput }) {
    const debounce = useRef<ReturnType<typeof setTimeout>>(null);
    const request = useRef<AbortController | null>(null);
    const [items, setItems] = useState<DuplicateMail[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (debounce.current !== null) clearTimeout(debounce.current);
        request.current?.abort();
        const subject = input.subject.trim();
        if (subject.length < 3) {
            setItems([]);
            setLoading(false);
            setFailed(false);
            return;
        }

        setLoading(true);
        setFailed(false);
        debounce.current = setTimeout(async () => {
            const controller = new AbortController();
            request.current = controller;
            const params = new URLSearchParams(Object.entries(input).filter(([, value]) => value.trim() !== ''));
            try {
                const response = await fetch(`${route('mail.duplicate-search')}?${params.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Duplicate search failed');
                const payload = (await response.json()) as { duplicates: DuplicateMail[] };
                setItems(payload.duplicates);
            } catch (searchError) {
                if (searchError instanceof DOMException && searchError.name === 'AbortError') return;
                setItems([]);
                setFailed(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 320);

        return () => {
            if (debounce.current !== null) clearTimeout(debounce.current);
            request.current?.abort();
        };
    }, [input.subject, input.sender_name, input.recipient_name, input.correspondence_reference, input.mail_date]);

    if (input.subject.trim().length < 3) return null;
    if (loading && items.length === 0) {
        return (
            <div className="mail-duplicate-search-state">
                <SearchLoader compact label="Checking existing correspondence…" />
            </div>
        );
    }
    if (failed) {
        return (
            <div className="mail-duplicate-search-state is-error">
                <RefreshCw aria-hidden="true" />
                Duplicate checking is temporarily unavailable. You can still save; the server will perform a final integrity check.
            </div>
        );
    }
    if (items.length === 0) {
        return (
            <div className="mail-duplicate-clear">
                <FileText aria-hidden="true" />
                No similar authorised records found.
            </div>
        );
    }

    const strongest = Math.max(...items.map((item) => item.match_strength));
    return (
        <section className={`mail-duplicate-results strength-${strongest}`} aria-live="polite">
            <div className="mail-duplicate-heading">
                <AlertTriangle aria-hidden="true" />
                <div>
                    <strong>{strongest >= 3 ? 'Strong possible duplicate detected' : 'Possible duplicate mail detected'}</strong>
                    <span>Please review existing records before creating another record.</span>
                </div>
                {loading && <SearchLoader compact label="Refreshing…" />}
            </div>
            <div className="mail-duplicate-list">
                {items.map((item) => (
                    <a key={item.id} href={item.url} target="_blank" rel="noreferrer" className="mail-duplicate-card">
                        <div className="mail-duplicate-card-top">
                            <span className={`mail-direction-chip ${item.direction}`}>{item.direction}</span>
                            <strong>{item.subject}</strong>
                            <ArrowUpRight aria-hidden="true" />
                        </div>
                        <div className="mail-duplicate-meta">
                            <span>
                                <b>Register</b>
                                {item.register_number}
                            </span>
                            <span>
                                <b>Reference</b>
                                {item.reference_number || '—'}
                            </span>
                            <span>
                                <b>From</b>
                                {item.sender}
                            </span>
                            <span>
                                <b>To</b>
                                {item.recipient}
                            </span>
                            <span>
                                <b>Mail date</b>
                                {item.mail_date || '—'}
                            </span>
                            <span>
                                <b>Recorded</b>
                                {item.recorded_at || '—'}
                            </span>
                            <span>
                                <b>Status</b>
                                {item.status}
                            </span>
                            <span>
                                <b>Officer</b>
                                {item.recorded_by || 'System import'}
                            </span>
                        </div>
                        <div className="mail-duplicate-match">
                            {item.matching_fields.length > 0
                                ? `Matching: ${item.matching_fields.join(', ')}`
                                : `${item.similarity}% subject similarity`}
                        </div>
                    </a>
                ))}
            </div>
        </section>
    );
}
