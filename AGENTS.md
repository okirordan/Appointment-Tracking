# Repository workflow

- Make and commit completed changes directly on `main` unless the user explicitly requests another branch or worktree. This is the user's standing preference.
- Preserve existing local and remote work; never force-push or discard changes to make branches match.
- A request to commit does not authorize pushing. Push only when the user or active task authorizes it; updating remote `main` may trigger production deployment.
