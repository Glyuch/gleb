#!/usr/bin/env bash
# Raw structural facts for docs/PROJECT_MAP.md.
# Run from the repo root on the server:  bash docs/project-map-facts.sh
# Then reconcile any diffs into docs/PROJECT_MAP.md (in the same commit as your change).
set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== ROUTES ==="
php artisan route:list --except-vendor 2>/dev/null || cat routes/web.php
echo; echo "=== CONTROLLERS ==="
find app/Http/Controllers -type f | sort
echo; echo "=== MODELS ==="
find app/Models -type f | sort
echo; echo "=== ACTIONS / SUPPORT ==="
find app/Actions app/Support -type f 2>/dev/null | sort || true
echo; echo "=== INERTIA PAGES (React) ==="
find resources/js/pages -type f | sort
echo; echo "=== BLADE VIEWS ==="
find resources/views -type f | sort
echo; echo "=== PUBLIC REPORTS ==="
ls -R public/reports 2>/dev/null || true
echo; echo "=== MIGRATIONS ==="
ls database/migrations | sort
echo; echo "=== SPECS ==="
ls docs/specs 2>/dev/null || true
