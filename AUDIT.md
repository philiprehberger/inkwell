# Inkwell — Technical Audit

*Independent read-only audit, 2026-08-07. No repository file was modified; this
document is the only file created and it is uncommitted.*

**Engagement brief**

| | |
|---|---|
| **Target** | `~/projects/inkwell` @ `babdbb6` (main, clean) |
| **What it is** | Form-submission / inbound API. Laravel 13.8 + PHP 8.3 + MySQL + Redis/Horizon + Filament 5 API at `api.inkwell.philiprehberger.com`; Next.js 16 docs + live-demo site at `inkwell.philiprehberger.com`. |
| **Stage** | Production bar, plus a reputational lens — the source is public and doubles as sales proof. |
| **Constraints honoured** | No new vendors or frameworks proposed. Every fix below uses Laravel/Filament/Next primitives already present. |
| **Out of scope** | `vendor/`, `node_modules/`, `.next/`, generated SDK clients. |
| **Measured** | 15,642 tracked LOC (app 5,145 · web 7,661 · tests 1,643 · scripts 4,502). 95 PHPUnit tests / 288 assertions, all passing. 24 OpenAPI operations. |

---

## 1. Executive summary

Inkwell is well-architected and, in most places, carefully built — the spam-signal
pipeline, the seven-destination contract, the envelope/scheme/header model and the
egress policy for credentialed destinations are genuinely good work that would
survive review at most companies. That makes the central finding harder, not easier,
to say: **the multi-tenancy control this codebase is built around does not run.**
`WorkspaceScope` resolves the current workspace with `property_exists()` against an
Eloquent model, which is always `false`, so on every API request the global scope
applies no filter at all. Most controllers happen to be safe because they
independently scope through `$workspace->forms()`; two do not, and those two return
the full submission payload. **I would not ship this.** Fix the scope and the two
endpoints — that is a few hours of work — and the posture flips from Critical to
solid, because nothing else here is structurally wrong.

The other three High findings share a shape worth naming: in each case a control is
*described* accurately in a comment and *implemented* differently, and no test
compares the two. The SSRF guard, the trusted-proxy configuration, and the ingest
endpoint's defence-layer docblock all promise more than the code delivers.

**Critical: 1 · High: 3 · Medium: 5 · Low/Note: 4**

## 2. Scorecard

| Domain | Rating | One-line justification |
|---|---|---|
| Security | **Critical** | Tenant isolation is inert on the API path; two endpoints leak and mutate other tenants' submissions. |
| Data & state integrity | **Adequate** | 26 FK/unique constraints, 12 migrations with explicit cascade, atomic `Cache::add` dedup. No integrity defects found. |
| Architecture & structure | **Strong** | Clean layering; `SpamSignal` and `Destination` are real contracts, not interfaces-in-name. Adding the 8th of either is genuinely mechanical. |
| Code quality | **Adequate** | Readable, well-commented, small functions. Points lost for comments that describe behaviour the code does not have. |
| Testing | **Weak** | 95 passing tests that are blind to all four top findings; the one isolation test passes via a path production never takes. |
| Standardization | **Weak** | No static analysis, no formatter config, 3 of 4 CI workflows disabled. The guardrails are decorative. |
| Design & UI | **Adequate** | Wrapping labels, solid header ARIA, no click-handlers on `div`s. Missing App Router `error`/`loading` boundaries. |
| Operations & DX | **Adequate** | Atomic releases with rollback, Horizon queue split, IMDSv2 enforced on the host. CI gates less than it appears to. |

---

## 3. Findings

### SECURITY

---

### [CRITICAL] [CONFIRMED] Tenant isolation does not apply on any API request; two endpoints expose and mutate other tenants' submissions

**Location:** `app/Models/Scopes/WorkspaceScope.php:36-49` (root cause) ·
`app/Http/Controllers/Api/SubmissionsController.php:79` ·
`app/Http/Controllers/Api/SubmissionsController.php:104`

