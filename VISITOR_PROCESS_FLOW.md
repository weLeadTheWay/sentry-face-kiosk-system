# Visitor Process Flow — As-Built Documentation

This document describes the Visitor Management module exactly as implemented in code after the compliance fix pass (2026-08-06). It supersedes the original `Visitors Process Flow.md` design doc and the `TO DO AND GUIDE INSTRUCTIONS.md` requirements list — every item in that TODO is implemented and reflected here.

---

## 1. Overview

The module has four phases: **Sync** (AppSheet → Laravel), **Face Registration** (public, token-based), **Kiosk Recognition & Entry** (device-authenticated), and **Google Sheets Sync-Back** (Time In / Time Out). Two identity concepts matter throughout:

- **`user_directory`** — one row per unique human being, identified by the compound key **(full name, email)**. A person can have multiple visits (`visitor_request` rows) but should only ever have one directory row and one active face profile.
- **`visitor_request`** — one row per approved visit. Carries its own lifecycle state independent of the person's identity.

---

## 2. Phase 1 — Sync (`POST /api/v1/visitor/sync`)

AppSheet calls this endpoint once a visitor request is approved. Handled by `VisitorSyncController` → `VisitorSyncService::syncApprovedRequest()`.

1. **Farm resolution** (`FarmResolver`) — exact or aliased match against `farm_list`/`farm_aliases`. No match → sync fails, nothing is written, admin must add a farm alias.
2. **Idempotency** — if `visitor_id` already exists on a `visitor_request` row, the existing `registration_token` is returned unchanged. No duplicate rows are ever created for the same AppSheet request.
3. **Directory identity resolution** (`VisitorSyncService::resolveDirectory()`) — the compound-match rule:

   | Full Name | Email | Result |
   |---|---|---|
   | Same | Same | Reuse existing `user_directory` |
   | Same | Different | Create new `user_directory` |
   | Different | Same | Create new `user_directory` |
   | Different | Different | Create new `user_directory` |

   Matching is case-insensitive and trimmed. **Identity is never merged on a single field alone** — only an exact compound match reuses a directory. `full_name` is always the canonically computed `first + middle + last` (not trusted from the raw payload) so future lookups stay consistent. `middle_name` is accepted and persisted.
4. **VisitorRequest creation** — stores `visitor_id`, `qr_url` (external, kept but no longer used for display — see §4), `farm_id`, `visit_datetime`/`departure_datetime`, and a generated `registration_token`. New fields: `face_registration_status` defaults to `PENDING`, `request_status` defaults to `ACTIVE`.

AppSheet then emails the visitor a face-registration link containing the `registration_token`.

---

## 3. Phase 2 — Face Registration (public, token-gated)

