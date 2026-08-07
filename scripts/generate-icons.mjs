/**
 * Generates resources/js/components/icons/* from the Airaa Design icon set
 * (outline variants). Run with: node scripts/generate-icons.mjs
 *
 * The generated component names deliberately match the lucide-react names the
 * app used previously, so page-level imports only need their module path
 * changed. Source SVGs are stroke-based on a 24px grid, so every glyph is
 * emitted as path data only and inherits stroke/size from the wrapper.
 */
import fs from 'node:fs';
import path from 'node:path';

const SRC = process.env.ICON_SRC ?? 'C:/Users/DELL/Desktop/WORK/icons/SVG';
const OUT = path.join(process.cwd(), 'resources/js/components/icons');

/** lucide component name -> "<category>/<file>" in the Airaa outline set. */
const MAP = {
    Activity: 'Business/activity',
    AlertCircle: 'Essentional/info-circle',
    AlertTriangle: 'Essentional/danger',
    Archive: 'Archive/archive',
    ArrowLeft: 'Arrow/arrow-left',
    ArrowRight: 'Arrow/arrow-right',
    ArrowUpRight: 'Arrow/export',
    BarChart3: 'Business/chart-2',
    Bell: 'Notifications/notification',
    BellRing: 'Notifications/notification-bing',
    BriefcaseBusiness: 'School, Learning/briefcase',
    Building2: 'Building/building-3',
    CalendarClock: 'Times/calendar-2',
    CalendarDays: 'Times/calendar',
    CheckCircle2: 'Essentional/tick-circle',
    ChevronDown: 'Arrow/arrow-down-2',
    ChevronLeft: 'Arrow/arrow-left-2',
    ChevronRight: 'Arrow/arrow-right-2',
    ChevronUp: 'Arrow/arrow-up-2',
    ClipboardCheck: 'Content, Edit/clipboard-tick',
    ClipboardList: 'Content, Edit/clipboard-text',
    Clock: 'Times/clock',
    Clock3: 'Times/clock',
    Copy: 'Content, Edit/document-copy',
    Database: 'Programing/data',
    Download: 'Content, Edit/document-download',
    Edit3: 'Content, Edit/edit-2',
    ExternalLink: 'Arrow/export',
    Eye: 'Security/eye',
    EyeOff: 'Security/eye-slash',
    FileCheck2: 'Content, Edit/clipboard-tick',
    FileEdit: 'Content, Edit/edit',
    FileSpreadsheet: 'Content, Edit/document-text-2',
    FileText: 'Content, Edit/document-text',
    FolderKanban: 'Grid/kanban',
    FolderOpen: 'Files/folder-open',
    FolderPlus: 'Files/folder-add',
    Forward: 'Arrow/forward-square',
    Hash: 'Business/hashtag',
    History: 'Arrow/history',
    Home: 'Essentional/home-2',
    Image: 'Video, Audio, Image/gallery',
    Inbox: 'Emails, Messages/direct-inbox',
    Info: 'Essentional/information',
    KeyRound: 'Security/key',
    Landmark: 'Building/bank',
    LayoutDashboard: 'Grid/element-3',
    Link2: 'Text, Paragraph, Character/link-2',
    LockKeyhole: 'Security/lock',
    LogIn: 'Arrow/login',
    LogOut: 'Arrow/logout',
    Mail: 'Emails, Messages/sms',
    MailCheck: 'Emails, Messages/sms-tracking',
    Menu: 'Essentional/menu',
    MessageSquarePlus: 'Emails, Messages/message-add',
    MessageSquareText: 'Emails, Messages/message-text',
    Monitor: 'Computers, Devices, Electronics/monitor',
    MonitorSmartphone: 'Computers, Devices, Electronics/monitor-mobbile',
    Moon: 'Weather/moon',
    Network: 'Programing/hierarchy-square-2',
    Paperclip: 'Text, Paragraph, Character/paperclip-2',
    Pencil: 'Content, Edit/edit-2',
    Plus: 'Essentional/add',
    Printer: 'Computers, Devices, Electronics/printer',
    RefreshCw: 'Arrow/refresh-2',
    RotateCcw: 'Arrow/rotate-left',
    Rows3: 'Grid/row-horizontal',
    Save: 'Archive/save-2',
    Search: 'Search/search',
    Send: 'Essentional/send-2',
    Server: 'Programing/data-2',
    Settings: 'Settings/setting-2',
    Settings2: 'Settings/setting-4',
    Share: 'Essentional/share',
    ShieldAlert: 'Security/shield-cross',
    ShieldCheck: 'Security/shield-tick',
    ShieldOff: 'Security/shield-slash',
    Sun: 'Weather/sun',
    Tags: 'Money/tag-2',
    Trash2: 'Essentional/trash',
    UploadCloud: 'Computers, Devices, Electronics/cloud-add',
    UserCheck: 'Users/user-tick',
    UserCircle: 'Users/profile-circle',
    UserMinus: 'Users/user-minus',
    UserPlus: 'Users/user-add',
    UserRound: 'Users/user',
    UserRoundCheck: 'Users/user-tick',
    Users: 'Users/profile-2user',
    UsersRound: 'Users/people',
    Video: 'Video, Audio, Image/video',
    Workflow: 'Programing/hierarchy-square-3',
};

