import type { ReactNode } from 'react';

interface StatCardProps {
    label: string;
    value: ReactNode;
    warn?: boolean;
}

export function StatCard({ label, value, warn = false }: StatCardProps) {
    return (
        <div className="stat-card">
            <div className="stat-label">{label}</div>
            <div className={`stat-value${warn ? ' warn' : ''}`}>{value}</div>
        </div>
    );
}

export function StatGrid({ children }: { children: ReactNode }) {
    return <div className="stat-grid">{children}</div>;
}
