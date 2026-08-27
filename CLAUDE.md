# CLAUDE.md — Sentry Face Kiosk

> **MANDATORY — READ BEFORE ANY WORK IN THIS REPO.**
> Before answering any question, explaining behavior, or implementing any change (new feature, bug fix, endpoint, business-rule change, refactor, migration) in this repository, you MUST read this file in full first. Use it to identify which module(s) the request touches, then read that module's section and its listed critical files before writing or explaining anything. Do not rely on memory of a previous session's reading of this file — re-read it at the start of every new conversation/session. If the request is trivial (e.g. answering from a single obviously-unrelated file), still confirm against this file's module map that it's actually unrelated before skipping deeper reading.

This file is the primary system/business context for Claude sessions working on this repository. It is derived from the actual current implementation (read on 2026-08-26). Where the implementation was ambiguous or unverifiable from static reading, this is marked `NEEDS VERIFICATION` or `UNKNOWN` rather than guessed.

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
  (AppSheet →API) (public token   (Visitor-with- (Gatesale/Truck,   (Farms, Kiosks,
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

**Responsibilities:** API-key authentication of the caller; farm-name resolution (fuzzy-but-safe); idempotent visitor-request creation; directory reuse/creation; full API request/response logging.

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
FarmResolver::resolve (alias → normalized name)
   ↓
resolveDirectory (reuse-by-name+email, else create UserDirectory + VisitorProfile)
   ↓
VisitorRequest::create (approval_status=Approved, request_status=ACTIVE, registration_token=REG_xxxxxxxx)
   ↓
ApiLog::create (always, success or failure)
   ↓
JSON response {success, message, registration_token, visitor_request}
```

**Data Used:** `visitor_request`, `user_directory`, `visitor_profile`, `farm_list`, `farm_aliases`, `identity_type`, `visitor_type`, `api_logs`.

**Dependencies:**
```text
Visitor Sync
 ├── VerifyApiKey middleware (config('sentry.sync_api_key') / SYNC_API_KEY env)
 ├── FarmResolver → farm_list, farm_aliases
 ├── IdentityType 'Visitor' row (must exist — no fallback if missing)
 ├── VisitorType 'Visitor' row (must exist — no fallback if missing)
 └── ApiLog (always written, success or failure)
```

**Related Modules:** Feeds `Visitor Registration` (the `registration_token` returned here is what the visitor uses at `/register/visitor?token=...`). Feeds `Kiosk Entry` indirectly (the resulting `visitor_request` is what a kiosk later recognizes via face/QR).

**Business Rules:**
- **Idempotency key is `visitor_id`** (an AppSheet-issued identifier, stored verbatim, never modified/prefixed/normalized). If a `visitor_request` with that `visitor_id` already exists, the sync call returns success with the *existing* `registration_token` — it never creates a duplicate.
- **Directory reuse requires BOTH `full_name` AND `email` to match** (case-insensitive, trimmed) an existing `user_directory` row. Any partial match (same email, different name, etc.) always creates a **new** directory — true duplicate people are only resolved later, by the face-match confirmation workflow in Visitor Registration, never here.
- `visitor_type_id` is only ever set on a **newly created** directory's `visitor_profile`; an existing/reused directory's profile is never modified by a sync call (so an admin's manual correction is never silently overwritten).
- Farm resolution: exact alias match → case-insensitive alias match → exact normalized farm-name match (strips a leading "FARM"/"FARMS " prefix only if followed by 2+ word chars, so "FARM A" is preserved but "FARM ALPHA" → "ALPHA"). **No fuzzy/LIKE matching** — a genuinely new farm-name spelling must get a `farm_aliases` row added via the admin panel, or the sync fails with "Farm not found."
- `visit_datetime`/`departure_datetime` are parsed with `Carbon::parse()` explicitly (not the model's datetime cast) to tolerate AppSheet's inconsistent US-style date padding.

**Status Lifecycle:** A freshly synced request is always `approval_status = 'Approved'`, `request_status = 'ACTIVE'` — approval already happened upstream in AppSheet; this system does not have its own approval step for this flow.

**Error Handling:** Missing/invalid `X-API-KEY` → 401 before any business logic runs. Missing farm/alias, missing `IdentityType`/`VisitorType` seed rows → `{success:false, message}` with HTTP 400, still logged to `api_logs`. All requests (success and failure) are recorded to `api_logs` with the full request payload and response body.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Route | `routes/api.php` | `POST /v1/visitor/sync` under `api.key` middleware |
| Middleware | `app/Http/Middleware/VerifyApiKey.php` | `X-API-KEY` check against `SYNC_API_KEY` |
| Request | `app/Http/Requests/Api/VisitorSyncRequest.php` | Field validation |
| Controller | `app/Http/Controllers/Api/VisitorSyncController.php` | Orchestrates service + `ApiLog` |
| Service | `app/Services/VisitorSyncService.php` | Core business logic |
| Service | `app/Services/FarmResolver.php` | Farm-name → `FarmList` resolution |
| Model | `app/Models/ApiLog.php` | Request/response audit trail for this endpoint |

**Configuration:** `SYNC_API_KEY` env → `config('sentry.sync_api_key')`.

**Known Constraints:** Single shared static API key (no per-caller keys/rotation). No signature/HMAC verification, no replay protection beyond the `visitor_id` idempotency check. No rate limiting configured on this route.

**Important Notes for Future Changes:** Never weaken the directory-reuse match (full_name + email) — it's a deliberate anti-merge safeguard; loosening it would let two different people be silently treated as one directory. Do not add fuzzy farm-name matching without discussing — it was explicitly rejected as unsafe (comment in `FarmResolver`).

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
3. **Validation:** request must exist; must not already be `isCompleted()` (COMPLETED/COMPLETED_AUTO/INCOMPLETE); `farm_id` must match the kiosk's own farm (defense-in-depth, duplicating the `recognize`-time check).
4. **first_entry** (only when no active session exists): creates `visitor_session` (`session_status=Inside`, fresh `login_id`), creates a `visitor_entry_logs` row (`movement_type=First Entry`, `action=IN`), then (unless excluded — see Business Rules) calls `VisitorSheetWriter::appendTimeIn` — failures here are caught and logged, **never** roll back the DB transaction.
5. **temporary_exit** (requires current status `Inside`): session → `Outside`, entry log `Temporary Exit`/`OUT`. No Sheets write.
6. **return** (requires current status `Outside`): session → `Inside`, entry log `Return`/`IN`. No Sheets write.
7. **final_exit**: session → `Completed` with a fresh `logout_id` (independently generated, never assumed to equal `login_id`) and `last_out`/`completed_at`; `visitor_request.request_status → COMPLETED`; entry log `Final Exit`/`OUT`; Sheets `appendTimeOut` (subject to the same exclusion rule).
8. **Photo:** stored on every transition (`storePhoto`) to `storage/app/public/kiosk-photos/{visitor_request_id}/{action}-{uniqid}.jpg` if a base64 photo was supplied; failures are logged and treated as non-fatal (`photo` column left null).
9. **Final response:** `{success, session_status, message}`.

**Data Used:** `visitor_request`, `visitor_session`, `visitor_entry_logs`, `kiosk_device`, `farm_list`.

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
- **Farm binding is enforced twice**: once at `/recognize` (shows the correct "wrong farm" message) and again inside `processEntry` itself (defense-in-depth so no other code path can bypass it).
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

**Data Used:** `user_directory`, `visitor_profile` (`visitor_type_id`, `company`, `plate_no`), `face_profile`, `visitor_request` (`origin` column is Gatesale/Truck/general-purpose, but `registration_token` is left `null` for self-service requests since there's no registration link), `farm_list`, cache locks (`cache_locks` table, `CACHE_STORE=database`).

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

### Module: `Admin Management` (Farms, Kiosks, Roles, Users, Reference Data, Biosecurity Rules, Audit Logs)

**Purpose:** Authenticated back-office CRUD for every piece of reference/configuration data the kiosk-facing modules depend on.

**Responsibilities:** Standard create/read/update/delete for: Farms (`FarmList`), Farm Aliases, Kiosk Devices (+ token regeneration), Identity Types, Employee Types, Biosecurity Rules (main module) with its two submodules Downtime Matrix and Downtime Stationary, Roles (+ permission assignment), Users, and a read-only Audit Log viewer.

**Entry Points:** All under `routes/web.php`, behind the `auth` middleware and a per-resource `permission:<key>` middleware — see the permission table below. All are Laravel resource routes (`Route::resource`) except `roles/{role}/permissions` (GET/POST), `kiosks/{kiosk}/regenerate-token` (POST), and `admin/biosecurity-rules` itself (a plain `GET`-only landing route — see the Biosecurity Rules submodule note below).

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
   page otherwise
```

**Data Used / permission key per resource:**

| Resource | Model | Permission key |
|---|---|---|
| Farms | `FarmList` | `farms.manage` |
| Farm Aliases | `FarmAlias` | `farms.manage` (shares Farms' permission) |
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

**Related Modules:** Farms/Farm Aliases feed `Visitor Sync`'s `FarmResolver`. Kiosk Devices feed `Kiosk Entry`/`Kiosk Self-Service`'s device auth. Roles/Permissions/Users feed `Authentication & Authorization`. Downtime Matrix/Downtime Stationary and Identity/Employee Types are reference data — `NEEDS VERIFICATION`: these are modeled and administrable but **no other module currently reads them** (no code path queries `DowntimeMatrix`/`DowntimeStationary` or joins on `employee_type_id` for any decision logic) — they appear to be forward-looking/scaffolded for the not-yet-implemented Employee tracking flow (`routeByIdentity()`'s `Employee` branch is a hard-coded placeholder response).

**Business Rules:**
- Every `Store*Request`/`Update*Request`'s `authorize()` independently re-checks the same permission the route middleware already checked — belt-and-suspenders, not a bypass path.
- Unique constraints enforced at the validation layer mirror DB-level unique columns (`farm_code`, `serial_number`, `role_name`, `user_email`, `identity_type_name`, `employee_type_name`, `alias_text`).
- `RoleController::updatePermissions` does a full `sync()` (not merge) — submitting an empty `permission_ids` array revokes **all** permissions from that role.
- The Biosecurity Rules landing page never queries or renders both submodules' data at once — `BiosecurityRuleController::index` returns only the two-card partial with no model query at all; each submodule's own controller (`DowntimeMatrixController`, `DowntimeStationaryController`) is the sole owner of its own listing/CRUD, loaded asynchronously only when its card/link is clicked. Do not merge the two submodules' index queries onto the landing page — that was an explicit request when the module was split.

**Important Files:**

| Component | File | Responsibility |
|---|---|---|
| Controllers | `app/Http/Controllers/Admin/*.php` | One per resource, identical pattern |
| Requests | `app/Http/Requests/Admin/{Store,Update}*Request.php` | Validation + authorization |
| Services | `app/Services/{AuthService,RolePermissionService,AuditLogService}.php` | Shared business logic |
| Middleware | `app/Http/Middleware/CheckPermission.php` | `permission:<key>` route middleware |

**Configuration:** `config('sentry.pagination')` (`APP_PAGINATION_SIZE` env, default 50) controls every index listing's page size.

**Known Constraints:** No bulk operations (import/export) on any admin resource. No soft-deletes anywhere — `destroy()` is a hard delete (cascades per each migration's FK constraints — e.g. deleting a `FarmList` cascades to `kiosk_device`, `downtime_matrix`, and `downtime_stationary`; deleting a `user_directory` row cascades to `visitor_request` and `visitor_profile`).

**Important Notes for Future Changes:** Do not add a new admin resource without both a `permission:<key>` route guard **and** a matching `authorize()` check in its FormRequests — the existing pattern relies on both being present. If you add a `VisitorType` admin controller (a real gap — see the Self-Service module's Known Constraints), follow this exact same pattern for consistency. If a third Biosecurity Rules submodule is ever added, follow the same landing-card + nested-resource-route pattern used for Downtime Matrix/Downtime Stationary rather than adding a third card of unrelated shape.

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

**Data Used:** `audit_logs` table. Every model in `app/Models/` uses this trait **except** `Permission` — `NEEDS VERIFICATION`: confirm `Permission` uses it — and `VisitorType`, `AuditLog` itself, and `ApiLog` (checked directly against the model list: `UserDirectory`, `VisitorProfile`, `VisitorRequest`, `VisitorSession`, `VisitorEntryLog`, `FaceProfile`, `KioskDevice`, `User`, `Role`, `Permission`, `FarmList`, `FarmAlias`, `EmployeeType`, `IdentityType`, `DowntimeMatrix`, `DowntimeStationary` all declare `use Auditable`; `VisitorType`, `AuditLog`, `ApiLog` do not).

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
        │  Admin Management (Farms/Aliases/Kiosks/Roles/Users/etc.)   │
        │        ↑ gated by ↓                                          │
        │  Authentication & Authorization                              │
        └───────────────────────────────────────────────────────────┘
```

**In plain language:**
- `Admin Management` and `Authentication & Authorization` sit underneath everything — they provision the Farms/Kiosks/Roles/Users that the runtime (kiosk-facing) modules depend on, but have no reverse dependency on them.
- `Visitor Sync` is the only inbound integration point for pre-approved visitors; it hands off to `Visitor Registration` via a token, which hands off to `Kiosk Entry` via a now-recognizable face/QR.
- `Kiosk Self-Service` is a parallel, self-contained path that bypasses both `Visitor Sync` and `Visitor Registration` entirely — Gatesale/Truck visitors are created and registered at the kiosk itself, then immediately reuse `Kiosk Entry`'s `processEntry` state machine.
- `Face Matching` is a pure dependency of three modules, with no dependencies of its own.
- `Google Sheets Integration` and `Session Auto-Resolution` are both one-way consumers of state produced by the two Kiosk modules; neither ever triggers a write back into `visitor_request`/`visitor_session` that the Kiosk modules would need to react to (except `Session Auto-Resolution`'s own status updates, which are themselves terminal).

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
                           │           └──▶ visitor_request ──┬──▶ farm_list
                           │                     │              │
                           │                     │              └──▶ visitor_session ──▶ visitor_entry_logs ──▶ kiosk_device
                           │                     │
                           │                     └── (registration flow only) registration_token, qr_url
                           │
                           └── (RBAC, separate tree) role ──▶ role_permissions ──▶ permission
                                      │
                                      └──▶ users

farm_list ──┬──▶ farm_aliases
            ├──▶ kiosk_device
            ├──▶ downtime_matrix (origin_farm_id, destination_farm_id — self-referential via farm_list; renamed from biosecurity_rules, area_type column dropped)
            └──▶ downtime_stationary (assigned_farm_id — single FK to farm_list, one row per farm)

(cross-cutting) audit_logs — one row per create/update/delete on any Auditable model, FK to users.user_id (nullable)
api_logs — one row per Visitor Sync call and per Google Sheets write attempt
```

### Entity notes

- **`user_directory`** — Purpose: the canonical "person" record (visitor, employee, contractor, etc.). Key: `directory_id`. Notable columns: `identity_type_id` (required FK — drives `routeByIdentity()`'s branching), `person_reference` (a synthetic uniqueness key, not always email), `full_name`/`email`/`phone`. **Created by:** Visitor Sync, Visitor Registration (indirectly, via directory-reuse), Kiosk Self-Service registration. **Updated by:** Kiosk Self-Service `update-details`, Visitor Registration (face-link confirmation repoints `visitor_request.directory_id`, not this table). **Read by:** everything. As of migration `2026_08_12_115846`, `visitor_type_id`/`company`/`plate_no` were **removed** from this table and now live exclusively on `visitor_profile` — if you see old code/docs referencing those columns directly on `UserDirectory`, they are stale.
- **`visitor_profile`** — Purpose: visitor-specific attributes, 1:1 with `user_directory` (unique `directory_id`). **Sole source of truth** for `visitor_type_id`/`company`/`plate_no` since the migration above. Cascade-deletes with its directory.
- **`face_profile`** — Purpose: one or more biometric templates per directory. Key: `face_profile_id`. `embedding` is a JSON array of floats (128-D, `face-api.js` convention). `is_active` gates whether `FaceMatchingService` considers it.
- **`visitor_request`** — Purpose: one specific approved (or self-service) visit. Key: `visitor_request_id`. Status fields: `approval_status` (`Approved` is the only value seen in code — no rejection/pending flow implemented for the Sync path), `request_status` (`ACTIVE`/`COMPLETED`/`COMPLETED_AUTO`/`INCOMPLETE`), `face_registration_status` (`PENDING`/`REGISTERED`/`FAILED_MATCH`). `visitor_id` is the AppSheet-issued idempotency key and QR payload (nullable — self-service requests have none). `registration_token` nullable (self-service requests have none). **Created by:** Visitor Sync, Kiosk Self-Service. **Updated by:** Kiosk Entry (`processEntry`), Session Auto-Resolution, Visitor Registration (re-pointing `directory_id` on a confirmed match).
- **`visitor_session`** — Purpose: one physical "inside the farm" episode per request (a request can have more than one session over its lifetime, e.g. multiple temporary-exit/return cycles do NOT create new sessions — a session persists across those; only `final_exit`/auto-resolution closes it). Key: `visitor_session_id`. `login_id`/`logout_id` are independently generated 8-char codes, never assumed equal, unique across the whole table (checked against both columns to avoid cross-collision).
- **`visitor_entry_logs`** — Purpose: immutable append-only log of every individual movement event (`First Entry`/`Temporary Exit`/`Return`/`Final Exit`, each with `IN`/`OUT` + timestamp + optional photo). No `updated_at` (`$timestamps = false`). Never updated after creation, only inserted.
- **`kiosk_device`** — Purpose: one physical tablet, tied to exactly one `farm_id`. `kiosk_token` is the bearer credential for all `kiosk.auth`-gated routes, auto-generated on create, rotatable via admin.
- **`farm_list`** / **`farm_aliases`** — Purpose: canonical farm records and alternate spellings AppSheet might send; aliases exist specifically to avoid fuzzy matching in `FarmResolver`.
- **`downtime_matrix`** (renamed from `biosecurity_rules` on 2026-08-26; `area_type` column dropped in that same migration; `access_level` column dropped in a follow-up migration the same day) — Purpose: origin→destination farm downtime pairs. Key: `rule_id`. Columns: `origin_farm_id`, `destination_farm_id` (both FK → `farm_list.farm_id`), `minimum_downtime`/`maximum_downtime` (nullable integers), `is_active`. Model: `App\Models\DowntimeMatrix`. **Not currently read by any business logic** — reference data ahead of a not-yet-built feature (`NEEDS VERIFICATION`).
- **`downtime_stationary`** (new table, 2026-08-26) — Purpose: a fixed min/max downtime window assigned to a single farm (no origin/destination pairing, unlike Downtime Matrix). Key: `rule_id`. Columns: `assigned_farm_id` (FK → `farm_list.farm_id`), `minimum_downtime_hours`/`max_downtime_hours` (`DECIMAL(5,2)`, nullable), `is_active`. Model: `App\Models\DowntimeStationary`. Sibling submodule to Downtime Matrix under the same Biosecurity Rules module/permission. **Not currently read by any business logic**, same as Downtime Matrix (`NEEDS VERIFICATION`).
- **`role` / `permission` / `role_permissions` / `users`** — Purpose: RBAC. One role per user; permissions are string keys (`resource.action` convention) checked via `User::hasPermission()`.
- **`audit_logs`** — Purpose: append-only change log, see the Audit Logging module.
- **`api_logs`** — Purpose: append-only integration call log, shared by Visitor Sync and Google Sheets Integration (both write to the same table, distinguished by `endpoint`).

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

Standard Laravel resource routes (`index`/`create`/`store`/`edit`/`update`/`destroy`) for: `admin/farms`, `admin/kiosks` (+ `POST admin/kiosks/{kiosk}/regenerate-token`), `admin/identity-types`, `admin/employee-types`, `admin/roles` (+ `GET`/`POST admin/roles/{role}/permissions`), `admin/users`, `admin/farm-aliases`, and `admin/audit-logs` (`index` only — read-only). `admin/biosecurity-rules` is a `GET`-only landing route (`biosecurity-rules.index`, two-card partial, no CRUD of its own); its two submodules are full resource routes nested under it: `admin/biosecurity-rules/downtime-matrix` (route names `downtime-matrix.*`) and `admin/biosecurity-rules/downtime-stationary` (route names `downtime-stationary.*`). See §2's Admin Management module for the permission-key mapping and the submodule load flow.

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
| Visitor Sync | `app/Http/Controllers/Api/VisitorSyncController.php`, `app/Services/VisitorSyncService.php`, `app/Services/FarmResolver.php`, `app/Http/Requests/Api/VisitorSyncRequest.php`, `app/Http/Middleware/VerifyApiKey.php` |
| Visitor Registration | `app/Http/Controllers/Visitor/RegistrationController.php`, `app/Services/VisitorRegistrationService.php`, `app/Services/Qr/VisitorQrCodeService.php` |
| Kiosk Entry | `app/Http/Controllers/Kiosk/KioskController.php`, `app/Services/Kiosk/VisitorKioskService.php`, `app/Models/VisitorRequest.php`, `app/Models/VisitorSession.php`, `app/Http/Middleware/VerifyKioskToken.php` |
| Kiosk Self-Service | `app/Http/Controllers/Kiosk/KioskController.php` (same file as Kiosk Entry), `app/Models/VisitorProfile.php` |
| Face Matching | `app/Services/Face/FaceMatchingService.php` |
| Google Sheets Integration | `app/Services/GoogleSheets/GoogleSheetsClient.php`, `app/Services/GoogleSheets/VisitorSheetWriter.php`, `app/Providers/AppServiceProvider.php` |
| Session Auto-Resolution | `app/Console/Commands/ResolveExpiredVisitorSessions.php`, `routes/console.php` |
| Biosecurity Rules (Downtime Matrix / Downtime Stationary) | `app/Http/Controllers/Admin/BiosecurityRuleController.php` (landing/cards only), `app/Http/Controllers/Admin/DowntimeMatrixController.php`, `app/Http/Controllers/Admin/DowntimeStationaryController.php`, `app/Models/DowntimeMatrix.php`, `app/Models/DowntimeStationary.php` |
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
8. Do not change any of the 14 business rules in §9 unless explicitly asked to.
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
| 3 | Admin Management | `DowntimeMatrix`, `DowntimeStationary` (the two Biosecurity Rules submodules, formerly the single `BiosecurityRule`/`biosecurity_rules` table) and `EmployeeType` are fully modeled/administrable but not read by any business logic | Rules can be created but have no runtime effect | Confusing to an admin who expects biosecurity rules to actually gate something; likely intended for a not-yet-built feature | `app/Models/DowntimeMatrix.php`, `app/Models/DowntimeStationary.php`, `app/Models/EmployeeType.php` | NEEDS VERIFICATION |
| 4 | Kiosk (Employee identity) | `routeByIdentity()` hard-codes a placeholder "not yet available" response for Employee identity type | Employees cannot use the kiosk at all today | Feature gap, not a bug — flagged since Employee-type reference data (EmployeeType) already exists, suggesting this was planned | `app/Http/Controllers/Kiosk/KioskController.php::routeByIdentity` | CONFIRMED (explicit placeholder in code) |
| 5 | Authentication | `max_login_attempts`/`lockout_duration`/`password_min_length` are defined in `config/sentry.php` but nothing in the codebase reads them | No login throttling/lockout is actually enforced despite config suggesting it should be | A brute-force login attempt is not currently rate-limited by this app | `config/sentry.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Services/AuthService.php` | NEEDS VERIFICATION (absence of usage confirmed via search; confirm no global throttle middleware applies) |
| 6 | Kiosk (frontend) | Face/QR recognition libraries are loaded live from external CDNs inside the kiosk Blade view, not vendored/bundled | Kiosk recognition fully depends on CDN + internet availability | A CDN or network outage disables face/QR recognition kiosk-wide even if the Laravel app itself is healthy | `resources/views/kiosk/show.blade.php` | CONFIRMED |
| 7 | Audit Logging | No retention/pruning job for `audit_logs`/`api_logs`; both are cross-cut by very high-frequency kiosk activity | Tables grow unboundedly | Long-term storage/performance risk for `AuditLogController::index` and general DB size | `app/Traits/Auditable.php`, `app/Models/ApiLog.php` | NEEDS VERIFICATION (no code inspected suggests any pruning; not proven to be a problem yet at current scale) |
| 8 | Session Auto-Resolution | `VisitorEntryLog` is not `Auditable` and has no `updated_at` | High-frequency kiosk events aren't separately audit-logged beyond their own `datetime` column | Possibly intentional (avoid double logging); flag if compliance requirements are raised | `app/Models/VisitorEntryLog.php` | NEEDS VERIFICATION |

Do not fix any of these unless explicitly asked.

---

## 16. Future Development Guide

```text
If the request is about Visitor Sync / the AppSheet integration:
    Read §2 "Visitor Sync" + its critical files
    ↓
    Check FarmResolver's alias rules before touching farm matching
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

If the request is about Admin Management (Farms/Kiosks/Roles/Users/reference data):
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
```

---

## 17. System Quick Reference

### Modules
Visitor Sync · Visitor Registration · Kiosk Entry · Kiosk Self-Service (Gatesale/Truck) · Face Matching · Google Sheets Integration · Session Auto-Resolution · Admin Management · Authentication & Authorization · Audit Logging (cross-cutting)

### Main APIs
`POST /api/v1/visitor/sync` · `POST /kiosk/{kiosk}/recognize` · `POST /kiosk/{kiosk}/entry` · `POST /kiosk/{kiosk}/gatesale/{update-details,create-visit,register-identity}` · `/register/visitor/*` (public) · `/admin/*` (authenticated resource routes) · `/login`, `/logout`

### Main Data Entities
`user_directory` · `visitor_profile` · `face_profile` · `visitor_request` · `visitor_session` · `visitor_entry_logs` · `kiosk_device` · `farm_list` / `farm_aliases` · `identity_type` / `employee_type` / `visitor_type` · `downtime_matrix` / `downtime_stationary` (Biosecurity Rules submodules, formerly `biosecurity_rules`) · `role` / `permission` / `users` · `audit_logs` / `api_logs`

### External Systems
AppSheet (inbound webhook, one-way) · Google Sheets API (outbound write, one-way) · `face-api.js` + `jsQR` (client-side, CDN-loaded)

### Critical Business Rules
Directory merge requires full_name+email match · No fuzzy farm matching · Terminal request states are permanent · Farm binding double-enforced · Gatesale/Truck: one active visit globally, guarded by a directory-keyed lock · Google Sheets writes excluded for Gatesale/Truck and always best-effort/non-blocking · Session Auto-Resolution never fabricates recovered times · Biometric conflict never blocks QR entry · RBAC checked at both route and FormRequest layers

### Critical Constraints
Face matching is an unindexed linear PHP scan · Kiosk recognition depends on external CDNs with no offline fallback · Google Sheets has no batching, only a 3x retry · `audit_logs`/`api_logs` have no pruning · `VisitorType` has no seeder/admin UI (operational gap)

### Most Important Files
`app/Http/Controllers/Kiosk/KioskController.php` · `app/Services/Kiosk/VisitorKioskService.php` · `app/Services/Face/FaceMatchingService.php` · `app/Services/VisitorSyncService.php` · `app/Services/GoogleSheets/VisitorSheetWriter.php` · `app/Console/Commands/ResolveExpiredVisitorSessions.php` · `app/Models/VisitorRequest.php` · `app/Traits/Auditable.php`
