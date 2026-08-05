# Rollback — Interchange adoption

**Written before the deploy, not after.** Plan R-1/R-2.

## What ships

- `philiprehberger/interchange` ^0.1.2 (trace context, Standard Webhooks, conformance interface)
- Nullable/defaulted columns on `form_destinations`, `submissions`, `workspaces`
- New table `signature_scheme_usage`
- `TraceMiddleware` appended to the HTTP stack; queue propagation registered by the package provider

## Why a code revert is safe here

Every column added is nullable or defaulted, and **no existing behaviour changes by default**:

| Field | Default | Effect if code reverts |
|---|---|---|
| `form_destinations.headers` | `null` | v0 code never reads it; no headers were sent before, none after |
| `form_destinations.envelope_shape` | `inkwell-native` | Identical body to pre-adoption — proven byte-for-byte by `GoldenDeliveryTest` |
| `form_destinations.signature_scheme` | `inkwell-v0` | Identical signature to pre-adoption, same test |
| `submissions.trace_id` / `trace_class` | `null` | Written but never read by v0 |
| `workspaces.egress_allowlist` | `null` | Only consulted for credentialed destinations, of which there are none until someone creates one |

So: **leave the columns in place on rollback.** Do not run `migrate:rollback` — the down migration drops columns and would lose any configuration an operator had already set.

## The case that is NOT safe

Per plan R-1, **configuration does not roll back with code.**

If a destination has been switched to `signature_scheme = 'standard-webhooks'` and the code reverts to a release that does not implement that scheme, the reverted code falls through to its native branch and signs with the **wrong scheme for a consumer that is now expecting Standard Webhooks** — a silent data-plane break that looks like a successful deploy.

**Therefore, before reverting code:**

```sql
-- Find destinations that would break
SELECT id, form_id, signature_scheme
FROM form_destinations
WHERE signature_scheme <> 'inkwell-v0';
```

If that returns rows, either revert those rows to `inkwell-v0` **in the same change as the code revert**, or do not revert the code. The consumer also needs telling, because their secret and verification changed when they opted in.

At the time of this deploy the query returns zero rows — no destination has opted in — so the first rollback is unconditionally safe. That stops being true the moment someone opts in.

## Procedure

1. `SELECT` above. If non-empty, plan the config revert first.
2. Repoint the release symlink to the previous release.
3. **Restart** `php8.3-fpm` — not reload, which leaves stale per-worker OPcache.
4. Restart the Horizon worker so queued jobs run the reverted code.
5. Verify a delivery: create a test submission against a webhook destination and confirm a 2xx and the expected `X-Inkwell-Signature` header shape.

## Verification after the forward deploy

- `GET /v1/healthz` returns healthy
- A submission to an existing form still delivers, with `X-Inkwell-Signature` present and `webhook-*` headers absent
- `signature_scheme_usage` shows `inkwell-v0` incrementing and `standard-webhooks` absent
- Response carries a `traceparent` header
