import type { CSSProperties, ReactNode } from 'react';

interface EmptyStateProps {
    children: ReactNode;
    style?: CSSProperties;
}

export default function EmptyState({ children, style }: EmptyStateProps) {
    return (
        <div className="empty" style={style}>
            {children}
        </div>
    );
}
