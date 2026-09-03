import Modal from '@/components/ats/modal';
import { ConfirmProvider, useConfirm } from '@/hooks/use-confirm';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

function ConfirmationExample() {
    const confirm = useConfirm();
    const [result, setResult] = useState('Pending');
    return (
        <>
            <button
                onClick={async () =>
                    setResult(String(await confirm({ title: 'Delete record?', message: 'This cannot be undone.', variant: 'danger' })))
                }
            >
                Delete record
            </button>
            <output>{result}</output>
        </>
    );
}

function ModalExample({
    dismissible = true,
    nested = false,
    autoFocusInput = false,
}: {
    dismissible?: boolean;
    nested?: boolean;
    autoFocusInput?: boolean;
}) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button onClick={() => setOpen(true)}>Open editor</button>
            <button>Outside action</button>
            {open && (
                <Modal title="Edit record" onClose={() => setOpen(false)} dismissible={dismissible} footer={<button>Save record</button>}>
                    <label>
                        Name
                        <input autoFocus={autoFocusInput} />
                    </label>
                    {nested && <ConfirmationExample />}
                </Modal>
            )}
        </>
    );
}

describe('Confirmation safety', () => {
    it('treats Enter on Cancel as cancellation, never approval', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <ConfirmationExample />
            </ConfirmProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Delete record' }));
        screen.getByRole('button', { name: 'Cancel' }).focus();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('status')).toHaveTextContent('false');
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('initially focuses Cancel on a destructive confirmation', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <ConfirmationExample />
            </ConfirmProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Delete record' }));
        expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();
    });

    it('approves when Enter activates the focused Confirm button', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <ConfirmationExample />
            </ConfirmProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Delete record' }));
        screen.getByRole('button', { name: 'Confirm' }).focus();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('status')).toHaveTextContent('true');
    });
});

describe('Shared modal keyboard access', () => {
    it('restores the opener when a child control takes autofocus', async () => {
        const user = userEvent.setup();
        render(<ModalExample autoFocusInput />);
        const opener = screen.getByRole('button', { name: 'Open editor' });
        await user.click(opener);
        expect(screen.getByRole('textbox', { name: 'Name' })).toHaveFocus();
        await user.click(screen.getByRole('button', { name: 'Close' }));
        await waitFor(() => expect(opener).toHaveFocus());
    });

    it('moves focus inside, wraps Tab in both directions, and restores the opener', async () => {
        const user = userEvent.setup();
        render(<ModalExample />);
        const opener = screen.getByRole('button', { name: 'Open editor' });
        await user.click(opener);
        const dialog = screen.getByRole('dialog', { name: 'Edit record' });
        expect(dialog).toContainElement(document.activeElement as HTMLElement);
        const first = within(dialog).getByRole('button', { name: 'Close' });
        const last = within(dialog).getByRole('button', { name: 'Save record' });
        last.focus();
        await user.tab();
        expect(first).toHaveFocus();
        await user.tab({ shift: true });
        expect(last).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await waitFor(() => expect(opener).toHaveFocus());
    });

    it('keeps a nondismissible dialog open after Escape and backdrop clicks', async () => {
        const user = userEvent.setup();
        render(<ModalExample dismissible={false} />);
        await user.click(screen.getByRole('button', { name: 'Open editor' }));
        await user.keyboard('{Escape}');
        const dialog = screen.getByRole('dialog', { name: 'Edit record' });
        // Click the backdrop itself, outside the dialog surface.
        await user.click(document.querySelector('.modal-backdrop')!);
        expect(screen.getByRole('dialog', { name: 'Edit record' })).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('lets Escape cancel only the nested confirmation and restores focus inside the parent', async () => {
        const user = userEvent.setup();
        render(
            <ConfirmProvider>
                <ModalExample nested />
            </ConfirmProvider>,
        );
        await user.click(screen.getByRole('button', { name: 'Open editor' }));
        const trigger = screen.getByRole('button', { name: 'Delete record' });
        await user.click(trigger);
        const alert = screen.getByRole('alertdialog');
        within(alert).getByRole('button', { name: 'Confirm' }).focus();
        await user.tab();
        expect(within(alert).getByRole('button', { name: 'Cancel' })).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Edit record' })).toBeInTheDocument();
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(screen.getByRole('status')).toHaveTextContent('false');
    });
});