**What:** `WorkspaceScope::currentWorkspaceId()` reads the workspace off the request
and gates on `property_exists($w, 'id')`. `ApiKeyAuth:52` sets that attribute to an
Eloquent `Workspace` **model**, whose `id` lives in the internal `$attributes` array —
so `property_exists()` returns `false`. The `is_string($w)` fallback does not match a
model either, and `auth()->check()` is false for token auth. The method returns
`null`, and `WorkspaceScope::apply()` returns early **without adding a predicate**.

Verified at runtime rather than inferred — attaching a `Workspace` model exactly as
`ApiKeyAuth` does:

```
resolved workspace id: NULL
SQL that Submission::findOrFail() would run:
  select * from "submissions" where "submissions"."id" = ?
```

No `workspace_id` clause. The scope has never filtered anything on the API path.

Most controllers are unaffected because they scope explicitly through the
relationship (`FormsController:146`, `DestinationsController:21/155`,
`SubmissionsController::show:58-65` all filter correctly). Two do not:

- `promote:79` — `Submission::with('deliveries')->findOrFail($id)`
- `replay:104` — `Submission::with('deliveries')->findOrFail($id)`

Both return `serializeDetail()` (`:144-149`), which includes `payload` (the visitor's
submitted form data), `meta` (`client_ip`, `user_agent`, `referer`) and `spam_signals`.

**Why it matters:** Any tenant holding any valid API key can, with another tenant's
submission ULID:

1. **Read that tenant's visitor PII** — name, email, phone, message body, and the
   submitter's IP address — via either endpoint.
2. **Force a re-delivery** of that submission to the victim tenant's own destinations
   (`replay` bumps `replay_sequence` and re-queues at `:118-120`). `replay` has **no
   state guard at all**, so it works on any submission, not just spam/quarantined.
3. **Mutate state** — `promote:89-96` flips state to `PROMOTED` and dispatches
   `DispatchDestinationsJob`, fanning the victim's data out to their destinations.

The audit row written at `:92` and `:122` records the *attacker's* workspace against
the *victim's* submission, so the victim's audit log will not show it.

Mitigating: IDs are ULIDs, so this needs a known or leaked ID rather than
enumeration. That reduces likelihood; it does not reduce impact, and ULIDs are
timestamp-ordered, which makes targeted guessing cheaper than random.

**Fix:** Two changes, both small.

1. In `WorkspaceScope::currentWorkspaceId()`, replace the `property_exists()` test
   with one that works on a model — `$w instanceof \App\Models\Workspace ? $w->id : null`,
   keeping the existing `is_string()` branch. This restores the control everywhere at once.
2. Independently, scope both lookups so they do not depend on the global scope:
   `$this->workspace($request)->submissions()->findOrFail($id)` (or an explicit
   `->where('workspace_id', …)` matching the pattern already used at `:58-65`).
   Defence in depth matters here precisely because the global scope silently stopped working.

Consider also making the scope **fail closed**: if a request is authenticated and no
workspace resolves, that is a bug, not a licence to read everything. The CLI/queue
path can keep its exemption by calling `withoutGlobalScope()` explicitly, which the
jobs already do (`DispatchDestinationsJob:35`, `DeliverToDestinationJob:47`).

**Effort:** S (both fixes together are well under a day)
**Blast radius:** Fixing the scope turns filtering on for every model using
`BelongsToWorkspace`. Any code that has been *relying* on the scope being inert will
start returning fewer rows — the `withoutGlobalScope()` call sites listed in §5 are
already explicit and unaffected, but run the full suite and exercise the Filament
admin, which resolves workspace by a different branch (`auth()->user()->current_workspace_id`)
and is therefore already working correctly.

---

### [HIGH] [CONFIRMED] `trustProxies(at: '*')` with no proxy in front — every per-IP defence on the public ingest endpoint is bypassable with one header

**Location:** `bootstrap/app.php:25-29` · consumed at
`app/Http/Controllers/Api/IngestController.php:64-70`

**What:** The comment says the app trusts "EC2's own NAT + Cloudflare's published
ranges … refreshed via the `inkwell:refresh-trusted-proxies` command (Phase 6
wiring)." The code trusts `*` — every client. The named command **does not exist**
(`app/Console/Commands/` contains only `CaptureGoldensCommand`,
`PurgeOldSubmissionsCommand`, `SeedAdminCommand`).

