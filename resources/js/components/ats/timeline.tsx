import type { ReactNode } from 'react';

interface TimelineItemProps {
    text: ReactNode;
    meta: ReactNode;
}

export function TimelineItem({ text, meta }: TimelineItemProps) {
    return (
        <div className="tl-item">
            <div className="tl-dot" />
            <div>
                <div className="tl-txt">{text}</div>
                <div className="tl-meta">{meta}</div>
            </div>
        </div>
    );
}

export function Timeline({ children }: { children: ReactNode }) {
    return <div>{children}</div>;
}
