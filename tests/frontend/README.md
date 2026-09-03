# UI regression checks

Install dependencies with `npm ci`, then run `npm run test:ui`.

The tests render the actual React components in jsdom and use keyboard/pointer
interactions. Inertia requests and the authenticated application shell are mocked
at the server boundary; the tests do not create accounts or modify application data.

Coverage includes:

- Cancel versus Confirm activation with Enter.
- Safe initial focus, Tab/Shift+Tab containment, focus restoration, and nested dialogs.
- Escape/backdrop protection for dialogs requiring deliberate dismissal.
- Accessible hierarchy labels and empty/populated collections.
- Division validation feedback and pending submission controls.

These checks complement, rather than replace, browser verification. Check the
shared dialogs and admin pages at 320, 768, 1024, and 1440 pixels, in light and
dark themes. Confirm form scrolling, visible focus, and readable button text.

Other verification commands:

```text
npm run build
npx tsc --noEmit
php artisan test
```

If the local PHP CLI does not load its installed ZIP extension, run the suite with
`php -d extension=zip vendor/bin/phpunit`. This enables ZIP for that process only.