/**
 * Glyphs the Airaa set has no direct equivalent for. Drawn on the same 24px
 * grid with the same 1.5 stroke so they sit correctly next to the rest.
 */
const HAND_DRAWN = {
    Check: ['<path d="M4.5 12.75 9.5 17.75 19.5 6.75" />'],
    X: ['<path d="M6 6l12 12" />', '<path d="M18 6 6 18" />'],
    Power: ['<path d="M12 3v9" />', '<path d="M17.66 6.34a8 8 0 1 1-11.32 0" />'],
    WifiOff: [
        '<path d="M2.99 9.14a15.14 15.14 0 0 1 6.02-3.19" />',
        '<path d="M13.2 5.79a15.13 15.13 0 0 1 7.81 3.35" />',
        '<path d="M6.34 12.4a10.6 10.6 0 0 1 2.86-1.66" />',
        '<path d="M14.5 10.62a10.6 10.6 0 0 1 3.16 1.78" />',
        '<path d="M9.75 15.63a5.53 5.53 0 0 1 4.79.35" />',
        '<path d="M12 19.5h.01" />',
        '<path d="M3 3l18 18" />',
    ],
    LoaderCircle: ['<path d="M12 3a9 9 0 1 0 9 9" />'],
};

const strip = (svg) => {
    const nodes = [];
    const re = /<(path|circle|rect|line|polyline|polygon|ellipse)\b([^>]*?)\/?>/g;
    let m;
    while ((m = re.exec(svg))) {
        const [, tag, attrsRaw] = m;
        const attrs = {};
        const ar = /([a-zA-Z-]+)="([^"]*)"/g;
        let a;
        while ((a = ar.exec(attrsRaw))) attrs[a[1]] = a[2];

        // Stroke, width, caps and joins come from the wrapper <svg>; only the
        // geometry and any solid-filled sub-shape need to survive.
        const filled = attrs.fill && attrs.fill !== 'none';
        delete attrs.stroke;
        delete attrs['stroke-width'];
        delete attrs['stroke-linecap'];
        delete attrs['stroke-linejoin'];
        delete attrs['stroke-miterlimit'];
        delete attrs.fill;

        const geometry = Object.entries(attrs)
            .map(([k, v]) => `${k}="${v}"`)
            .join(' ');
        nodes.push(`<${tag} ${geometry}${filled ? ' fill="currentColor" stroke="none"' : ''} />`);
    }
    return nodes;
};

// Clear previously generated glyphs but keep the hand-written wrapper.
fs.mkdirSync(OUT, { recursive: true });
for (const file of fs.readdirSync(OUT)) {
    if (file !== 'create-icon.tsx') fs.rmSync(path.join(OUT, file));
}

const kebab = (name) => name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').replace(/([A-Z])(\d)/g, '$1-$2').toLowerCase();

const entries = [];
const missing = [];

for (const [name, source] of Object.entries(MAP)) {
    const file = path.join(SRC, `${source}.svg`);
    if (!fs.existsSync(file)) {
        missing.push(`${name} -> ${source}`);
        continue;
    }
    entries.push([name, strip(fs.readFileSync(file, 'utf8'))]);
}
for (const [name, nodes] of Object.entries(HAND_DRAWN)) entries.push([name, nodes]);

entries.sort(([a], [b]) => a.localeCompare(b));

for (const [name, nodes] of entries) {
    const body = nodes.map((n) => `        ${n}`).join('\n');
    fs.writeFileSync(
        path.join(OUT, `${kebab(name)}.tsx`),
        `import { createIcon } from './create-icon';\n\nexport const ${name} = createIcon('${name}', (\n    <>\n${body}\n    </>\n));\n`,
        'utf8',
    );
}

const barrel = [
    "export { createIcon } from './create-icon';",
    "export type { IconComponent, IconProps } from './create-icon';",
    ...entries.map(([name]) => `export { ${name} } from './${kebab(name)}';`),
].join('\n');
fs.writeFileSync(path.join(OUT, 'index.ts'), `${barrel}\n`, 'utf8');

console.log(`generated ${entries.length} icons`);
if (missing.length) console.log(`MISSING SOURCES:\n${missing.join('\n')}`);
