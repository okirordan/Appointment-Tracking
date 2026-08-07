interface Props {
    errors: Record<string, string | undefined>;
}

/**
 * Clear summary section for validation errors, complementing the
 * field-level messages (PRD §23). Renders nothing when the form is valid.
 */
export default function FormErrorSummary({ errors }: Props) {
    const messages = Object.values(errors).filter((message): message is string => Boolean(message));

    if (messages.length === 0) {
        return null;
    }

    return (
        <div role="alert" className="annotation" style={{ borderLeftColor: 'var(--err)', background: 'var(--err-soft)' }}>
            <div style={{ fontWeight: 600, fontSize: 12, color: 'var(--err)' }}>Please correct the following:</div>
            <ul style={{ margin: '6px 0 0', paddingLeft: 18, fontSize: 13 }}>
                {messages.map((message, index) => (
                    <li key={index}>{message}</li>
                ))}
            </ul>
        </div>
    );
}
