import {
    AlertCircle,
    BarChart3,
    Bell,
    Building2,
    Check,
    CheckCircle2,
    ChevronDown,
    ClipboardList,
    Clock,
    Download,
    Home,
    Landmark,
    LayoutDashboard,
    LogIn,
    LogOut,
    LucideIcon as LucideIconType,
    Mail,
    Menu,
    MessageSquarePlus,
    Network,
    Paperclip,
    Plus,
    Search,
    Settings,
    ShieldCheck,
    Tags,
    UserCircle,
    UserPlus,
    Users,
    UsersRound,
    X,
} from 'lucide-react';

/**
 * Resolves the prototype's lucide icon names (served with nav data) to
 * lucide-react components so the UI uses the exact same glyphs.
 */
const icons: Record<string, LucideIconType> = {
    'alert-circle': AlertCircle,
    'bar-chart-3': BarChart3,
    bell: Bell,
    'building-2': Building2,
    check: Check,
    'check-circle-2': CheckCircle2,
    'chevron-down': ChevronDown,
    'clipboard-list': ClipboardList,
    clock: Clock,
    download: Download,
    home: Home,
    landmark: Landmark,
    'layout-dashboard': LayoutDashboard,
    'log-in': LogIn,
    'log-out': LogOut,
    menu: Menu,
    mail: Mail,
    'message-square-plus': MessageSquarePlus,
    network: Network,
    paperclip: Paperclip,
    plus: Plus,
    search: Search,
    settings: Settings,
    'shield-check': ShieldCheck,
    tags: Tags,
    'user-circle': UserCircle,
    'user-plus': UserPlus,
    users: Users,
    'users-round': UsersRound,
    x: X,
};

interface LucideIconProps {
    name: string;
    className?: string;
}

export default function LucideIcon({ name, className }: LucideIconProps) {
    const Icon = icons[name];

    if (!Icon) {
        return null;
    }

    return <Icon className={className} aria-hidden="true" />;
}
