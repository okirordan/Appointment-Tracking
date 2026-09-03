export interface OrganizationEntity {
    id: number;
    name: string;
    code: string | null;
    type: string;
    type_label: string;
    parent_id: number | null;
    parent_name: string | null;
    description: string | null;
    head_user_id: number | null;
    head_name: string | null;
    secretary_user_id: number | null;
    secretary_name: string | null;
    active: boolean;
    is_top_level: boolean;
    sort_order: number;
    children_count: number;
    users_count: number;
}

export interface PersonOption {
    id: number;
    name: string;
    title: string | null;
}

export interface EntityTypeOption {
    value: string;
    label: string;
}

export interface OrganizationStructureProps {
    entities: OrganizationEntity[];
    entityTypes: EntityTypeOption[];
    headOptions: PersonOption[];
    secretaryOptions: PersonOption[];
    summary: {
        total: number;
        active: number;
        top_level: number;
        external: number;
    };
}
