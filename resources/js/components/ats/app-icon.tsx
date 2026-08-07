import {
    Activity,
    AlertCircle,
    Archive,
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
    type IconComponent,
    Landmark,
    LayoutDashboard,
    LogIn,
    LogOut,
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
} from '@/components/icons';

/**
 * Resolves the kebab-case icon names the backend ships with navigation data to
 * their Airaa Design components, so server-driven menus stay in sync with the
 * icon set used everywhere else.
 */
const icons: Record<string, IconComponent> = {
    activity: Activity,
    'alert-circle': AlertCircle,
    archive: Archive,
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
    mail: Mail,
    menu: Menu,
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

interface AppIconProps {
    name: string;
    className?: string;
}

export default function AppIcon({ name, className }: AppIconProps) {
    const Icon = icons[name];

    if (!Icon) {
        return null;
    }

    return <Icon className={className} aria-hidden="true" />;
}