There is also no Cloudflare in front of this host. Response headers from the live API
are `server: Apache/2.4.58 (Ubuntu)` with no `cf-ray`. So `X-Forwarded-For` arrives
straight from the internet and Laravel treats it as the client address.

**Why it matters:** `$request->ip()` is attacker-controlled on the *unauthenticated*
ingest endpoint, which defeats four of the eight defence layers its own docblock
claims:

- the per-IP token bucket (`IngestController:66`, 60/min/form) — rotate the header, unlimited submissions;
- `IpReputationSignal` (StopForumSpam blocklist) — spoof a clean address;
- `SubmissionRateSignal` (>10/IP/form/60s) — never triggers;
- the `client_ip` recorded into `meta` (`:117`) and surfaced in the admin becomes attacker-authored, so abuse investigation starts from poisoned data.

**Fix:** Set the trusted proxies to the actual edge. With Apache on the same host
proxying to php-fpm, that is the loopback: `$middleware->trustProxies(at: ['127.0.0.1', '::1'])`.
If Cloudflare is put in front later, add its ranges then — and either write the
`inkwell:refresh-trusted-proxies` command the comment promises or delete the promise.
Until then, correct the comment so it describes the configuration that exists.

**Effort:** S
**Blast radius:** `$request->ip()` starts returning the real client address, so
per-IP rate limits become effective for the first time. Expect previously-invisible
throttling on any genuinely high-volume form; the 60/min default is per-form-per-IP
and should be comfortable, but check the busiest form before shipping.

---

### [HIGH] [CONFIRMED] Open redirect on the unauthenticated ingest endpoint

**Location:** `app/Http/Controllers/Api/IngestController.php:75, 158, 162-163` ·
documented as a feature at `web/app/page.tsx:9`

**What:** `$redirectUrl = $raw['_redirect'] ?? $form->success_redirect_url ?? null;`
takes `_redirect` straight from the submitted form body. It is then returned in the
JSON response (`:158`) and, for ordinary browser posts, used as the target of a
`302` (`:162-163`). Nothing validates it against the form's origins allowlist, the
workspace, or any scheme/host rule.

The docs site actively teaches this parameter — `web/app/page.tsx:9` shows
`<input type="hidden" name="_redirect" value="https://example.com/thanks">` in the
copy-paste integration snippet — so it is a designed input, not a stray field.

**Why it matters:** `POST /v1/forms/{id}/submit` with `_redirect=https://evil.example`
produces a 302 to an attacker's site **from your brand domain**, with no
authentication needed and any public form ID. That is a ready-made phishing primitive:
the victim sees a legitimate `api.inkwell.philiprehberger.com` URL and lands on a
credential-harvesting page. It also makes the domain a usable open-redirect hop for
laundering links past filters that allowlist known-good hosts.

**Fix:** Constrain the redirect target to something the *form owner* chose, not
something the *submitter* supplied. The cleanest fit for the existing model: accept
`_redirect` only when its origin appears in the form's `cors_origins` (already stored,
already used at `:57`), or when it exactly matches `success_redirect_url`; otherwise
fall back to `success_redirect_url` and ignore the supplied value. Reject
non-`http(s)` schemes outright — as written, `_redirect=javascript:…` is also
reflected into the JSON body.

**Effort:** S
**Blast radius:** Any integrator currently passing an off-allowlist `_redirect` will
start landing on the hosted thank-you page instead. Worth a line in the changelog;
the allowlist is per-form so owners can re-enable their own targets.

---

### [HIGH] [CONFIRMED] SSRF guard is bypassed by an HTTP redirect, and does not survive DNS rebinding

**Location:** `app/Services/SsrfGuard.php:30-59` · called at
`app/Services/Destinations/WebhookDestination.php:87` · delivery at `:154`

