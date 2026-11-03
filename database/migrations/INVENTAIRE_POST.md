# Inventaire des points POST — checklist CSRF (Phase 0.2)

> Source : `grep` sur les routes `public/index.php`, les vues `src/Views/`, et les scripts `public/js/`.
> Chaque case cochée signifie que le jeton CSRF est injecté/transmis pour cette cible (Phase 1.3 / 1.4).

## A — Routes POST déclarées (`public/index.php`)

Famille `/login`
- [x] `POST /login` → `AuthController::login`

Famille `/users/*`
- [x] `POST /users/store`
- [x] `POST /users/update/{id}`
- [x] `POST /users/delete/{id}`

Famille `/roles/*`
- [x] `POST /roles/store`
- [x] `POST /roles/update/{id}`
- [x] `POST /roles/delete/{id}`

Famille `/permissions/*`
- [x] `POST /permissions/store`
- [x] `POST /permissions/update/{id}`
- [x] `POST /permissions/delete/{id}`

Famille `/settings/*`
- [x] `POST /settings/update`

Famille `/packing-lists/*`
- [x] `POST /packing-lists/store`
- [x] `POST /packing-lists/update/{id}`
- [x] `POST /packing-lists/close/{id}`
- [x] `POST /packing-lists/delete/{id}`
- [x] `POST /packing-lists/{packingListId}/palettes/generate-multiple`
- [x] `POST /packing-lists/reopen/{id}`
- [x] `POST /packing-lists/{packingListId}/palettes/store`

Famille `/palettes/*`
- [x] `POST /palettes/open/{id}`
- [x] `POST /palettes/update-max-colis/{id}`
- [x] `POST /palettes/close/{id}`
- [x] `POST /palettes/delete/{id}`
- [x] `POST /palettes/print-qrcodes`
- [x] `POST /palettes/reopen/{id}`

Famille `/colis/*`
- [x] `POST /colis/process-palette-scan` (form classique fallback)
- [x] `POST /colis/process-palette-scan-ajax` (AJAX, X-CSRF-Token)
- [x] `POST /colis/process-scan` (form classique fallback)
- [x] `POST /colis/process-scan-ajax` (AJAX, X-CSRF-Token)
- [x] `POST /colis/delete/{colisId}`
- [x] `POST /colis/move/{colisId}`
- [x] `POST /colis/massive-move`
- [x] `POST /colis/update-comment/{colisId}`

Famille `/search/*`
- [x] `POST /search/ajax` (AJAX JSON, X-CSRF-Token)
- [x] `POST /search/by-of` (AJAX JSON, X-CSRF-Token)
- [x] `POST /search/by-sku` (AJAX JSON, X-CSRF-Token)
- _Note_ : `GET /search/global` — pas de CSRF (GET).

Famille `/theoretical-exports/*`
- [x] `POST /theoretical-exports/process-import` (multipart)

Famille `/sites/*`
- [x] `POST /sites/store`
- [x] `POST /sites/update/{id}`
- [x] `POST /sites/deactivate/{id}`
- [x] `POST /sites/reactivate/{id}`
- [x] `POST /sites/force-delete/{id}`

## B — Formulaires HTML POST (`src/Views/`)

- [x] `auth/login.php` — `/login`
- [x] `packing_lists/create.php` — `/packing-lists/store`
- [x] `packing_lists/edit.php` — `/packing-lists/update/{id}`
- [x] `packing_lists/index.php` — close/reopen/delete x3
- [x] `packing_lists/show.php` — close/reopen/delete/palettes-store/generate-multiple/print-qrcodes (x6)
- [x] `palettes/show.php` — close/reopen/delete/open/update-max-colis/colis-delete/update-comment x2 (≈8)
- [x] `colis/scan.php` — paletteActivationForm + colisScanForm (formulaires fallback, soumis via AJAX en pratique)
- [x] `colis/search.php` — colis-delete, update-comment
- [x] `roles/create.php`, `roles/edit.php`, `roles/index.php` (delete)
- [x] `users/create.php`, `users/edit.php`, `users/index.php` (delete)
- [x] `permissions/create.php`, `permissions/edit.php`, `permissions/index.php` (delete)
- [x] `settings/edit.php`
- [x] `sites/create.php`, `sites/edit.php`, `sites/index.php` (deactivate/reactivate/force-delete)
- [x] `theoretical_exports/import.php` (multipart)
- [x] `partials/_move_colis_modal.php`
- [x] `partials/_move_colis_massive_modal.php`

## C — Appels AJAX POST (`public/js/`, vues inline)

- [x] `public/js/search.js:57` → `/search/ajax`, `/search/by-of`, `/search/by-sku` (`application/json`)
- [x] `src/Views/colis/scan.php:544` → `/colis/process-palette-scan-ajax` (`application/x-www-form-urlencoded`)
- [x] `src/Views/colis/scan.php:641` → `/colis/process-scan-ajax` (`application/x-www-form-urlencoded`)

Tous transportent l'en-tête `X-CSRF-Token` via `window.csrfToken()`.

## D — GET d'écriture / autres pièges
- `GET /search/global` : GET — pas de jeton requis.
- Aucun autre point d'écriture identifié.
