import { LoaderDots } from '@/components/ats/page-loader';

interface SearchLoaderProps {
    compact?: boolean;
    label?: string;
}

export function SearchLoader({ compact = false, label = 'Searching…' }: SearchLoaderProps) {
    return (
        <span className={compact ? 'search-loader-wrap compact' : 'search-loader-wrap'} role="status">
            <LoaderDots compact={compact} />
            <span className="search-loader-label">{label}</span>
        </span>
    );
}