**What:** `SsrfGuard::assertSafeUrl()` correctly resolves the host and rejects private,
loopback, link-local and reserved ranges via `FILTER_FLAG_NO_PRIV_RANGE |
FILTER_FLAG_NO_RES_RANGE`. It checks **only the URL it is given**. The delivery call
is `Http::withHeaders(...)->timeout(10)->withBody(...)->post($url)` with no
`allow_redirects` override, and Guzzle follows redirects by default (up to 5). So a
destination pointed at a public host that answers `302 Location: http://127.0.0.1:…`
reaches the internal target with no second check.

Two further gaps, one of them self-documented at `:9-11` — *"Resolves the URL host
once via gethostbyname (good enough for a portfolio demo). For production you'd want
DNS rebinding protection too."*

- **TOCTOU / rebinding:** the guard resolves the name, then Guzzle resolves it again
  independently. A DNS record with a short TTL can answer public on the first lookup
  and private on the second.
- **IPv4-only:** `gethostbyname()` returns only an A record. For a dual-stack host the
  guard validates the public IPv4 while the client may connect over IPv6 to a private
  ULA. *(SUSPECTED — I did not confirm the client's address-family preference here.)*

**Why it matters:** The attempt record stores a 4 KB snippet of the response body
(`WebhookDestination:157`) and that snippet is returned to the tenant through the
submissions API (`SubmissionsController::serializeDetail:150-155`). That turns SSRF
into a **read** primitive, not just a blind request. The EC2 host is shared with
several other services on loopback ports — the ClamAV daemon at `127.0.0.1`
(`app/Services/Files/ClamScanner.php:26`), MySQL, Redis, and other applications'
HTTP ports — all reachable from it.

Genuinely mitigating, and worth crediting: **IMDSv2 is enforced on the instance.** I
verified that `http://169.254.169.254/latest/meta-data/` returns `401` without a
token, so the classic escalation to instance credentials is closed. This is why the
finding is High and not Critical.

**Fix:** Disable redirect following on the delivery call —
`Http::withOptions(['allow_redirects' => false])` — and treat a 3xx as a delivery
failure. A webhook receiver has no legitimate need to redirect, and this closes the
bypass completely with one option. For the rebinding gap, resolve once and pin: pass
the validated IP as the connect target with `curl`'s `resolve` option via
`withOptions(['curl' => [CURLOPT_RESOLVE => [...]]])`, keeping the `Host` header. If
pinning is judged not worth it, change the docblock at `:9-11` — a public repository
that says its own SSRF guard is "good enough for a portfolio demo" reads badly to the
technical buyer this project exists to convince.

**Effort:** S for redirects, M for pinning
**Blast radius:** Any destination currently relying on a redirect (a receiver behind
a URL shortener, or `http://` → `https://` upgrades) will start failing. Worth
checking `delivery_attempts` for 3xx statuses before shipping.

---

### [MEDIUM] [CONFIRMED] IDOR on the data-subject status endpoint

**Location:** `app/Http/Controllers/Api/DataSubjectController.php:90`

**What:** `DataSubjectRequest::findOrFail($id)` with no workspace filter — the same
inert-global-scope root cause as the Critical, in a lower-value place.

**Why it matters:** Any admin-scoped key can read another tenant's GDPR erasure
request by ID: `email_hash`, `reason`, `state`, `submissions_purged`, timestamps
(`:99-110`). The email is hashed rather than plaintext, which caps the damage, but it
confirms the existence and disposition of another organisation's data-subject
requests — itself compliance-relevant information.

**Fix:** `$this->workspace($request)->dataSubjectRequests()->findOrFail($id)`. Fixing
the global scope also closes this, but scope it explicitly for the same
defence-in-depth reason.

**Effort:** S · **Blast radius:** None.

---

### TESTING

---

### [MEDIUM] [CONFIRMED] The isolation test passes through a code path production never takes; the broken endpoints have no coverage

**Location:** `tests/Feature/WorkspaceScopeTest.php:12-31`

**What:** The only cross-tenant test covers `GET /v1/forms` and `GET /v1/forms/{id}`.
Both are protected by explicit relationship scoping (`FormsController:146` uses
`$workspace->forms()`), so they pass **regardless of whether the global scope
functions**. There is no test for cross-tenant access to submissions, destinations,
or data-subject requests — which is exactly where the two unscoped lookups live.

**Why it matters:** 95 green tests, and the portfolio copy and project plan both cite
"WorkspaceScope cross-tenant isolation" as covered. The suite would not have caught
the Critical above, and the passing test actively creates confidence that the control
works. This is the same failure mode as a degrade path with no healthy-path test: the
assertion is true, and it proves something other than what it appears to prove.

**Fix:** Add a case per workspace-owned resource that asserts a 404 for a foreign ID —
submissions (`show`, `promote`, `replay`), destinations, data-subject requests. Then
add one unit test on `WorkspaceScope::currentWorkspaceId()` asserting it resolves an
actual `Workspace` **model**, since that is the specific thing that broke and the only
test that would have caught it before the endpoints did.

**Effort:** S · **Blast radius:** None.

---

### STANDARDIZATION & OPERATIONS

---

### [MEDIUM] [CONFIRMED] Three of four CI workflows are disabled and there is no static analysis

**Location:** `.github/workflows/` — `api-smoke.yml.disabled`,
`sdk-ci.yml.disabled`, `sdk-publish.yml.disabled` · `composer.json:53-65`

**What:** Only `api-ci.yml` runs. It does Spectral lint, migrations, `php artisan test`
and the Interchange conformance filter — a genuinely useful gate. But there is no
PHPStan/Larastan, no Pint or formatter config, and no ESLint config for `web/`. The
repo's only style artifact is `.editorconfig`.

**Why it matters:** Static analysis is the tool class that finds this audit's Critical.
`property_exists()` on an Eloquent model returning a value that is then discarded is
the kind of dead-branch/always-false condition Larastan flags at level 5+. The
`api-smoke.yml` workflow that is disabled is the one that would have caught a broken
deploy from outside.

**Fix:** Add Larastan at level 5 and let it run non-blocking for one build to size the
backlog, then gate. Re-enable `api-smoke.yml`. Both use tooling already standard in
this stack — no new vendors.

**Effort:** M · **Blast radius:** Expect an initial backlog of pre-existing warnings;
introduce at a level the repo already passes and ratchet.

---

### [MEDIUM] [CONFIRMED] Ingest docblock lists defences that are not in the method

**Location:** `app/Http/Controllers/Api/IngestController.php:23-37`

**What:** The docblock enumerates eight defence layers. Layer 4, "IP blocklist (Phase
6 wiring)", has no corresponding code in `__invoke`. Layer 2 claims "Body size cap
(100 KB) at the Apache layer; Laravel enforces 100 KB JSON" — I found no such
enforcement in the controller, middleware stack, or `config/`. *(SUSPECTED for the
Apache half: I did not read the vhost, so the cap may exist at the web-server layer
only, in which case the "Laravel enforces" clause is the inaccurate part.)*

