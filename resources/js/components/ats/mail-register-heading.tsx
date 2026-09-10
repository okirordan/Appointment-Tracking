interface MailRegisterHeadingProps {
    direction: 'incoming' | 'outgoing' | 'filed';
    officeName?: string;
}

const headingCopy = {
    incoming: {
        primary: 'Active Incoming',
        accessible: 'Active Incoming Correspondence',
        register: 'Incoming register',
    },
    outgoing: {
        primary: 'Outgoing and Forwarded',
        accessible: 'Outgoing and Forwarded Correspondence',
        register: 'Dispatch register',
    },
    filed: {
        primary: 'Filed',
        accessible: 'Filed Correspondence',
        register: 'Archive register',
    },
} as const;

export default function MailRegisterHeading({ direction, officeName }: MailRegisterHeadingProps) {
    const copy = headingCopy[direction];

    return (
        <div className="mail-register-heading-copy">
            <div className="mail-register-heading-meta">
                {officeName && <span className="mail-register-office-reference">{officeName}</span>}
                <span className="mail-register-heading-type">{copy.register}</span>
            </div>
            <h1 className="mail-register-title" aria-label={copy.accessible}>
                <span className="mail-register-title-primary">{copy.primary}</span>
                <span className="mail-register-title-secondary">Correspondence</span>
            </h1>
        </div>
    );
}
