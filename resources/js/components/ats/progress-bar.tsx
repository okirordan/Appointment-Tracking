interface ProgressBarProps {
    percent: number;
    variant?: string; // '' | 'done' | 'late'
}

export default function ProgressBar({ percent, variant = '' }: ProgressBarProps) {
    return (
        <div className="progress-track">
            <div className={`progress-fill${variant ? ` ${variant}` : ''}`} style={{ width: `${Math.max(0, Math.min(100, percent))}%` }} />
        </div>
    );
}