**Why it matters:** This is the file a reviewer reads to understand the security model
of the one unauthenticated write endpoint in the system. A list that overstates by two
layers is worse than no list — it is the document a future maintainer will trust
instead of re-reading the code, and it is public.

**Fix:** Delete the layers that do not exist or implement them. If the body cap lives
in Apache, say which directive and where.

**Effort:** S · **Blast radius:** None.

---

### [MEDIUM] [CONFIRMED] `trace_class` defaults to `'production'` inline rather than through the contract

**Location:** `app/Http/Controllers/Api/IngestController.php:128-131`

**What:** `?->state?->get('mnl_class') ?? 'production'` hard-codes the fallback at the
call site instead of using the Interchange package's `TraceClass`.

**Why it matters:** Any caller that omits or malforms `tracestate` has their traffic
silently recorded as production. Scenario, canary and demo traffic then becomes
indistinguishable from real submissions in the data — which is the exact property
trace classification exists to provide. The same inline default is already tracked as
open in Webhook Relay; finding it here too makes it a fleet pattern rather than a
one-off, and a `trace.persist`-style conformance key would catch both.

**Fix:** Resolve through the package's `TraceClass` so the default lives in one place
and changes to the contract propagate.

**Effort:** S · **Blast radius:** Rows written after the change may classify
differently; historical rows keep the old value.

