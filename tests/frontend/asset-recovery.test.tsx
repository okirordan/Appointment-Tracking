import { isPageAssetLoadError } from '@/lib/asset-recovery';
import { expect, it } from 'vitest';

it('identifies stale page chunks after a frontend rebuild', () => {
    expect(isPageAssetLoadError(new TypeError('Failed to fetch dynamically imported module: http://127.0.0.1:8080/build/assets/basic-old.js'))).toBe(
        true,
    );
    expect(isPageAssetLoadError(new Error('Loading chunk 42 failed'))).toBe(true);
    expect(isPageAssetLoadError(new Error('Request failed with status code 500'))).toBe(false);
});
