# CLAUDE.md — Sentry Face Kiosk

> **MANDATORY — READ BEFORE ANY WORK IN THIS REPO.**
> Before answering any question, explaining behavior, or implementing any change (new feature, bug fix, endpoint, business-rule change, refactor, migration) in this repository, you MUST read this file in full first. Use it to identify which module(s) the request touches, then read that module's section and its listed critical files before writing or explaining anything. Do not rely on memory of a previous session's reading of this file — re-read it at the start of every new conversation/session. If the request is trivial (e.g. answering from a single obviously-unrelated file), still confirm against this file's module map that it's actually unrelated before skipping deeper reading.

This file is the primary system/business context for Claude sessions working on this repository. It is derived from the actual current implementation (read on 2026-08-26; the "Facility Master Data" module and its tables were added 2026-08-27 in two same-day phases — Phase 1 built the tables as dormant/parallel data, Phase 2 cut `visitor_request`/`kiosk_device`/Visitor Sync over to depend on them for real — see that module's section for exactly what is and isn't wired in; the "Downtime Matrix PDF Import" module — Phase 1, parse/validate/preview only, no production-table writes — was added 2026-08-27; its Phase 2 (added 2026-08-28) added a real "Save to Production" mapping step that writes verified imports' eligible rows into `downtime_matrix`/`downtime_stationary` (deactivating, never deleting, whatever was previously active) — see that module's section for the full mapping rules; on 2026-08-28, every admin listing under `Admin Management` (Facilities, Facility Aliases, Kiosk Devices, Identity Types, Employee Types, Downtime Matrix, Downtime Stationary, the Downtime Matrix Import *list*, Roles, Users, Audit Logs) was migrated — through two superseded intermediate approaches, both now fully removed — to a real jQuery DataTables.js implementation: an empty Data Table Shell on page load, filter controls that never fire a request on their own, and a Filter-button click that's the sole trigger for the first (and only the first) automatic request to a dedicated per-module `/data` JSON endpoint using DataTables' server-side processing protocol; see the "Admin Management" module's "Admin Data Table Architecture" subsection. Later the same day this was extended to the Downtime Matrix Import *Preview/show* page too — its three category tabs (Farm-to-Farm/Stationary/Others) are each their own filter-gated Data Table sharing one `rows-data` endpoint, filtered by `rule_type`; see the "Downtime Matrix PDF Import" module. Where the implementation was ambiguous or unverifiable from static reading, this is marked `NEEDS VERIFICATION` or `UNKNOWN` rather than guessed.

**Rule for future sessions:** if you discover that code no longer matches this document, trust the code and update this document — do not silently follow stale documentation.

---

## 1. System Overview

**What it is:** "Sentry Face Kiosk" (`composer.json` name: `sentry/face-kiosk`) — a Laravel 12 / PHP 8.2 biosecurity, visitor, and employee tracking system for farm sites, using face recognition and QR codes as the physical entry/exit credential at unmanned tablet kiosks.

**Business purpose:** Farms need to know who is physically on-site at any time (visitors, gate-sale customers, delivery trucks — employee tracking is scaffolded but not yet implemented), enforce that a person is only ever "inside" one farm at a time, keep a legally/operationally auditable log of every entry and exit (with photo), and mirror visitor Time In/Time Out records into a Google Sheet for stakeholders who work outside this system.

**Main users:**
- **Farm-side kiosk operators / visitors themselves** — unattended tablet kiosks in a public/gate area, interacted with via webcam face scan or QR scan. No login.
- **Back-office admin staff** — authenticated web app (`/admin/*`) for managing farms, kiosks, roles/users, reference data, and biosecurity rules.
- **External system: AppSheet** — a Google AppSheet-based approval workflow (upstream of this system) that pushes approved visitor requests via a webhook.

**Major external systems:**
- **AppSheet** (via a Google Apps Script/bot, presumed) — POSTs approved visitor requests into `POST /api/v1/visitor/sync`. This app never calls out to AppSheet; it is push-only, inbound.
- **Google Sheets API** (`google/apiclient`) — this app writes ("Time In"/"Time Out" tabs) visitor movement rows to a spreadsheet for external visibility. One-way write, no reads.
- **Browser-side face recognition**: `face-api.js` and `jsQR.js`, loaded from a CDN directly inside the kiosk Blade view (not bundled, not vendored) — the actual face descriptor math and QR decoding happen client-side in JavaScript; the PHP backend only stores/compares already-computed 128-float descriptors and already-decoded QR strings.

**Main technologies:** Laravel 12, PHP 8.2, Eloquent/MySQL (SQLite for tests/dev via `.env.example`), Blade views + jQuery (`resources/js`) for the admin panel, vanilla JS + CDN `face-api.js`/`jsQR` for the kiosk, `endroid/qr-code` for server-side QR PNG generation, `google/apiclient` for Sheets.

**High-level architecture:**

```text
                              SENTRY FACE KIOSK
                                     │
        ┌───────────────┬───────────┼───────────────┬────────────────┐
        │               │           │                │                │
   VISITOR SYNC   VISITOR REG.   KIOSK ENTRY    KIOSK SELF-SERVICE   ADMIN
  (AppSheet →API) (public token   (Visitor-with- (Gatesale/Truck,   (Facilities, Kiosks,
        │          face/QR reg)   Approval flow)  no pre-approval)   Roles, Users,
        │               │           │                │             Biosecurity, etc.)
        ▼               ▼           ▼                ▼                │
  VisitorSyncService  VisitorRegistrationService  VisitorKioskService │
        │               │           │                │                │
        └───────┬───────┴─────┬─────┴────────┬───────┘                │
                ▼              ▼              ▼                       │
         FaceMatchingService  Session/EntryLog state machine   RolePermissionService
                │                       │                              │
                ▼                       ▼                              ▼
         user_directory /       Google Sheets (Time In/Out)      roles / permissions /
     visitor_profile / face_profile   (VisitorSheetWriter)           users tables
                │
                ▼
     visitor_request → visitor_session → visitor_entry_logs
                                     │
                                     ▼
                    ResolveExpiredVisitorSessions (daily scheduled job)
                                     │
                                     ▼
                          audit_logs (cross-cutting, all models)
```

---

## 2. System Modules

### Module: `Visitor Sync` (AppSheet Ingest)

**Purpose:** Accept a visitor visit that has already been approved in an external AppSheet workflow, and turn it into a `visitor_request` + (if needed) a `user_directory`/`visitor_profile` pair inside this system, generating a `registration_token` the visitor uses to self-register their face.

**Responsibilities:** API-key authentication of the caller; facility-name resolution (fuzzy-but-safe); idempotent visitor-request creation; directory reuse/creation; full API request/response logging.

**Entry Points:**
- `POST /api/v1/visitor/sync` (routes/api.php), behind the `api.key` middleware alias.

**Main Flow:**
```text
AppSheet bot
   ↓
POST /api/v1/visitor/sync  (X-API-KEY header)
   ↓
VerifyApiKey middleware  (constant-comparison against config('sentry.sync_api_key'))
   ↓
VisitorSyncRequest (form validation)
   ↓
VisitorSyncController::store
   ↓
VisitorSyncService::syncApprovedRequest
   ↓
FacilityResolver::resolve (alias → normalized name, resolves against facility_list/facility_aliases)
   ↓
resolveDirectory (reuse-by-name+email, else create UserDirectory + VisitorProfile)
   ↓
VisitorRequest::create (approval_status=Approved, request_status=ACTIVE, registration_token=REG_xxxxxxxx)
   ↓
ApiLog::create (always, success or failure)
   ↓
JSON response {success, message, registration_token, visitor_request}
```

**Data Used:** `visitor_request`, `user_directory`, `visitor_profile`, `facility_list`, `facility_aliases`, `identity_type`, `visitor_type`, `api_logs`.

**Dependencies:**
```text
Visitor Sync
 ├── VerifyApiKey middleware (config('sentry.sync_api_key') / SYNC_API_KEY env)
 ├── FacilityResolver → facility_list, facility_aliases
 ├── IdentityType 'Visitor' row (must exist — no fallback if missing)
 ├── VisitorType 'Visitor' row (must exist — no fallback if missing)
 └── ApiLog (always written, success or failure)
```

**Related Modules:** Feeds `Visitor Registration` (the `registration_token` returned here is what the visitor uses at `/register/visitor?token=...`). Feeds `Kiosk Entry` indirectly (the resulting `visitor_request` is what a kiosk later recognizes via face/QR).

**Business Rules:**
- **Idempotency key is `visitor_id`** (an AppSheet-issued identifier, stored verbatim, never modified/prefixed/normalized). If a `visitor_request` with that `visitor_id` already exists, the sync call returns success with the *existing* `registration_token` — it never creates a duplicate.
- **Directory reuse requires BOTH `full_name` AND `email` to match** (case-insensitive, trimmed) an existing `user_directory` row. Any partial match (same email, different name, etc.) always creates a **new** directory — true duplicate people are only resolved later, by the face-match confirmation workflow in Visitor Registration, never here.
- `visitor_type_id` is only ever set on a **newly created** directory's `visitor_profile`; an existing/reused directory's profile is never modified by a sync call (so an admin's manual correction is never silently overwritten).
- Facility resolution (via `FacilityResolver`, renamed from `FarmResolver` on 2026-08-27 — see the "Facility Master Data" module): exact alias match → case-insensitive alias match → exact normalized facility-name match (strips a leading "FARM"/"FARMS " prefix only if followed by 2+ word chars, so "FARM A" is preserved but "FARM ALPHA" → "ALPHA" — this text-normalization quirk is unchanged from the farm-only era, since AppSheet still only ever sends farm names today). **No fuzzy/LIKE matching** — a genuinely new facility-name spelling must get a `facility_aliases` row added (no admin UI for this yet — see Known Constraints), or the sync fails with "Facility not found."
- `visit_datetime`/`departure_datetime` are parsed with `Carbon::parse()` explicitly (not the model's datetime cast) to tolerate AppSheet's inconsistent US-style date padding.

**Status Lifecycle:** A freshly synced request is always `approval_status = 'Approved'`, `request_status = 'ACTIVE'` — approval already happened upstream in AppSheet; this system does not have its own approval step for this flow.

**Error Handling:** Missing/invalid `X-API-KEY` → 401 before any business logic runs. Missing facility/alias, missing `IdentityType`/`VisitorType` seed rows → `{success:false, message}` with HTTP 400, still logged to `api_logs`. All requests (success and failure) are recorded to `api_logs` with the full request payload and response body.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Route | `routes/api.php` | `POST /v1/visitor/sync` under `api.key` middleware |
| Middleware | `app/Http/Middleware/VerifyApiKey.php` | `X-API-KEY` check against `SYNC_API_KEY` |
| Request | `app/Http/Requests/Api/VisitorSyncRequest.php` | Field validation |
| Controller | `app/Http/Controllers/Api/VisitorSyncController.php` | Orchestrates service + `ApiLog` |
| Service | `app/Services/VisitorSyncService.php` | Core business logic |
| Service | `app/Services/FacilityResolver.php` | Facility-name → `FacilityList` resolution (renamed from `FarmResolver` on 2026-08-27) |
| Model | `app/Models/ApiLog.php` | Request/response audit trail for this endpoint |

**Configuration:** `SYNC_API_KEY` env → `config('sentry.sync_api_key')`.

**Known Constraints:** Single shared static API key (no per-caller keys/rotation). No signature/HMAC verification, no replay protection beyond the `visitor_id` idempotency check. No rate limiting configured on this route. The inbound payload's field name is still the literal string `farm` (`$data['farm']`) — this is AppSheet's external contract and was deliberately left unrenamed during the 2026-08-27 cutover; only the internal resolution target (`facility_list`/`facility_aliases`) changed.

**Important Notes for Future Changes:** Never weaken the directory-reuse match (full_name + email) — it's a deliberate anti-merge safeguard; loosening it would let two different people be silently treated as one directory. Do not add fuzzy facility-name matching without discussing — it was explicitly rejected as unsafe (comment in `FacilityResolver`).

---

### Module: `Visitor Registration` (Public Face/QR Self-Registration)

**Purpose:** Give a newly-synced visitor (holding only a `registration_token` link, e.g. from an SMS/email) a public, unauthenticated flow to register their face against their `visitor_request`, so the kiosk can recognize them later. Also resolves the "I already exist in the system" case via two alternate paths (Option A / Option B below).

**Responsibilities:** Token-gated public pages; face descriptor capture and storage; duplicate-face detection with a user confirmation step; a fallback name-search + face-reverify path; QR code generation for the visitor's badge.

**Entry Points (all public, `routes/web.php`, no auth):**
- `GET /register/visitor?token=...` — landing/registration page.
- `GET /register/visitor/search?token=...` and `GET /register/visitor/search/query?q=...` — Option B name search.
- `GET /register/visitor/capture?token=...` (page) / `POST /register/visitor/capture` — Option A: submit a face descriptor.
- `POST /register/visitor/verify` — Option B: re-verify a face against a **specific** chosen directory.
- `POST /register/visitor/confirm` — Option A: confirm/deny "is this you?" when a face already matches a different directory.
- `GET /register/visitor/success?token=...` — final confirmation page (shows the QR).
- `GET /register/visitor/qr?token=...[&download=1]` — streams a PNG QR code.

**Main Flow (Option A — the primary path):**
```text
Visitor opens link with token
   ↓
RegistrationController::show → validates token maps to an Approved visitor_request
   ↓
showCapture → webcam UI (face-api.js client-side)
   ↓
POST captureFace {token, descriptor[128 floats], face_image}
   ↓
VisitorRegistrationService::completeFaceRegistrationOptionA
   ├── FaceMatchingService::findMatch(descriptor)  [no directory filter]
   ├── if match belongs to the SAME directory  → already_registered (no duplicate row)
   ├── if match belongs to a DIFFERENT directory → face_found_different_directory
   │        ↓ visitor answers "is this you?"
   │     POST confirmMatch {confirmed: true|false}
   │        confirmed=true  → VisitorRequest.directory_id is REPOINTED to the matched directory,
   │                           face_registration_status=REGISTERED
   │        confirmed=false → markManualVerificationRequired (FAILED_MATCH, manual_verification_required=true)
   └── if no match anywhere → create a new FaceProfile for this directory, face_registration_status=REGISTERED
   ↓
success page → GET /register/visitor/qr (PNG encoding visitor_id)
```

**Main Flow (Option B — fallback via name search, used when face capture repeatedly fails or the visitor prefers to search):**
```text
showSearch → searchName (typeahead over active user_directory by full_name)
   ↓
visitor picks a candidate directory_id
   ↓
POST verifyFace {directory_id, descriptor, attempt}
   ↓
VisitorRegistrationService::verifyFaceOptionB
   ├── FaceMatchingService::findMatch(descriptor, onlyDirectoryId=selected)  — scoped match, must match THIS directory
   ├── match found  → visitor_request.directory_id = selected, face_registration_status=REGISTERED
   ├── no match, attempt < 3 → face_not_found (client retries, increments attempt)
   └── no match, attempt >= 3 → markManualVerificationRequired (FAILED_MATCH)
```

**Data Used:** `visitor_request` (looked up by `registration_token`, never by ID directly from client input), `user_directory`, `face_profile`, `visitor_profile`.

**Dependencies:**
```text
Visitor Registration
 ├── FaceMatchingService (shared with Kiosk modules)
 ├── VisitorQrCodeService (endroid/qr-code) → QR PNG generation
 └── Storage::disk('public') → face-photos/{visitor_request_id}/*.jpg
```

**Related Modules:** Downstream of `Visitor Sync` (needs a valid `registration_token`). Once `face_registration_status = 'REGISTERED'`, the same directory becomes recognizable by `Kiosk Entry`. Shares `FaceMatchingService` with both Kiosk modules — a face registered here is the same face a kiosk will later match against.

**Business Rules:**
- Every lookup in this module is by `registration_token` + `approval_status = 'Approved'` — never by a bare `visitor_request_id` from client input, so a token is the sole capability grant.
- **Terminal failure path (both Option A "No, not me" and Option B's 3rd failed attempt) never creates or links a face profile.** It sets `face_registration_status = FAILED_MATCH` and `manual_verification_required = true` so an admin must resolve it. The visitor is told they may still use their (separately generated) QR code — QR is treated as an independent credential from face, never blocked by a face conflict.
- `success` page redirects back to the registration page if `face_registration_status` is still `PENDING` (registration not actually completed yet).
- QR PNG always encodes `visitor_request.visitor_id` **exactly** — this must never diverge from what the kiosk's QR scanner path expects (`VisitorQrCodeService` docblock is explicit about this single-source-of-truth constraint). Note: `visitor_request.qr_url` (received from AppSheet at sync time) is stored on the row but is **not** what's rendered/downloaded on the success page — the locally generated PNG (encoding `visitor_id`) is. `NEEDS VERIFICATION`: whether anything else in the system (or AppSheet itself) still relies on the stored `qr_url` value.

**Status Lifecycle (`visitor_request.face_registration_status`):**
```text
PENDING → REGISTERED                (successful capture/verify/confirm)
PENDING → FAILED_MATCH              (declined match, or 3rd failed Option-B attempt) — manual_verification_required=true
```

**Error Handling:** Invalid/missing token → 400 JSON or an error-carrying Blade view (route-dependent). QR generation failures are logged (`\Log::error`) with full context and return a 500 with an explicit "logged for investigation" message rather than a silent blank image.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Controller | `app/Http/Controllers/Visitor/RegistrationController.php` | All public registration endpoints |
| Service | `app/Services/VisitorRegistrationService.php` | Option A/B business logic |
| Service | `app/Services/Face/FaceMatchingService.php` | Shared descriptor matching |
| Service | `app/Services/Qr/VisitorQrCodeService.php` | QR PNG rendering |
| Views | `resources/views/visitor/{register,capture,search,success}.blade.php` | Public UI (face-api.js/jsQR loaded client-side) |

**Configuration:** None module-specific beyond shared `filesystems` (public disk) and app URL for `Storage::url()`.

**Known Constraints:** Face matching here is a **linear scan of all active `face_profile` rows** with no directory filter for Option A's initial check — see Known Constraints under Face Matching below; this is the module most exposed to that cost since it runs on every registration attempt.

**Important Notes for Future Changes:** Do not let Option A/B create a `face_profile` before confirming there's no existing match — the current ordering (match-check first, create second) is what prevents duplicate face rows for the same person. Do not change the QR payload to anything other than the raw `visitor_id` without also updating the kiosk's QR decode expectations.

---

### Module: `Kiosk Entry` (Visitor-with-Approval session lifecycle)

**Purpose:** The core "am I inside or outside the farm" state machine for a pre-approved visitor (synced via AppSheet, registered via the module above). Runs entirely on an unattended tablet at a farm gate.

**Responsibilities:** Kiosk device authentication; face/QR recognition against active, farm-correct visitor requests; session state transitions with photo capture at every transition; triggering Google Sheets writes on first entry and final exit.

**Entry Points:**
- `GET /kiosk/{kiosk}` — public page load (no token needed to *view*; see Middleware below).
- `GET /kiosk/{kiosk}/verify-token` — lightweight pre-flight check, behind `kiosk.auth`.
- `POST /kiosk/{kiosk}/recognize` — face or QR recognition, behind `kiosk.auth`.
- `POST /kiosk/{kiosk}/entry` — state-changing action (`first_entry`/`temporary_exit`/`return`/`final_exit`), behind `kiosk.auth`.

**Main Flow:**
```text
Kiosk tablet loads /kiosk/{kiosk}
   ↓
JS captures X-KIOSK-TOKEN from device config, calls /verify-token then starts webcam loop
   ↓
VerifyKioskToken middleware  (X-KIOSK-TOKEN header → KioskDevice lookup, attaches to request)
   ↓
POST /recognize {descriptor} or {qr_value}
   ↓
KioskController::recognize
   ├── FaceMatchingService::findMatch (unscoped) OR direct visitor_id lookup for QR
   ├── routeByIdentity() — Employee → placeholder; non-Visitor → unsupported;
   │                        Gatesale/Truck → handleSelfServiceRecognition() (separate module below);
   │                        plain Visitor → continue below
   ├── pickBestActiveRequest() — priority: non-completed > correct farm > most recent
   ├── buildRecognitionResponse()
   │     ├── farm mismatch  → 403 wrong_farm (names the visitor's actual approved farm)
   │     ├── isCompleted()  → 409 request_completed
   │     └── else           → 200 {session_state, directory}
   ↓
Kiosk UI shows the appropriate action button(s)
   ↓
POST /entry {visitor_request_id, action, photo?, authentication_method}
   ↓
VisitorKioskService::processEntry — see state machine below
```

**Detailed Process Flow — `processEntry` state machine:**

1. **Trigger:** `POST /kiosk/{kiosk}/entry`.
2. **Input:** `visitor_request_id`, `action` (`first_entry`/`temporary_exit`/`return`/`final_exit`), optional base64 `photo`, `authentication_method` (`FACE`|`QR`, defaults `FACE`).
3. **Validation:** request must exist; must not already be `isCompleted()` (COMPLETED/COMPLETED_AUTO/INCOMPLETE); `facility_id` must match the kiosk's own facility (defense-in-depth, duplicating the `recognize`-time check; column renamed from `farm_id` on 2026-08-27, see "Facility Master Data").
4. **first_entry** (only when no active session exists): creates `visitor_session` (`session_status=Inside`, fresh `login_id`), creates a `visitor_entry_logs` row (`movement_type=First Entry`, `action=IN`), then (unless excluded — see Business Rules) calls `VisitorSheetWriter::appendTimeIn` — failures here are caught and logged, **never** roll back the DB transaction.
5. **temporary_exit** (requires current status `Inside`): session → `Outside`, entry log `Temporary Exit`/`OUT`. No Sheets write.
6. **return** (requires current status `Outside`): session → `Inside`, entry log `Return`/`IN`. No Sheets write.
7. **final_exit**: session → `Completed` with a fresh `logout_id` (independently generated, never assumed to equal `login_id`) and `last_out`/`completed_at`; `visitor_request.request_status → COMPLETED`; entry log `Final Exit`/`OUT`; Sheets `appendTimeOut` (subject to the same exclusion rule).
8. **Photo:** stored on every transition (`storePhoto`) to `storage/app/public/kiosk-photos/{visitor_request_id}/{action}-{uniqid}.jpg` if a base64 photo was supplied; failures are logged and treated as non-fatal (`photo` column left null).
9. **Final response:** `{success, session_status, message}`.

**Data Used:** `visitor_request`, `visitor_session`, `visitor_entry_logs`, `kiosk_device`, `facility_list` (both `visitor_request.facility_id` and `kiosk_device.facility_id` reference `facility_list.facility_id`, not `farm_list` — see "Facility Master Data").

**Dependencies:**
```text
Kiosk Entry
 ├── VerifyKioskToken middleware → kiosk_device.kiosk_token
 ├── FaceMatchingService (shared)
 ├── VisitorSheetWriter (Google Sheets — optional/best-effort)
 └── Storage::disk('public') → kiosk-photos/*
```

**Related Modules:** Shares `pickBestActiveRequest`/`buildRecognitionResponse`/`processEntry` with `Kiosk Self-Service` (Gatesale/Truck reuse `processEntry` for their own first-entry). Feeds the `Google Sheets Integration` module. Feeds `Session Auto-Resolution` (any session left dangling here is what that scheduled job cleans up).

**Business Rules:**
- **A completed request (`isCompleted()` = COMPLETED / COMPLETED_AUTO / INCOMPLETE) is a hard terminal state** — never reused, always requires a brand-new approved request to visit again.
- **Farm binding is enforced twice**: once at `/recognize` (shows the correct "wrong farm" message) and again inside `processEntry` itself (defense-in-depth so no other code path can bypass it). Both checks compare `facility_id` (not `farm_id`, renamed 2026-08-27) — the business rule and the "wrong farm" wording visitors/admins see are unchanged, only the underlying column/table is now `facility_list` instead of `farm_list`.
- `pickBestActiveRequest` ordering is intentional and load-bearing: non-completed requests outrank completed ones, which outrank a plain "most recent" tiebreak — this exists specifically so a stale COMPLETED request for the kiosk's own farm never masks a genuinely active request at a *different* farm (the correct message must be "wrong farm," not "already completed").
- `authentication_method` accepts only the literal strings `FACE` or `QR`; anything else silently defaults to `FACE`.
- Google Sheets writes for `first_entry`/`final_exit` are **best-effort and non-blocking** — a Sheets failure never fails the kiosk transaction; it's only logged (`\Log::error`).

**Status Lifecycle:**
```text
VISITOR_REQUEST.request_status:      ACTIVE → COMPLETED (manual final_exit)
                                      ACTIVE → COMPLETED_AUTO / INCOMPLETE (scheduled job, see below)

VISITOR_SESSION.session_status:      (none) → Inside → Outside ⇄ Inside → Completed
                                                   Inside/Outside → INCOMPLETE or COMPLETED_AUTO (scheduled job)
```

**Error Handling:** Missing kiosk header → 401 (middleware level). Missing `visitor_request_id`/`action` → 400. Unknown request/terminal request/invalid action-for-current-status → `{success:false, message}` with 400. Photo storage and Sheets-write failures are caught individually and logged; they never bubble up as an error response to the kiosk.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Middleware | `app/Http/Middleware/VerifyKioskToken.php` | `X-KIOSK-TOKEN` → `KioskDevice` resolution |
| Controller | `app/Http/Controllers/Kiosk/KioskController.php` | `recognize`, `entry`, identity routing, self-service (shared file) |
| Service | `app/Services/Kiosk/VisitorKioskService.php` | Session state machine |
| Model | `app/Models/VisitorRequest.php` | `scopeActiveToday`, `isCompleted`, `isExcludedFromGoogleSheets` |
| Model | `app/Models/VisitorSession.php` | `generateSessionCode` (login_id/logout_id) |
| View | `resources/views/kiosk/show.blade.php` | Full kiosk UI + inline face-api.js/jsQR JS (CDN-loaded) |

**Configuration:** None beyond kiosk-token generation (`KioskDevice::generateKioskToken`, format `KIOSK_<32 random upper-case chars>`, retried up to 3 times for uniqueness).

**Known Constraints:** No offline mode — kiosk JS depends on a CDN for `face-api.js`/`jsQR` and on a live connection to the Laravel backend for every recognize/entry call. `authentication_method` and `photo` are entirely client-trusted inputs (no server-side check that a photo was actually taken by *this* recognition event).

**Important Notes for Future Changes:** Never bypass `isCompleted()` as the terminal-state check — a stale/direct request being reusable would let a person log a second, ghost visit. `pickBestActiveRequest`'s ordering is the fix for a real reported bug (see comment in `KioskController`) — do not simplify it back to "most recent only."

---

### Module: `Kiosk Self-Service` (Gatesale & Truck)

**Purpose:** Let two specific visitor types — **Gatesale** (walk-in customers buying directly at the farm gate) and **Truck** (delivery/pickup drivers) — register themselves and start a visit **entirely at the kiosk**, with no upstream AppSheet approval and no separate registration link. Both types share one implementation; Truck differs only by requiring/displaying a Plate No.

**Responsibilities:** Self-identification (new registration or face-match against an existing self-service directory); enforcing "one active visit per person across ALL farms, at any time"; visit detail capture (Host/Origin/Purpose) at the kiosk itself; excluding this traffic from Google Sheets entirely.

**Entry Points (all behind `kiosk.auth`):**
- `POST /kiosk/{kiosk}/recognize` — same endpoint as Kiosk Entry; a Gatesale/Truck face match is intercepted and rerouted (see `routeByIdentity`).
- `POST /kiosk/{kiosk}/gatesale/update-details` — edit an existing self-service directory's own fields.
- `POST /kiosk/{kiosk}/gatesale/create-visit` — create (or resume) the visit itself.
- `POST /kiosk/{kiosk}/gatesale/register-identity` — first-time registration (new face) OR re-routes to a match if the face already exists.

**Main Flow:**
```text
Kiosk recognize (face) → routeByIdentity() detects visitor_type ∈ {Gatesale, Truck}
   ↓
handleSelfServiceRecognition()
   ├── an ACTIVE request already exists for this directory, THIS farm  → resume directly (same shape as Kiosk Entry's "Go Out")
   ├── an ACTIVE request exists at a DIFFERENT farm                    → 403 gatesale_active_elsewhere (must finish that visit first)
   └── no ACTIVE request anywhere                                      → gatesale_confirm_identity ("Is this you?")
                                                                              ↓
                                                            (optional) gatesale/update-details — edit own fields
                                                                              ↓
                                                            POST gatesale/create-visit {directory_id, host_name, origin, purpose, photo}
                                                                              ↓
                                                            createGatesaleVisit() — see concurrency section below
                                                                              ↓
                                                            VisitorKioskService::processEntry('first_entry')  [only if newly created]
```

**Brand-new person (no face match at all):**
```text
POST gatesale/register-identity {visitor_type: Gatesale|Truck, full_name, company, descriptor, email?, phone?, plate_no?}
   ↓
FaceMatchingService::findMatch (unscoped, re-checked to avoid a duplicate on a flaky retry)
   ├── match found + is a Gatesale/Truck directory  → handleSelfServiceRecognition() (as above — do not create a duplicate)
   ├── match found + NOT Gatesale/Truck              → 422 identity_not_supported (no conversion, no duplicate profile)
   └── no match  → DB::transaction: UserDirectory::create + VisitorProfile::create(visitor_type, company, plate_no if Truck) + FaceProfile::create
                        ↓
                  returns directory payload (registration only — no visit/session/entry_log yet)
```

**Data Used:** `user_directory`, `visitor_profile` (`visitor_type_id`, `company`, `plate_no`), `face_profile`, `visitor_request` (`origin` column is Gatesale/Truck/general-purpose, but `registration_token` is left `null` for self-service requests since there's no registration link), `facility_list` (via `visitor_request.facility_id`/`kiosk_device.facility_id`, renamed from `farm_id`/`farm_list` on 2026-08-27), cache locks (`cache_locks` table, `CACHE_STORE=database`).

**Dependencies:**
```text
Kiosk Self-Service
 ├── Shared with Kiosk Entry: FaceMatchingService, VisitorKioskService::processEntry, buildRecognitionResponse, pickBestActiveRequest
 ├── Cache::lock() (database-backed) — concurrency guard, keyed by directory_id only
 └── VisitorType 'Gatesale' / 'Truck' rows (NOT seeded anywhere in database/seeders — see Known Constraints)
```

**Related Modules:** Reuses `VisitorKioskService::processEntry` from Kiosk Entry for the actual first-entry bookkeeping. Explicitly **excluded** from `Google Sheets Integration` (`VisitorRequest::isExcludedFromGoogleSheets()`). Also excluded from Sheets writes inside `Session Auto-Resolution`.

**Business Rules:**
- **One active Gatesale/Truck request per directory, across ALL farms, with no date scoping** — `ACTIVE` status alone is authoritative ("currently ongoing"); the scheduled job is solely responsible for closing out anything stale. This is different from the Visitor-with-Approval farm-matching rule, which is scoped by "today."
- **Concurrency:** guarded by a two-layer defense — `Cache::lock("gatesale-visit-{directory_id}", 30)->block(10, ...)` (closes the true insert-race gap a row lock alone can't cover, since two concurrent calls could both see zero existing rows) wrapping a `DB::transaction` with `lockForUpdate()` as a second layer. The lock key is **directory only** (not directory+farm), deliberately, so two kiosks at different farms racing for the same person also serialize correctly.
- `first_entry` is only triggered for a request this call **just created** (`wasRecentlyCreated`) — resuming an already-ACTIVE request must never create a second entry log.
- Gatesale visits are **fixed same-day**: `departure_datetime` is set to `23:00:00` on the visit date at creation time (not left null), specifically so the expired-session scheduler's effective deadline is 23:00 rather than the generic end-of-day fallback.
- Host Name/Origin/Purpose are required **only** when actually creating a new request (not when resuming an existing ACTIVE one).
- Plate No. is required (and editable) **only** for Truck; Gatesale ignores it entirely and it is always stored as `null`.
- `resolveSelfServiceDirectoryOrFail` never trusts a client-supplied `directory_id` — it re-validates `is_active`, identity type = Visitor, and visitor type ∈ {Gatesale, Truck} on every call across all three self-service endpoints.
- `update-details` can only ever change the visitor's own contact/profile fields (`full_name`, `email`, `phone`, `company`, `plate_no` for Truck) — never `directory_id`, `identity_type_id`, or `visitor_type_id`. It never creates a request/session on its own.

**Status Lifecycle:** Same `request_status`/`session_status` values as Kiosk Entry (ACTIVE → COMPLETED / COMPLETED_AUTO / INCOMPLETE via the scheduled job).

**Error Handling:** `resolveSelfServiceDirectoryOrFail` aborts 422 on any identity mismatch. `createGatesaleVisit` aborts 403 if an active request exists at a different farm (checked again inside the locked transaction, not just at recognize time). Missing Host/Origin/Purpose for a genuinely new visit → 422.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Controller | `app/Http/Controllers/Kiosk/KioskController.php` | `gatesaleUpdateDetails`, `gatesaleCreateVisit`, `gatesaleRegisterIdentity`, `routeByIdentity`, `handleSelfServiceRecognition`, `createGatesaleVisit` (same file as Kiosk Entry) |
| Model | `app/Models/VisitorProfile.php` | `visitor_type_id`/`company`/`plate_no` — sole source of truth for this data |
| Model | `app/Models/VisitorRequest.php` | `isGatesale()`, `isTruck()`, `isExcludedFromGoogleSheets()` |

**Configuration:** None specific; relies on the same `CACHE_STORE=database` used app-wide.

**Known Constraints:** **`VisitorType` rows for "Gatesale" and "Truck" (and "Visitor") are not created by any seeder** (`database/seeders/` has no `VisitorTypeSeeder`) and there is **no admin CRUD controller for `VisitorType`** either (unlike `IdentityType`/`EmployeeType`, which both have full admin UIs). `NEEDS VERIFICATION`: how these rows are provisioned in a real deployment — likely manual DB insert or `tinker`, since tests create them ad hoc (`VisitorType::create(['visitor_type_name' => 'Gatesale'])`). This is a real operational gap worth flagging if asked to touch onboarding/setup.

**Important Notes for Future Changes:** Do not scope the "one active request" check by farm or date — it is deliberately global-and-status-only. Do not change the `Cache::lock()` key to include farm — that would reopen the cross-farm race it was added to close. If you add a new self-service visitor type, it must flow through `handleSelfServiceRecognition`/`resolveSelfServiceDirectoryOrFail`, not a parallel implementation.

---

### Module: `Face Matching` (shared service)

**Purpose:** Single shared implementation of "does this face descriptor match a known person," used by Visitor Registration, Kiosk Entry, and Kiosk Self-Service alike, so matching logic/threshold never drifts between call sites.

**Responsibilities:** Compare a client-supplied 128-float face descriptor against stored `face_profile.embedding` rows (optionally scoped to one directory) using Euclidean distance.

**Entry Points:** Not HTTP-facing — a plain service class (`App\Services\Face\FaceMatchingService::findMatch(array $descriptor, ?int $onlyDirectoryId = null, float $threshold = 0.6)`), injected into the controllers/services listed above.

**Main Flow:**
```text
findMatch(descriptor, onlyDirectoryId?, threshold=0.6)
   ↓
FaceProfile::where('is_active', true) [+ where directory_id = onlyDirectoryId if given]
   ↓
for each stored profile: skip if embedding length mismatches descriptor length
   ↓
Euclidean distance (plain PHP loop, not a vector library)
   ↓
first profile with distance <= threshold wins (no "best of all" ranking — first hit under threshold returns immediately)
```

**Data Used:** `face_profile` (`embedding` JSON-cast array, `is_active`).

**Dependencies:** None external — pure PHP/Eloquent.

**Related Modules:** Consumed by Visitor Registration, Kiosk Entry, Kiosk Self-Service.

**Business Rules:** Threshold is a fixed default `0.6` (the `face-api.js` convention for 128-D descriptors) — callable with a different threshold but nothing in the codebase currently overrides it. Returns `null` immediately for a non-array/empty descriptor, or for any stored embedding whose length doesn't match the incoming descriptor's length.

**Known Constraints:** **Linear scan over every active `face_profile` row, per call, in PHP** — no vector index/ANN, no DB-side distance computation. Acceptable at current data scale (per prior implementation notes) but will degrade linearly as the visitor directory grows. Any future change here should consider this before adding more call sites that invoke it per-frame or per-second.

**Important Notes for Future Changes:** Do not duplicate this matching logic anywhere else. If matching quality/threshold ever needs to change, change it here — every module inherits the update automatically.

---

### Module: `Google Sheets Integration` (Visitor Time In/Out write-back)

**Purpose:** Mirror visitor movement (first entry, final exit) into an external Google Sheet for stakeholders who don't have access to this system, per farm-operations requirements.

**Responsibilities:** Format and append rows to two tabs ("Time In", "Time Out") of one configured spreadsheet; log every attempt (success or failure) to `api_logs`; never let a Sheets outage affect the kiosk transaction that triggered it.

**Entry Points:** Not HTTP-facing — called internally from `VisitorKioskService::processEntry` (first_entry/final_exit) and from `ResolveExpiredVisitorSessions` (recovered final-exit only).

**Main Flow:**
```text
VisitorSheetWriter::appendTimeIn(VisitorEntryLog) / appendTimeOut(VisitorEntryLog)
   ↓
Load session → visitorRequest → directory (via relations on the passed-in log)
   ↓
Build row: [Date, Time, Full Name, SNTRY-prefixed Login/Logout ID, visitor_id, photo path, public photo URL]
   ↓
GoogleSheetsClient::appendRow(spreadsheetId, "Time In!A:G" | "Time Out!A:G", row)
   ↓
Google\Service\Sheets values.append (USER_ENTERED), wrapped in retry(3, ..., 1000ms backoff)
   ↓
ApiLog::create (success or failure, always)
```

**Data Used:** Reads `visitor_entry_logs` → `visitor_session` → `visitor_request` → `user_directory`; writes only to the external Google Sheet (no local table is the destination). Failure/success metadata is written to `api_logs`.

**Dependencies:**
```text
Google Sheets Integration
 ├── google/apiclient (Google\Client, Google\Service\Sheets)
 ├── credentials/service-account.json (gitignored; present on the local checkout as of this writing)
 ├── SENTRY_VISITORS_ID env → the target spreadsheet ID
 └── Storage::disk('public')->url() → public photo URL column (requires FILESYSTEM_DISK/public disk correctly configured with APP_URL)
```

**Related Modules:** Called by `Kiosk Entry` and `Session Auto-Resolution`. **Never called** for `Kiosk Self-Service` (Gatesale/Truck) traffic — see `VisitorRequest::isExcludedFromGoogleSheets()`.

**Business Rules:**
- **Gatesale and Truck visits must never trigger a Sheets write**, enforced by checking `isExcludedFromGoogleSheets()` at every call site (not by adding a parameter to `VisitorSheetWriter` itself — the exclusion lives on the model, checked by the caller).
- Login ID / Logout ID are written with a mandatory `SNTRY-` prefix (idempotent — won't double-prefix if already present); the underlying DB columns do **not** carry this prefix themselves.
- Date format `n/d/Y` (month never zero-padded, e.g. `8/06/2026`); time format `H:i:s` (24-hour).
- Photo URL must come from `Storage::disk('public')->url()` explicitly — calling `Storage::url()` without specifying the disk resolves against the `local` disk (which has no configured public URL) and silently produces a broken relative path. This was a real bug fixed in this codebase; do not regress it.
- **Never blocks or rolls back the triggering kiosk transaction.** Every write is wrapped in try/catch at the call site; failures are logged to both `\Log::error` and `api_logs`.

**Error Handling:** 3 retries (1s apart) at the Google API call level (`retry()` helper). Any remaining failure (auth, network, quota) is caught, logged, and recorded to `api_logs` with `status_code = 500` — the visitor-facing kiosk response is unaffected either way.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Service | `app/Services/GoogleSheets/GoogleSheetsClient.php` | Thin Sheets API wrapper + retry |
| Service | `app/Services/GoogleSheets/VisitorSheetWriter.php` | Row formatting, exclusion checks, `ApiLog` |
| Provider | `app/Providers/AppServiceProvider.php` | **Explicit singleton bindings** for both classes — required (see note below) |

**Configuration:**

| Variable | Purpose | Module | Used By |
|---|---|---|---|
| `SENTRY_VISITORS_ID` | Target spreadsheet ID | Google Sheets | `config('sentry.google.visitors_spreadsheet_id')` |
| (file) `credentials/service-account.json` | Google service-account credentials | Google Sheets | `config('sentry.google.credentials_path')` |

**Known Constraints:** Google Sheets API rate limits apply (not otherwise mitigated beyond the 3-retry backoff) — a burst of kiosk activity could hit quota; failures degrade gracefully (logged, not fatal) but rows would be missing from the sheet until manually reconciled. No batching — one API call per entry/exit event.

**Important Notes for Future Changes:** `AppServiceProvider::register()` **explicitly binds `GoogleSheetsClient`/`VisitorSheetWriter` as singletons** — this is not incidental. `VisitorKioskService` takes `?VisitorSheetWriter $sheetWriter = null` as a constructor default; Laravel's container returns that default verbatim (skipping resolution entirely) unless an explicit binding exists, which would silently disable all Sheets writes with no error and no log line. **Do not remove that binding.** Do not add a new caller of `VisitorSheetWriter` without also checking `isExcludedFromGoogleSheets()` first.

---

### Module: `Session Auto-Resolution` (scheduled job)

**Purpose:** Clean up visitor sessions where the approved visit window has passed but the visitor never completed a proper "Final Exit" at the kiosk (forgot, tablet died, etc.), so `ACTIVE` requests don't pile up forever and block legitimate future visits.

**Responsibilities:** Daily scan of all `ACTIVE` visitor requests; resolve each to a terminal state based on its last known session/entry-log state; recover a real exit time where possible; never fabricate data that wasn't actually observed.

**Entry Points:**
- `php artisan visitor:resolve-expired-sessions` — scheduled `dailyAt('00:00')` in `routes/console.php`.

**Main Flow:**
```text
ResolveExpiredVisitorSessions::handle
   ↓
VisitorRequest::where('request_status', 'ACTIVE')->chunkById(100, ...)   [idempotent: only ACTIVE is ever selected]
   ↓
for each request: deadline = departure_datetime ?? visit_datetime->endOfDay()
   ↓
if now() <= deadline → skip (not expired yet)
   ↓
find latest session in ['Inside','Outside'] for this request
   ├── no session at all                 → markIncomplete (NO_SESSION)
   ├── session_status = 'Inside'         → markIncomplete (INSIDE_EXPIRED)  — forgot to Leave Farm
   └── session_status = 'Outside'
         ├── no OUT entry log found      → markIncomplete (OUTSIDE_NO_OUT_LOG) — never fabricate a last_out
         └── OUT entry log found         → markCompletedAuto — recovers last_out from that real log,
                                             generates a fresh logout_id, writes to Sheets (if not excluded)
```

**Data Used:** `visitor_request`, `visitor_session`, `visitor_entry_logs` (read-only lookup of the latest real `OUT` log to recover `last_out`).

**Dependencies:** `VisitorSheetWriter` (only for the `COMPLETED_AUTO` / recovered-exit case; `INCOMPLETE` never writes to Sheets since no genuine exit was observed).

**Related Modules:** Cleans up state left behind by `Kiosk Entry` and `Kiosk Self-Service`. Its `markIncomplete`/`markCompletedAuto` mirror the manual `final_exit` logic in `VisitorKioskService` closely enough that a future refactor should consider whether they should share more code — currently they are independent implementations.

**Business Rules:**
- **Idempotent by construction**: filtering to `request_status = ACTIVE` means a request already resolved (to `INCOMPLETE`/`COMPLETED_AUTO`, or manually `COMPLETED`) is never reselected on a subsequent run.
- **Never fabricates data**: `first_in`/`last_out` are left untouched unless a real, previously-logged `OUT` entry exists to recover from.
- If duplicate/historical session rows exist for one request, always resolves the **most recently created** one (`latest('visitor_session_id')`).
- Gatesale/Truck requests are resolved through the exact same logic but (like all their Sheets interactions) never trigger a Sheets write, even on `COMPLETED_AUTO`.
- Once a request/session is auto-resolved, it is a **terminal state** — `VisitorKioskService::processEntry`'s `isCompleted()` check (not any date-window check) is what actually prevents a resolved request from being reused via a direct `/entry` call.

**Status Lifecycle:**
```text
ACTIVE + Inside/no-session/Outside-no-log  → INCOMPLETE  (no Sheets write)
ACTIVE + Outside with a real OUT log        → COMPLETED_AUTO  (Sheets write, unless excluded)
```

**Error Handling:** Sheets write failures inside `markCompletedAuto` are caught and logged (`Log::error`), never blocking the DB state transition (which already committed inside its own `DB::transaction` before the Sheets call is attempted). All resolutions are logged via `Log::info` with full before/after state for audit purposes.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Command | `app/Console/Commands/ResolveExpiredVisitorSessions.php` | Entire module |
| Schedule | `routes/console.php` | `dailyAt('00:00')` registration |

**Configuration:** None beyond the standard Laravel scheduler being run (`php artisan schedule:work` or a real cron calling `schedule:run`) — `NEEDS VERIFICATION`: confirm the production deployment actually has a cron/scheduler process running, since Laravel's scheduler does nothing on its own without one.

**Known Constraints:** Runs once daily at midnight — an `ACTIVE` request isn't cleaned up until the *next* midnight after its deadline passes, so a stale request can block a person's next legitimate visit (in the global cross-farm Gatesale/Truck check, or the wrong-farm message) for up to ~24h. `chunkById(100, ...)` bounds memory but means very large `ACTIVE` backlogs take proportionally longer.

**Important Notes for Future Changes:** Do not remove the `request_status = ACTIVE` filter — it's what makes repeated runs safe. Do not have this job fabricate a `last_out`/`first_in` value when no real log exists — `INCOMPLETE` is the intentional "we don't know" terminal state, distinct from `COMPLETED_AUTO`'s "we recovered a real time."

---

### Module: `Facility Master Data` (Phase 1: additive tables — Phase 2: core cutover — Phase 3: admin CRUD — Phase 4: Downtime Matrix/Stationary cutover — Phase 5: legacy Farm admin decommissioned — all 2026-08-27)

**Purpose:** Normalized, multi-brand replacement for the farm-only `farm_list`/`farm_aliases` model — introduced so that non-farm sites (plants, DC warehouses) can eventually be represented alongside farms, classified by business group (`facility_type`: BVA/GEFI/GEFI-LIVE/PS/FEEDMILL/GP-HY/PS-HY/IBG) and physical kind (`facility_category`: FARM/PLANT/DC_WAREHOUSE/OTHER), with an `is_rtl` flag per facility.

**Current status — read this before touching either "farm" or "facility" anything:**
- **Phase 1** (same-day, earlier) created `facility_type`/`facility_category`/`facility_list`/`facility_aliases` as new, additive-only tables, seeded with real reference data (8 types, 4 categories, 16 named facilities), fully isolated from the rest of the schema.
- **Phase 2** (same-day, later — this cutover) migrated the **core** farm-dependent runtime path onto `facility_list`: `visitor_request.farm_id` and `kiosk_device.farm_id` were **renamed and repointed** to `facility_id`/`facility_list` (with real data backfilled, not dropped — see mapping below); `FarmResolver` was **renamed to `FacilityResolver`** and now resolves against `facility_list`/`facility_aliases`; `VisitorSyncService`, both Kiosk farm-binding checks (`KioskController`, `VisitorKioskService`), and the Kiosk Devices admin screen were all updated to match. **`farm_list`/`farm_aliases` themselves were NOT touched, renamed, or dropped** — they still exist with all 8 farms and 11 aliases intact, but as of this cutover **nothing reads or writes them anymore** except the still-unchanged Farms/Farm Aliases admin screens (`downtime_matrix`/`downtime_stationary` were deferred at this point, then migrated in Phase 4 below).
- **Farm ID → Facility ID mapping is NOT the identity function** — a hard constraint discovered during this cutover, not a design choice: Phase 1 had already assigned the same 8 farm names (Saturn, Venus, Cinnamon, Mars, Madera, Rosemary, San Pascual, Victory) sequential `facility_id` 1–8 alongside 8 unrelated non-farm facilities at 9–16, so `facility_id` could not equal `farm_id` without renumbering all 16 rows (rejected as unnecessary risk). The approved, permanent mapping (encoded directly in the migrations, not derived at runtime):

  | farm_id | farm_code | farm_name | → facility_id | facility_code (now real, was placeholder) |
  |---|---|---|---|---|
  | 1 | MLF | MADERA | 5 | `MLF` (+ `location = 'Tarlac City'`) |
  | 3 | SPLF | SAN PASCUAL | 7 | `SPLF` |
  | 4 | VLF | VICTORY | 8 | `VLF` |
  | 5 | RLF | ROSEMARY | 6 | `RLF` |
  | 7 | CLF | CINNAMON | 3 | `CLF` |
  | 10 | SLF | SATURN | 1 | `SLF` |
  | 11 | VENUS | VENUS | 2 | `VENUS` |
  | 12 | MARS | MARS | 4 | `MARS` |

  The other 8 `facility_list` rows (DC Plaridel, DC Sta. Rosa, S&B Cebu Plant, Sacobia, GEFI - MCP, Buenavista Farm, Kelsey, Forestierra) have no `farm_list` counterpart at all and keep their Phase 1 placeholder `facility_code`.
- This was done in response to an external migration-planning instruction set (kept at the repo root as `Claude Instructions — Safe Facility Master Data Migration Across All Modules.md`), which explicitly required a dependency audit and an approved mapping before any schema change — both were done and confirmed before this cutover ran. That instruction document also assumed a "Biosecurity PDF parser" that resolves facility names via aliases — **no such parser exists anywhere in this codebase**; disregard that part of the instructions if revisited.
- `facility_aliases` still has **zero rows** — no alias text was supplied for any of the 16 facilities in either phase; `FacilityResolver`'s alias-lookup steps are live code paths but have no data to match against yet beyond exact/normalized facility-name matching.
- **Phase 3** (same-day, later still) added a **Facility / Facility Alias admin CRUD** — `FacilityController`/`FacilityAliasController`, mirroring the Farms/Farm Aliases controller+request+view pattern exactly, gated by a new `facilities.manage` permission (seeded via `PermissionSeeder`, granted to the `Administrator` role via `RolePermissionSeeder`). This closes the "no admin CRUD" gap noted after Phase 2. Verifying this phase's display of the 16 existing facilities caught a real, unrelated data-entry typo from the Phase 1 seed (`facility_id` 16 was stored as `"Forestiera"`/`GEFILIVE-FORESTIERA`, one `r` short of the seeder source's `"Forestierra"`/`GEFILIVE-FORESTIERRA`) — corrected directly in the DB to match the seeder.
- **Phase 4** (same-day, later still) migrated **Downtime Matrix/Downtime Stationary** onto `facility_list` too — `downtime_matrix.origin_farm_id`/`destination_farm_id` and `downtime_stationary.assigned_farm_id` were **renamed and repointed** to `origin_facility_id`/`destination_facility_id`/`assigned_facility_id`, using the exact same farm_id→facility_id mapping as Phase 2 (real data backfilled, not dropped — the one existing rule, `rule_id=3`, maps `origin_farm_id 1→origin_facility_id 5` (Madera) and `destination_farm_id 3→destination_facility_id 7` (San Pascual), values `13.00`/`26.00` preserved exactly). `DowntimeMatrixController`/`DowntimeStationaryController`'s dropdowns now source `FacilityList::all()` instead of `FarmList::all()`, so a downtime rule can reference a FARM, PLANT, DC_WAREHOUSE, or OTHER facility, not only a farm — **no new downtime business logic was added**, this was purely the master-data relationship swap; Downtime Matrix/Stationary still aren't read by any business logic (see Known Issue #3). At this point `farm_list`/`farm_aliases` had **zero** remaining live FKs from any table — only the (then still-existing) Farms/Farm Aliases admin screens read/write them.
  - Two MySQL-specific migration pitfalls surfaced here that SQLite's grammar didn't catch (worth remembering for any future FK-column rename): (1) InnoDB refuses to drop a unique index while a FK still depends on it ("Cannot drop index ... needed in a foreign key constraint") — the FK must be dropped in its own prior `Schema::table()` statement, strictly before the unique index or the column, in both `up()` and `down()`; (2) MySQL's 64-character identifier limit means Laravel's auto-generated composite-unique name for the long facility columns (`downtime_matrix_origin_facility_id_destination_facility_id_unique`) is too long — it needed an explicit shorter name (`downtime_matrix_origin_dest_facility_unique`) passed to both the `unique()` call and its matching `dropUnique()` in `down()`.
- **Phase 5** (same-day, later still) **decommissioned the legacy Farms/Farm Aliases admin module** now that nothing in the runtime depended on it: removed `FarmController`, `FarmAliasController`, their four `Store/Update*Request` classes, all 8 view files (`resources/views/admin/{farms,farm-aliases}/`), the two `Route::resource` lines, the sidebar nav block, and the `farms.manage` permission (deleted from `PermissionSeeder.php` and from the live DB's `permissions`/`role_permissions` tables). **`farm_list`/`farm_aliases` tables and their Eloquent models (`FarmList`, `FarmAlias`) were deliberately kept** — the tables as an explicit rollback/legacy-data safety layer (per the source instructions), the models because the tables still exist and nothing asked for their removal. No tests existed for the Farm admin module (it predated this whole migration effort), so there was nothing to remove there. One residual, out-of-scope reference was found and left alone: `resources/views/admin/dashboard.blade.php`/`dashboard-content.blade.php` still show a "Farms" stat tile calling `FarmList::count()` — harmless (the model still exists) but now links to nothing, since there's no admin screen left to navigate to.

**Responsibilities (today):** Canonical facility-name resolution for Visitor Sync (via `FacilityResolver`); the FK target for `visitor_request.facility_id`, `kiosk_device.facility_id`, `downtime_matrix.{origin,destination}_facility_id`, and `downtime_stationary.assigned_facility_id`; full admin CRUD for facilities and facility aliases.

**Entry Points:** `admin/facilities` and `admin/facility-aliases` (both full `Route::resource`, behind `auth` + `permission:facilities.manage`) — see Admin Management for the shared pattern. Also consumed internally by `VisitorSyncService` (via `FacilityResolver`) and by `KioskDeviceController`/`DowntimeMatrixController`/`DowntimeStationaryController`'s create/edit dropdowns (all now `FacilityList::all()`).

**Data Used:** `facility_type`, `facility_category`, `facility_list`, `facility_aliases`. Four existing tables now carry a live FK into `facility_list`: `visitor_request.facility_id` (`RESTRICT` on delete), `kiosk_device.facility_id` (`CASCADE` on delete), `downtime_matrix.origin_facility_id`/`destination_facility_id` (`CASCADE` on delete, unchanged from the old farm_id behavior), and `downtime_stationary.assigned_facility_id` (`CASCADE` on delete, also unchanged). Nothing in the schema points at `farm_list` anymore except `farm_aliases` itself.

**Dependencies:** None of its own — a pure lookup/reference structure, same as `farm_list` was.

**Related Modules:** Now the live dependency of **Visitor Sync** (`FacilityResolver`), **Kiosk Entry** (`visitor_request.facility_id`/`kiosk_device.facility_id` binding checks), **Kiosk Self-Service** (shares the same binding checks), and **Admin Management**'s Biosecurity Rules submodules (Downtime Matrix/Downtime Stationary). The old Farms/Farm Aliases admin screens (`FarmController`/`FarmAliasController`) were removed entirely in Phase 5 — `farm_list`/`farm_aliases` are now pure legacy tables with no admin UI and no code reading/writing them at all.

**Business Rules:**
- `facility_code` and `facility_type_name`/`facility_category_name` are DB-unique, same pattern as `farm_list.farm_code`.
- A facility's `facility_category`/`is_rtl` are per-facility properties, not inferred from `facility_type` — e.g. GEFI has both a PLANT (`GEFI - MCP`) and, under the separate `GEFI-LIVE` type, RTL FARMs. Do not assume every facility under one type shares a category or RTL setting.
- The farm_id → facility_id mapping above is a **fixed, permanent translation**, not a name-based lookup performed at migration time — do not attempt to re-derive it from `facility_name` matching in any future code; the migrations hardcode the exact pairs.

**Models:** `App\Models\FacilityType`, `App\Models\FacilityCategory`, `App\Models\FacilityList` (relations: `facilityType()`, `facilityCategory()`, `aliases()`, `kioskDevices()`, `originDowntimeMatrixRules()`, `destinationDowntimeMatrixRules()`, `downtimeStationaryRules()`), `App\Models\FacilityAlias` (relation: `facility()`) — all four use the `Auditable` trait (matching `FarmList`/`FarmAlias`/`IdentityType`/`EmployeeType`'s pattern). `App\Models\VisitorRequest::facility()`, `App\Models\KioskDevice::facility()`, `App\Models\DowntimeMatrix::originFacility()`/`destinationFacility()`, and `App\Models\DowntimeStationary::assignedFacility()` (all renamed from their `*Farm()` equivalents) now belong to `FacilityList`, not `FarmList`. `FarmList`'s reciprocal `kioskDevices()`/`originDowntimeMatrixRules()`/`destinationDowntimeMatrixRules()`/`downtimeStationaryRules()` methods were removed (Phase 4) since the columns they pointed at no longer exist on those tables — moved to `FacilityList` instead.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Migrations (Phase 1) | `database/migrations/2026_08_27_110000_create_facility_type_table.php` through `..._110003_create_facility_aliases_table.php` | Schema for all 4 new tables |
| Migrations (Phase 2) | `database/migrations/2026_08_27_120000_update_farm_matched_facility_codes.php`, `..._120001_migrate_visitor_request_farm_id_to_facility_id.php`, `..._120002_migrate_kiosk_device_farm_id_to_facility_id.php` | Real facility_code/location backfill; `visitor_request`/`kiosk_device` column cutover (both fully reversible via their own `down()`) |
| Migrations (Phase 4) | `database/migrations/2026_08_27_130000_migrate_downtime_matrix_farm_ids_to_facility_ids.php`, `..._130001_migrate_downtime_stationary_farm_id_to_facility_id.php` | `downtime_matrix`/`downtime_stationary` column cutover, same mapping/pattern as Phase 2 (also fully reversible) |
| Models | `app/Models/FacilityType.php`, `FacilityCategory.php`, `FacilityList.php`, `FacilityAlias.php` | Eloquent models |
| Service | `app/Services/FacilityResolver.php` (renamed from `FarmResolver.php`) | Facility-name/alias resolution for Visitor Sync |
| Seeders | `database/seeders/FacilityTypeSeeder.php`, `FacilityCategorySeeder.php`, `FacilityListSeeder.php` (registered in `DatabaseSeeder.php`) | Reference data + the 16 named facilities |
| Controllers (Phase 3) | `app/Http/Controllers/Admin/FacilityController.php`, `FacilityAliasController.php` | Full CRUD (originally patterned on `FarmController`/`FarmAliasController`, both removed in Phase 5) |
| Requests (Phase 3) | `app/Http/Requests/Admin/{Store,Update}Facility{,Alias}Request.php` | Validation + `facilities.manage` authorization |
| Views (Phase 3) | `resources/views/admin/facilities/*`, `resources/views/admin/facility-aliases/*` | Admin UI |
| Test helper | `tests/Concerns/CreatesFacilities.php` | Shared `createFacility()` used by every Kiosk/Visitor Sync/Downtime test that needs a valid `facility_list` fixture |
| Tests (Phase 3) | `tests/Feature/Admin/FacilityAdminTest.php`, `FacilityAliasAdminTest.php` | CRUD, validation, uniqueness, cascade/restrict-on-delete, deactivation-safety coverage |
| Tests (Phase 4) | `tests/Feature/Admin/DowntimeMatrixAdminTest.php`, `DowntimeStationaryAdminTest.php` | Same coverage shape as Phase 3, for the renamed `*_facility_id` columns |
| Removed (Phase 5) | `app/Http/Controllers/Admin/{Farm,FarmAlias}Controller.php`, `app/Http/Requests/Admin/{Store,Update}{Farm,FarmAlias}Request.php`, `resources/views/admin/{farms,farm-aliases}/*` | Legacy Farm admin CRUD — deleted; `farm_list`/`farm_aliases` tables and `FarmList`/`FarmAlias` models were kept |

**Known Constraints:** `facility_code` is real for the 8 farm-matched facilities but still placeholder (`TYPE-SLUG`) for the other 8 (no requirement to change these — the admin screen lets anyone rename them now that a UI exists). `facility_aliases` has zero rows, so any AppSheet facility-name spelling other than the exact/normalized match will fail sync with "Facility not found" until an admin adds an alias via `admin/facility-aliases`. `farm_list`/`farm_aliases` are now pure legacy data with **no admin UI at all** (Phase 5 removed it) — they exist only as a rollback/legacy safety layer per the source instructions; nothing in the running application reads or writes them. `resources/views/admin/dashboard{,-content}.blade.php` still show a "Farms" stat tile (`FarmList::count()`) — a known, deliberately-left residual (see Phase 5 note above), harmless but pointing at a now admin-less concept.

**Important Notes for Future Changes:** The only remaining farm→facility decision is whether/when to drop the now fully-legacy `farm_list`/`farm_aliases` tables themselves (kept intentionally as of Phase 5, per the source instructions' rollback guidance — nothing reads or writes them, admin UI included). Do not re-derive the farm_id→facility_id mapping from name matching in new code — treat the table above as the historical record of a fact, not a formula. When adding fields to the Facility form, follow `FacilityController`'s exact pattern (Store/Update Request + view three-way split). **If you ever write another migration that renames a FK column backed by a unique/composite-unique index on MySQL**, drop the FK in its own prior `Schema::table()` statement before touching the index or column (InnoDB blocks dropping an index a live FK still needs), and check any auto-generated constraint name against MySQL's 64-character identifier limit before relying on it — both bit the Phase 4 migration and neither was caught by the sqlite-backed test suite.

---

### Module: `Admin Management` (Facilities, Kiosks, Roles, Users, Reference Data, Biosecurity Rules, Audit Logs)

**Purpose:** Authenticated back-office CRUD for every piece of reference/configuration data the kiosk-facing modules depend on.

**Responsibilities:** Standard create/read/update/delete for: Facilities (`FacilityList`), Facility Aliases, Kiosk Devices (+ token regeneration), Identity Types, Employee Types, Biosecurity Rules (main module) with its two submodules Downtime Matrix and Downtime Stationary, Roles (+ permission assignment), Users, and a read-only Audit Log viewer. **The legacy Farms/Farm Aliases CRUD (`FarmController`/`FarmAliasController`) was decommissioned on 2026-08-27 (Phase 5)** once nothing in the runtime depended on it anymore — see the "Facility Master Data" module.

**Entry Points:** All under `routes/web.php`, behind the `auth` middleware and a per-resource `permission:<key>` middleware — see the permission table below. All are Laravel resource routes (`Route::resource`) except `roles/{role}/permissions` (GET/POST), `kiosks/{kiosk}/regenerate-token` (POST), and `admin/biosecurity-rules` itself (a plain `GET`-only landing route — see the Biosecurity Rules submodule note below). As of 2026-08-27, the landing route's middleware is `permission:biosecurity.manage,downtime_matrix_import.manage` (the `CheckPermission` middleware treats a comma-separated list as OR) so a user granted only the newer `downtime_matrix_import.manage` key can still reach the landing page — see the separate "Downtime Matrix PDF Import" module.

**Biosecurity Rules is one module with two submodules, loaded one at a time (not two nested resources shown together):**
```text
GET /admin/biosecurity-rules
   ↓
BiosecurityRuleController::index — renders a landing partial with two cards/links,
   "Downtime Matrix" and "Downtime Stationary" — no table data is queried or shown here
   ↓
user clicks a card (an .ajax-link, same mechanism as every other admin nav link)
   ↓
GET /admin/biosecurity-rules/downtime-matrix        → DowntimeMatrixController (full CRUD)
   or
GET /admin/biosecurity-rules/downtime-stationary    → DowntimeStationaryController (full CRUD)
   ↓
that submodule's own index/create/edit partials replace #content — the other
   submodule's table is never fetched or rendered until its own card/link is clicked
```
Each submodule's `_index`/`_create`/`_edit` partial carries a "← Biosecurity Rules" link back to the landing page. Both submodule route groups are nested under the `admin/biosecurity-rules/` URI prefix but keep short route names (`downtime-matrix.*`, `downtime-stationary.*`) because Laravel's resource registrar names nested-slash resources from their last URI segment, not the full path.

As of 2026-08-27, the landing page has a **third** card — "Downtime Matrix Import" (`downtime-matrix-import.index`) — gated separately by `downtime_matrix_import.manage` rather than sharing `biosecurity.manage`, and following an entirely different architecture (a service-layer parse pipeline, not the direct-Eloquent CRUD pattern the two cards above use). It's documented as its own top-level module — see "Downtime Matrix PDF Import" — not as a third CRUD submodule here, since it doesn't follow this section's `Store*Request`/`Update*Request`/direct-Eloquent pattern at all.

**Main Flow (identical pattern across every controller in this module):**
```text
Route (auth + permission:<key> middleware)
   ↓
Admin\*Controller::index/create/store/edit/update/destroy
   ↓
Store*Request / Update*Request (FormRequest — authorize() re-checks the same permission, rules() validates)
   ↓
Eloquent model create/update/delete  (triggers Auditable trait → audit_logs row automatically)
   ↓
Blade partial response — every controller has a private view() helper that returns
   a bare partial for AJAX requests (admin panel is AJAX-driven) or the full index
   page otherwise. As of the 2026-08-28 Data Table migration (see the
   "Admin Data Table Architecture" subsection below), index() no longer passes a
   paginated list variable to the view at all — the index Blade page is a static
   shell with no server-side data dependency — which incidentally resolved the
   old create()/edit() full-page-fallback crash (see Known Issue #11: RESOLVED).
```

**Data Used / permission key per resource:**

| Resource | Model | Permission key |
|---|---|---|
| Facilities (added 2026-08-27, Phase 3) | `FacilityList` | `facilities.manage` |
| Facility Aliases (added 2026-08-27, Phase 3) | `FacilityAlias` | `facilities.manage` (shares Facilities' permission) |
| ~~Farms~~ / ~~Farm Aliases~~ | ~~`FarmList`~~ / ~~`FarmAlias`~~ | ~~`farms.manage`~~ — **removed 2026-08-27, Phase 5** (controllers, views, routes, nav, and the `farms.manage` permission itself all deleted; `farm_list`/`farm_aliases` tables kept as legacy data with no admin UI) |
| Kiosk Devices | `KioskDevice` | `kiosks.manage` |
| Identity Types | `IdentityType` | `identity_types.manage` |
| Employee Types | `EmployeeType` | `employee_types.manage` |
| Biosecurity Rules (landing/cards only, no data) | — | `biosecurity.manage` |
| Biosecurity Rules → Downtime Matrix | `DowntimeMatrix` | `biosecurity.manage` (shares the module's one permission) |
| Biosecurity Rules → Downtime Stationary | `DowntimeStationary` | `biosecurity.manage` (shares the module's one permission) |
| Roles (+ permissions) | `Role`/`Permission` | `roles.manage` |
| Users | `User` | `users.manage` |
| Audit Logs (view only) | `AuditLog` | `audit_logs.view` |

**Dependencies:** `RolePermissionService` (role↔permission sync), `AuthService` (user create/update with password hashing), `AuditLogService` (filtered/paginated log queries).

**Related Modules:** Facilities/Facility Aliases feed `Visitor Sync` (`FacilityResolver`, renamed from `FarmResolver`), which resolves exclusively against `facility_list`/`facility_aliases` — see the "Facility Master Data" module. Kiosk Devices feed `Kiosk Entry`/`Kiosk Self-Service`'s device auth via `kiosk_device.facility_id` → `facility_list` — its create/edit dropdown lists `FacilityList::all()`. Roles/Permissions/Users feed `Authentication & Authorization`. Downtime Matrix/Downtime Stationary also point at `facility_list` (`origin_facility_id`/`destination_facility_id`/`assigned_facility_id`, dropdowns sourced from `FacilityList::all()`) — and Identity/Employee Types are reference data — `NEEDS VERIFICATION`: these are modeled and administrable but **no other module currently reads them** (no code path queries `DowntimeMatrix`/`DowntimeStationary` or joins on `employee_type_id` for any decision logic) — they appear to be forward-looking/scaffolded for the not-yet-implemented Employee tracking flow (`routeByIdentity()`'s `Employee` branch is a hard-coded placeholder response). **The legacy Farms/Farm Aliases admin CRUD was removed entirely on 2026-08-27 (Phase 5)** — `farm_list`/`farm_aliases` now have zero remaining live FKs from any table AND no admin UI; they are pure legacy data kept only as a rollback safety layer. **`Downtime Matrix PDF Import`** (added 2026-08-27, its own module section) is a staging front-end for this module's Downtime Matrix/Downtime Stationary submodules — through `VERIFIED` it never writes to `downtime_matrix`/`downtime_stationary` itself, but its Phase 2 Save to Production step (added 2026-08-28) does write directly into these same submodules' tables (via the same `DowntimeMatrix`/`DowntimeStationary` Eloquent models this module's own CRUD controllers use), deactivating whichever rows were previously active and inserting/reactivating the newly mapped ones.

**Business Rules:**
- Every `Store*Request`/`Update*Request`'s `authorize()` independently re-checks the same permission the route middleware already checked — belt-and-suspenders, not a bypass path.
- Unique constraints enforced at the validation layer mirror DB-level unique columns (`facility_code`, `serial_number`, `role_name`, `user_email`, `identity_type_name`, `employee_type_name`, `alias_text`).
- `RoleController::updatePermissions` does a full `sync()` (not merge) — submitting an empty `permission_ids` array revokes **all** permissions from that role.
- The Biosecurity Rules landing page never queries or renders both submodules' data at once — `BiosecurityRuleController::index` returns only the two-card partial with no model query at all; each submodule's own controller (`DowntimeMatrixController`, `DowntimeStationaryController`) is the sole owner of its own listing/CRUD, loaded asynchronously only when its card/link is clicked. Do not merge the two submodules' index queries onto the landing page — that was an explicit request when the module was split.

**Admin Data Table Architecture — jQuery DataTables.js, filter-button-gated, server-side processing (added 2026-08-28; this is now the ONE standard, uniformly applied to all 11 admin listings):**

Every admin resource listing — Facilities, Facility Aliases, Kiosk Devices, Identity Types, Employee Types, Downtime Matrix, Downtime Stationary, the Downtime Matrix Import *list*, **and (added later the same day) the Downtime Matrix Import *Preview/show* page's three category tabs**, Roles, Users, and Audit Logs — runs this exact pattern. (Two earlier same-day iterations existed during development — a fully server-rendered `<table>`, then a custom hand-rolled Data Table that auto-loaded on page open — both fully replaced; nothing in the running app uses either anymore, and `public/js/admin-datatable.js` plus `RendersDataTableJson.php` were deleted, not just deprecated.) The Preview page is the one case where a single page hosts **three independent Data Tables** (one per tab, all backed by one `rows-data` endpoint filtered by `rule_type`) rather than one — see the "Downtime Matrix PDF Import" module's own section for that page's specifics; everything below still applies per-tab.

```text
GET /admin/{module}                → Data Table Shell only: table headers, an EMPTY <tbody>, filter
                                       controls built from the module's actual schema (never invented -
                                       e.g. Facility Aliases has an Alias Text "contains" input and a
                                       Facility <select>; Users has Name/Email search + Role + Status;
                                       Audit Logs has Module/Action/User dropdowns sourced from
                                       distinct existing values), and Filter/Reset buttons. Any small
                                       *lookup* data the filters need (a facility dropdown's options,
                                       distinct module/action values, etc.) MAY be queried here - that
                                       is reference data for the filter UI, not the module's own
                                       records, and is not what "no automatic load" refers to. The
                                       module's OWN table is never queried by index().
                                            ↓
                                       jQuery(`#{module}-table`) is a plain, un-enhanced <table> at
                                       this point - jQuery.fn.DataTable.isDataTable(...) is false.
                                       NOTHING calls .DataTable() yet, so there is no ajax request.
                                            ↓
                                       user edits filter inputs - this NEVER triggers a request,
                                       by construction: nothing is wired to the input/change events
                                            ↓
                                       user clicks Filter → JS snapshots the current filter values
                                       into a closure variable, then either calls jQuery(...).DataTable(
                                       {...}) for the first time (which performs its one and only
                                       "automatic" ajax call, immediately) or, if already initialized,
                                       calls dtInstance.ajax.reload()
                                            ↓
GET /admin/{module}/data           → dedicated, module-specific JSON endpoint (one per module - never
                                       a generic model-agnostic endpoint). Reads DataTables' own
                                       server-side-processing query params (draw, start, length,
                                       order[0][column]/order[0][dir], parsed via the shared
                                       app/Http/Controllers/Admin/Concerns/HandlesDataTablesRequest.php
                                       trait) PLUS the module's own custom filter params (e.g.
                                       alias_text, facility_id, status) that the JS's ajax.data
                                       callback appends from the Filter-click snapshot. Applies
                                       filters, ordering, and offset/limit entirely in Eloquent/SQL.
                                            ↓
                                       {"draw": N, "recordsTotal": <unfiltered count>,
                                        "recordsFiltered": <filtered count, pre-pagination>,
                                        "data": [...plain field values, never HTML...]}
                                       - this exact shape is what DataTables.js's serverSide mode
                                       requires; every module's data() builds it directly (there is
                                       no shared response-builder trait - each query/filter set is
                                       different enough that a shared builder would hide more than
                                       it saved; HandlesDataTablesRequest only parses the request).
                                            ↓
                                       DataTables.js itself renders <tr>/<td> from the `columns`
                                       config. A column with NO custom `render` (plain
                                       `{data:'field'}`) is safely text-escaped by DataTables
                                       automatically. A column WITH a custom `render` function is
                                       NOT auto-escaped - DataTables inserts whatever the function
                                       returns via innerHTML, even a plain string with no HTML tags -
                                       so every render() that interpolates a free-text database
                                       column (name, description, filename, IP, status, etc.) MUST
                                       call the shared escapeHtml() (now defined once in
                                       public/js/admin.js, loaded on every admin page) - a numeric
                                       or boolean value, or a value the app itself controls (fixed
                                       enum labels, a route-built URL, a CSRF token), needs no
                                       escaping since it can't carry HTML metacharacters.
                                            ↓
                                       Subsequent page-number clicks, column-header sort clicks, and
                                       page-length changes are core DataTables.js behavior - they DO
                                       trigger immediate ajax requests (expected/correct, distinct
                                       from "filters"), and each one re-invokes the same ajax.data
                                       callback, which re-sends the LAST Filter-clicked snapshot - so
                                       filters are automatically preserved across pagination without
                                       extra code, and a filter input edited but not yet re-confirmed
                                       via Filter is never what gets sent.
                                            ↓
                                       Reset button: clears the filter inputs, clears the snapshot,
                                       and calls dtInstance.destroy() (plus manually emptying the
                                       <tbody> for clarity) - this does NOT re-query anything; the
                                       table sits empty again until Filter is clicked.
```

- **"No filter" and "ALL" both mean unrestricted, but only one of them ever runs a query.** Opening the module page runs zero queries. Clicking Filter with a dropdown left on its default "All ..." (`value="ALL"`) and/or a text input left blank *does* run a query — an intentionally unrestricted one (`ALL`/empty are explicitly treated server-side as "no restriction on this field", never "search for the literal string ALL" or "return nothing"). The distinction is about *whose action* causes the request (page-open vs. explicit Filter click), not about what values are selected.
- **Route registration order is load-bearing:** every `GET admin/{module}/data` route is registered *before* its module's `Route::resource(...)` call, and every resource explicitly adds `->except('show')` (no controller in this module ever implemented `show()` — it was dead code even before this migration). Without both, a resource's implicit `GET admin/{module}/{id}` (show) route — registered earlier — would swallow `admin/{module}/data` by treating the literal string `"data"` as the `{id}` route parameter.
- **`app/Http/Controllers/Admin/Concerns/HandlesDataTablesRequest.php`** only parses DataTables' request protocol (`dtDraw()`, `dtStart()`, `dtLength()`, `dtOrderColumn()`, `dtOrderDir()`) — it does not build the response or decide what to query. **`request()->query('order.0.column')` dot-notation does NOT reliably resolve DataTables' nested `order[0][column]` array query param** — read `request()->query('order')` as a plain nested array and index into it directly (`$order[0]['column']`); this bit the first implementation.
- **Never name a custom filter query param `search` on a module using real DataTables.js.** DataTables always sends its own reserved `search[value]`/`search[regex]` object as part of every request — even with `searching: false` and even when a tab's JS never sets a `search` key itself — so a same-named custom param collides and arrives server-side as a PHP array, not a string (`(string) $array` throws, Laravel turns it into a 500). This bit the Downtime Matrix Import Preview page's "Others" tab filter — see that module's own Business Rules for the specific incident and its regression test.
- **`dtOrderColumn($columns, $default)`'s `$columns` array MUST be keyed by each column's real position in the JS `columns` array — not compacted to "just the orderable ones."** DataTables reports back the real index of whichever column was sorted (e.g. `column=2` for the 3rd JS column), so a controller whose orderable columns aren't at JS positions 0/1 needs explicit integer keys, e.g. Downtime Matrix's `[2 => 'minimum_downtime', 3 => 'maximum_downtime']` (origin/destination at JS positions 0/1 are non-orderable relation columns). Getting this wrong doesn't error — it silently sorts by the wrong column or falls back to the default, which is why every controller in this module now carries a comment stating its JS column positions explicitly next to its `$orderableColumns` array; keep that comment in sync if you ever reorder a module's `columns` config in the Blade view.
- **A relation's display column (facility name, role name, uploaded-by name, etc.) is joined (`leftJoin`), not eager-loaded (`with()`), whenever it needs to be sortable or filterable** — e.g. Facility Aliases/Downtime Matrix/Downtime Stationary join `facility_list` directly so `facility_name`/`origin_facility`/etc. are real columns in the same query. This avoids both an N+1 (resolving the relation per row) and the impossibility of `ORDER BY` on an eager-loaded relation's column. A relation column that's display-only and never sortable (e.g. Kiosk Devices' `facility_name`, marked `orderable: false` in JS) may still use `with()` for simplicity — the join is specifically needed only when `ORDER BY`/`WHERE` must reach that column.
- **DataTables.js is loaded from CDN** (`cdn.datatables.net` 2.1.8, both the CSS and JS) in `layouts/admin.blade.php`, alongside jQuery — consistent with this codebase's existing convention of loading `face-api.js`/`jsQR` from CDN in the kiosk view rather than vendoring them.
- **Any new admin Data Table must follow this same pattern by default** — a page route that renders an empty shell (+ small filter-lookup data only), a dedicated `/data` JSON endpoint per module returning the DataTables server-side envelope, real `jQuery(...).DataTable({serverSide:true,...})` deferred until an explicit Filter click, and `escapeHtml()` on every `render()` that touches free text. Do not reintroduce a custom hand-rolled Data Table renderer, and do not build a shared "generic" `/data` endpoint that dynamically picks a model.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Controllers | `app/Http/Controllers/Admin/*.php` | One per resource — `index()` renders the shell (+ any small filter-dropdown lookup data), `data()` builds and returns that module's own DataTables server-side JSON envelope |
| Concern | `app/Http/Controllers/Admin/Concerns/HandlesDataTablesRequest.php` | Parses DataTables' `draw`/`start`/`length`/`order` request params only — response shape is built per-controller |
| Requests | `app/Http/Requests/Admin/{Store,Update}*Request.php` | Validation + authorization |
| Services | `app/Services/{AuthService,RolePermissionService,AuditLogService}.php` | Shared business logic (`AuditLogService` now only has `getRecordLogs()` — its old `getFilteredLogs()` was folded directly into `AuditLogController::data()` when that module migrated) |
| Middleware | `app/Http/Middleware/CheckPermission.php` | `permission:<key>` route middleware |
| JS | `public/js/admin.js` | App-wide AJAX page navigation (`.ajax-link`/`.ajax-form`, unrelated to Data Tables) **plus** the shared `escapeHtml()` every module's `render()` callbacks use |
| JS | inline `<script>` at the bottom of each module's `_index.blade.php` | That module's own `jQuery(...).DataTable({serverSide:true,...})` init, `columns`/`render` config, and Filter/Reset handlers — `resources/views/admin/facility-aliases/_index.blade.php` was the original proof-of-concept and remains a good simple reference |

**Configuration:** DataTables.js uses its own client-side `pageLength`/`lengthMenu` per module (currently `25`, options `[10,25,50,100]`) — since `length`/`start` are sent per-request by DataTables.js itself, `config('sentry.pagination')` (`APP_PAGINATION_SIZE`) is confirmed **no longer read anywhere in `app/`** — it was the legacy server-rendered pagination default. Left in `config/sentry.php`/`.env.example` as unused dead config rather than removed, matching how this codebase already leaves other superseded config in place (e.g. `max_login_attempts`) rather than deleting it.

**Known Constraints:** No bulk operations (import/export) on any admin resource. No soft-deletes anywhere — `destroy()` is a hard delete (cascades per each migration's FK constraints — e.g. deleting a `user_directory` row cascades to `visitor_request` and `visitor_profile`; deleting a `FacilityList` row cascades to `facility_aliases`, `kiosk_device`, `downtime_matrix` (both `origin_facility_id`/`destination_facility_id`), and `downtime_stationary`, and is blocked (`RESTRICT`) by any `visitor_request` still pointing at it — this is now the operative version of the pre-cutover `FarmList`/`visitor_request`/`downtime_matrix`/`downtime_stationary` behavior). **As of the 2026-08-27 cutovers, deleting a `FarmList` row no longer cascades to (or is blocked by) anything** — `farm_aliases` is the only remaining FK into `farm_list`; `kiosk_device`, `visitor_request`, `downtime_matrix`, and `downtime_stationary` were all moved to `facility_list` across Phases 2 and 4 and have no relationship to `farm_list` at all anymore. `facility_type`/`facility_category` (the lookup tables `facility_list` depends on) still have no admin CRUD of their own — only `FacilityList`/`FacilityAlias` got one in Phase 3 — so a genuinely new facility type or category still needs a seeder change or direct DB access.

**Important Notes for Future Changes:** Do not add a new admin resource without both a `permission:<key>` route guard **and** a matching `authorize()` check in its FormRequests — the existing pattern relies on both being present. If you add a `VisitorType` admin controller (a real gap — see the Self-Service module's Known Constraints), follow the Data Table pattern above. If a third Biosecurity Rules submodule is ever added, follow the same landing-card + nested-resource-route pattern used for Downtime Matrix/Downtime Stationary rather than adding a third card of unrelated shape. `FacilityController`/`FacilityAliasController` (added 2026-08-27, Phase 3) remain the canonical example of this module's controller+request+view CRUD pattern; `FacilityAliasController`/`resources/views/admin/facility-aliases/_index.blade.php` are additionally the original Data Table proof-of-concept and a good starting template — filter controls above the table, a Filter/Reset button pair, deferred `.DataTable()` init, and the DataTables server-side JSON envelope. `DowntimeMatrixImportController::rowsData()`/`resources/views/admin/downtime-matrix-import/_show.blade.php` are the reference for a page hosting **multiple** Data Tables sharing one endpoint (filtered by a structural param like `rule_type`) rather than one table per page — use that shape, not three near-duplicate endpoints, if a future page needs the same kind of tabbed-category layout.

---

### Module: `Downtime Matrix PDF Import` (Phase 1 — Parse, Validate, Preview, added 2026-08-27; Phase 2 — Production Mapping, added 2026-08-28)

**Purpose:** Let an admin upload a BFI/BVA-format Downtime Matrix PDF (the same kind of document `Admin Management`'s Downtime Matrix/Downtime Stationary screens require to be re-keyed by hand today) and have it parsed, facility-resolved, normalized, classified, and validated into a human-reviewable staging preview (Phase 1), then — once verified — actually map the eligible staged rows into the live `downtime_matrix`/`downtime_stationary` configuration behind a dedicated confirmation step (Phase 2). **Phase 2 is the "Save to Production" flow described throughout this section** — it is real, it writes real production rows, and it is gated behind its own confirmation page and the same `downtime_matrix_import.manage` permission as everything else in this module. It does not, however, read anything back out of `downtime_matrix`/`downtime_stationary` for any kiosk/business decision — see Known Issue #3.

**Responsibilities:** PDF upload/storage; text-and-position extraction from the PDF's content stream(s) (including any Form XObject the table happens to be rendered inside); reconstructing the visual grid from those positioned fragments; resolving each origin/destination label against `facility_list`/`facility_aliases` (including a dynamic facility-*group* concept, e.g. "LEP, DC" → all active `DC_WAREHOUSE` facilities); normalizing raw hour values into `minimum_downtime`/`maximum_downtime`; classifying each rule `FARM_TO_FARM` vs `STATIONARY`; assigning a final `resolution_status` per a fixed precedence; staging the result; and a Verify/Cancel status-only workflow.

**Entry Points:** All under `admin/biosecurity-rules/downtime-matrix-import`, behind `auth` + `permission:downtime_matrix_import.manage` (`routes/web.php`):
- `GET /admin/biosecurity-rules/downtime-matrix-import` — import history list.
- `GET .../create` — upload form (Matrix Type selector: `BFI_BVA` functional, `HOGS` disabled/"Coming Soon").
- `POST /admin/biosecurity-rules/downtime-matrix-import` — upload + synchronous parse, renders the Preview directly (no redirect).
- `GET .../{downtime_matrix_import}` — Preview shell: import metadata, an aggregate Import Summary, and three empty Data Table tabs (Farm-to-Farm/Stationary/Others) plus Verify/Cancel actions. Loads no staged rows itself.
- `GET .../{downtime_matrix_import}/rows-data` — jQuery DataTables server-side processing endpoint for one tab's rows (added 2026-08-28, see "Admin Data Table Architecture" under Admin Management) — `rule_type` (which tab) and `status` (exact match) apply to all three tabs; `origin_raw_label`/`destination_raw_label` (exact match, dropdown-driven) apply to Farm-to-Farm, `destination_raw_label` alone to Stationary, and `label_search` (contains, deliberately not named `search` — see Business Rules) to Others only.
- `POST .../{downtime_matrix_import}/verify`, `POST .../{downtime_matrix_import}/cancel` — status-only transitions.
- `PUT .../{downtime_matrix_import}/rows` — saves a manual row correction (added 2026-08-28 as a dedicated "Edit Rows" page's bulk-submit action, **revised the same day** to be the save target of a per-row **Edit modal** on the Preview page itself — see the Business Rules bullet below). Accepts the same `rows: {import_row_id: {...}}` shape either way (still technically batch-capable), but the modal only ever sends one row per call. Returns JSON (`{applied, rows: [...]}`), not HTML — a no-op (`applied: false`, row data unchanged) if the import isn't `PENDING_VERIFICATION`.
- `GET .../{downtime_matrix_import}/produce` — the **Save to Production confirmation step** (added 2026-08-28), reachable from a **"Production"** action on the import history list, and (revised the same day) also from an identical **Production** button on the import's own Preview page whenever it's `VERIFIED` — both are rendered client-/server-side only for rows/imports with `status = VERIFIED`. Reuses the exact same Preview view as the plain `show` route (same Import Summary, same three Data Table tabs) so the admin reviews the identical staged data, plus a "Confirm Save to Production" panel listing filename/date/parsed-row counts per status/rows-to-be-mapped/rows-to-be-skipped, and **Save to Production**/**Cancel** buttons at the bottom instead of nothing. Falls back to the plain (non-confirmation) Preview if the import isn't actually `VERIFIED`.
- `POST .../{downtime_matrix_import}/produce` — the actual **Production mapping**: maps every eligible (`resolution_status` `VALID`/`WARNING`) staging row into `downtime_matrix`/`downtime_stationary`, deactivates the previously-active production configuration (never deletes it), and sets `status = PRODUCED` — see `DowntimeMatrixImportService::produce()` under Business Rules for the full mapping rules, and Important Files for its transactional/rollback behavior. A no-op (falls back to the plain Preview, no result shown) for any import that isn't `VERIFIED` at the moment this is called.

**Main Flow (the parse pipeline, `App\Services\DowntimeMatrixImport\DowntimeMatrixImportService::import()`):**
```text
POST .../downtime-matrix-import {matrix_type, pdf_file}
   ↓
StoreDowntimeMatrixImportRequest (mimes:pdf, matrix_type in:BFI_BVA — HOGS rejected server-side even if the disabled <option> is tampered with)
   ↓
DowntimeMatrixImportService::import()
   ├── Storage::disk('public')->put(...) — original PDF retained for audit/traceability
   ├── PdfTextExtractor — own content-stream tokenizer (NOT Smalot\PdfParser\Page::getDataTm(),
   │      which only walks a page's own direct stream, not Form XObjects — the real sample PDF
   │      renders its entire table inside one such XObject) → [{text, x, y}, ...] fragments
   ├── GridReconstructor — clusters fragment (x,y) into row/column bands anchored on the literal
   │      "DESTINATION"/"ORIGIN" header labels; throws GridReconstructionException rather than
   │      emitting a plausible-but-wrong grid if the header structure can't be confidently found
   ├── MatrixGridParser — pairs row bands into origin axis-entries (Clean Area/Restricted Area
   │      farm pairs, or single non-farm rows) and column bands into destination axis-entries
   │      (Downtime Area/Dormitory farm pairs, or single non-farm columns); emits one candidate
   │      row per origin×destination combination that has ANY non-blank value (value-based
   │      emission — a self-pair or farm↔non-farm-destination cell is skipped only because it's
   │      blank in the real PDF, never because of its position). One deliberate exception: a
   │      destination configured `always_include_as_farm_destination` (currently "LEP, DC") always
   │      gets a synthesized row for every farm origin even when blank — blank there means "no
   │      downtime required," a real fact, not missing data (flagged INFO, min/max stay null)
   ├── ImportValidator — per candidate: FacilityImportResolver (exact name → exact alias →
   │      normalized name → normalized alias → facility-group → stationary-origin sentinel → else
   │      UNMATCHED; AMBIGUOUS on a multi-facility normalized match) for both sides, RuleClassifier
   │      (always returns FARM_TO_FARM / STATIONARY / OTHERS, never "doesn't fit" - STATIONARY only
   │      for the "Outside" sentinel origin + a farm destination; FARM_TO_FARM requires the origin
   │      itself to be farm-like, not just the destination; everything else is OTHERS, a real
   │      category, not an error state), DowntimeNormalizer (derives whichever of Downtime Area/
   │      Dormitory is actually present - both -> min=downtime_area, max=+dormitory; only one
   │      present -> that value is the min, max stays null (an INFO-tier note explains why); a
   │      present-but-unparseable value, not simply a missing one, is what's INVALID; Clean vs
   │      Restricted consolidated only if identical, else both raw readings preserved and WARNING);
   │      assigns final resolution_status by fixed precedence
   │      INVALID > AMBIGUOUS > UNMATCHED > WARNING > VALID > INFO (combining every applicable finding's
   │      message, not just the winning one), plus in-import duplicate-pair detection
   ↓
DowntimeMatrixImportRow::insert() (bulk) + downtime_matrix_imports status/count columns updated
   ↓
Preview → Verify (status→VERIFIED) or Cancel (status→CANCELLED) — staging status only
```

**Phase 2 flow (`DowntimeMatrixImportService::produce()`, only reachable once `status = VERIFIED`):**
```text
GET .../produce  (Production confirmation step - review only, no writes)
   ↓
POST .../produce
   ↓
DowntimeMatrixImportService::produce(), inside one DB::transaction()
   ├── Load this import's rows WHERE resolution_status IN (VALID, WARNING) - UNMATCHED/AMBIGUOUS/INVALID
   │      are never even loaded for mapping, let alone written
   ├── Deactivate every currently-active downtime_matrix/downtime_stationary row (is_active=false,
   │      never deleted) - preserves the outgoing configuration as history
   ├── For each eligible row:
   │      FARM_TO_FARM → downtime_matrix: expand either side's facility-group category (if any,
   │         e.g. "LEP, DC" → DC_WAREHOUSE) into its CURRENTLY active member facilities via
   │         FacilityImportResolver::resolveGroupMembers() (queried fresh, not reused from parse
   │         time) - one production row per (origin, destination) pair in the resulting cross
   │         product, skipping any accidental self-pair
   │      STATIONARY → downtime_stationary: destination_facility_id becomes assigned_facility_id;
   │         the "Outside" sentinel origin is never stored anywhere (downtime_stationary has no
   │         origin column at all)
   │      OTHERS → never mapped (no production table target exists for it), regardless of status
   ├── All target rows are collected in PHP first, then written via exactly two bulk
   │      DowntimeMatrix::upsert()/DowntimeStationary::upsert() calls (deduplicated/keyed by each
   │      table's own unique columns) - never one Eloquent create()/update() per row - see
   │      Business Rules for why (a real production timeout, not a style preference)
   ├── downtime_matrix_imports.status → PRODUCED, produced_by/produced_at stamped
   └── On ANY exception, the whole transaction rolls back automatically; produce() catches it,
          logs it, and returns {success:false, error} instead of letting the request fail - the
          import stays VERIFIED, nothing in downtime_matrix/downtime_stationary changed
   ↓
Preview, now showing a Production Result panel (success counts, or the failure message)
```

**Data Used:** `downtime_matrix_imports` (one row per upload), `downtime_matrix_import_rows` (one row per parsed rule, read-only during production mapping — never modified by it), `facility_list`/`facility_aliases` (read-only, resolution and group-expansion) — **never** `farm_list`/`farm_aliases`. As of Phase 2, `downtime_matrix`/`downtime_stationary` **are** written to by the Save to Production flow (`is_active` toggled on the prior configuration, new/updated rows inserted `is_active=true`) — Phase 1's staging tables (`downtime_matrix_imports`/`downtime_matrix_import_rows`) themselves are still never touched by this write.

**Dependencies:**
```text
Downtime Matrix PDF Import
 ├── smalot/pdfparser (composer, added for this feature — pure PHP, no external binary)
 ├── Storage::disk('public') → downtime-matrix-imports/{import_id}/*.pdf
 ├── facility_list / facility_category / facility_aliases (read-only)
 └── downtime_matrix / downtime_stationary (write, Phase 2 only — Save to Production)
```

**Related Modules:** As of Phase 2, this **is** a real upstream writer of `Admin Management`'s Downtime Matrix/Downtime Stationary submodules' own data — a Save to Production run directly changes what those two admin screens list as active, and deactivates (not deletes) whatever was active before. The two remain architecturally and permission-wise independent (`downtime_matrix_import.manage` here vs. `biosecurity.manage` there; no shared controller/request code) — this module writes into their tables via `DowntimeMatrix`/`DowntimeStationary` Eloquent models directly, the same models those admin screens use, so a produced rule shows up there immediately, editable/deletable through the normal Downtime Matrix/Downtime Stationary CRUD like any other row. Reads `facility_list`/`facility_aliases` the same way `Visitor Sync`'s `FacilityResolver` does, but via its own independent `FacilityImportResolver` (different normalization: this feature's PDF uses suffix-style names with parenthetical qualifiers, e.g. "Madera Farm (Red-Act)", which `FacilityResolver`'s prefix-only normalization does not handle — the two resolvers are deliberately not shared, to avoid either one's normalization drifting to accommodate the other's source format).

**Business Rules:**
- **Through `VERIFIED`, the parse/preview/edit pipeline never writes to `downtime_matrix`/`downtime_stationary`** — Verify/Cancel change `downtime_matrix_imports.status` only. Only the Phase 2 Save to Production flow, described in the rules below, writes to those two tables at all, and only once an admin explicitly confirms it.
- **A facility-group match (e.g. "LEP, DC" → `DC_WAREHOUSE`) is never assigned a single `facility_id`** — `origin_facility_id`/`destination_facility_id` stay null, `origin_facility_group_category`/`destination_facility_group_category` carries the category instead. Exactly **one** staging row is created per grid cell regardless of how many facilities belong to that category — the group is expanded into its current member facilities only at Preview render time (a live `FacilityList` query), never materialized as multiple staging rows. The Preview shows a group as `"{display_name} ({current member facility names})"` (e.g. "DC Warehouses (DC Plaridel, DC Sta. Rosa)") — `display_name` comes from `config('downtime_matrix_import.facility_groups')`, not the raw PDF label.
- **Cell emission is value-based, not position-based** — a self-pair or a farm-origin×non-farm-destination cell is skipped only when genuinely blank; if either ever carries a value, it is still emitted and resolved/classified/validated normally (e.g. a populated self-pair correctly lands `INVALID`, "origin and destination resolve to the same facility", rather than being silently dropped). **One deliberate exception to "skip when blank":** a destination flagged `always_include_as_farm_destination` in config (currently only `"LEP, DC"`) always gets a synthesized `FARM_TO_FARM` row for every farm origin, even when that cell is blank in the PDF — a blank cell there is read as "no downtime required to enter a DC Warehouse" (a real business fact), not as missing data. These rows carry `minimum_downtime`/`maximum_downtime = null` and an `INFO`-tier "No downtime required for this cell." message, never `UNMATCHED`/`INVALID` on that basis.
- **`rule_type` is a three-way classification — `FARM_TO_FARM` / `STATIONARY` / `OTHERS` — and `RuleClassifier::classify()` always returns one of the three** (revised twice, same day, per explicit user clarification; there is no "doesn't fit anywhere" case anymore, `OTHERS` is that catch-all by design):
  - `STATIONARY`: **only** when the origin is the recognized "generic outside-the-system" sentinel — `config('downtime_matrix_import.stationary_origin_labels')`, currently just `"Outside"` — paired with a destination that resolves to category `FARM`. This matches `downtime_stationary`'s production shape: one rule per farm, no origin/destination pair, "Outside" being that rule's implicit unstated origin (the schema has no room for a second non-farm origin to map to the same farm).
  - `FARM_TO_FARM`: the origin must actually be farm-like — a real farm **or** a facility group standing in for one (e.g. "LEP, DC" → `DC_WAREHOUSE`) — paired with a farm (or group) on the other side, symmetric either direction. A destination simply being a farm is **not**, on its own, enough — the origin has to be farm-like too, otherwise it isn't Farm-to-Farm.
  - `OTHERS`: everything else — a non-sentinel, non-farm, non-group origin (e.g. "Organikultura Area", "Fabrication") paired with a farm destination, or any combination fitting neither rule above. Still fully resolved/validated and surfaced for review, never dropped (in the real sample PDF, both known `Others` origins are also `INVALID` — missing a Downtime Area value, only Dormitory is populated — so that finding combines with their `UNMATCHED` origin per the precedence rule below).
- **`resolution_status` precedence is fixed:** `INVALID > AMBIGUOUS > UNMATCHED > WARNING > VALID > INFO`. Every applicable finding for a row is collected and its message included in `validation_message`; only the status itself follows the single highest-ranked finding. `INFO` (e.g. "only a minimum threshold could be derived for this cell") never wins the status — it exists purely to carry a message onto an otherwise-`VALID` row without falsely flagging it as a problem.
- Facility resolution never fuzzy-matches — exact/normalized name or alias only, mirroring `Visitor Sync`'s `FacilityResolver` philosophy. An `UNMATCHED` row is retained and shown in Preview (never hidden/dropped) specifically so an admin can add the missing `facility_alias` via the existing `admin/facility-aliases` screen and re-upload — this is the intended workflow, not an error state to suppress.
- **Downtime Area and Dormitory are not simple arithmetic inputs — `DowntimeNormalizer` derives whichever value is actually present** (revised the same day, per explicit user clarification, from an earlier "Downtime Area is always required" rule): both present → `minimum = downtime_area`, `maximum = downtime_area + dormitory` (unchanged); only Downtime Area present → `minimum = downtime_area`, `maximum = null`; only Dormitory present → `minimum = dormitory` (it stands in as the threshold — "hours required before entering the next area"), `maximum = null`; neither present → nothing derivable for that reading, no finding (this can only happen for one half of a Clean/Restricted pair when the other half has data — a cell that's blank on both sides for its only reading is never emitted as a candidate at all). A value that is **present but unparseable/negative** (as opposed to simply missing) is still always `INVALID` and never defaulted to 0 — that distinction, not "missing vs present," is what actually drives the `INVALID` finding now. A single-value derivation adds a non-blocking `INFO`-tier finding (rank below `VALID` in the precedence — never wins the status, but its message is still included) explaining which threshold was determined and that no maximum could be. In `FARM_TO_FARM`, if only one of Clean/Restricted yields a derivable reading, that reading is used directly (no comparison, nothing to disagree with) rather than left null.
- **The Preview groups rows into three categories (Farm-to-Farm / Stationary / Others) behind a summary-first, tab-selected Data Table UI (rebuilt 2026-08-28 onto the jQuery DataTables.js pattern — see "Admin Data Table Architecture" under Admin Management).** An "Import Summary" table (row/status counts per category, plus a Total row) renders first, computed via a single `GROUP BY rule_type, resolution_status` aggregate query — never by loading every row to tally in PHP. Clicking a category tab only toggles which tab's (still-empty) Data Table shell is visible; that tab's own Data Table is not initialized, and its `rows-data` endpoint is not called, until the admin sets that tab's filters (or leaves them unrestricted) and clicks that tab's own **Filter** button — identical gating to every other admin Data Table in this app, just scoped per tab instead of per page. A fourth **"Show All"** button reveals all three tab shells at once and, for each one not yet loaded, triggers its own Filter click programmatically with whatever is currently in its own filter fields (default "All" if untouched) — three explicit, bundled loads behind one click, not an automatic load; a tab the admin already filtered is left alone rather than being silently reloaded/reset. All three tabs share one `rows-data` endpoint, filtered server-side by `rule_type`; the Stationary tab's `columns` config drops the Origin column and shows a single "Designated Farm" column (the resolved destination) since a Stationary row's origin is always the same recognized sentinel by construction — Farm-to-Farm and Others share the same column shape (Origin/Destination both shown). The origin/destination *display* value (resolved facility name, resolved facility-*group* display, or `"{raw label} (unresolved)"`) is computed server-side per row in `DowntimeMatrixImportController::sideDisplay()` (moved off the Blade `$sideDisplay` closure that this used to be, now that rows arrive a page at a time via JSON rather than all at once) — it is not a sortable/filterable database column, so those two Data Table columns are `orderable: false`.
- **Farm-to-Farm's filters are Origin and Destination dropdowns (exact match on `origin_raw_label`/`destination_raw_label`), Stationary's is one "Designated Farm" dropdown (exact match on `destination_raw_label`), and Status (exact match on `resolution_status`) is shared by all three tabs — Others alone keeps a free-text "contains" search instead of dropdowns, since its origins (`"Organikultura Area"`, `"Fabrication"`, etc.) aren't a fixed enumerable set the way Origin/Destination farms are.** The Origin/Destination/Designated-Farm dropdown *options* are the distinct raw labels actually present in **this import's** rows for that tab (a small `SELECT DISTINCT ... WHERE import_id = ?` lookup query run in `showResponse()`, not the rows themselves) — so a farm this import genuinely didn't reference never appears as a filter choice, and "Outside" never appears as a Farm-to-Farm option since it's Stationary-only by construction. These lookup queries are the one case in this module where server-rendering literal raw-label strings from `downtime_matrix_import_rows` into the initial page is correct, not a "leaked row data" violation — the same reasoning as every other admin Data Table's filter-dropdown lookup (Facility Aliases' Facility dropdown, Audit Logs' Module/Action dropdowns): a distinct-value list of one column is not the record set itself.
- **DataTables.js always sends its own reserved `search[value]`/`search[regex]` object as part of every request — even with the search box hidden (`searching: false`), and even for a tab whose own JS never touches a `search` key.** A custom filter param literally named `search` collides with that reserved key and arrives server-side as a PHP array, not a string (`(string) $array` throws `ErrorException: Array to string conversion`, which Laravel turns into a 500). This is why the Others tab's free-text filter is named **`label_search`**, not `search` — a real bug hit during this feature's build: the original code coincidentally worked because every tab's `ajax.data` callback unconditionally overwrote `d.search` with a string; once Farm-to-Farm/Stationary stopped needing a text filter at all, nothing overwrote DataTables' own array anymore and the collision surfaced. `tests/Feature/Admin/DowntimeMatrixImportAdminTest.php::test_rows_data_tolerates_datatables_own_reserved_search_param` locks this in. **Never name a custom Data Table filter param `search` on any module using real DataTables.js** — pick a more specific name, always.
- **Per-row Edit modal (added 2026-08-28, revised the same day from an earlier dedicated "Edit Rows" page)** lets an admin manually resolve a row flagged `WARNING`/`UNMATCHED`/`AMBIGUOUS`/`INVALID` instead of only being able to fix the source PDF and re-upload — an **Edit** button on every row of all three Preview tabs (rendered client-side by each tab's DataTables `renderActions()` callback, gated on a server-rendered `canEdit` JS flag that's `true` only while `PENDING_VERIFICATION`; the earlier standalone page, its route, and its "Edit Rows" launch button were removed in the same revision) opens one shared modal (`#dmi-edit-modal` in `_show.blade.php`) pre-filled from that row's own DataTables row data — `DowntimeMatrixImportController::rowPayload()` (shared by `rowsData()` and `updateRows()`, see Important Files) is what makes the raw `rule_type`/`origin_raw_label`/`destination_raw_label`/`origin_facility_id`/`destination_facility_id` fields available for this without a second request. The modal edits Origin/Destination facility `<select>`s (Origin hidden for `STATIONARY` rows — their origin is always the implicit "Outside" sentinel, never a resolvable facility) and the two downtime-hour inputs; `origin_raw_label`/`destination_raw_label` (the verbatim PDF text) are never editable, only the *resolved* `*_facility_id` and the downtime values are. Saving PUTs a single-row `rows: {id: {...}}` payload to the same `downtime-matrix-import.rows.update` endpoint the old page used, then reloads only the tab the edited row belongs to (`dtInstance.ajax.reload(null, false)` — keeps the admin's current page/position) from the JSON response, so the applied change is visibly reflected without a full page reload. `DowntimeMatrixImportService::updateRows()` (unchanged by this revision) applies the correction in a transaction, **recomputes the row's `resolution_status` with a deliberately simpler recheck than the parse-time `ImportValidator` pipeline** (that operates on raw PDF text; this operates on an admin's already-chosen facility ids): `INVALID` if `maximum_downtime < minimum_downtime`; `UNMATCHED` if a side that needs a resolved facility (origin needs one unless `rule_type = STATIONARY`) still has neither a `facility_id` nor a `facility_group_category`; otherwise `VALID`. Selecting a real facility on a side that was previously group-resolved clears that side's `*_facility_group_category` — a human's explicit choice always supersedes the automatic group match. Every edit stamps `edited_by`/`edited_at`, and the parent import's denormalized `*_rows_count` columns are recomputed via a `GROUP BY resolution_status` aggregate afterward.
- **Production action visibility (Phase 2, added 2026-08-28):** a **"Production"** action appears on the import history list's Actions column (client-side `renderActions()` in `_index.blade.php`, next to the existing **View** action) only for rows with `status = VERIFIED` — never for `PENDING_VERIFICATION` (nothing verified yet), `CANCELLED`, or already-`PRODUCED` rows. The same action is also offered directly on the Preview page itself (`_show.blade.php`'s bottom action block, added the same day this note was revised) whenever `isVerified()` and `$productionMode` isn't already true — i.e. immediately after clicking Verify/Continue, and on any later plain revisit of a `VERIFIED` import's Preview — so an admin doesn't have to navigate back to the import list to find it. Both places link to the exact same `downtime-matrix-import.produce.confirm` route; clicking either one never immediately touches production — it only navigates to the confirmation step below.
- **Save to Production confirmation step is mandatory — there is no direct "produce" action anywhere that skips it.** The confirmation page reuses the same Preview the admin already used to verify the import (same Import Summary, same three Data Table tabs — a deliberate reuse, not a second summary view, so the admin reviews the identical staged data one more time), plus an explicit summary (file, import date, total/valid/warning/unmatched/ambiguous/invalid row counts, and computed "rows to be mapped"/"rows to be skipped" totals) and **Save to Production**/**Cancel** buttons appended at the bottom instead of Verify/Cancel. Merely viewing this page (a `GET`) makes no database change whatsoever. **Cancel** here is a plain link back to the read-only Preview — it makes no request and changes no state; it must never be confused with the `PENDING_VERIFICATION`-only "Cancel" button elsewhere on the same page, which actually cancels the import.
- **Save to Production maps only `resolution_status = VALID` or `WARNING` staging rows — `UNMATCHED`/`AMBIGUOUS`/`INVALID` rows are never loaded for mapping, let alone written.** `WARNING` rows are explicitly included on purpose (e.g. a `NORMALIZED_NAME`/`NORMALIZED_ALIAS` resolution is still a real, admin-reviewable resolution, just a lower-confidence one than an exact match) — the whole point of Verify is that the admin already accepted the staged data as-is before reaching this step.
- **`rule_type` decides the production target, independent of `resolution_status`: `FARM_TO_FARM` → `downtime_matrix`, `STATIONARY` → `downtime_stationary`, `OTHERS` → nowhere.** An `OTHERS` row (e.g. "Organikultura Area", "Fabrication") has no production-table equivalent at all — even if it were somehow `VALID`/`WARNING`, `DowntimeMatrixImportService::collectProductionRows()` maps it to nothing, contributing 0 to `production_records_created` and 0 to the "Mapped" counts in the result panel.
- **A facility-group side (e.g. "LEP, DC" → `DC_WAREHOUSE`) is expanded at Save-to-Production time, using whichever facilities are active *right now* — never by reusing group membership captured at parse time, and never by writing multiple rows back onto `downtime_matrix_import_rows` itself** (the staging row stays exactly as parsed: `facility_id` null, `*_facility_group_category` set). One `FARM_TO_FARM` staging row with a group on one side produces one `downtime_matrix` row **per currently-active facility in that category** — e.g. a "LEP, DC → Saturn" staging row (2 active `DC_WAREHOUSE` facilities today) produces 2 rows: `DC Plaridel → Saturn` and `DC Sta. Rosa → Saturn`, both carrying the staging row's own `minimum_downtime`/`maximum_downtime` unchanged. This is exactly why `production_records_created` can exceed `staging_rows_processed` in the Production Result panel (see below) — it is not a bug or a double-count.
- **`STATIONARY`'s "Outside" sentinel origin is never stored as a facility, anywhere in production** — `downtime_stationary` has no origin column at all by schema, so this is structurally enforced, not just a mapping choice. The staging row's `destination_facility_id` becomes `downtime_stationary.assigned_facility_id` directly.
- **The already-computed `minimum_downtime`/`maximum_downtime` on a staging row are copied through to production verbatim, never recomputed.** `DowntimeNormalizer` already resolved the Downtime-Area-vs-Dormitory-only distinction at parse time (see the Downtime Area/Dormitory rule above) — a "Dormitory only" reading (`minimum_downtime` = the dormitory hours, `maximum_downtime = null`) lands in `downtime_matrix`/`downtime_stationary` exactly as-is, including the `null` maximum.
- **Existing active production rules are deactivated, never deleted, before the new configuration is written — but a facility pair/assignment that was already used by *any* historical row (active or not) is reactivated and updated in place via a bulk `DowntimeMatrix::upsert()`/`DowntimeStationary::upsert()` call, never a second `INSERT`.** This is a direct consequence of both tables' own standing `UNIQUE` constraints (`downtime_matrix`: `(origin_facility_id, destination_facility_id)`; `downtime_stationary`: `assigned_facility_id`), which apply regardless of `is_active` — a literal "deactivate then always INSERT" approach would violate that constraint the moment any Save to Production run re-touches a pair/assignment a *previous* run (or the pre-existing manual admin data) already created, which is the ordinary, expected case for repeat imports of the same farm set. A pair/assignment untouched by this import's eligible rows simply stays deactivated, preserved as history, exactly as the literal "deactivate, don't delete" requirement describes.
- **Only one import can be `PRODUCED` at a time — producing a new import automatically reverts any *other* import currently sitting at `PRODUCED` back to `VERIFIED`, restoring it to its state immediately before it was produced** (added 2026-08-28, after a live-usage question surfaced that `PRODUCED` had no such uniqueness guarantee). `PRODUCED` means "this import's rows are what's actually active in `downtime_matrix`/`downtime_stationary` right now" — and since a Save to Production run's own unconditional deactivation step (see above) already makes that true regardless of which import previously held the status, leaving the *old* import's status at `PRODUCED` would be a lie the moment a newer one supersedes it. `DowntimeMatrixImportService::produce()` looks up every **other** import currently `PRODUCED` (`where('status', 'PRODUCED')->where('import_id', '!=', $import->import_id)`, expected to normally be 0 or at most 1 row, but not assumed to be) and, inside the same transaction, sets each one back to `status = VERIFIED` with `produced_by`/`produced_at` cleared to `null` — its `verified_by`/`verified_at` are untouched, so it genuinely reverts to exactly the state it was in right after its own Verify click, not a fresh one. This uses a normal per-row Eloquent `update()` (not a bulk query), since the row count here is always small — it is not the same performance-sensitive path as the production-table writes. The reverted import's filename(s) are returned in `produce()`'s result (`reverted_imports`) and shown as a note on the Production Result panel, so the admin sees it happen rather than discovering it later. If a failed attempt (see below) leaves *no* import newly `PRODUCED`, no reversion happens either — the whole transaction, reversion included, rolls back together.
- **All production writes are bulk operations (`DowntimeMatrix::upsert()`/`DowntimeStationary::upsert()`, plus two `where('is_active', true)->update(...)` deactivation calls) — never one Eloquent `create()`/`update()`/`updateOrCreate()` per staging row.** This was a deliberate, load-bearing rewrite (2026-08-28), not a style choice: the original per-row `updateOrCreate()` implementation (each firing an `audit_logs` insert via the `Auditable` trait) hit a real `Maximum execution time of 60 seconds exceeded` fatal error in production use, against a real import with 63 eligible rows on a DB connection with non-trivial per-query latency — a PHP execution-time fatal is **not** catchable by `produce()`'s own `try/catch` (it aborts the script outright, mid-statement), so this failure mode bypassed the graceful-failure handling entirely and surfaced as a raw 500. Because it happened *inside* an open `DB::transaction()`, the dropped connection still rolled everything back safely (confirmed directly against the affected import: status remained `VERIFIED`, `produced_by` stayed null, zero partial `downtime_matrix`/`downtime_stationary` rows) — but relying on "the transaction happens to save us if PHP dies first" is not an acceptable normal-path design. The bulk rewrite collects every target production row in PHP (deduplicated by the production table's own unique key — a genuine in-import duplicate pair or overlapping facility-group expansion collapses to one write, last-value-wins, the same outcome a sequential `updateOrCreate()` would have produced) and issues at most **six** queries total for the entire `produce()` call, regardless of staging-row count: one to load eligible rows, two bulk deactivations, up to two bulk `upsert()`s, and the import's own status update. **A direct, load-bearing consequence: individual production rows written by Save to Production are *not* audit-logged per-row** (bulk `upsert()`/`update()` bypass Eloquent model events entirely — the same precedent already established for `DowntimeMatrixImportRow::insert()` during the parse pipeline) — only the parent `downtime_matrix_imports` row's own `status`/`produced_by`/`produced_at` change is (via a normal Eloquent `update()`), so there is still exactly one "who produced this import, and when" audit record per Save to Production run, just not one per production rule. This is a real, deliberate trade-off against manual Downtime Matrix/Downtime Stationary admin CRUD, which still audit-logs every individual change — see Known Constraints.
- **The entire Save to Production write — deactivation, every mapped insert/update, and the `PRODUCED` status stamp — runs inside one `DB::transaction()`.** If anything throws partway through (a genuine DB error; `DowntimeMatrixImportService::produce()` does not otherwise validate row data beyond what parsing/editing already enforced), the whole transaction rolls back automatically: no partial deactivation, no partial insert, and the import's status is untouched (still `VERIFIED`, `produced_by`/`produced_at` still null). `produce()` catches the exception itself (mirroring how `import()` already handles a parse failure), logs it, and returns `{success:false, error}` rather than letting the request fail with a 500 — the Preview then shows a "Production mapping failed." panel with that message instead of the success summary, and the admin can retry Save to Production once the underlying problem is fixed.
- **`produce()` never modifies `downtime_matrix_import_rows` and never touches the original uploaded PDF** — both stay exactly as they were after Verify, for the full lifetime of the import, including after a successful Save to Production.
- **The Production Result panel** (shown once, directly on the response to a Save to Production POST — not a redirect, not a session flash) reports `staging_rows_processed` (count of `VALID`+`WARNING` rows considered), `production_records_created` (actual `downtime_matrix`/`downtime_stationary` rows written — can exceed the above via facility-group expansion), a `Mapped` breakdown by `VALID`/`WARNING`, and a `Skipped` breakdown by `UNMATCHED`/`AMBIGUOUS`/`INVALID` (read straight from the import's own denormalized `*_rows_count` columns, since those rows were never touched).

**Status Lifecycle:** `downtime_matrix_imports.status`: `PENDING_VERIFICATION → VERIFIED` or `PENDING_VERIFICATION → CANCELLED` (manual, via the Preview page's actions), and (Phase 2, added 2026-08-28) `VERIFIED → PRODUCED` (manual, via the Save to Production confirmation step, on *this* import) with a paired **`PRODUCED → VERIFIED` reversion** that happens automatically, not manually, on any *other* import that was previously `PRODUCED` (see Business Rules — "only one import can be `PRODUCED` at a time"). `PENDING_VERIFICATION → CANCELLED` and `CANCELLED` themselves are still genuinely terminal — `cancel()` is only meaningful, and only rendered, while `PENDING_VERIFICATION`. `VERIFIED` and `PRODUCED`, by contrast, can now cycle: `VERIFIED → PRODUCED` (this import, via its own confirmed Save to Production) and `PRODUCED → VERIFIED` (an older import, automatically, the moment a newer one is produced) are both real, and an import can move back and forth between the two indefinitely as later imports supersede it and (in principle) it could be produced again itself. `PENDING_VERIFICATION → CANCELLED`, `VERIFIED → PRODUCED`, and the automatic `PRODUCED → VERIFIED` reversion are the only transitions with any effect outside `downtime_matrix_imports` itself: the first none, `VERIFIED → PRODUCED` a real write into `downtime_matrix`/`downtime_stationary`, and the reversion none (it only corrects that import's own status bookkeeping — the production tables were already changed by the *new* import's own write, not by the reversion) — see Business Rules. `downtime_matrix_import_rows.resolution_status` (`VALID`/`WARNING`/`UNMATCHED`/`AMBIGUOUS`/`INVALID`) is assigned once at parse time, but **as of 2026-08-28 is no longer strictly immutable** — an admin can manually correct a row via the per-row Edit modal while the import is still `PENDING_VERIFICATION` (see the Edit modal business rule above), which recomputes that row's `resolution_status` and stamps `edited_by`/`edited_at`. Once the import leaves `PENDING_VERIFICATION` (`VERIFIED`/`CANCELLED`/`PRODUCED`), rows are immutable again — the Edit button no longer renders (`canEdit` is `false`), and `updateRows()` itself is a no-op (`applied: false`, row data returned unchanged) for a non-pending import even if called directly. A fresh re-upload still always creates a whole new `downtime_matrix_imports` row rather than editing an existing one's rows in bulk.

**Error Handling:** A parse-time exception (most likely `GridReconstructionException` if the PDF's header structure can't be confidently located) is caught by `DowntimeMatrixImportService::import()`, logged, and recorded on `downtime_matrix_imports.parse_error_message` — the import row still exists (viewable, cancellable) with zero staged rows, rather than the request failing outright. `StoreDowntimeMatrixImportRequest` rejects non-PDF files and any `matrix_type` other than `BFI_BVA` (422/session errors) before parsing ever starts. A production-mapping exception (Phase 2, inside `DowntimeMatrixImportService::produce()`) is caught the same way — logged, the whole `DB::transaction()` rolled back automatically, `{success:false, error}` returned — and rendered as a "Production mapping failed." panel on the Preview rather than a 500; the import's status is left exactly as it was (`VERIFIED`), never left half-`PRODUCED`.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Migrations | `database/migrations/2026_08_27_140000_create_downtime_matrix_imports_table.php`, `..._140001_create_downtime_matrix_import_rows_table.php`, `..._2026_08_28_100000_add_edited_by_to_downtime_matrix_import_rows_table.php` (adds `edited_by`/`edited_at`, nullable, for the per-row Edit feature), `..._2026_08_28_110000_add_promoted_columns_to_downtime_matrix_imports_table.php` + `..._2026_08_28_120000_rename_promoted_columns_to_produced_on_downtime_matrix_imports_table.php` (together add `produced_by`/`produced_at`, nullable, same `nullOnDelete()` pattern as `verified_by`/`cancelled_by` — the columns were briefly named `promoted_*` before Phase 2's mapping design settled on "Production"/`PRODUCED` terminology, so a same-day follow-up migration renames them and backfills any `status='PROMOTED'` row to `'PRODUCED'`; kept as two migrations, not squashed, since the first had already been committed) | Staging schema (additive only) plus, as of Phase 2, the columns tracking who/when an import was produced — `downtime_matrix`/`downtime_stationary` themselves get no schema changes, only new/updated rows |
| Models | `app/Models/DowntimeMatrixImport.php` (Auditable — a real compliance entity; `isProduced()`, `producedBy()` added 2026-08-28 alongside the Phase 2 flow, mirroring `isVerified()`/`verifiedBy()`), `app/Models/DowntimeMatrixImportRow.php` (**now also Auditable, added 2026-08-28** — deliberately harmless for the bulk parse-time insert, since `DowntimeMatrixImportRow::insert()` bypasses Eloquent model events entirely; only a later `->save()` from a manual Edit correction actually fires the `updated` event and gets audit-logged, which is exactly the compliance-relevant subset worth capturing — Phase 2's `produce()` never touches this model at all, so it stays untouched by production mapping too), `app/Models/DowntimeMatrix.php`/`DowntimeStationary.php` (unchanged by this feature — Phase 2 writes to them via their existing `upsert()`/bulk `update()` query-builder calls, the same models `Admin Management`'s own Downtime Matrix/Downtime Stationary CRUD already uses; these specific bulk writes bypass Eloquent model events, so — unlike that manual CRUD — they are not individually audit-logged, see Business Rules) |
| Config | `config/downtime_matrix_import.php` — `non_farm_axis_labels` (structural, read by `MatrixGridParser`), `facility_groups` (resolution → `FARM_TO_FARM`, read by `FacilityImportResolver`; each group's `always_include_as_farm_destination` flag — currently only "LEP, DC" — is read by `MatrixGridParser` to force-synthesize a "no downtime required" row per farm even when blank; the same `resolveGroupMembers()` this config drives is also what Phase 2's `produce()` calls to expand a group at production time), and `stationary_origin_labels` (resolution → `STATIONARY`, currently just `"Outside"`, read by `FacilityImportResolver`/`RuleClassifier`) — three deliberately separate concerns, not all reducible to one list |
| Services | `app/Services/DowntimeMatrixImport/{PdfTextExtractor,GridReconstructor,MatrixGridParser,FacilityImportResolver,FacilityResolutionResult,ParsedDowntimeValue,DowntimeNormalizer,RuleClassifier,ImportValidator,DowntimeMatrixImportService}.php` — `DowntimeMatrixImportService::updateRows()` (added 2026-08-28, unchanged by the later same-day page→modal revision) is the manual-edit write path; `countsFor()` is shared between the parse-time `persistRows()` and the edit-time `updateRows()` so the parent's denormalized counts are computed the same way regardless of which path changed them. `produce()` (Phase 2, added 2026-08-28) is the real Save-to-Production write path — unlike `verify()`/`cancel()`'s plain status flip, this one does real work: collects every eligible (`VALID`/`WARNING`) staging row's target production row(s) in PHP via its private `collectProductionRows()`/`resolvedFacilityIds()` helpers (the latter is what calls `FacilityImportResolver::resolveGroupMembers()` for group expansion), deactivates the prior active `downtime_matrix`/`downtime_stationary` configuration and writes the new one via exactly two bulk `upsert()` calls (rewritten 2026-08-28 from an original per-row `updateOrCreate()` loop, after that version caused a real production timeout — see Business Rules), reverts any *other* import currently `PRODUCED` back to `VERIFIED` (added the same day, once `PRODUCED` was clarified to mean "the currently active one," not just "was produced at some point" — see Business Rules), wraps the whole thing in `DB::transaction()` with its own try/catch, and only then stamps this import's own `status=PRODUCED`/`produced_by`/`produced_at`. |
| Controller | `app/Http/Controllers/Admin/DowntimeMatrixImportController.php` — the one Admin controller in this codebase that delegates to a service instead of doing direct Eloquent (the parse pipeline is too complex for the usual pattern); also the only one whose `view()` helper takes a per-call-site full-page fallback, since its upload form is a deliberate non-ajax plain POST (see Known Constraints). `rowsData()` (added 2026-08-28) is the Preview's Data Table JSON endpoint — a sibling to `data()` (the import list's own Data Table endpoint), not a reuse of it, since the model/columns/filters are entirely different. `sideDisplay()` is the server-side move of what used to be the Blade `$sideDisplay` closure. `rowPayload()` (added 2026-08-28, same day as the page→modal revision) is the shared per-row JSON shape both `rowsData()` and `updateRows()` return — it's what lets the Edit modal pre-fill from a row's own DataTables data with no extra request. `updateRows()` returns JSON (`{applied, rows}`) rather than a rendered view — `applied: false` and unchanged row data is how it signals a no-op when the import isn't `PENDING_VERIFICATION`. The standalone `editRows()` action and its route were removed in the same revision — there is no page-based fallback anymore. `produceConfirm()`/`produce()` (Phase 2, added 2026-08-28) back the Save to Production flow — `produceConfirm()` reuses `showResponse()` with `$productionMode=true` (a GET, purely a view toggle, no state change) and `produce()` calls the service's `produce()` only when `isVerified()`, threading its `{success, ...}` result array into `showResponse()`'s new `$productionResult` param either way; both fall back to the plain Preview (no confirmation panel, no result panel) otherwise, the same "fall back to current state, don't error" shape `verify()`/`cancel()` already use. |
| Request | `app/Http/Requests/Admin/StoreDowntimeMatrixImportRequest.php`, `UpdateDowntimeMatrixImportRowsRequest.php` (added 2026-08-28 — validates the `rows[{import_row_id}][...]` shape; still accepts more than one row per call, though only the modal's single-row submission and legacy/API callers would ever use that) — `produce()` needs no FormRequest of its own either, same as `verify()`/`cancel()` (no body to validate, just a route-bound model and the group's `permission:downtime_matrix_import.manage` route middleware) |
| Views | `resources/views/admin/downtime-matrix-import/{index,create,show}.blade.php` (full-page wrappers) and `_{index,create,show}.blade.php` (ajax partials) — `_show.blade.php`'s three tabs are Data Table shells (empty `<tbody>`, filter controls, Filter/Reset buttons) since 2026-08-28, each with an added `orderable:false` **Actions** column whose `render()` emits an Edit button (only when the page's `canEdit` JS flag is `true`) carrying that row's full `rowPayload()` data as a `data-row` attribute. The same file also carries the shared `#dmi-edit-modal` markup (Origin/Destination `<select>`s populated from `$facilities`, an active-facility list `showResponse()` now passes in) and its open/save/close JS. The standalone `{edit-rows,_edit-rows}.blade.php` page views (added, then removed, both 2026-08-28) no longer exist. `_show.blade.php`'s bottom action block (Phase 2, added 2026-08-28) now branches four ways in priority order: a Production Result panel first (`$productionResult` set — success summary or failure message), then Verify/Cancel while `PENDING_VERIFICATION`, then the Save to Production confirmation panel (`$productionMode` true) with its filename/date/counts summary table, then (plain `isVerified()`, revised the same day) a standalone **Production** button linking to `produce.confirm` — this last branch is what lets an admin reach the confirmation step directly from the Preview (including the response to the Verify click itself) instead of having to navigate back to the import list first — nothing otherwise. `_index.blade.php`'s `renderActions()` (added 2026-08-28) conditionally appends the same **Production** link next to **View** on the list, client-side, gated on `row.status === 'VERIFIED'`. |
| Tests | `tests/Unit/Services/DowntimeMatrixImport/*.php`, `tests/Feature/Admin/DowntimeMatrixImportAdminTest.php` (includes the real sample PDF as a fixture, a standalone test asserting `downtime_matrix`/`downtime_stationary` are never written to through `VERIFIED`, `rows-data` coverage: envelope shape, `rule_type` scoping, Status/Search filters, facility-group display resolution, pagination, column-index-aware sort ordering, and that the payload carries the raw fields the Edit modal needs; manual-edit coverage against `rows.update` directly — status recompute on save including the `UNMATCHED`/`INVALID` recheck cases, denormalized-count recomputation, permission gating, and that it's a no-op once the import is no longer `PENDING_VERIFICATION`; a modal-availability test asserting the page's `canEdit` JS flag tracks `PENDING_VERIFICATION` status; and Phase 2 Production-mapping coverage — the confirmation step falls back to a plain Preview unless `VERIFIED` and otherwise shows the full filename/date/counts summary plus Save to Production/Cancel, viewing it alone makes no DB change, `produce()` is a no-op unless `VERIFIED`, a real end-to-end mapping run (VALID/WARNING mapped with an independently-recomputed expected count, UNMATCHED/AMBIGUOUS/INVALID all individually confirmed skipped, `FARM_TO_FARM`→`downtime_matrix`/`STATIONARY`→`downtime_stationary` with correct facility ids, "Outside" never stored, `"LEP, DC"` expanding to both active `DC_WAREHOUSE` facilities, a crafted dormitory-only reading copied through with a null maximum, prior active rules deactivated-not-deleted using off-PDF facilities to isolate the assertion, and a genuine rolled-back-transaction case via a mocked `FacilityImportResolver::resolveGroupMembers()` throw), permission gating on both the GET confirmation route and the POST; that the Preview page's own Production shortcut button appears exactly when `isVerified()` (including in the response to the Verify click itself) and is absent for `PENDING_VERIFICATION`/`CANCELLED`/`PRODUCED`; and that producing a second import reverts a previously-`PRODUCED` import back to `VERIFIED` with its original `verified_by`/`verified_at` preserved and `produced_by`/`produced_at` cleared, the reverted import's filename named in the Production Result panel, and no reversion note shown when there was nothing to revert) |

**Configuration:** `config/downtime_matrix_import.php` (see above). No new environment variables — `smalot/pdfparser` is a composer dependency only.

**Known Constraints:** Single page only — `PdfTextExtractor` reads page 1 and does not support a multi-page matrix. Synchronous parsing on the upload request — no queue; fine for the current single-page/~100-cell document, would need revisiting if a much larger matrix appears. The upload form (`_create.blade.php`) is **deliberately not** an `.ajax-form` — `public/js/admin.js` submits ajax-forms via jQuery's `form.serialize()`, which silently drops file inputs, so this one form does a plain `multipart/form-data` browser POST instead; every other action in this feature (Verify/Cancel, Save to Production, and the Preview's own Filter/Reset buttons) uses the standard `.ajax-form`/AJAX conventions normally. `GridReconstructor`'s coordinate-clustering tolerances (`Y_TOLERANCE = 3.0`, the 100/200 origin-label-region X cutoffs) were tuned against the one real sample PDF in `documents/` and `tests/Fixtures/` — a structurally different BFI/BVA layout (e.g. a different number of farms, or non-uniform row/column spacing) may need them revisited. Facility resolution depends on `facility_aliases` being populated for any PDF label that doesn't collapse to an exact/normalized `facility_list.facility_name` — with zero aliases seeded (the real production starting state), most real rows land `WARNING` (normalized match) rather than `VALID`, which is correct/expected, not a bug (and, as of Phase 2, still fully eligible to be mapped to production — see Business Rules). **The Farm-to-Farm/Others tabs' `rows-data` request mapping depends on which tab is asking** — Stationary's `columns` config omits the Origin column, shifting every later column's real JS position left by one, so `DowntimeMatrixImportController::rowsData()` picks its `$orderableColumns` index→name map based on the request's own `rule_type` param rather than a single fixed map; keep that in sync if either tab's `columns` config is ever reordered. **`produce()` does not gate on the *count* of `UNMATCHED`/`AMBIGUOUS`/`INVALID` rows before running** — a `VERIFIED` import with plenty of unresolved rows can still be produced; those specific rows are simply skipped (never mapped), which is correct per the instructions for this phase (map `VALID`/`WARNING`, skip everything else) rather than a promotion-readiness gate. **A production facility pair/assignment that any *other*, unrelated import already produced is silently reactivated and overwritten (via bulk `upsert()`) by a later Save to Production run that happens to touch the same pair** — this is a deliberate, necessary consequence of `downtime_matrix`/`downtime_stationary`'s own standing `UNIQUE` constraints (see Business Rules), not a bug, but it does mean two imports covering overlapping facility sets will "fight" over the same production rows rather than each getting its own independent set — there is no per-import ownership concept for produced rows. **Save to Production's own writes into `downtime_matrix`/`downtime_stationary` are not individually audit-logged** (see Business Rules) — if an admin needs to know exactly which production rows a given Save to Production run touched, today the only trace is `downtime_matrix_imports.produced_by`/`produced_at` plus reasoning about which of the import's own staged rows were eligible; there is no row-level "changed by this import" record in `audit_logs` the way manual Downtime Matrix/Downtime Stationary CRUD provides.

**Important Notes for Future Changes:** The natural next enhancement here is a `resolution_status`-count gate on `produce()` itself (e.g. warn, or require explicit override, before producing an import with a nonzero `unmatched_rows_count`/`ambiguous_rows_count`/`invalid_rows_count` — the `*_rows_count` columns already make this cheap) — today `produce()` silently skips those rows without surfacing that as a decision point beyond the Skipped breakdown in the Production Result panel, which an admin could plausibly miss. If a genuine "one production row per import" ownership model is ever wanted (see the Known Constraints note about imports fighting over overlapping pairs), it would need a new column on `downtime_matrix`/`downtime_stationary` tying a row back to the `downtime_matrix_imports` row that (most recently) produced it — out of scope for this phase, and not requested. Do not modify `FacilityResolver` (Visitor Sync's) to share logic with `FacilityImportResolver` — their normalization rules solve different, incompatible source-text conventions (AppSheet's prefix style vs. this PDF's suffix+parenthetical style); if genuine duplication needs removing later, extract only the parts that are truly identical (e.g. the alias-lookup query shape), not the normalization functions themselves. If `GridReconstructor` needs to support a second PDF layout, prefer adding new anchor/config-driven detection over hardcoding a second parsing path. **Do not change `DowntimeMatrixImportService::produce()`'s bulk `upsert()`/`where()->update()` writes back to a per-row `updateOrCreate()`/`create()` loop "for audit completeness"** — that per-row version is what originally shipped and it caused a real `Maximum execution time of 60 seconds exceeded` fatal error against a production-sized import (see Business Rules for the full incident) precisely because it is uncatchable by `produce()`'s own error handling. If per-row audit logging of individual production rules is ever genuinely required, it needs to be re-added in a way that doesn't reintroduce one query (plus one `audit_logs` insert) per eligible staging row — e.g. a single summarized `audit_logs` entry per Save to Production run (listing the affected pairs/assignments in its JSON payload) rather than one row-level Eloquent event per production rule.

---

### Module: `Authentication & Authorization`

**Purpose:** Session-based login for back-office admin users, plus a simple single-role-per-user, role→permissions RBAC model gating every admin route.

**Responsibilities:** Credential verification; session login/logout; permission-key checks at both the route-middleware and FormRequest-authorization layers.

**Entry Points:** `GET/POST /login`, `POST /logout` (`routes/web.php`, unauthenticated). `permission:<key>` middleware guards everything under `auth`.

**Main Flow:**
```text
POST /login {email, password, remember?}
   ↓
LoginRequest (email format, password min:6)
   ↓
AuthService::authenticate — User::where(email, is_active=true) + Hash::check against hash_password
   ↓
success  → Auth::login($user, remember) → redirect dashboard
failure  → back()->withErrors(['email' => 'Invalid credentials.'])   (deliberately generic — does not reveal which field was wrong)
```

**Data Used:** `users`, `roles`, `permissions`, `role_permissions` (pivot).

**Dependencies:** Laravel's built-in `Auth` facade/session guard (default guard, `config/auth.php` — standard Laravel defaults, not customized beyond `getAuthPasswordName()` returning `hash_password`).

**Related Modules:** Gates every `Admin Management` route. `User::hasPermission()`/`hasAnyPermission()` are the single source of truth `CheckPermission` middleware and every admin FormRequest call into.

**Business Rules:**
- A user with no assigned `role_id` (or a role with no matching permission) has **zero** permissions — `hasPermission()` returns `false` if `$this->role` is null, never throws.
- Login requires `is_active = true` on the `users` row — a deactivated user cannot authenticate even with correct credentials.
- One role per user (`role_id` is a single FK, not a pivot) — there is no multi-role support.

**Status Lifecycle:** N/A (no multi-step auth state beyond logged-in/out).

**Error Handling:** Invalid credentials → generic error, no user enumeration. `CheckPermission` middleware: unauthenticated → redirect to login; authenticated but lacking permission → `abort(403)`.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Controller | `app/Http/Controllers/Auth/LoginController.php` | Login/logout |
| Service | `app/Services/AuthService.php` | Credential check, user create/update (password hashing) |
| Middleware | `app/Http/Middleware/CheckPermission.php` | Route-level permission gate |
| Model | `app/Models/User.php` | `hasPermission`, `hasAnyPermission`, `getAuthPasswordName` override |

**Configuration:** `APP_SESSION_TIMEOUT` (`config('sentry.session_timeout')`) and `max_login_attempts`/`lockout_duration`/`password_min_length` are defined in `config/sentry.php` but `NEEDS VERIFICATION`: no code currently reads `max_login_attempts`, `lockout_duration`, or `password_min_length` from this config — there is no visible lockout/throttle implementation wired to the login route. Treat these as **not currently enforced** unless you find otherwise.

**Known Constraints:** No password-reset flow implemented (`Illuminate\Auth\Passwords\PasswordResetServiceProvider` is registered by the framework default, but no controller/route in this app uses it). No 2FA. No login rate limiting despite config values existing for it (see above).

**Important Notes for Future Changes:** If you implement login throttling, wire it to the existing `config('sentry.max_login_attempts')`/`lockout_duration` values rather than inventing new config keys — they were clearly intended for this and are currently dead config.

---

### Module: `Audit Logging` (cross-cutting)

**Purpose:** Automatic, tamper-evident-ish record of every create/update/delete across nearly all models, for compliance/traceability (biosecurity + visitor-tracking context implies this matters for regulatory reasons).

**Responsibilities:** Transparently record `action`, `module` (model class basename), `record_id`, before/after JSON, acting user, and IP on every mutation of an `Auditable` model.

**Entry Points:** Not HTTP-facing — an Eloquent model trait (`App\Traits\Auditable`) hooked into `created`/`updated`/`deleted` model events via `bootAuditable()`.

**Main Flow:**
```text
Any Eloquent model using the Auditable trait: create() / update() / delete()
   ↓
static::created / updated / deleted event fires
   ↓
logAuditEvent(action, oldValues?, newValues?)
   ↓
AuditLog::create([user_id: auth()->id(), action, module: class_basename($this), record_id: $this->getKey(),
                   old_value_json, new_value_json, ip_address: request()?->ip(), created_at: now()])
```

**Data Used:** `audit_logs` table. Every model in `app/Models/` uses this trait **except** `Permission` — `NEEDS VERIFICATION`: confirm `Permission` uses it — and `VisitorType`, `AuditLog` itself, and `ApiLog` (checked directly against the model list: `UserDirectory`, `VisitorProfile`, `VisitorRequest`, `VisitorSession`, `VisitorEntryLog`, `FaceProfile`, `KioskDevice`, `User`, `Role`, `Permission`, `FarmList`, `FarmAlias`, `EmployeeType`, `IdentityType`, `DowntimeMatrix`, `DowntimeStationary` all declare `use Auditable`; `VisitorType`, `AuditLog`, `ApiLog` do not). This enumeration predates the Facility Master Data and Downtime Matrix PDF Import modules and was never extended to them — `NEEDS VERIFICATION` for `FacilityList`/`FacilityAlias`/`FacilityType`/`FacilityCategory`/`DowntimeMatrixImport` (all confirmed Auditable at the time each was added, per their own module sections) and `DowntimeMatrixImportRow` (added 2026-08-28, alongside the manual per-row edit feature — see the Downtime Matrix PDF Import module's Important Files for why it's harmless on the bulk parse insert).

**Dependencies:** None external — pure Eloquent event hooks.

**Related Modules:** Applies across every module in this document that touches an Eloquent model with the trait — this is genuinely cross-cutting, not owned by any one business module.

**Business Rules:**
- Runs **synchronously and unconditionally** inside the same request — a mutation that succeeds always produces an audit row in the same transaction context as the mutation itself (no queueing, no batching).
- `user_id` is `auth()->id()`, which is `null` for any unauthenticated mutation (e.g., a Kiosk or Visitor Sync write) — audit rows from those flows will have a null actor. This is expected, not a bug, but means audit trail attribution for kiosk/self-service/sync activity relies on `ip_address` and the surrounding `visitor_request`/`kiosk_device` records instead of a `user_id`.
- `VisitorEntryLog` has `public $timestamps = false` and is **not** `Auditable` — its own `datetime` column is the record of when it happened; it is not separately audit-logged. `NEEDS VERIFICATION` if this is intentional (avoiding double-logging high-frequency kiosk events) or an oversight.

**Error Handling:** No special handling — if `AuditLog::create()` itself were to fail, it would throw like any other Eloquent call and abort the outer request (no try/catch around it). `NEEDS VERIFICATION`: this means an audit-logging failure (e.g., a DB constraint issue on `audit_logs`) would currently take down the triggering business operation too, since there's no isolation between them.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Trait | `app/Traits/Auditable.php` | Entire module |
| Model | `app/Models/AuditLog.php` | Storage/read model |
| Service | `app/Services/AuditLogService.php` | Admin-facing filtered queries |
| Controller | `app/Http/Controllers/Admin/AuditLogController.php` | Read-only viewer |

**Configuration:** None.

**Known Constraints:** `audit_logs` will grow unboundedly (every kiosk entry/exit touches multiple Auditable models via cascading relation updates) — no retention/pruning job exists. `NEEDS VERIFICATION`/flag if asked about audit-log storage growth or performance of `AuditLogController::index` at scale.

**Important Notes for Future Changes:** Do not remove the `Auditable` trait from a model to "reduce noise" without discussing — for a biosecurity/compliance system this is very likely a hard requirement, not an optional nicety. If adding a new model that represents a real business entity (not a lookup/reference table), add the trait unless there's a specific reason not to (as was apparently decided for `VisitorType`).

---

## 3. Module Dependency Map

```text
                          ┌─────────────────────┐
                          │   AppSheet (bot)     │
                          └──────────┬───────────┘
                                     │ POST /api/v1/visitor/sync
                                     ▼
                          ┌─────────────────────┐
                          │   Visitor Sync       │──────┐
                          └──────────┬───────────┘      │ registration_token
                                     │                   ▼
                                     │          ┌─────────────────────┐
                                     │          │ Visitor Registration │
                                     │          └──────────┬───────────┘
                                     │                     │ face registered
                                     ▼                     ▼
                          ┌─────────────────────────────────────────┐
                          │              Face Matching               │◄────────────┐
                          └───────────────────┬───────────────────────┘             │
                                              │                                     │
                     ┌────────────────────────┼─────────────────────────┐           │
                     ▼                        ▼                         ▼           │
           ┌──────────────────┐    ┌────────────────────┐    ┌──────────────────┐  │
           │   Kiosk Entry     │    │ Kiosk Self-Service  │    │ (Employee — n/a)  │  │
           │ (Visitor-Approval)│───▶│  (Gatesale/Truck)    │    └──────────────────┘  │
           └─────────┬─────────┘    └──────────┬───────────┘                         │
                     │ processEntry()          │ processEntry() (shared)             │
                     ▼                          ▼                                     │
           ┌─────────────────────────────────────────────┐                           │
           │        visitor_session / visitor_entry_logs   │                           │
           └───────────────────────┬───────────────────────┘                          │
                                   │                                                  │
                     ┌─────────────┴─────────────┐                                    │
                     ▼                             ▼                                  │
        ┌─────────────────────────┐   ┌─────────────────────────────┐                 │
        │ Google Sheets Integration │   │  Session Auto-Resolution      │──(also calls)─┘
        │ (excluded for Gatesale/   │   │  (daily scheduled job)        │
        │  Truck)                  │   └─────────────────────────────┘
        └─────────────────────────┘

        Cross-cutting, touches almost everything above:
        ┌───────────────────────────────────────────────────────────┐
        │                      Audit Logging (trait)                  │
        └───────────────────────────────────────────────────────────┘

        Configuration/back-office, feeds the runtime modules with reference data:
        ┌───────────────────────────────────────────────────────────┐
        │  Admin Management (Facilities/Aliases/Kiosks/Roles/Users/etc.) │
        │        ↑ gated by ↓                                          │
        │  Authentication & Authorization                              │
        └───────────────────────────────────────────────────────────┘
                                     ▲
                                     │ writes downtime_matrix/downtime_stationary
                                     │ via Save to Production (Phase 2)
                          ┌─────────────────────────────┐
                          │ Downtime Matrix PDF Import     │
                          │ (parse/validate/preview, then  │
                          │  Save to Production maps into  │
                          │  downtime_matrix/stationary)   │
                          └─────────────────────────────┘
```

**In plain language:**
- `Admin Management` and `Authentication & Authorization` sit underneath everything — they provision the Facilities/Kiosks/Roles/Users that the runtime (kiosk-facing) modules depend on, but have no reverse dependency on them.
- `Visitor Sync` is the only inbound integration point for pre-approved visitors; it hands off to `Visitor Registration` via a token, which hands off to `Kiosk Entry` via a now-recognizable face/QR.
- `Kiosk Self-Service` is a parallel, self-contained path that bypasses both `Visitor Sync` and `Visitor Registration` entirely — Gatesale/Truck visitors are created and registered at the kiosk itself, then immediately reuse `Kiosk Entry`'s `processEntry` state machine.
- `Face Matching` is a pure dependency of three modules, with no dependencies of its own.
- `Google Sheets Integration` and `Session Auto-Resolution` are both one-way consumers of state produced by the two Kiosk modules; neither ever triggers a write back into `visitor_request`/`visitor_session` that the Kiosk modules would need to react to (except `Session Auto-Resolution`'s own status updates, which are themselves terminal).
- `Downtime Matrix PDF Import` sits beside `Admin Management` — it reads `facility_list`/`facility_aliases` the same layer does, and (Phase 2) its Save to Production step is a genuine upstream writer of `Admin Management`'s Downtime Matrix/Downtime Stationary data, via the same `DowntimeMatrix`/`DowntimeStationary` Eloquent models those admin screens use. Still no *runtime* (kiosk-facing) module depends on it — nothing downstream of `downtime_matrix`/`downtime_stationary` reads them for any live business decision (see Known Issue #3).

---

## 4. Cross-Module Processes

### Process: Full pre-approved visitor lifecycle (Visitor Sync → Kiosk Entry)

- **Starting module:** Visitor Sync
- **Trigger:** AppSheet bot POSTs an approved visit.
- **Intermediate modules:** Visitor Registration (face capture), Face Matching (used both at registration and at every later kiosk recognition).
- **Data passed between modules:** `registration_token` (Sync → Registration, via a link sent outside this system); `directory_id`/`face_profile` (Registration → Kiosk, via the shared `user_directory`/`face_profile` tables — no explicit "handoff" call, just shared state).
- **Ending module:** Kiosk Entry (session lifecycle) → Session Auto-Resolution (if never manually completed) and/or Google Sheets Integration (Time In/Out mirrored externally).
- **External systems involved:** AppSheet (inbound only), Google Sheets (outbound only).
- **Important state changes:** `visitor_request.face_registration_status`: `PENDING → REGISTERED` (or `FAILED_MATCH`, terminal-but-not-blocking) is the gate between "cannot be recognized at a kiosk yet" and "can be recognized." `visitor_request.request_status`: `ACTIVE → COMPLETED` (or `COMPLETED_AUTO`/`INCOMPLETE`).

### Process: Self-service visitor lifecycle (Kiosk Self-Service, no upstream systems)

- **Starting module:** Kiosk Self-Service (a kiosk `recognize`/`register-identity` call).
- **Trigger:** A Gatesale/Truck person walks up to a kiosk with no prior record in the system.
- **Intermediate modules:** Face Matching (dedup check), Kiosk Entry (`processEntry` reused for the actual session bookkeeping).
- **Data passed between modules:** None external — entirely self-contained within one kiosk session.
- **Ending module:** Kiosk Entry's session lifecycle; Session Auto-Resolution as the safety net.
- **External systems involved:** None — this is the one runtime flow that never touches Google Sheets or any external API.
- **Important state changes:** Same `request_status`/`session_status` transitions as above, but `registration_token` is always `null` and `face_registration_status` is set directly to `REGISTERED` at visit creation (no separate registration step exists for this path).

### Process: Stale-session cleanup (any Kiosk module → Session Auto-Resolution)

- **Starting module:** Either Kiosk module, whenever a visitor fails to complete `final_exit` before their approved window closes.
- **Trigger:** Daily cron at 00:00.
- **Intermediate modules:** None — reads `visitor_request`/`visitor_session`/`visitor_entry_logs` directly.
- **Data passed between modules:** None inbound; outbound to Google Sheets Integration only for the `COMPLETED_AUTO` (recovered exit) case, and only for non-excluded (i.e., not Gatesale/Truck) requests.
- **Ending module:** Google Sheets Integration (conditionally) and the terminal status itself, which then blocks all future reuse via both Kiosk modules' `isCompleted()` checks.
- **External systems involved:** Google Sheets (conditionally).
- **Important state changes:** `ACTIVE → INCOMPLETE` or `ACTIVE → COMPLETED_AUTO`, both terminal.

---

## 5. Core Data Model

```text
identity_type ──┐
                ├──▶ user_directory ──┬──▶ visitor_profile (visitor_type_id, company, plate_no)
employee_type ──┘         │           │        │
                           │           │        └──▶ visitor_type (Visitor/Gatesale/Truck — NOT admin-managed, see gap noted above)
                           │           │
                           │           ├──▶ face_profile (embedding[], is_active)
                           │           │
                           │           └──▶ visitor_request ──┬──▶ facility_list (was farm_list until 2026-08-27 cutover)
                           │                     │              │
                           │                     │              └──▶ visitor_session ──▶ visitor_entry_logs ──▶ kiosk_device ──▶ facility_list
                           │                     │
                           │                     └── (registration flow only) registration_token, qr_url
                           │
                           └── (RBAC, separate tree) role ──▶ role_permissions ──▶ permission
                                      │
                                      └──▶ users

facility_type ──┐
                ├──▶ facility_list ──┬──▶ facility_aliases
facility_category ──┘                ├──▶ visitor_request.facility_id (RESTRICT)
                                      ├──▶ kiosk_device.facility_id (CASCADE)
                                      ├──▶ downtime_matrix.origin_facility_id / destination_facility_id (CASCADE, self-referential)
                                      └──▶ downtime_stationary.assigned_facility_id (CASCADE)
(all four FKs above are live since the 2026-08-27 cutovers — Phase 2 for
 visitor_request/kiosk_device, Phase 4 for downtime_matrix/downtime_stationary;
 admin CRUD for facility_list/facility_aliases themselves added in Phase 3)

farm_list ──▶ farm_aliases
(as of the 2026-08-27 cutovers, farm_list/farm_aliases have ZERO remaining
 live FKs from any other table AND no admin UI (Phase 5 removed the Farms/
 Farm Aliases screens entirely) — pure legacy data, kept only as a rollback
 safety layer, nothing in the application reads or writes them)

(cross-cutting) audit_logs — one row per create/update/delete on any Auditable model, FK to users.user_id (nullable)
api_logs — one row per Visitor Sync call and per Google Sheets write attempt

downtime_matrix_imports ──▶ downtime_matrix_import_rows ──▶ facility_list (origin/destination, nullable)
(new 2026-08-27, Downtime Matrix PDF Import module — staging only, Phase 1;
 NOT connected to downtime_matrix/downtime_stationary in any direction)
```

### Entity notes

- **`user_directory`** — Purpose: the canonical "person" record (visitor, employee, contractor, etc.). Key: `directory_id`. Notable columns: `identity_type_id` (required FK — drives `routeByIdentity()`'s branching), `person_reference` (a synthetic uniqueness key, not always email), `full_name`/`email`/`phone`. **Created by:** Visitor Sync, Visitor Registration (indirectly, via directory-reuse), Kiosk Self-Service registration. **Updated by:** Kiosk Self-Service `update-details`, Visitor Registration (face-link confirmation repoints `visitor_request.directory_id`, not this table). **Read by:** everything. As of migration `2026_08_12_115846`, `visitor_type_id`/`company`/`plate_no` were **removed** from this table and now live exclusively on `visitor_profile` — if you see old code/docs referencing those columns directly on `UserDirectory`, they are stale.
- **`visitor_profile`** — Purpose: visitor-specific attributes, 1:1 with `user_directory` (unique `directory_id`). **Sole source of truth** for `visitor_type_id`/`company`/`plate_no` since the migration above. Cascade-deletes with its directory.
- **`face_profile`** — Purpose: one or more biometric templates per directory. Key: `face_profile_id`. `embedding` is a JSON array of floats (128-D, `face-api.js` convention). `is_active` gates whether `FaceMatchingService` considers it.
- **`visitor_request`** — Purpose: one specific approved (or self-service) visit. Key: `visitor_request_id`. Status fields: `approval_status` (`Approved` is the only value seen in code — no rejection/pending flow implemented for the Sync path), `request_status` (`ACTIVE`/`COMPLETED`/`COMPLETED_AUTO`/`INCOMPLETE`), `face_registration_status` (`PENDING`/`REGISTERED`/`FAILED_MATCH`). `visitor_id` is the AppSheet-issued idempotency key and QR payload (nullable — self-service requests have none). `registration_token` nullable (self-service requests have none). **Created by:** Visitor Sync, Kiosk Self-Service. **Updated by:** Kiosk Entry (`processEntry`), Session Auto-Resolution, Visitor Registration (re-pointing `directory_id` on a confirmed match). As of migration `2026_08_27_120001`, its site-binding column is `facility_id` (FK → `facility_list.facility_id`, `RESTRICT` on delete) — **not** `farm_id`/`farm_list` anymore; `facility()` replaces the old `farm()` relation.
- **`visitor_session`** — Purpose: one physical "inside the farm" episode per request (a request can have more than one session over its lifetime, e.g. multiple temporary-exit/return cycles do NOT create new sessions — a session persists across those; only `final_exit`/auto-resolution closes it). Key: `visitor_session_id`. `login_id`/`logout_id` are independently generated 8-char codes, never assumed equal, unique across the whole table (checked against both columns to avoid cross-collision).
- **`visitor_entry_logs`** — Purpose: immutable append-only log of every individual movement event (`First Entry`/`Temporary Exit`/`Return`/`Final Exit`, each with `IN`/`OUT` + timestamp + optional photo). No `updated_at` (`$timestamps = false`). Never updated after creation, only inserted.
- **`kiosk_device`** — Purpose: one physical tablet, tied to exactly one facility. `kiosk_token` is the bearer credential for all `kiosk.auth`-gated routes, auto-generated on create, rotatable via admin. As of migration `2026_08_27_120002`, its site-binding column is `facility_id` (FK → `facility_list.facility_id`, `CASCADE` on delete) — **not** `farm_id`/`farm_list` anymore; `facility()` replaces the old `farm()` relation, and the Kiosk Devices admin screen's dropdown now lists `FacilityList::all()`.
- **`farm_list`** / **`farm_aliases`** — Purpose (historical): canonical farm records and alternate spellings AppSheet might send; aliases existed specifically to avoid fuzzy matching in the service that resolved them. **As of the 2026-08-27 cutovers (Phases 2 and 4), and the Phase 5 removal of the Farms/Farm Aliases admin CRUD, nothing in the application reads or writes these two tables at all** — no runtime module, no admin UI, no remaining FKs from any other table into `farm_list`. They are pure legacy data, deliberately kept (not dropped) per the source instructions' rollback guidance, with their `FarmList`/`FarmAlias` Eloquent models also left in place (unused, but harmless) since the tables still exist.
- **`downtime_matrix`** (renamed from `biosecurity_rules` on 2026-08-26; `area_type` column dropped that same day; `access_level` column dropped in a follow-up migration the same day; `minimum_downtime`/`maximum_downtime` widened from `INTEGER` to `DECIMAL(6,2)` and a `UNIQUE(origin_farm_id, destination_farm_id)` constraint added on 2026-08-27; `origin_farm_id`/`destination_farm_id` renamed and repointed to `origin_facility_id`/`destination_facility_id` → `facility_list.facility_id` later the same day, Phase 4) — Purpose: origin→destination facility downtime pairs, one rule per facility-pair (a FARM, PLANT, DC_WAREHOUSE, or OTHER facility on either side, not only a farm). Key: `rule_id`. Columns: `origin_facility_id`, `destination_facility_id` (both FK → `facility_list.facility_id`, `CASCADE` on delete, jointly unique via the explicitly-named `downtime_matrix_origin_dest_facility_unique` constraint — Laravel's auto-generated name for this pair exceeds MySQL's 64-char identifier limit), `minimum_downtime`/`maximum_downtime` (nullable `DECIMAL(6,2)`, supports fractional hours), `is_active`. Model: `App\Models\DowntimeMatrix` (`originFacility()`/`destinationFacility()` relations, renamed from `originFarm()`/`destinationFarm()`). Both FormRequests enforce the composite uniqueness pre-save (`Rule::unique('downtime_matrix')->where(...)`) so a duplicate facility-pair surfaces as a normal 422 validation error rather than a raw DB constraint violation. **Not currently read by any kiosk-facing/runtime business logic** — no code path queries this table to gate a decision (`NEEDS VERIFICATION`; the Phase 4 cutover changed only the master-data relationship, not this fact). As of the Downtime Matrix PDF Import module's Phase 2 (2026-08-28), however, this table **is** actively written to — a Save to Production run maps a verified import's eligible `FARM_TO_FARM` rows into it (`DowntimeMatrixImportService::produce()`, via a bulk `upsert()` keyed by this table's own unique pair — not per-row Eloquent calls, for real performance reasons, see that module's Business Rules), deactivating (not deleting) whatever was previously `is_active`. Manual admin CRUD via `DowntimeMatrixController` and Save to Production now both write the same rows through the same model — there's no separation between "manually entered" and "PDF-produced" rows once they land here, though only the former is individually audit-logged (`upsert()` bypasses Eloquent model events).
- **`downtime_stationary`** (new table, 2026-08-26; columns renamed from `minimum_downtime_hours`/`max_downtime_hours` to `minimum_downtime`/`maximum_downtime` and widened from `DECIMAL(5,2)` to `DECIMAL(6,2)`, plus a `UNIQUE(assigned_farm_id)` constraint added, on 2026-08-27; `assigned_farm_id` renamed and repointed to `assigned_facility_id` → `facility_list.facility_id` later the same day, Phase 4) — Purpose: a fixed min/max downtime window assigned to a single facility, one rule per facility (no origin/destination pairing, unlike Downtime Matrix). Key: `rule_id`. Columns: `assigned_facility_id` (FK → `facility_list.facility_id`, `CASCADE` on delete, unique), `minimum_downtime`/`maximum_downtime` (nullable `DECIMAL(6,2)`), `is_active`. Model: `App\Models\DowntimeStationary` (`assignedFacility()` relation, renamed from `assignedFarm()`). Sibling submodule to Downtime Matrix under the same Biosecurity Rules module/permission; both FormRequests validate `assigned_facility_id` uniqueness pre-save the same way Downtime Matrix does. **Not currently read by any kiosk-facing/runtime business logic**, same as Downtime Matrix (`NEEDS VERIFICATION`); had zero rows at the time of the Phase 4 cutover, so nothing needed backfilling. As of the Downtime Matrix PDF Import module's Phase 2 (2026-08-28), a Save to Production run maps a verified import's eligible `STATIONARY` rows into this table the same way (a bulk `upsert()` keyed by `assigned_facility_id`, deactivating rather than deleting whatever was previously active) — see that module's Business Rules.
- **`role` / `permission` / `role_permissions` / `users`** — Purpose: RBAC. One role per user; permissions are string keys (`resource.action` convention) checked via `User::hasPermission()`.
- **`audit_logs`** — Purpose: append-only change log, see the Audit Logging module.
- **`api_logs`** — Purpose: append-only integration call log, shared by Visitor Sync and Google Sheets Integration (both write to the same table, distinguished by `endpoint`).
- **`facility_type`** / **`facility_category`** / **`facility_list`** / **`facility_aliases`** (new tables, 2026-08-27 — see the "Facility Master Data" module above) — Purpose: normalized, multi-brand replacement structure for `farm_list`/`farm_aliases`, seeded with real reference data (8 types, 4 categories, 16 facilities). **As of the same-day Phase 2 cutover, this is now the live, authoritative site-binding target** for `visitor_request.facility_id` and `kiosk_device.facility_id`, and the resolution target for Visitor Sync's `FacilityResolver`. `facility_list.facility_code` is real for the 8 facilities that correspond to a `farm_list` row, still placeholder (`TYPE-SLUG`) for the other 8. No admin CRUD exists for any of these 4 tables yet.
- **`downtime_matrix_imports`** / **`downtime_matrix_import_rows`** (new tables, 2026-08-27 — see the "Downtime Matrix PDF Import" module) — Purpose: staging area for a PDF-parsed downtime matrix pending human review, entirely separate from `downtime_matrix`/`downtime_stationary`. `downtime_matrix_imports` (`import_id` PK): one row per upload, `status` (`PENDING_VERIFICATION`/`VERIFIED`/`CANCELLED`), `stored_file_path` (the original PDF, retained for audit), denormalized `*_rows_count` columns, `uploaded_by`/`verified_by`/`cancelled_by` (FK → `users.user_id`, the latter two nullable with `nullOnDelete()` so deleting a user never deletes import history). `downtime_matrix_import_rows` (`import_row_id` PK): one row per parsed rule, `rule_type` (`FARM_TO_FARM`/`STATIONARY`), `origin_raw_label`/`destination_raw_label` (verbatim PDF text), `origin_facility_id`/`destination_facility_id` (nullable FK → `facility_list.facility_id`, `nullOnDelete()` — null whenever unresolved OR resolved to a facility *group*, in which case `origin_facility_group_category`/`destination_facility_group_category` carries the category instead, e.g. `DC_WAREHOUSE`), `resolution_status` (`VALID`/`WARNING`/`UNMATCHED`/`AMBIGUOUS`/`INVALID`), `validation_message`. **No FK, direct or indirect, exists from either table to `downtime_matrix`/`downtime_stationary`** — this is Phase 1's hard boundary, not an oversight.

---

## 6. API Architecture

### Public JSON API (`routes/api.php`, prefix `/api/v1`)

| Method | Path | Purpose | Module | Auth | Controller → Service |
|---|---|---|---|---|---|
| POST | `/v1/visitor/sync` | Ingest an AppSheet-approved visit | Visitor Sync | `X-API-KEY` header (`api.key` middleware) | `VisitorSyncController::store` → `VisitorSyncService::syncApprovedRequest` |

### Kiosk-facing JSON endpoints (`routes/web.php`, device-token auth)

| Method | Path | Purpose | Module | Auth | Controller |
|---|---|---|---|---|---|
| GET | `/kiosk/{kiosk}` | Load kiosk UI | Kiosk Entry | none (public page) | `KioskController::show` |
| GET | `/kiosk/{kiosk}/verify-token` | Pre-flight token check | Kiosk Entry | `X-KIOSK-TOKEN` (`kiosk.auth`) | `KioskController::verifyToken` |
| POST | `/kiosk/{kiosk}/recognize` | Face/QR recognition | Kiosk Entry + Self-Service (shared) | `kiosk.auth` | `KioskController::recognize` |
| POST | `/kiosk/{kiosk}/entry` | Session state transition | Kiosk Entry + Self-Service (shared) | `kiosk.auth` | `KioskController::entry` |
| POST | `/kiosk/{kiosk}/gatesale/update-details` | Edit self-service directory | Kiosk Self-Service | `kiosk.auth` | `KioskController::gatesaleUpdateDetails` |
| POST | `/kiosk/{kiosk}/gatesale/create-visit` | Create/resume self-service visit | Kiosk Self-Service | `kiosk.auth` | `KioskController::gatesaleCreateVisit` |
| POST | `/kiosk/{kiosk}/gatesale/register-identity` | First-time self-service face registration | Kiosk Self-Service | `kiosk.auth` | `KioskController::gatesaleRegisterIdentity` |

### Public visitor-registration endpoints (`routes/web.php`, token-in-query/body auth)

| Method | Path | Purpose | Module |
|---|---|---|---|
| GET | `/register/visitor` | Registration landing page | Visitor Registration |
| GET | `/register/visitor/search`, `/register/visitor/search/query` | Option B name search | Visitor Registration |
| GET/POST | `/register/visitor/capture` | Option A face capture | Visitor Registration |
| POST | `/register/visitor/verify` | Option B scoped face re-verify | Visitor Registration |
| POST | `/register/visitor/confirm` | Option A "is this you?" confirmation | Visitor Registration |
| GET | `/register/visitor/success` | Post-registration success page | Visitor Registration |
| GET | `/register/visitor/qr` | QR PNG (`?download=1` forces attachment) | Visitor Registration |

### Authenticated admin panel (`routes/web.php`, `auth` + `permission:<key>`)

Standard Laravel resource routes (`index`/`create`/`store`/`edit`/`update`/`destroy`) for: `admin/facilities`, `admin/facility-aliases`, `admin/kiosks` (+ `POST admin/kiosks/{kiosk}/regenerate-token`), `admin/identity-types`, `admin/employee-types`, `admin/roles` (+ `GET`/`POST admin/roles/{role}/permissions`), `admin/users`, and `admin/audit-logs` (`index` only — read-only). `admin/biosecurity-rules` is a `GET`-only landing route (`biosecurity-rules.index`, now a three-card partial, no CRUD of its own); its two CRUD submodules are full resource routes nested under it: `admin/biosecurity-rules/downtime-matrix` (route names `downtime-matrix.*`) and `admin/biosecurity-rules/downtime-stationary` (route names `downtime-stationary.*`). See §2's Admin Management module for the permission-key mapping and the submodule load flow. `admin/farms`/`admin/farm-aliases` were removed 2026-08-27 (Phase 5) along with the legacy Farm admin module — see "Facility Master Data." The landing page's third card, `admin/biosecurity-rules/downtime-matrix-import` (route names `downtime-matrix-import.*`: `index`/`create`/`store`/`show`/`verify`/`cancel` — not a full `Route::resource`, no `edit`/`update`/`destroy`), is the "Downtime Matrix PDF Import" module (added 2026-08-27) — gated by its own `downtime_matrix_import.manage` permission rather than `biosecurity.manage`, and the landing route itself now accepts either key.

### Auth endpoints (`routes/web.php`, public)

`GET/POST /login`, `POST /logout` — see Authentication & Authorization module.

**Common response conventions:** Kiosk/API JSON responses consistently use `{success: bool, message?, type?, ...}` with meaningful HTTP status codes (400 validation/business-rule failure, 401 auth failure, 403 forbidden/farm-mismatch, 404 not-found/no-active-request, 409 conflict/already-completed, 422 unprocessable/validation). Admin panel responses are Blade HTML fragments (AJAX-driven) or full pages, not JSON.

---

## 7. External Integrations

### AppSheet (inbound webhook source)

- **Purpose:** Upstream approval workflow for regular (non-self-service) visitors; produces approved visit records this system ingests.
- **Modules using it:** Visitor Sync (sole consumer).
- **Authentication:** Static shared API key in `X-API-KEY` header, compared against `SYNC_API_KEY`.
- **Env vars:** `SYNC_API_KEY`.
- **Endpoints (ours, called by AppSheet):** `POST /api/v1/visitor/sync`.
- **Data retrieved:** Visitor identity fields, farm name (free text), host name, purpose, visit/departure datetimes, a pre-generated `visitor_id` and `qr_url`.
- **Data written back to AppSheet:** None — this integration is one-way inbound. `NEEDS VERIFICATION`: whether `registration_token` returned in the sync response is relayed back to the visitor by AppSheet (out of scope of this codebase).
- **Failure behavior:** Returns a `{success:false, message}` JSON with 400 for any resolvable business failure (unknown farm, missing reference data); every attempt (success/failure) is logged to `api_logs`.
- **Rate limits/quotas:** None enforced by this app on this route.

### Google Sheets API

- **Purpose:** External write-back of visitor Time In/Time Out for stakeholders outside this system.
- **Modules using it:** Kiosk Entry, Session Auto-Resolution. Explicitly **not** Kiosk Self-Service.
- **Authentication:** Google service-account JSON credentials.
- **Env vars:** `SENTRY_VISITORS_ID`.
- **Config (non-secret):** `credentials/service-account.json` path (`config('sentry.google.credentials_path')`).
- **Data written:** Rows appended to "Time In" and "Time Out" tabs — date, time, name, SNTRY-prefixed login/logout ID, `visitor_id`, photo path, public photo URL.
- **Data retrieved:** None — write-only.
- **Failure behavior:** Caught, logged (`\Log::error` + `api_logs`), never blocks the triggering kiosk transaction.
- **Rate limits/quotas:** Standard Google Sheets API quotas apply; mitigated only by a 3-attempt retry with 1s backoff, no other throttling.

### Browser-side face/QR libraries (not a backend integration, but critical to the system)

- **`face-api.js`** (`v0.22.2`, loaded from `cdn.jsdelivr.net`) and its model weights (loaded from a **different** CDN: `cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights`) — computes the 128-D face descriptor client-side; the PHP backend never sees raw images for matching purposes, only the resulting float array (except for the optional stored photo, which is separate).
- **`jsQR`** (`v1.4.0`, CDN) — decodes QR codes client-side from the video feed; only the decoded string reaches the backend as `qr_value`.
- **Constraint:** Both are loaded live from CDNs inside `resources/views/kiosk/show.blade.php` — **the kiosk has a hard runtime dependency on external CDN availability**, despite being physically an offline-capable-looking tablet kiosk. This is a real operational risk worth flagging if asked about kiosk reliability/offline behavior.

---

## 8. Environment Variables

| Variable | Purpose | Module | Used By |
|---|---|---|---|
| `SYNC_API_KEY` | Shared secret for AppSheet → Visitor Sync auth | Visitor Sync | `VerifyApiKey` middleware |
| `SENTRY_VISITORS_ID` | Google Sheet spreadsheet ID | Google Sheets Integration | `VisitorSheetWriter` |
| `APP_PAGINATION_SIZE` | Admin listing page size (default 50) | Admin Management | every index() |
| `APP_SESSION_TIMEOUT` | Documented session-timeout value (default 120) | Authentication | `NEEDS VERIFICATION` — not confirmed to be wired to anything beyond `config/sentry.php` itself |
| `ADMIN_EMAIL` / `ADMIN_NAME` / `ADMIN_PASSWORD` | Seed values for the initial admin account | Admin/Auth | `AdminUserSeeder` |
| `DB_CONNECTION`, `DB_*` | Database connection (SQLite by default per `.env.example`, MySQL supported) | all | Laravel core |
| `FILESYSTEM_DISK` | Default disk (kiosk/face photos use the explicit `public` disk regardless of this default) | Kiosk Entry, Visitor Registration | Laravel core / `Storage` |
| `APP_URL` | Base URL — required for the `public` disk's photo URLs used in Sheets writes | Google Sheets Integration | `Storage::disk('public')->url()` |
| `CACHE_STORE` | Must be `database` (or another store supporting `Cache::lock()`) for the Gatesale/Truck concurrency guard to function | Kiosk Self-Service | `Cache::lock()` |

(No secret values are reproduced here — only names/purposes, per documentation policy.)

---

## 9. Business Rules (centralized — do not break these silently)

1. **Directory identity merge rule (Visitor Sync):** A directory is only reused when **both** `full_name` and `email` match an existing row (case-insensitive/trimmed). Never merge on one field alone.
2. **Farm resolution has no fuzzy matching (Visitor Sync):** Alias-exact → alias-case-insensitive → normalized-exact only. A genuinely new name variant must get an alias row, not a smarter matcher.
3. **Terminal request states are permanent (Kiosk Entry / Self-Service):** `COMPLETED`/`COMPLETED_AUTO`/`INCOMPLETE` (`VisitorRequest::isCompleted()`) can never be reused for further entry/exit actions — a new approved/self-service request is always required.
4. **Farm binding is enforced at every layer (Kiosk Entry / Self-Service):** recognize-time AND process-entry-time, independently. A visitor approved for Farm A can never transact at Farm B's kiosk.
5. **Recognition candidate priority (Kiosk Entry):** non-completed > correct-farm > most-recent, in that order — never simplify to "most recent only."
6. **Gatesale/Truck: one active visit globally, not per-farm/per-day (Kiosk Self-Service):** `request_status = ACTIVE` alone is authoritative, checked across all farms with no date scoping.
7. **Gatesale/Truck concurrency guard (Kiosk Self-Service):** `Cache::lock()` keyed by `directory_id` only (not farm) plus `lockForUpdate()` inside a transaction — both layers are required, don't remove either.
8. **Google Sheets exclusion (Kiosk Entry / Self-Service / Session Auto-Resolution):** Gatesale and Truck visits must never write to Google Sheets, checked via `VisitorRequest::isExcludedFromGoogleSheets()` at every call site.
9. **Sheets/photo failures never block the kiosk transaction:** both are best-effort, caught, and logged independently of the DB state change they're attached to.
10. **Never fabricate recovered data (Session Auto-Resolution):** `last_out`/`first_in` are only ever set from a real, previously-logged event; otherwise the request is marked `INCOMPLETE`, never guessed.
11. **Biometric conflict never blocks QR (Visitor Registration):** a failed/declined face match sets `manual_verification_required` but explicitly leaves the visitor's QR code usable for kiosk entry.
12. **QR payload is always the raw `visitor_id`** (Visitor Registration / Kiosk) — never re-encode anything else without updating both the generator and every scanner call site.
13. **RBAC is enforced twice (Admin Management):** route middleware (`permission:<key>`) and FormRequest `authorize()` must both check the same key — this is intentional defense-in-depth, not redundant code to simplify away.
14. **Audit trail is automatic and synchronous** for every `Auditable` model — do not remove the trait from a business-entity model without a deliberate, discussed reason.
15. **Biosecurity Rules uniqueness (Admin Management):** `downtime_matrix` allows at most one rule per `(origin_facility_id, destination_facility_id)` pair; `downtime_stationary` allows at most one rule per `assigned_facility_id` (both columns renamed from `*_farm_id` in the 2026-08-27 Phase 4 cutover — FK now points at `facility_list`, not `farm_list`). Both are enforced by a DB-level `UNIQUE` constraint (added 2026-08-27) and mirrored in the FormRequest validation layer so violations surface as a normal 422, not a raw SQL error — do not remove either layer independently.
16. **Downtime Matrix PDF Import only writes to `downtime_matrix`/`downtime_stationary` via an explicit, confirmed Save to Production step (Phase 2):** through `VERIFIED`, the only records this feature creates are `downtime_matrix_imports`/`downtime_matrix_import_rows` — Verify/Cancel change staging status only. Only `VERIFIED → PRODUCED` (`DowntimeMatrixImportService::produce()`, reached solely through its own confirmation page) actually maps eligible (`VALID`/`WARNING`) staging rows into those two production tables, inside a single rolled-back-on-failure transaction — see §2's Downtime Matrix PDF Import module for the full mapping rules.
17. **A facility-group match in Downtime Matrix PDF Import is never assigned a single `facility_id`** — one staging row per grid cell regardless of group size; the group is expanded into current member facilities only at Preview render time, never materialized as multiple rows.
18. **Downtime Matrix PDF Import's `resolution_status` precedence is fixed:** `INVALID > AMBIGUOUS > UNMATCHED > WARNING > VALID > INFO`, with every applicable finding's message combined in `validation_message` — do not change to a first-match-wins scheme. `INFO` never wins the status; it only carries an explanatory message.
20. **Downtime Matrix PDF Import derives a threshold from whichever of Downtime Area/Dormitory is present, not just Downtime Area:** a missing (not simply present-but-garbage) Downtime Area is no longer automatically `INVALID` — Dormitory alone still yields a `minimum_downtime` (with `maximum_downtime` left null). Only a value that is present but unparseable/negative is `INVALID`.
21. **Every farm gets a Farm-to-Farm row to "LEP, DC" (DC Warehouses) even when that PDF cell is blank** — configured via `always_include_as_farm_destination` in `config('downtime_matrix_import.facility_groups')`. A blank cell there means no downtime is required, not missing data: the synthesized row carries `minimum_downtime`/`maximum_downtime = null` and an `INFO`-tier message, never `UNMATCHED`/`INVALID`. This is currently scoped only to "LEP, DC" — do not generalize it to every non-farm destination without being asked.
19. **Downtime Matrix PDF Import classifies every row into exactly one of three categories — `FARM_TO_FARM` / `STATIONARY` / `OTHERS` — never a "doesn't fit" case:** `STATIONARY` requires the recognized "Outside" sentinel origin paired with a farm destination (mirrors `downtime_stationary`'s one-rule-per-farm production shape); `FARM_TO_FARM` requires the origin itself to be farm-like (a real farm or the "LEP, DC" group), not just the destination; everything else (e.g. "Organikultura Area", "Fabrication") is `OTHERS`, a real category shown in its own Preview tab, not an error state.
22. **Save to Production (`VERIFIED → PRODUCED`) is confirmation-gated and maps only `VALID`/`WARNING` staging rows into `downtime_matrix`/`downtime_stationary` — `UNMATCHED`/`AMBIGUOUS`/`INVALID` rows are always skipped, never mapped.** The "Production" action (on the import list and, added the same day, on the import's own Preview page once `VERIFIED`) only routes to the confirmation step, never directly to production. `DowntimeMatrixImportService::produce()` runs the whole mapping inside one `DB::transaction()`, using only bulk writes (`upsert()` for the new configuration, `where()->update()` for deactivating the prior one — never a per-row Eloquent loop, since that was tried first and caused a real 60-second-timeout failure against a production-sized import) before stamping `status`/`produced_by`/`produced_at`. Any exception rolls the whole thing back automatically and leaves the import `VERIFIED`, not partially `PRODUCED` — though note a PHP execution-timeout fatal specifically is *not* catchable by `produce()`'s own try/catch (it still rolls back via the dropped DB connection, just without a graceful `{success:false}` response), which is exactly why the bulk rewrite exists — to make hitting that timeout in the first place highly unlikely. Do not revert the bulk writes back to per-row `updateOrCreate()`/`create()` — see that module's Business Rules for the full incident and the resulting audit-logging trade-off (individual production rows are no longer audit-logged; the parent import's own `produced_by`/`produced_at` still is).

---

## 10. Status and State Machines

### `visitor_request.request_status`

```text
ACTIVE ──(manual final_exit, Kiosk Entry/Self-Service)──▶ COMPLETED
ACTIVE ──(Session Auto-Resolution: Outside + real OUT log)──▶ COMPLETED_AUTO
ACTIVE ──(Session Auto-Resolution: Inside, or no session, or Outside+no log)──▶ INCOMPLETE
```
Owning modules: Kiosk Entry / Kiosk Self-Service (`ACTIVE→COMPLETED`); Session Auto-Resolution (the other two transitions). All three target states are terminal — `VisitorRequest::isCompleted()` is the single check used everywhere else in the codebase to detect this.

### `visitor_request.face_registration_status`

```text
PENDING ──(successful capture/verify/confirm)──▶ REGISTERED
PENDING ──(declined match, or 3rd failed Option-B attempt)──▶ FAILED_MATCH  (+ manual_verification_required=true)
```
Owning module: Visitor Registration. Self-service (Kiosk Self-Service) requests skip `PENDING` entirely and are created directly as `REGISTERED`.

### `visitor_session.session_status`

```text
(created) → Inside ⇄ Outside (temporary_exit/return, repeatable) → Completed (manual final_exit)
Inside/Outside → INCOMPLETE or COMPLETED_AUTO (Session Auto-Resolution, terminal)
```
Owning module: Kiosk Entry / Kiosk Self-Service (all manual transitions, shared `processEntry`); Session Auto-Resolution (the two terminal auto-transitions).

### `visitor_request.approval_status`

Effectively constant: always `Approved` in every code path that creates a request (Visitor Sync, Kiosk Self-Service). No rejection/pending-approval workflow exists inside this codebase — approval, where it happens at all (AppSheet), happens entirely upstream.

### `downtime_matrix_imports.status`

```text
PENDING_VERIFICATION ──(admin clicks Verify)──▶ VERIFIED
PENDING_VERIFICATION ──(admin clicks Cancel)──▶ CANCELLED
VERIFIED ──(admin clicks Save to Production, at the confirmation step)──▶ PRODUCED
PRODUCED ──(automatic: a DIFFERENT import is Saved to Production)──▶ VERIFIED
```
Owning module: Downtime Matrix PDF Import. `PENDING_VERIFICATION → CANCELLED` and `CANCELLED` itself are genuinely terminal - status-only, no other effect. `VERIFIED` and `PRODUCED` are **not** mutually terminal - only one import may be `PRODUCED` at a time (added 2026-08-28), so producing import B automatically reverts import A back to `VERIFIED` (its state immediately before it was produced - `verified_by`/`verified_at` untouched, `produced_by`/`produced_at` cleared) if A currently holds that status, and A could in principle be produced again later. `VERIFIED → PRODUCED` is **not** status-only — it's the trigger for `DowntimeMatrixImportService::produce()` actually mapping the import's eligible (`VALID`/`WARNING`) staging rows into `downtime_matrix`/`downtime_stationary` (Phase 2, added 2026-08-28) — see that module's Business Rules for the mapping rules, the PRODUCED-uniqueness reversion, and the transactional rollback behavior. `downtime_matrix_import_rows.resolution_status` (`VALID`/`WARNING`/`UNMATCHED`/`AMBIGUOUS`/`INVALID`) is assigned once at parse time by a fixed precedence, but is **not** itself immutable — while the import is still `PENDING_VERIFICATION`, an admin can recompute one row's status via the Preview page's per-row Edit modal (see that module's Business Rules). Once the import leaves `PENDING_VERIFICATION` (`VERIFIED`/`CANCELLED`/`PRODUCED`), rows are immutable again.

---

## 11. Error Handling and Monitoring

- **Logging:** Standard Laravel `Log` facade (`config/logging.php`, default `stack`/`single` channel per `.env.example`). Business-logic errors that are expected-but-notable (Sheets failures, QR generation failures, photo storage failures, session-resolution outcomes) are explicitly logged with structured context arrays, not just exception messages.
- **API/integration error trail:** `api_logs` table captures every Visitor Sync call and every Google Sheets write attempt with full request/response payloads and status codes — this is the first place to look when diagnosing an integration issue, before tailing log files.
- **Business-domain audit trail:** `audit_logs` (see Audit Logging module) captures every data mutation, separate from the above.
- **HTTP error conventions:** 400 (business-rule/validation failure), 401 (missing/invalid device or API auth), 403 (forbidden — wrong farm, active-elsewhere, permission denied), 404 (not found / no active request), 409 (conflict — already completed), 422 (form validation / identity confirmation failure), 500 (unexpected — e.g. QR generation).
- **No centralized exception handling customization:** `bootstrap/app.php`'s `withExceptions()` callback is empty — the app relies entirely on Laravel's default exception rendering/behavior.
- **Retry behavior:** Only Google Sheets API calls have explicit retry logic (3 attempts, 1s apart, via the `retry()` helper). No other external call in the system retries automatically.
- **Monitoring:** `NEEDS VERIFICATION` — no APM/error-tracking SDK (e.g. Sentry.io, despite the app's name being "Sentry" — this is a naming coincidence, not the Sentry.io product) or health-check beyond Laravel's default `/up` route (registered in `bootstrap/app.php`) was found in the codebase.

---

## 12. Performance and Technical Constraints

- **Face matching is a linear PHP-side scan** (`FaceMatchingService`) over every active `face_profile` row on every recognition/registration attempt — no vector index, no DB-side computation. This is the single most scale-sensitive piece of the system; any feature that increases how often/how many places call `findMatch()` should account for this cost growing linearly with the visitor directory size.
- **Kiosk recognition loop cadence** is controlled entirely client-side (inline JS in `kiosk/show.blade.php`) — not documented/enforced server-side. `NEEDS VERIFICATION` for the actual polling interval; treat any change to kiosk-side polling frequency as directly multiplying load on `FaceMatchingService`.
- **Google Sheets API quota:** one `values.append` call per Time In/Time Out event, no batching. A burst of simultaneous kiosk activity across many farms could approach per-minute quota limits; failures degrade gracefully but leave sheet gaps.
- **Session Auto-Resolution batch size:** `chunkById(100, ...)` — safe for memory at any scale, but a very large `ACTIVE` backlog (e.g. after an extended outage) will take proportionally longer per daily run; there is no parallelism.
- **No caching layer** for reference data (farms, farm aliases, identity/visitor/employee types) — every request re-queries these small tables directly. Given their size this is unlikely to matter, but worth knowing before "optimizing" prematurely.
- **`audit_logs` and `api_logs` have no retention/pruning** — both grow without bound. Flag this if asked about long-term storage/performance planning.
- **CDN dependency for kiosk-critical JS** (`face-api.js`, model weights, `jsQR`, all from external CDNs) — a CDN outage would fully disable kiosk recognition even if the Laravel backend itself is healthy. If asked to improve kiosk reliability, vendoring these assets locally (as the original Phase-2 plan intended, per historical notes, though never completed) is the natural fix.

---

## 13. Critical Files by Module

| Module | Critical Files |
|---|---|
| Visitor Sync | `app/Http/Controllers/Api/VisitorSyncController.php`, `app/Services/VisitorSyncService.php`, `app/Services/FacilityResolver.php` (renamed from `FarmResolver.php` on 2026-08-27), `app/Http/Requests/Api/VisitorSyncRequest.php`, `app/Http/Middleware/VerifyApiKey.php` |
| Visitor Registration | `app/Http/Controllers/Visitor/RegistrationController.php`, `app/Services/VisitorRegistrationService.php`, `app/Services/Qr/VisitorQrCodeService.php` |
| Kiosk Entry | `app/Http/Controllers/Kiosk/KioskController.php`, `app/Services/Kiosk/VisitorKioskService.php`, `app/Models/VisitorRequest.php`, `app/Models/VisitorSession.php`, `app/Http/Middleware/VerifyKioskToken.php` |
| Kiosk Self-Service | `app/Http/Controllers/Kiosk/KioskController.php` (same file as Kiosk Entry), `app/Models/VisitorProfile.php` |
| Face Matching | `app/Services/Face/FaceMatchingService.php` |
| Google Sheets Integration | `app/Services/GoogleSheets/GoogleSheetsClient.php`, `app/Services/GoogleSheets/VisitorSheetWriter.php`, `app/Providers/AppServiceProvider.php` |
| Session Auto-Resolution | `app/Console/Commands/ResolveExpiredVisitorSessions.php`, `routes/console.php` |
| Biosecurity Rules (Downtime Matrix / Downtime Stationary) | `app/Http/Controllers/Admin/BiosecurityRuleController.php` (landing/cards only), `app/Http/Controllers/Admin/DowntimeMatrixController.php`, `app/Http/Controllers/Admin/DowntimeStationaryController.php`, `app/Models/DowntimeMatrix.php`, `app/Models/DowntimeStationary.php` |
| Downtime Matrix PDF Import | `app/Services/DowntimeMatrixImport/*.php` (`DowntimeMatrixImportService`, `PdfTextExtractor`, `GridReconstructor`, `MatrixGridParser`, `FacilityImportResolver`, `DowntimeNormalizer`, `RuleClassifier`, `ImportValidator`), `app/Http/Controllers/Admin/DowntimeMatrixImportController.php`, `app/Models/{DowntimeMatrixImport,DowntimeMatrixImportRow}.php`, `config/downtime_matrix_import.php` |
| Facility Master Data | `app/Models/{FacilityType,FacilityCategory,FacilityList,FacilityAlias}.php`, `app/Services/FacilityResolver.php`, `app/Http/Controllers/Admin/{Facility,FacilityAlias}Controller.php`, `database/seeders/Facility{Type,Category,List}Seeder.php`, `database/migrations/2026_08_27_110000_*` through `..._120002_*` |
| Admin Management | `app/Http/Controllers/Admin/*.php`, `app/Http/Requests/Admin/*.php`, `app/Services/{AuthService,RolePermissionService,AuditLogService}.php` |
| Authentication & Authorization | `app/Http/Controllers/Auth/LoginController.php`, `app/Services/AuthService.php`, `app/Http/Middleware/CheckPermission.php`, `app/Models/User.php` |
| Audit Logging | `app/Traits/Auditable.php`, `app/Models/AuditLog.php` |

**Before modifying any module, read that module's section above plus its critical files first.**

---

## 14. Change Safety Rules

1. Read this `CLAUDE.md` before making significant changes.
2. Identify which module (§2) the requested change belongs to — most requests touch exactly one, but Kiosk Entry and Kiosk Self-Service share one controller file and several service methods, so check both when editing `KioskController.php` or `VisitorKioskService.php`.
3. Read that module's full documentation section before writing code.
4. Read the critical files listed for that module (§13) before editing.
5. Trace cross-module flows (§4) before changing anything shared: `processEntry`, `FaceMatchingService`, `VisitorRequest::isCompleted()`/`isExcludedFromGoogleSheets()`, the `Auditable` trait.
6. Check whether an existing service already performs the operation you're about to add — this codebase consistently centralizes shared logic (one `FaceMatchingService`, one `processEntry`, one `pickBestActiveRequest`) rather than duplicating it per call site; follow that pattern.
7. Reuse existing services instead of writing parallel logic, especially for anything touching face matching, session state, or Google Sheets exclusion.
8. Do not change any of the 21 business rules in §9 unless explicitly asked to.
9. Do not change the shape of any kiosk/API JSON response (`success`/`type`/`message` conventions) without checking the corresponding frontend (`resources/views/kiosk/show.blade.php`, `resources/views/visitor/*.blade.php`) and the test suite (`tests/Feature/Kiosk/*`, `tests/Feature/Visitor/*`) for every consumer.
10. Do not hardcode or log secrets (`SYNC_API_KEY`, Google service-account contents) — both are already externalized correctly; keep them that way.
11. Preserve the Google Sheets exclusion rule and the non-blocking/best-effort nature of Sheets writes when touching `VisitorKioskService` or `ResolveExpiredVisitorSessions`.
12. Before adding a loop/bulk operation over `visitor_request`/`face_profile`, consider the linear face-matching cost (§12) and the lack of batching in Google Sheets writes.
13. Update this `CLAUDE.md` when a module, process, architecture, or business rule changes materially.
14. If code behavior differs from this document, trust the code, fix the document, and say so.

---

## 15. Current Known Issues

| # | Module | Problem | Current Behavior | Possible Impact | Related Files | Status |
|---|---|---|---|---|---|---|
| 1 | Kiosk Self-Service | `VisitorType` rows (Visitor/Gatesale/Truck) have no seeder and no admin CRUD | Must be created manually (DB/tinker) in every environment | New/rebuilt environments will have a non-functional Self-Service and even Sync flow until someone manually inserts these rows | `database/seeders/`, `app/Http/Controllers/Admin/` (absence) | CONFIRMED (absence verified directly) |
| 2 | Visitor Registration | `visitor_request.qr_url` (from AppSheet) is stored but not rendered/downloaded anywhere — the success page generates its own QR locally from `visitor_id` instead | Column appears write-only in current UI | Possibly dead data, or used by a system outside this codebase | `app/Http/Controllers/Visitor/RegistrationController.php::qrCode`, `resources/views/visitor/success.blade.php` | NEEDS VERIFICATION |
| 3 | Admin Management | `DowntimeMatrix`, `DowntimeStationary` (the two Biosecurity Rules submodules, formerly the single `BiosecurityRule`/`biosecurity_rules` table) and `EmployeeType` are fully modeled/administrable but not read by any *kiosk-facing/runtime* business logic — still true after both the 2026-08-27 Phase 4 facility_id cutover (master-data only, no new logic) and the Downtime Matrix PDF Import module's 2026-08-28 Phase 2 (which actively **writes** rows into `DowntimeMatrix`/`DowntimeStationary` via its Save to Production flow, but adds no *reader* of them anywhere) | Rules can be created (manually, or via a PDF import's Save to Production) but have no runtime effect — nothing gates kiosk entry/exit or any other decision on their contents | Confusing to an admin who expects biosecurity rules to actually gate something; likely intended for a not-yet-built feature | `app/Models/DowntimeMatrix.php`, `app/Models/DowntimeStationary.php`, `app/Models/EmployeeType.php`, `app/Services/DowntimeMatrixImport/DowntimeMatrixImportService.php` | NEEDS VERIFICATION |
| 4 | Kiosk (Employee identity) | `routeByIdentity()` hard-codes a placeholder "not yet available" response for Employee identity type | Employees cannot use the kiosk at all today | Feature gap, not a bug — flagged since Employee-type reference data (EmployeeType) already exists, suggesting this was planned | `app/Http/Controllers/Kiosk/KioskController.php::routeByIdentity` | CONFIRMED (explicit placeholder in code) |
| 5 | Authentication | `max_login_attempts`/`lockout_duration`/`password_min_length` are defined in `config/sentry.php` but nothing in the codebase reads them | No login throttling/lockout is actually enforced despite config suggesting it should be | A brute-force login attempt is not currently rate-limited by this app | `config/sentry.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Services/AuthService.php` | NEEDS VERIFICATION (absence of usage confirmed via search; confirm no global throttle middleware applies) |
| 6 | Kiosk (frontend) | Face/QR recognition libraries are loaded live from external CDNs inside the kiosk Blade view, not vendored/bundled | Kiosk recognition fully depends on CDN + internet availability | A CDN or network outage disables face/QR recognition kiosk-wide even if the Laravel app itself is healthy | `resources/views/kiosk/show.blade.php` | CONFIRMED |
| 7 | Audit Logging | No retention/pruning job for `audit_logs`/`api_logs`; both are cross-cut by very high-frequency kiosk activity | Tables grow unboundedly | Long-term storage/performance risk for `AuditLogController::index` and general DB size | `app/Traits/Auditable.php`, `app/Models/ApiLog.php` | NEEDS VERIFICATION (no code inspected suggests any pruning; not proven to be a problem yet at current scale) |
| 8 | Session Auto-Resolution | `VisitorEntryLog` is not `Auditable` and has no `updated_at` | High-frequency kiosk events aren't separately audit-logged beyond their own `datetime` column | Possibly intentional (avoid double logging); flag if compliance requirements are raised | `app/Models/VisitorEntryLog.php` | NEEDS VERIFICATION |
| 9 | Facility Master Data | ~~No admin CRUD for `facility_list`/`facility_aliases`~~ **RESOLVED 2026-08-27 (Phase 3)** — `admin/facilities`/`admin/facility-aliases` now exist, gated by `facilities.manage` | Facilities and facility aliases can now be created/edited/deleted through the admin panel | N/A — closed | `app/Http/Controllers/Admin/{Facility,FacilityAlias}Controller.php` | RESOLVED |
| 10 | Admin Management | ~~Two visually similar site-management screens (`admin/farms` vs `admin/facilities`), only one with runtime effect~~ **RESOLVED 2026-08-27 (Phase 5)** — the legacy `FarmController`/`FarmAliasController`, their Requests, views, routes, and nav links were removed entirely; `admin/facilities`/`admin/facility-aliases` are now the only site-management screens. `farm_list`/`farm_aliases` remain in the DB as inert legacy data (no admin UI, no code path) | N/A — closed | `app/Http/Controllers/Admin/{Facility,FacilityAlias}Controller.php` | RESOLVED |
| 11 | Admin Management (all resources) | ~~Every Admin controller's private `view()` helper falls back to re-rendering the full `index` page for a non-AJAX request, but `create()`/`edit()` never pass that page's required paginated-list variable~~ **RESOLVED 2026-08-28, as a side effect of the Data Table migration** — `index()` no longer queries or passes a paginated list variable to its Blade view at all (that's now the `/data` JSON endpoint's job), so the full-page fallback for `create()`/`edit()` no longer needs (or is missing) any such variable. A direct, non-AJAX `GET admin/facilities/{id}/edit` now renders correctly. This applies to every resource covered by the Data Table migration (Facilities, Facility Aliases, Kiosk Devices, Identity Types, Employee Types, Downtime Matrix, Downtime Stationary, Roles, Users) — Audit Logs never had `create()`/`edit()` to begin with, and Downtime Matrix Import's `create()` was never affected (it never took a list variable). | N/A — closed | `app/Http/Controllers/Admin/*.php` | RESOLVED |
| 12 | Admin Management (dashboard) | `resources/views/admin/dashboard.blade.php` and `dashboard-content.blade.php` still show a "Farms" stat tile calling `\App\Models\FarmList::count()`, left in place when the Farms admin screen was removed (Phase 5) | The tile still renders a live count (currently 8) but no longer links to anything — there's no `admin/farms` screen left to navigate to | Cosmetic only — `FarmList` model and `farm_list` table both still exist, so nothing errors; just a stale/orphaned stat an admin might click expecting a screen | `resources/views/admin/dashboard.blade.php`, `resources/views/admin/dashboard-content.blade.php` | CONFIRMED (found during Phase 5 decommissioning; deliberately left out of scope, not fixed) |

Do not fix any of these unless explicitly asked.

---

## 16. Future Development Guide

```text
If the request is about Visitor Sync / the AppSheet integration:
    Read §2 "Visitor Sync" + its critical files
    ↓
    Check FacilityResolver's alias rules before touching facility matching (renamed from FarmResolver on 2026-08-27)
    ↓
    Check the directory-reuse rule (full_name+email) before touching dedup logic
    ↓
    Implement change
    ↓
    Update this file if the sync contract (request/response shape) changes

If the request is about the Kiosk (recognition, entry/exit, Gatesale/Truck):
    Read §2 "Kiosk Entry" AND "Kiosk Self-Service" (they share one controller + processEntry)
    ↓
    Check FaceMatchingService and isCompleted()/isExcludedFromGoogleSheets() — the two
    single-source-of-truth checks almost everything else defers to
    ↓
    Check whether the change affects Google Sheets exclusion or the concurrency lock
    ↓
    Run/extend the existing Feature tests (tests/Feature/Kiosk/*) — they are extensive
    and encode most of the subtle business rules in this document
    ↓
    Implement change
    ↓
    Update this file if a state transition, recognition response shape, or business rule changes

If the request is about Visitor Registration (face capture / QR):
    Read §2 "Visitor Registration"
    ↓
    Check FaceMatchingService's threshold/behavior before touching matching
    ↓
    Preserve the "biometric conflict never blocks QR" rule
    ↓
    Implement change
    ↓
    Update this file if Option A/B flow or the manual-verification trigger changes

If the request is about Google Sheets:
    Read §2 "Google Sheets Integration"
    ↓
    Preserve non-blocking/best-effort behavior and the Gatesale/Truck exclusion
    ↓
    Remember the explicit singleton bindings in AppServiceProvider are load-bearing
    ↓
    Implement change
    ↓
    Update this file if row format/columns or spreadsheet structure changes

If the request is about Admin Management (Facilities/Kiosks/Roles/Users/reference data):
    Read §2 "Admin Management" + "Authentication & Authorization"
    ↓
    Follow the existing controller/request/service pattern exactly (every resource looks the same)
    ↓
    Add both a permission:<key> route guard AND a matching FormRequest authorize() check
    ↓
    Implement change
    ↓
    Update this file if a new permission key or resource is added

If the request is about the scheduled cleanup job:
    Read §2 "Session Auto-Resolution"
    ↓
    Preserve idempotency (request_status = ACTIVE filter) and "never fabricate data"
    ↓
    Implement change
    ↓
    Update this file if a new terminal state or resolution path is added

If the request is about Downtime Matrix PDF Import (upload, parsing, facility
resolution, editing staged rows, or Save to Production mapping):
    Read §2 "Downtime Matrix PDF Import" in full — the Phase 1 parse pipeline's
    five stages (extract → reconstruct grid → parse candidates → resolve/classify/
    normalize/validate → stage) each live in their own class under
    app/Services/DowntimeMatrixImport/; Phase 2's Save to Production mapping
    (VERIFIED → PRODUCED) lives in DowntimeMatrixImportService::produce() and
    its private collectProductionRows()/resolvedFacilityIds() helpers
    ↓
    Confirm whether the change touches an already-decided Phase 2 rule before
    altering it: only VALID/WARNING rows map, OTHERS never maps to anything,
    facility groups expand at production time (not parse time), only one
    import may be PRODUCED at a time (producing a new one reverts any other
    PRODUCED import back to VERIFIED), production writes are bulk
    upsert()/where()->update() calls - never one Eloquent create()/update()
    per staging row, after the per-row version caused a
    real 60-second-timeout failure in production use - and the whole
    mapping runs inside one rolled-back-on-failure DB::transaction() - see
    that module's Business Rules for the reasoning behind each
    ↓
    If touching facility resolution, do not reuse/modify App\Services\FacilityResolver
    (Visitor Sync's) — this module's FacilityImportResolver has its own
    normalization for a different source-text convention, deliberately kept separate
    ↓
    If touching grid parsing, remember GridReconstructor's tolerances were tuned
    against one real PDF (documents/ and tests/Fixtures/) — verify against the
    real fixture PDF test, expect to need iteration for a structurally different layout
    ↓
    If touching Save to Production, remember it writes into downtime_matrix/
    downtime_stationary through the SAME Eloquent models Admin Management's own
    Downtime Matrix/Downtime Stationary CRUD uses - a schema change to either
    table affects both
    ↓
    Implement change
    ↓
    Update this file if the staging schema, resolution_status precedence, the
    production mapping rules, or the PRODUCED status/its downstream effects change
```

---

## 17. System Quick Reference

### Modules
Visitor Sync · Visitor Registration · Kiosk Entry · Kiosk Self-Service (Gatesale/Truck) · Face Matching · Google Sheets Integration · Session Auto-Resolution · Facility Master Data (live site-binding target since the 2026-08-27 cutover) · Admin Management · Downtime Matrix PDF Import (Phase 1 — parse/validate/preview, added 2026-08-27; Phase 2 — Save to Production mapping into `downtime_matrix`/`downtime_stationary`, added 2026-08-28) · Authentication & Authorization · Audit Logging (cross-cutting)

### Main APIs
`POST /api/v1/visitor/sync` · `POST /kiosk/{kiosk}/recognize` · `POST /kiosk/{kiosk}/entry` · `POST /kiosk/{kiosk}/gatesale/{update-details,create-visit,register-identity}` · `/register/visitor/*` (public) · `/admin/*` (authenticated resource routes) · `/admin/biosecurity-rules/downtime-matrix-import/*` (upload/preview/verify/cancel) · `/login`, `/logout`

### Main Data Entities
`user_directory` · `visitor_profile` · `face_profile` · `visitor_request` (site-bound via `facility_id`) · `visitor_session` · `visitor_entry_logs` · `kiosk_device` (site-bound via `facility_id`) · `facility_type` / `facility_category` / `facility_list` / `facility_aliases` (live site-binding target for `visitor_request`, `kiosk_device`, `downtime_matrix`, and `downtime_stationary` since 2026-08-27, now with a full admin CRUD) · `farm_list` / `farm_aliases` (pure legacy data as of 2026-08-27 Phase 5 — no admin UI, no remaining FKs, `FarmList`/`FarmAlias` models kept but unused) · `identity_type` / `employee_type` / `visitor_type` · `downtime_matrix` / `downtime_stationary` (Biosecurity Rules submodules, formerly `biosecurity_rules`, facility-based since the 2026-08-27 Phase 4 cutover) · `downtime_matrix_imports` / `downtime_matrix_import_rows` (Downtime Matrix PDF Import staging tables, added 2026-08-27 — never connected to `downtime_matrix`/`downtime_stationary`) · `role` / `permission` / `users` · `audit_logs` / `api_logs`

### External Systems
AppSheet (inbound webhook, one-way) · Google Sheets API (outbound write, one-way) · `face-api.js` + `jsQR` (client-side, CDN-loaded)

### Critical Business Rules
Directory merge requires full_name+email match · No fuzzy farm matching · Terminal request states are permanent · Farm binding double-enforced · Gatesale/Truck: one active visit globally, guarded by a directory-keyed lock · Google Sheets writes excluded for Gatesale/Truck and always best-effort/non-blocking · Session Auto-Resolution never fabricates recovered times · Biometric conflict never blocks QR entry · RBAC checked at both route and FormRequest layers · Downtime Matrix/Stationary uniqueness enforced at both DB and FormRequest layers · Downtime Matrix PDF Import only writes to `downtime_matrix`/`downtime_stationary` through a confirmed Save to Production step (never through Verify alone), maps only VALID/WARNING rows, and never assigns a facility-group match a single `facility_id` — a group is expanded into its current active member facilities only at production-mapping time

### Critical Constraints
Face matching is an unindexed linear PHP scan · Kiosk recognition depends on external CDNs with no offline fallback · Google Sheets has no batching, only a 3x retry · `audit_logs`/`api_logs` have no pruning · `VisitorType` has no seeder/admin UI (operational gap, unlike `facility_list`, which got one 2026-08-27) · every Admin controller's `view()` fallback for `create()`/`edit()` on a non-AJAX request throws (never triggered in real use, see Known Issue #11) · Downtime Matrix PDF Import's grid-reconstruction tolerances are tuned against one real PDF layout and its parsing is synchronous/single-page only

### Most Important Files
`app/Http/Controllers/Kiosk/KioskController.php` · `app/Services/Kiosk/VisitorKioskService.php` · `app/Services/Face/FaceMatchingService.php` · `app/Services/VisitorSyncService.php` · `app/Services/GoogleSheets/VisitorSheetWriter.php` · `app/Console/Commands/ResolveExpiredVisitorSessions.php` · `app/Models/VisitorRequest.php` · `app/Traits/Auditable.php` · `app/Services/DowntimeMatrixImport/DowntimeMatrixImportService.php`
