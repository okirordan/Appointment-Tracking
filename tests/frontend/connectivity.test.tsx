import { appConnectionAvailable } from '@/pwa/connectivity';
import { describe, expect, it } from 'vitest';

describe('app connection status', () => {
    it.each(['localhost', 'LOCALHOST', '127.0.0.1', '[::1]', '::1'])(
        'keeps the local app available at %s when the laptop has no internet',
        (hostname) => {
            expect(appConnectionAvailable(hostname, false)).toBe(true);
        },
    );

    it('uses browser connectivity for a remote app server', () => {
        expect(appConnectionAvailable('ats.example.org', false)).toBe(false);
        expect(appConnectionAvailable('ats.example.org', true)).toBe(true);
    });
});
