import { CaseFileLetterhead, correspondenceStatusTone } from '@/components/ats/correspondence-case-file';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

describe('correspondence case-file letterhead', () => {
    it('uses brass for active assignment states and forest for resolved states', () => {
        expect(correspondenceStatusTone('Assigned')).toBe('amber');
        expect(correspondenceStatusTone('Awaiting response')).toBe('amber');
        expect(correspondenceStatusTone('Responded')).toBe('green');
        expect(correspondenceStatusTone('Filed')).toBe('green');
        expect(correspondenceStatusTone('Registered')).toBe('blue');
    });

    it('presents the reference before the formal subject and exposes status as a stamp', () => {
        render(
            <CaseFileLetterhead
                recordKind="Incoming correspondence"
                reference="PS/REG/0041"
                subject="Review of the education sector report"
                status="Responded"
                tone="green"
            >
                <span>Date received 9 September 2026</span>
            </CaseFileLetterhead>,
        );

        const reference = screen.getByText('PS/REG/0041');
        const subject = screen.getByRole('heading', { name: 'Review of the education sector report' });
        const stamp = screen.getByRole('status', { name: 'Correspondence status: Responded' });

        expect(reference.compareDocumentPosition(subject) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(stamp).toHaveClass('case-file-status-stamp', 'tone-green');
        expect(document.querySelector('.correspondence-status-badge')).not.toBeInTheDocument();
    });
});