Route group: `/register/visitor/*`, no auth middleware (visitors aren't system users). Token validated against `visitor_request.registration_token` + `approval_status = Approved` on every action.

### Option A — Register My Face
`GET /register/visitor/capture` → `capture.blade.php`. On "Capture Face":

1. **Client-side face-quality gate** (face-api.js, before anything is sent to the server): `detectAllFaces()` must find **exactly one** face (rejects 0 or 2+), and its detector confidence score must be **≥ 0.7** (rejects blurry/poor-angle captures). Only a passing capture extracts and sends a real 128-value descriptor.
2. **Server-side matching** (`VisitorRegistrationService::completeFaceRegistrationOptionA`):
   - **No match anywhere** → creates a new `FaceProfile`, sets `face_registration_status = REGISTERED`.
   - **Match belongs to the visitor's own already-linked directory** (e.g. re-registering) → **no duplicate profile created**, status set to `REGISTERED`, user is told "Your face is already registered."
   - **Match belongs to a different directory** → prompts "Is this you?"

### Face-Match Confirmation ("Is this you?")
`POST /register/visitor/confirm`. Exact business rule:

- **Yes** → `visitor_request.directory_id` re-linked to the matched directory, `face_registration_status = REGISTERED`, no new face profile. Proceeds to success + QR.
- **No** → **no face profile created, no directory link changed.** Sets `face_registration_status = FAILED_MATCH` and `manual_verification_required = true`. Displays: *"A biometric conflict has been detected. Please contact the administrator. You may still use your assigned QR Code for entry."* The visitor still reaches the success page and can still view/download their QR — this is a deliberate design choice (a rejected face match doesn't block a visitor who has an otherwise-valid approved request; it just disables face auth for them and flags the account for admin follow-up).

### Option B — I Already Registered My Face
`GET /register/visitor/search` → name search (fuzzy `LIKE` on `full_name`) → select a profile → redirects to `capture.blade.php?option=B&directory_id=X`. On "Verify My Face":

1. Same client-side face-quality gate as Option A.
2. `POST /register/visitor/verify` → `VisitorRegistrationService::verifyFaceOptionB()` — matches the descriptor **only** against the selected directory's face profile (not a global scan).
3. **Match** → links `visitor_request.directory_id` to the selected directory, `face_registration_status = REGISTERED`.
4. **No match** → client-side counter increments; up to **3 attempts** allowed. On the 3rd failure: "Verification failed. Please contact the administrator." and the capture button is disabled. No state is mutated on failed attempts.

### Registration Success Page Access Control
`GET /register/visitor/success?token=...`. **Gated** — a guessed/reused token cannot reach this page unless the request has reached a terminal registration outcome:

- `face_registration_status = PENDING` → redirected back to `/register/visitor/register` with a notice ("Please complete your registration first."). **No** face_profile → **no** access to success/QR.
- `REGISTERED` → normal success framing + QR.
- `FAILED_MATCH` → warning framing with the biometric-conflict message + QR still shown (per the business rule above).

---

## 4. QR Codes — Locally Generated

**The `qr_url` column from AppSheet is no longer used for display.** It was found to be an unreliable external image URL (producing a broken "Missing variable text" image in some cases — traced to the upstream QR-image-service template, not to any code in this app). Laravel is now the single source of truth:

- `App\Services\Qr\VisitorQrCodeService::generate(string $payload)` — generates a PNG on-demand using `endroid/qr-code`, encoding **exactly** `visitor_request.visitor_id`.
- `GET /register/visitor/qr?token=...` streams this PNG (`&download=1` for an attachment download). Same access gate as the success page (`PENDING` → 404).
- The kiosk's QR scanner (§5) decodes this same value and looks it up via `visitor_id` — a closed loop with no external dependency.

`qr_url` itself is still stored on `visitor_request` (harmless, unused for rendering — kept in case another system reads it directly).

---

## 5. Phase 3 — Kiosk (device-authenticated)

Every kiosk device has an auto-generated `kiosk_token`. `GET /kiosk/{kiosk}` renders the shell (unauthenticated — the device needs to load the page before it can present a token); `POST /kiosk/{kiosk}/recognize` and `/entry` require `X-KIOSK-TOKEN` (validated by `VerifyKioskToken` middleware, which resolves the **real** device from the token — the `{kiosk}` URL segment is never trusted for identity). Admins can view/copy/regenerate a kiosk's token from **Admin → Kiosks → Edit** (no direct DB query needed).

### Recognition
The kiosk defaults to **Face Recognition** on load, running a continuous client-side detection loop (face-api.js, 600ms self-scheduling tick — never overlaps itself):

- **IDLE** — looks for an actual detected face; only sends a request to `/recognize` when one is genuinely present (a real 128-value descriptor, not a placeholder).
- **DETECTED** — a match was found and action buttons are shown; the loop keeps a lightweight presence check running, and if the face disappears for ~1.2s, immediately resets to IDLE (finishes the interaction instead of leaving stale state on screen).
- **PROCESSING** — an entry/exit action is in flight; detection is paused entirely.
- **QR_SCAN** — see below.

**Face and QR are switchable at any time**, not gated behind failures:
- An always-visible **"Scan QR Code Instead"** button switches to `QR_SCAN` mode immediately.
- After **3 consecutive failed face-recognition attempts** (genuine non-matches, not network errors), the kiosk **automatically** switches to `QR_SCAN` as a usability aid.
- While in `QR_SCAN`, a **"Back to Face Recognition"** button returns to face mode.
- Both entry points land in the identical `QR_SCAN` implementation — no duplicated logic.

**Identical validation for both paths** (`KioskController::recognize()`): whether a face descriptor or a decoded QR value is submitted, both go through the exact same `VisitorRequest::activeToday()` scope and the exact same `VisitorKioskService::resolveActiveRequest()` call. QR is strictly an alternate *identification* method — it never bypasses any check the face path enforces.

Distinct response types (no more generic "not recognized" for every failure):

| Type | Meaning |
|---|---|
| `face_not_found` / `qr_not_found` | No matching person/code at all |
| `face_found_no_active_request` / `qr_found_no_active_request` | Person identified, but no approved visit is valid today |
| `request_completed` | The visit has already been fully completed — no actions offered |
| `face_match` / `qr_match` (success) | Proceed to action buttons per session state |

### Multi-Day Visit Window
`VisitorRequest::scopeActiveToday()` is the single source of truth for "is this visit valid today," used everywhere a window check is needed:

```
today >= visit_datetime's date
AND today <= (departure_datetime's date, or visit_datetime's date if no departure was set)
```

This correctly covers single-day visits (no departure → defaults to the visit day only), multi-day visits, and overnight spans — a visitor approved from day 1 through day 3 is recognized on day 2, which the previous `whereDate('visit_datetime', today())` logic incorrectly rejected.

### Action Buttons — Strict Session-State Mapping
Regardless of which authentication path produced the match, the buttons shown are driven **only** by `resolveActiveRequest()`'s returned status:

| `session_state.status` | Buttons |
|---|---|
| `no_session` | Enter Farm |
| `Inside` | Temporary Exit, Leave Farm |
| `Outside` | Return to Farm, Leave Farm |
| `request_completed` | *(none)* — "This visit has already been completed." only |

### Entry Lifecycle (`VisitorKioskService::processEntry`)
- **`first_entry`** — creates the visitor's **one and only** `VisitorSession` (guarded by `!$activeSession`) with `session_status = Inside`, logs `First Entry / IN`, appends to the Google Sheet "Time In" tab (best-effort).
- **`temporary_exit`** — updates the existing session to `Outside`, logs `Temporary Exit / OUT`. No new session.
- **`return`** — updates the existing session back to `Inside`, logs `Return / IN`. No new session.
- **`final_exit`** — updates the session to `Completed` (`last_out`, `completed_at`), **and sets `visitor_request.request_status = COMPLETED`**, logs `Final Exit / OUT`, appends to "Time Out" (best-effort).

**Every** `visitor_entry_logs` row now records `authentication_method` (`FACE` or `QR`) — sourced from whichever path the kiosk actually used for that specific action, for audit purposes.

### Completed Requests Cannot Be Reused
This was the core bug: a `Completed` session was previously indistinguishable from "never visited" (the session lookup excluded `Completed` status), so re-scanning after a final exit would silently create a second session under the same approved request. Fixed by checking `visitor_request.isCompleted()` (i.e. `request_status === 'COMPLETED'`) as the **very first** check in `resolveActiveRequest()`, before any session lookup — a completed request now always returns `request_completed` and never falls through to "no session." A new approved `visitor_request` (a fresh sync from AppSheet) is required for another visit.

---

## 6. Google Sheets Sync-Back

Unchanged in mechanism, verified working: `VisitorSheetWriter::appendTimeIn()` / `appendTimeOut()`, called only from `first_entry` and `final_exit` respectively. Column mapping (`Time In!A:G` / `Time Out!A:G`):

| Time In | Time Out |
|---|---|
| Date In | Date Out |
| Time In | Time Out |
| Name | Name |
| Login ID | Logout ID *(same `user_directory.login_id` field)* |
| Visitor ID | Visitor ID |
| Picture (path) | Picture (path) |
| picture_url | picture_url_out |

Failures are caught, logged to `api_logs` (`status_code = 500`), and **never roll back or block** the local `VisitorSession`/`VisitorEntryLog` write — a visitor's physical entry/exit always succeeds locally regardless of Sheets/network availability.

---

## 7. Known Limitations (disclosed, not defects)

- **No liveness/anti-spoof detection.** The client-side-only face-api.js stack can verify "exactly one real-looking face at ≥0.7 confidence," but cannot reliably distinguish a live person from a printed photo, screen replay, or non-human face. True liveness detection would require a dedicated (typically paid, API-based) liveness SDK — out of scope for this pass per explicit product decision. Server-side matching is a pure Euclidean-distance embedding comparison (threshold 0.6), which is standard for face-api.js but is not itself a spoof defense.
- **Frontend build tooling (`npm run build`) is not currently functional** — `vite`/`laravel-vite-plugin` are referenced in `vite.config.js` but are not declared in `package.json`, and `node_modules` doesn't contain a working `vite` binary. This predates this fix pass and is unrelated to the Visitor module; the two now-orphaned entries (`kiosk.js`, `visitor.js`) were removed from `vite.config.js`'s input list as part of cleanup, but the underlying build pipeline gap is a separate, pre-existing issue.

---

## 8. Test Coverage

### Automated (PHPUnit, `php artisan test`)

| File | Covers |
|---|---|
| `tests/Unit/VisitorSyncServiceTest.php` | All 4 directory-identity cases, sync idempotency, unresolvable-farm failure, `middle_name` persistence |
| `tests/Feature/Visitor/VisitorRegistrationTest.php` | New-face registration, duplicate-registration dedup, match-yes linking, match-no biometric-conflict side effects, success-page `PENDING` gate, Option B verify success + 3-strikes |
| `tests/Feature/Visitor/VisitorQrCodeTest.php` | QR PNG generation, access gate (`PENDING` → 404), registered/failed-match access, download headers |
| `tests/Feature/Kiosk/KioskRecognizeTest.php` | face/QR not-found vs found-no-active-request distinction, multi-day window (inside/outside), completed-request block, QR/face validation parity |
| `tests/Feature/Kiosk/KioskEntryTest.php` | `authentication_method` persisted across all 4 movement types, exactly-one-session invariant, final-exit completion block, Sheets-failure non-blocking |
| `tests/Feature/Kiosk/KioskDeviceAdminTest.php` | Token regeneration invalidates the old token, new token authenticates |

**Result at completion: 35 passed, 0 failed** (excluding one pre-existing, unrelated scaffold test — see below). All Sheets calls are mocked (`VisitorSheetWriter` bound to a Mockery double) so the suite never hits the real Google API.

**Pre-existing, out-of-scope failure:** `tests/Feature/ExampleTest.php` asserts `GET /` returns 200, but this app's root route has always redirected to `/login` or `/dashboard` (see `routes/web.php`) — predates this work, unrelated to the Visitor module.

### Manual / Physical QA Checklist (cannot be automated)

- [ ] Real webcam capture in varied lighting — confirm the 0.7 confidence threshold and single-face-count rule feel right, not overly strict or lax.
- [ ] Complete Option A registration end-to-end with a real face; confirm the success page shows a scannable QR.
- [ ] Register the same face twice — confirm "already registered" message, no duplicate profile.
- [ ] Trigger a face-match conflict (register a second request with the same face as an existing different directory) — test both "Yes" and "No" branches, confirm the QR is still reachable after "No."
- [ ] Complete Option B: search by name, verify with a matching face (success) and a non-matching face 3 times (contact-administrator message, capture disabled).
- [ ] Scan the generated QR with an independent phone QR-reader app — confirm the decoded text is exactly the `visitor_id`.
- [ ] At the kiosk: scan a real registered face → confirm correct action buttons per session state (no_session/Inside/Outside).
- [ ] Let 3 real face-recognition attempts fail → confirm auto-switch to QR mode; use the manual "Scan QR Code Instead" button independently as well.
- [ ] Scan a physically printed/displayed QR at the kiosk camera — confirm entry succeeds and `authentication_method = QR` is recorded.
- [ ] Walk a full lifecycle (Enter → Go Outside → Return → Leave Farm) for one visitor; confirm exactly one session, four correctly-typed entry logs, and correct Sheets rows in both "Time In" and "Time Out" tabs (including working `picture_url` links — requires `php artisan storage:link`).
- [ ] Re-scan the same visitor after Final Exit — confirm "already completed" message and no new session is created.
- [ ] Generate a kiosk token via Admin → Kiosks → Edit, copy it, pair a real kiosk device with it; regenerate and confirm the old token stops working immediately.
- [ ] Confirm a multi-day visitor (visit day 1, departure day 3) can be recognized on day 2, not just day 1.

---

## 9. Deployment Notes
- Run `php artisan migrate` for the two new migrations (`face_registration_status`/`manual_verification_required` on `visitor_request`, `authentication_method` on `visitor_entry_logs`).
- Run `composer install` to pull in `endroid/qr-code` (new dependency).
- Existing `face_profile` rows created before the face-api.js fix (earlier this session) store a placeholder 3-value embedding and will never match a real 128-value descriptor again — this is expected; those visitors need to re-register their face.
- `php artisan storage:link` must be run once so kiosk photo `picture_url` values resolve publicly for the Google Sheets columns.
