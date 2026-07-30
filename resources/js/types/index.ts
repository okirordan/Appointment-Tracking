export interface AuthUser {
    id: number;
    username: string;
    full_name: string;
    first_name: string;
    initials: string;
    title: string | null;
    role: string;
    role_label: string;
    permissions: string[];
    department: {
        id: number;
        name: string;
        code: string;
    } | null;
    office_attachment: {
        id: number;
        official_job_title: string;
        supervisor_name: string | null;
        supervisor_title: string | null;
        office_name: string | null;
        delegated_permissions: string[];
    } | null;
    force_password_change: boolean;
    two_factor_enabled: boolean;
}

export interface NavItemData {
    key: string;
    label: string;
    icon: string;
    tone: 'blue' | 'cyan' | 'purple' | 'orange' | 'pink' | 'green' | 'amber' | 'slate';
    href: string;
    active: boolean;
}

export interface NotificationItem {
    id: number;
    message: string;
    is_read: boolean;
    time_label: string;
    task_id: number | null;
}

export interface SharedData {
    name: string;
    auth: {
        user: AuthUser | null;
    };
    nav: NavItemData[];
    notifications: {
        unread_count: number;
        items: NotificationItem[];
    } | null;
    flash: {
        success: string | null;
        error: string | null;
        status: string | null;
        temp_credential: TempCredential | null;
        nonce: string | null;
    };
    [key: string]: unknown;
}

export interface TempCredential {
    name: string;
    username: string;
    password: string;
    context: 'created' | 'reset';
}

export interface TaskRow {
    id: number;
    reference: string;
    title: string;
    assigned_to_name: string;
    department_name: string;
    priority: string;
    priority_class: string;
    status: string;
    status_class: string;
    progress: number;
    progress_class: string;
    due_label: string;
    overdue: boolean;
    days_overdue_label: string;
    updated_at: string | null;
}

export interface TaskHistoryItem {
    id: number;
    action_type: string;
    status: string | null;
    note: string | null;
    by: string;
    when_label: string;
}

export interface TaskAnnotation {
    id: number;
    author: string;
    author_role: string | null;
    text: string | null;
    when_label: string;
}

export interface TaskEvidence {
    id: number;
    filename: string;
    source_type: 'file' | 'link';
    mime_type: string;
    size_label: string;
    external_url: string | null;
    preview_kind: 'pdf' | 'image' | 'video' | 'document' | 'link' | 'none';
    preview_url: string | null;
    uploaded_by: string;
    when_label: string;
    download_url: string;
}

export interface TaskDetail extends TaskRow {
    description: string;
    status_value: string;
    assigned_by_name: string;
    assigned_to_user_id: number | null;
    active_assignees: Array<{
        user_id: number;
        name: string;
        title: string | null;
        assigned_at: string;
    }>;
    ownership: {
        creator: string;
        owner: string;
        current_assignee: string;
        responsible_officer: string;
        current_reviewer: string | null;
        final_approver: string | null;
    };
    execution_status: string;
    review_status: string;
    approval_status: string;
    workflow_route: Array<{
        id: number;
        sequence: number;
        sender_name: string;
        recipient_name: string;
        recipient_inactive: boolean;
        position_name: string | null;
        role_name: string | null;
        status: string;
        status_value: string;
        instructions: string | null;
        assigned_at: string;
        due_at: string;
        submitted_at: string;
        reviewed_at: string;
        review_decision: string | null;
        reviewer_comments: string | null;
        is_skipped: boolean;
        is_current: boolean;
        is_direct: boolean;
    }>;
    pending_submission: {
        id: number;
        note: string;
        submitted_by: string | null;
        submitted_by_title: string | null;
        submitted_at: string;
    } | null;
    review_history: Array<{ id: number; decision: string; comments: string; reviewer: string; reviewer_title: string | null; when_label: string }>;
    initial_instruction: string | null;
    division_name: string | null;
    workstream_name: string | null;
    mail_origin: {
        register_number: string;
        sender_name: string;
        recipient_name: string;
        correspondence_reference: string | null;
        received_date_label: string;
        details: string | null;
        attachment_count: number;
        /** Present only when the viewer is authorised to open the original correspondence. */
        mail_url: string | null;
    } | null;
    history: TaskHistoryItem[];
    annotations: TaskAnnotation[];
    evidence: TaskEvidence[];
    can_update_progress: boolean;
    can_annotate: boolean;
    can_delegate: boolean;
    can_submit: boolean;
    can_review: boolean;
    can_reassign: boolean;
    can_unassign: boolean;
    can_direct: boolean;
}

export interface SelectOption {
    value: string;
    label: string;
}

export interface PaginatedData<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
