import BasicCorrespondenceList from '@/pages/mail/basic-correspondence-list';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('displays details, recording and receiving offices, then date', () => {
    const { container } = render(
        <BasicCorrespondenceList
            entries={[{ id: 12, text: 'Sent for review.', from_office: 'PS/ES', destination_office: 'C/LEIT', logged_date: '09/10/2026' }]}
        />,
    );
    expect(Array.from(container.firstElementChild!.children).map((element) => element.textContent)).toEqual([
        'Sent for review.',
        'From Office: PS/ES',
        'To Office: C/LEIT',
        'Date: 09/10/2026',
    ]);
    expect(screen.queryByText(/Receiving office/)).not.toBeInTheDocument();
});

it('preserves custom office names and supports older entries without an office', () => {
    render(
        <BasicCorrespondenceList
            entries={[
                { id: 12, text: 'Filed.', from_office: 'PS/ES', destination_office: 'Regional field office', logged_date: '09/10/2026' },
                { id: 13, text: 'Legacy note.', from_office: null, destination_office: null, logged_date: '08/10/2026' },
            ]}
        />,
    );
    expect(screen.getByText('To Office: Regional field office')).toBeInTheDocument();
    expect(screen.getByText('From Office: Not recorded')).toBeInTheDocument();
    expect(screen.getByText('To Office: Not recorded')).toBeInTheDocument();
});
