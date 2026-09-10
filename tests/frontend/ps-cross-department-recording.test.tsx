import { DepartmentInteractionModal, NotesAndInstructionsHistory } from '@/components/ats/department-interaction';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
    }),
}));

vi.stubGlobal('route', (name: string, mailId?: number) => `/${name}/${mailId ?? ''}`);

describe('PS Office department interaction entry point', () => {
    it('places the recording button below notes and instructions history', () => {
        const onRecord = vi.fn();
        render(
            <NotesAndInstructionsHistory
                entries={[
                    {
                        id: 'correspondence-41',
                        message: 'Review and provide comments.',
                        origin_title: 'Office of the Permanent Secretary',
                        recipient_title: 'Basic Education',
                        author_name: 'Registry Officer',
                        author_title: 'PS Registry',
                        author_office: 'Office of the Permanent Secretary',
                        kind: 'Department interaction',
                        occurred_at_label: '01/09/2026 09:15',
                        recorded_at_label: '09/09/2026 14:30',
                        attachments: [],
                    },
                ]}
                canRecordDepartmentInteraction
                onRecordDepartmentInteraction={onRecord}
            />,
        );

        const history = screen.getByRole('list', { name: 'Correspondence messages in chronological order' });
        const button = screen.getByRole('button', { name: 'Add Correspondence' });

        expect(history.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getByText('Department interaction')).toBeInTheDocument();
        expect(screen.getByText('Occurred 01/09/2026 09:15')).toBeInTheDocument();
        expect(screen.getByText('Recorded 09/09/2026 14:30')).toBeInTheDocument();
        fireEvent.click(button);
        expect(onRecord).toHaveBeenCalledOnce();
    });

    it('starts the quick form with department or officer title followed by note or annotation', () => {
        render(<DepartmentInteractionModal mailId={10} onClose={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Add Correspondence' });
        const target = within(dialog).getByRole('combobox', { name: /Department or officer title/ });
        const annotation = within(dialog).getByRole('textbox', { name: /Note or annotation/ });

        expect(target.compareDocumentPosition(annotation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(target).toHaveAttribute('placeholder', 'Search C/LEIT, C/HRM, an officer name, title, office or department');
        expect(target.tagName).toBe('INPUT');
        expect(annotation).toBeRequired();
        expect(within(dialog).getByText('Additional movement details')).toBeInTheDocument();
    });
});