---

### [MEDIUM] [CONFIRMED] A database write on every authenticated request

**Location:** `app/Http/Middleware/ApiKeyAuth.php:54`

**What:** `$apiKey->forceFill(['last_used_at' => now()])->saveQuietly();` runs on every
request that passes auth.

**Why it matters:** One write per read request. At low volume this is invisible; under
the concurrency this project is presented as being ready for, it puts a write on the
hot path of every list endpoint and makes a single busy API key a row-level contention
point. It is also a write inside what is otherwise a pure authentication check.

**Fix:** Throttle it — only write when the stored `last_used_at` is older than a
minute. The value is used for key-hygiene reporting, so minute granularity is ample.

**Effort:** S · **Blast radius:** `last_used_at` becomes accurate to the minute.

---

### [LOW] Dead code in the hosted thank-you controller

**Location:** `app/Http/Controllers/Api/HostedThankYouController.php:6, 12`

The `$id` parameter is accepted and never used, and `use App\Models\Submission;` is
imported and never referenced. The page is a static string, so there is no data leak —
but the route accepts any value and renders identically, which reads as an unfinished
lookup. Either use the ID to render the per-workspace branded page the comment at
`:14-15` describes, or drop the parameter. **Effort:** S

### [LOW] `.env.integration` is committed

**Location:** `.env.integration` (tracked)

Contains `APP_KEY=` (empty), `APP_DEBUG=true` and a sqlite DSN — no live credentials,
so this is hygiene rather than exposure. But it sits beside correctly-ignored
`.env.deployment`/`.env.production` entries in `.gitignore:3-6`, so the exception is
easy to misread as "env files are fine to commit here." Rename to
`.env.integration.example` for consistency with the other tracked templates. **Effort:** S

### [LOW] No `error.tsx` / `loading.tsx` boundaries in the docs site

**Location:** `web/app/` — 9 route segments, zero error or loading boundaries

A thrown error in any server component renders the framework default rather than
anything branded. The live-demo route posts to the API and is the most likely to fail
in front of a prospect. **Effort:** S

### [NOTE] What is genuinely good here

Not filler — these are the reasons the Critical is worth fixing rather than rewriting
around:

- **`Destination` is a real contract.** `deliver()` returning an `AttemptResult`
  instead of throwing (`WebhookDestination:89, 158-161`) means transport failure is
  data, and retry policy lives in one place. Seven implementations, no special cases.
- **`HeaderPolicy` / `EgressPolicy`** (`app/Services/Destinations/Security/`) reason
  from a threat model — allowlist over denylist, with the rationale written down —
  and correctly treat a credentialed destination as different in kind from an
  anonymous one.
- **Tenant headers cannot override signatures** (`WebhookDestination:138-142`). That
  is a subtle, easily-missed hole, closed deliberately with the reason stated.
- **The dedup claim is atomic** — `Cache::add()` (`SubmissionDedupCache:33`) is SETNX,
  not read-then-write, and the loser deletes its own row rather than racing.
- **`now()` over `time()`** for signature timestamps (`WebhookDestination:102`),
  chosen specifically so the golden regression is freezable.

---

## 4. Root causes

**1. One control was assumed to work and never tested against the way it is actually
called.** `WorkspaceScope` is a good design — a global scope plus an opt-in trait is
the right shape for this. It fails on a single expression that is wrong only for
Eloquent models, and every safe controller is safe by independent accident. This one
cause produces the Critical and the Medium IDOR.

**2. Comments are treated as documentation of intent, and drift from implementation
with nothing comparing them.** The proxy comment, the SSRF docblock, the ingest
defence-layer list, and the reference to a command that does not exist are four
instances. Each is individually minor; together they mean the fastest way to
understand this system — reading its unusually good comments — is also the least
reliable.

