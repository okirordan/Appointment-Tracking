import ThemeSelector from '@/components/ats/theme-selector';
import { Link } from '@inertiajs/react';
import BasicProfileMenu from './basic-profile-menu';
import MailModeSwitch, { useMailUrl } from './mode';

type ActivePage = 'home' | 'incoming' | 'outgoing';

export default function BasicSiteHeader({ active }: { active?: ActivePage }) {
    const url = useMailUrl();
    const clean = { view: null, section: null, page: null, q: null, status: null, recipient: null, date_from: null, date_to: null, category: null };
    return (
        <header className="site-header">
            <div className="topbar">
                <Link className="ats-brand" href={url('/home?type=all', clean)}>
                    <img src="/images/moes-crest.jpg" alt="Uganda coat of arms" />
                    <span>
                        <strong>MoES</strong>
                        <small>Mail Management System</small>
                    </span>
                </Link>
                <nav aria-label="Mail navigation">
                    <Link className="nav-item" href={url('/home?type=all', clean)} aria-current={active === 'home' ? 'page' : undefined}>
                        Home
                    </Link>
                    <Link
                        className="nav-item"
                        href={url(route('mail.incoming.index'), clean)}
                        aria-current={active === 'incoming' ? 'page' : undefined}
                    >
                        Incoming Mail
                    </Link>
                    <Link
                        className="nav-item"
                        href={url(route('mail.outgoing.index'), clean)}
                        aria-current={active === 'outgoing' ? 'page' : undefined}
                    >
                        Outgoing Mail
                    </Link>
                </nav>
                <MailModeSwitch />
                <ThemeSelector compact />
                <BasicProfileMenu />
            </div>
        </header>
    );
}
