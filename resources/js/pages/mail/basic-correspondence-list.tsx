import type { MailDetail } from './index';

export default function BasicCorrespondenceList({ entries }: { entries: MailDetail['basic_correspondences'] }) {
    return entries.length ? (
        entries.map((entry) => (
            <div className="item basic-correspondence" key={entry.id}>
                <strong>{entry.text}</strong>
                <span className="item-meta">From Office: {entry.from_office || 'Not recorded'}</span>
                <span className="item-meta">To Office: {entry.destination_office || 'Not recorded'}</span>
                <time className="mute">Date: {entry.logged_date}</time>
            </div>
        ))
    ) : (
        <div className="empty">
            <strong>No correspondences yet</strong>
            <p>Add a correspondence to record where this mail was sent or filed.</p>
        </div>
    );
}
