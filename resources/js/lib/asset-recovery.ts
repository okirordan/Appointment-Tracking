/** A tab opened before a rebuild may request a page chunk that the new build removed. */
export function isPageAssetLoadError(exception: unknown): boolean {
    const message = exception instanceof Error ? exception.message : String(exception);
    return /failed to fetch dynamically imported module|error loading dynamically imported module|importing a module script failed|loading chunk .+ failed|unable to preload css/i.test(
        message,
    );
}