**3. The test suite is organised around features, not around controls.** 95 tests
cover what each endpoint does. Almost none assert what an endpoint must *refuse*. The
isolation test is the clearest case: it tests the resource that was already safe.

**4. Guardrails were configured but not switched on.** Three disabled workflows and no
static analysis mean the class of defect that a linter catches for free — an
always-false condition, an unused import, a dead parameter — accumulates silently.

### What breaks first at 10x

Not the application. `ApiKeyAuth:54`'s per-request write, and the per-IP rate limiter
that currently cannot function (see the trusted-proxies finding) — under real load
with the proxy fix in place, the 60/min/IP/form default is the first number anyone
will need to tune.

### Riskiest day-one change

Adding a new endpoint that looks up a workspace-owned model by ID with
`Model::findOrFail($id)`. Every existing example in the codebase is split between two
patterns, one of which is safe and one of which is not, and the unsafe one looks more
idiomatic. Nothing in CI would stop it.

---

## 5. Remediation plan

**Immediate — before the next deploy**

1. Fix `WorkspaceScope::currentWorkspaceId()` to resolve a `Workspace` model *(unlocks 2, 3)*.
2. Scope `SubmissionsController::promote` and `::replay` explicitly. Do not rely on 1.
3. Scope `DataSubjectController::status` explicitly.
4. Add cross-tenant 404 tests for submissions, destinations and data-subject requests, plus a unit test on `currentWorkspaceId()` with a real model. **Write these before 1–3 and watch them fail** — otherwise you are re-asserting the same unverified confidence.
5. `trustProxies(at: ['127.0.0.1', '::1'])`.
6. `allow_redirects => false` on webhook delivery.
7. Validate `_redirect` against the form's `cors_origins` / `success_redirect_url`.

Items 1–3 are the outage-class fixes; 5–7 are each a one-line change. All seven are
plausibly one sitting.

**Near-term — this month**

8. Make `WorkspaceScope` fail closed for authenticated requests. Do this *after* 4 is green, because the tests are what tell you whether anything was depending on the current behaviour.
9. Reconcile the four inaccurate comments with reality (proxy, SSRF, ingest layers, missing command).
10. Larastan at a level the repo passes today, wired into `api-ci.yml`; re-enable `api-smoke.yml`.
11. Throttle the `last_used_at` write.
12. Route `trace_class` through the package's `TraceClass`.

**Structural — this quarter**

13. Pin the resolved IP for outbound delivery, closing DNS rebinding, or amend the docblock to stop advertising the gap.
14. Add `error.tsx` / `loading.tsx` to the docs site.
15. Decide the fate of the disabled SDK workflows — a workflow suffixed `.disabled` in a public repo reads as abandoned; either finish or delete.

Note that **8 invalidates nothing but depends on 4**, and **1 makes 2, 3 redundant as
security controls** — keep them anyway as defence in depth, since the whole finding
exists because a single shared control failed silently.

---

## 6. Open questions and assumptions

- **Was the 100 KB body cap ever configured in Apache?** I did not read the vhost
  (`infra/`, and the live server config), so I could not confirm the docblock's claim.
  If it is not there, the unauthenticated ingest endpoint has no request-size limit.
- **Is any tenant currently relying on `_redirect` to a host outside their form's
  `cors_origins`?** Determines whether the redirect fix needs a deprecation window.
- **Was `trustProxies('*')` a deliberate temporary step for a planned Cloudflare
  rollout?** The comment implies so. If Cloudflare is imminent the fix is the same
  today, with the ranges added at cutover.
- **Assumption:** loopback-only Apache is the sole proxy. Based on the live response
  headers showing Apache with no CDN fingerprint.

**Deliberately not inspected:** `vendor/`, `node_modules/`, `.next/`, generated SDK
clients (per scope). Also **not deeply reviewed**: the Filament admin resources
(`app/Filament/`) — the admin resolves workspace through a different, working branch,
so it is outside the blast radius of the Critical, but its own authorisation model
was not audited; `scripts/` (4,502 LOC of deploy tooling); the migration set beyond
constraint counting; and the docs site beyond the accessibility and boundary checks
reported above.
