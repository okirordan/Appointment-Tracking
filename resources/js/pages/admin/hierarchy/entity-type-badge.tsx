interface Props {
    type: string;
    label: string;
}

export default function EntityTypeBadge({ type, label }: Props) {
    return (
        <span className={`organization-type-badge type-${type}`} data-entity-type={type}>
            {label}
        </span>
    );
}
