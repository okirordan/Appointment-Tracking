import AnnotationTitlePicker, { type AnnotationTitleOption } from '@/components/ats/annotation-title-picker';

interface Props {
    origin: AnnotationTitleOption | null;
    recipient: AnnotationTitleOption | null;
    onOriginSelect: (title: AnnotationTitleOption | null) => void;
    onRecipientSelect: (title: AnnotationTitleOption | null) => void;
    originError?: string;
    recipientError?: string;
    className?: string;
}

export default function AnnotationTitleRoutingFields({
    origin,
    recipient,
    onOriginSelect,
    onRecipientSelect,
    originError,
    recipientError,
    className = '',
}: Props) {
    return (
        <section className={`annotation-routing-panel ${className}`.trim()} aria-label="Department and officer routing">
            <div className="annotation-routing-heading">
                <strong>Department/Officer</strong>
                <span>Optional officer-title routing.</span>
            </div>
            <div className="annotation-title-grid">
                <AnnotationTitlePicker
                    label="From — Officer Title"
                    selected={origin}
                    onSelect={onOriginSelect}
                    placeholder="Search originating officer title…"
                    hint="Officer title issuing the instruction."
                    error={originError}
                />
                <AnnotationTitlePicker
                    label="To — Officer Title"
                    selected={recipient}
                    onSelect={onRecipientSelect}
                    placeholder="Search receiving officer title…"
                    hint="Officer title receiving the instruction."
                    error={recipientError}
                />
            </div>
        </section>
    );
}
