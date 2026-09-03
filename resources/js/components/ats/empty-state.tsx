import type { CSSProperties, ReactNode } from 'react';

interface EmptyStateProps {
    children: ReactNode;
    style?: CSSProperties;
}

export default function EmptyState({ children, style }: EmptyStateProps) {
    return (
        <div className="empty" role="status" style={style}>
            {children}
        </div>
    );
}
