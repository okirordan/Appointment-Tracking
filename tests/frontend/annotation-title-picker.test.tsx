import AnnotationTitlePicker, { type AnnotationTitleOption } from '@/components/ats/annotation-title-picker';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const title: AnnotationTitleOption = {
    id: 27,
    shorthand: 'C/ICT',
    full_title: 'Commissioner Information and Communication Technology',
    label: 'C/ICT — Commissioner Information and Communication Technology',
};

function MailForm({ onSubmit }: { onSubmit: () => void }) {
    const [selected, setSelected] = useState<AnnotationTitleOption | null>(null);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
        >
            <AnnotationTitlePicker label="Officer Title" selected={selected} onSelect={setSelected} />
            <button type="submit">Record incoming correspondence</button>
        </form>
    );
}

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (_url: string, init?: RequestInit) =>
                new Response(JSON.stringify(init?.method === 'POST' ? { title } : { titles: [] }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
        ),
    );
});

afterEach(() => vi.unstubAllGlobals());

describe('Shared officer title creation inside the mail form', () => {
    it('lets a secretary return to the full title input with the mouse', async () => {
        const user = userEvent.setup();
        render(<MailForm onSubmit={vi.fn()} />);
        const search = screen.getByRole('combobox', { name: 'Officer Title' });
        await user.type(search, 'C/ICT');
        await user.click(await screen.findByRole('button', { name: /Add.*C\/ICT/ }));

        const fullTitle = screen.getByRole('textbox', { name: 'Full designation' });
        expect(fullTitle).toHaveFocus();
        await user.click(search);
        await user.click(fullTitle);

        expect(fullTitle).toHaveFocus();
        await user.keyboard(title.full_title);
        expect(fullTitle).toHaveValue(title.full_title);
    });

    it('does not submit the correspondence while Enter is used to finish a new title', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(<MailForm onSubmit={onSubmit} />);
        await user.type(screen.getByRole('combobox', { name: 'Officer Title' }), 'C/ICT');
        await user.click(await screen.findByRole('button', { name: /Add.*C\/ICT/ }));
        await user.keyboard(`${title.full_title}{Enter}`);

        expect(onSubmit).not.toHaveBeenCalled();
        expect(await screen.findByText(title.full_title)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Clear Officer Title' })).toBeInTheDocument();
    });

    it('does not submit the correspondence when Enter is pressed in title search', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(<MailForm onSubmit={onSubmit} />);
        await user.type(screen.getByRole('combobox', { name: 'Officer Title' }), 'C/ICT{Enter}');

        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('does not show a stale search response after the search text changes', async () => {
        const user = userEvent.setup();
        let finishFirstSearch!: (response: Response) => void;
        const fetchMock = vi.fn(() =>
            fetchMock.mock.calls.length === 1
                ? new Promise<Response>((resolve) => {
                      finishFirstSearch = resolve;
                  })
                : Promise.resolve(new Response(JSON.stringify({ titles: [] }))),
        );
        vi.stubGlobal('fetch', fetchMock);
        render(<MailForm onSubmit={vi.fn()} />);
        const search = screen.getByRole('combobox', { name: 'Officer Title' });
        await user.type(search, 'C');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        await user.type(search, '/ICT');

        await act(async () => finishFirstSearch(new Response(JSON.stringify({ titles: [title] }))));

        expect(screen.queryByText(title.full_title)).not.toBeInTheDocument();
    });

    it('creates and selects a missing title without submitting the mail form', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(<MailForm onSubmit={onSubmit} />);
        await user.type(screen.getByRole('combobox', { name: 'Officer Title' }), 'C/ICT');
        await user.click(await screen.findByRole('button', { name: /Add.*C\/ICT/ }));
        await user.keyboard(title.full_title);
        await user.click(screen.getByRole('button', { name: 'Save and select' }));

        expect(await screen.findByText(title.full_title)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Clear Officer Title' })).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('keeps the entered full title available when the directory rejects a duplicate', async () => {
        const user = userEvent.setup();
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async (_url: string, init?: RequestInit) =>
                    new Response(
                        JSON.stringify(init?.method === 'POST' ? { errors: { shorthand: ['This shorthand already exists.'] } } : { titles: [] }),
                        {
                            status: init?.method === 'POST' ? 422 : 200,
                            headers: { 'Content-Type': 'application/json' },
                        },
                    ),
            ),
        );
        render(<MailForm onSubmit={vi.fn()} />);
        await user.type(screen.getByRole('combobox', { name: 'Officer Title' }), 'C/ICT');
        await user.click(await screen.findByRole('button', { name: /Add.*C\/ICT/ }));
        await user.keyboard(title.full_title);
        await user.click(screen.getByRole('button', { name: 'Save and select' }));

        expect(await screen.findByText('This shorthand already exists.')).toBeInTheDocument();
        expect(screen.getByRole('textbox')).toHaveValue(title.full_title);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Save and select' })).toBeEnabled());
    });
});
