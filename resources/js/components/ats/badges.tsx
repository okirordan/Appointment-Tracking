interface BadgeProps {
    label: string;
    badgeClass: string;
}

export function StatusBadge({ label, badgeClass }: BadgeProps) {
    return <span className={`badge ${badgeClass}`}>{label}</span>;
}

export function PriorityBadge({ label, badgeClass }: BadgeProps) {
    return <span className={`badge ${badgeClass}`}>{label}</span>;
}

export function OverdueTag({ children }: { children: React.ReactNode }) {
    return <span className="overdue-tag">{children}</span>;
}
