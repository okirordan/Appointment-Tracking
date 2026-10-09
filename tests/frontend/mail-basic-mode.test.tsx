import { BasicAssignmentForm, BasicCorrespondenceForm, BasicMailForm } from '@/pages/mail/basic-forms';
import type { MailDetail, Props } from '@/pages/mail/index';
import MailModeSwitch from '@/pages/mail/mode';
import { mailContextUrl } from '@/pages/mail/navigation';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    page: { url: '/home?type=mail', props: { mailMode: 'basic', canSwitchMailMode: true } },
    post: vi.fn(),
    put: vi.fn(),
    formData: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => state.page,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    useForm: (data: Record<string, unknown>) => {
        const [current, setCurrent] = useState(data);
        state.formData = current;
        return {
            data: current,
            errors: {},
            processing: false,
            setData: (key: string | ((previous: Record<string, unknown>) => Record<string, unknown>), value?: unknown) =>
                setCurrent((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value })),
            post: state.post,
            put: state.put,
        };
    },
}));
vi.mock('@/components/ats/mail-duplicate-suggestions', () => ({ default: () => null }));
vi.mock('@/components/ats/recipient-picker', () => ({
    default: ({ label, mailId, placeholder }: { label: string; mailId?: number; placeholder?: string }) => (
        <label>
            {label}
            <input data-mail-id={mailId ?? ''} placeholder={placeholder} />
        </label>
    ),
}));

beforeEach(() => {
    state.page = { url: '/home?type=mail', props: { mailMode: 'basic', canSwitchMailMode: true } };
    state.post.mockClear();
    vi.stubGlobal(
        'route',
        (name: string) =>
            ({
                'mail.incoming.store': '/incoming-mail',
                'mail.outgoing.store': '/outgoing-mail',
                'mail.assign': '/mail/12/assign',
                'mail.assign-outgoing': '/mail/12/assign-outgoing',
                'mail.updates.store': '/mail/12/updates',
                'mail.correspondence-offices.index': '/mail/12/correspondence-offices',
                'mail.show': '/mail/12',
            })[name] ?? '/incoming-mail',
    );
});

describe('Basic action points and correspondences', () => {
    const mail = { id: 12, direction: 'incoming' } as MailDetail;

    it('keeps the template action fields and submits to the ATS task assignment flow', () => {
        render(<BasicAssignmentForm mail={mail} />);
        expect(screen.getByLabelText('Action details *')).toBeRequired();
        expect(screen.queryByLabelText('Assignee')).not.toBeInTheDocument();
        expect(screen.getByPlaceholderText('Describe the action to complete')).toBeInTheDocument();
        expect(screen.getByLabelText('Due date *')).toBeRequired();
        expect(screen.getByLabelText('Status')).toHaveValue('Assigned');
        expect(state.formData.action_required).toBe(true);
        expect(state.formData.basic_action).toBe(true);
        fireEvent.submit(screen.getByRole('button', { name: 'Save action point' }).closest('form')!);
        expect(state.post).toHaveBeenCalledWith('/mail/12/assign?mode=basic&section=actions');
    });

    it('sends outgoing action points to the existing outgoing task route', () => {
        render(<BasicAssignmentForm mail={{ ...mail, direction: 'outgoing' }} />);
        expect(screen.queryByLabelText('Assignee')).not.toBeInTheDocument();
        fireEvent.submit(screen.getByRole('button', { name: 'Save action point' }).closest('form')!);
        expect(state.post).toHaveBeenCalledWith('/mail/12/assign-outgoing?mode=basic&section=actions');
    });

    it('keeps the template correspondence fields and sends their values to the shared update route', () => {
        render(<BasicCorrespondenceForm mail={mail} />);
        expect(screen.queryByLabelText('Source (optional)')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Source type')).not.toBeInTheDocument();
        expect(state.formData.destination_kind).toBe('office');
        expect(screen.getByLabelText('Receiving office *')).toBeRequired();
        expect(screen.getByLabelText('Receiving office *')).toHaveAttribute('role', 'combobox');
        expect(screen.getByPlaceholderText('Search a title or enter an office')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Describe what was sent or filed')).toBeInTheDocument();
        expect(screen.getByLabelText('Date recorded *')).toBeRequired();
        expect(screen.getByLabelText('Correspondence details *')).toBeRequired();
        expect(screen.queryByLabelText('Entry type')).not.toBeInTheDocument();
        expect(state.formData.entry_method).toBe('basic_correspondence');
        fireEvent.submit(screen.getByRole('button', { name: 'Save correspondence' }).closest('form')!);
        expect(state.post).toHaveBeenCalledWith('/mail/12/updates?mode=basic&section=correspondences');
    });

    it('adds and removes receiving office fields for the same correspondence', () => {
        render(<BasicCorrespondenceForm mail={mail} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add recipient' }));
        expect(screen.getAllByLabelText('Receiving office *')).toHaveLength(2);
        expect(state.formData.additional_destinations).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Remove recipient' }));
        expect(screen.getAllByLabelText('Receiving office *')).toHaveLength(1);
        expect(state.formData.additional_destinations).toHaveLength(0);
    });
});

describe('mail mode navigation', () => {
    it('shows the switch on mail home and keeps the selected mode accessible', () => {
        render(<MailModeSwitch />);
        expect(screen.getByRole('group', { name: 'Mail display mode' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Basic' })).toHaveAttribute('aria-current', 'true');
        expect(screen.getByRole('link', { name: 'Full' })).toHaveAttribute('href', '/home?type=mail&mode=full');
    });
    it('switches the current record while retaining section and register filters', () => {
        state.page.url = '/mail/12?section=actions&q=school&recipient=PS&page=2';
        render(<MailModeSwitch />);
        expect(screen.getByRole('link', { name: 'Full' }).getAttribute('href')).toContain(
            '/mail/12?section=actions&q=school&recipient=PS&page=2&mode=full',
        );
    });
    it('provides a mail entry point from non-mail pages without carrying their query', () => {
        state.page.url = '/tasks?status=completed';
        render(<MailModeSwitch />);
        expect(screen.getByRole('link', { name: 'Basic' })).toHaveAttribute('href', '/home?type=mail&mode=basic');
    });
    it('carries only presentation context, never record data or external return targets', () => {
        const value = mailContextUrl('/mail/4', '/incoming-mail?q=test&page=2&password=secret&return_url=https://example.com', 'basic', {
            section: 'details',
        });
        expect(value).toBe('/mail/4?q=test&page=2&mode=basic&section=details');
    });
});

describe('Basic capture', () => {
    const props = {
        direction: 'incoming',
        registerOfficeName: 'Registry',
        mailFeatures: { correspondence_reference: true },
        priorityOptions: [],
    } as Props;
    it('keeps Reference No. optional and submits through the existing capture route', () => {
        render(<BasicMailForm props={props} />);
        expect(screen.getByLabelText('Reference No.')).not.toBeRequired();
        expect(screen.getByLabelText('Date received *')).toBeRequired();
        fireEvent.submit(screen.getByRole('button', { name: 'Save mail' }).closest('form')!);
        expect(state.post).toHaveBeenCalledWith('/incoming-mail?mode=basic', { forceFormData: true });
    });
    it('uses Date sent for outgoing mail without presenting a received date', () => {
        render(<BasicMailForm props={{ ...props, direction: 'outgoing' }} />);
        expect(screen.getByLabelText('Date sent *')).toBeRequired();
        expect(screen.queryByLabelText('Date received *')).not.toBeInTheDocument();
    });
});
