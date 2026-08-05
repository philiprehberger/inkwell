# Threat model — authenticated webhook destinations

**Plan items 5.1–5.5. Written before implementation, deliberately.**

Inkwell is multi-tenant. Adding tenant-controlled headers to a feature that already has a tenant-controlled URL and a tenant-controlled body turns a webhook forwarder into a **general-purpose authenticated request forger** unless it is constrained on purpose.

The existing `SsrfGuard` refuses private, loopback and link-local destinations. That is necessary and insufficient: it does nothing about a tenant aiming a credentialed request at a third-party API, or at another tenant's public endpoint.

---

## 1. What an attacker gets

A workspace member can already:

- choose an arbitrary public URL
- cause Inkwell's server to make a request to it
- have that request contain submission data

Adding headers would additionally let them:

| Capability | Consequence |
|---|---|
| Set `Authorization` | Inkwell relays a credential the attacker supplies to a target the attacker chooses — Inkwell becomes a confused deputy with a clean source IP and a clean reputation |
| Set `Host` | Virtual-host confusion; a request that looks like it is for a different site |
| Set `Cookie` | Session riding against a third-party service |
| Set hop-by-hop headers (`Connection`, `Transfer-Encoding`, `Upgrade`, `TE`, `Trailer`, `Proxy-*`) | Request smuggling / desync against intermediaries |
| Set `Content-Length` | Desync between what Inkwell sends and what a proxy reads |
| Set unbounded header count or size | Memory and bandwidth amplification, and a way to DoS the receiving side from our IP |

The severe cases share a shape: **Inkwell's identity is what makes the request valuable.** Anyone can curl an endpoint from their own machine; doing it from our server, with our IP reputation and our egress, is the actual capability being granted.

## 2. Decisions

### 5.2 — Allowlist, not denylist

Header names are validated against an **explicit permitted set**. A denylist fails open the moment a new dangerous header is standardised, and there is no reason a form-forwarding product needs arbitrary headers.

Permitted: `Authorization`, `X-Api-Key`, `Api-Key`, `X-Auth-Token`, `X-Signature`, `Idempotency-Key`, `Accept`, `Content-Type`, `X-Request-Id`, and any `X-`-prefixed name that is not otherwise forbidden.

Hard-blocked regardless of prefix: `Host`, `Cookie`, `Set-Cookie`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Upgrade`, `TE`, `Trailer`, `Expect`, and anything matching `Proxy-*`.

Bounds: **at most 10 headers**, name ≤ 64 characters, value ≤ 2,048 characters. Names must match `^[A-Za-z0-9-]+$`; values must contain no CR or LF (header injection).

### 5.3 — Per-workspace egress allowlist: **required for credentialed destinations**

Decision: **yes, for any destination carrying a secret-bearing header.**

Rationale: an unauthenticated webhook to an arbitrary URL leaks submission data the tenant already owns — bad, but bounded by what they could obtain anyway. A *credentialed* request is different in kind, because the credential may not be theirs to use and the target may trust our IP.

Implementation: a workspace with `egress_allowlist` set restricts credentialed destinations to those hosts. A workspace without one may not save a credentialed destination at all. Non-credentialed destinations are unaffected, preserving current behaviour for every existing destination.

### 5.4 — Secret-bearing header values are credentials

- Encrypted at rest via Laravel's encrypted cast — not merely "stored in a json column".
- Redacted in every log line, audit row, API response and Filament display.
- **Never returned after write.** Reads yield `••••••••` and a `has_value` boolean. An operator can replace a value; nobody can retrieve one.
- The redaction list matches on substring so `X-Inkwell-Signature` is caught by `signature`, not only exact names.

### 5.5 — No tenant-authored templates in v1

The plan's original design allowed a tenant-authored body template. **Rejected for v1.**

A template engine over tenant input is a code-execution surface, and "restricted, non-eval expression syntax" describes an intention rather than a guarantee. Sandboxing is a thing to get right deliberately, not as a sub-item of a webhook feature.

Instead v1 ships a **fixed set of named envelope shapes**:

| Shape | Body |
|---|---|
| `inkwell-native` *(default)* | Today's envelope, byte-for-byte unchanged |
| `webhook-relay` | `{"type": "...", "payload": {…}}` — what Webhook Relay's ingest requires |
| `flat` | The submission payload alone, no wrapper |

This covers the actual requirement — letting Inkwell post directly into Webhook Relay — with zero injection surface. A sandboxed engine is deferred to the roadmap with named guarantees.

## 3. What is explicitly not mitigated

- A tenant can still send submission data to any public URL. That is the product.
- A tenant can still use Inkwell's IP for unauthenticated requests. Rate limits and the SSRF guard bound this; the egress allowlist is available for workspaces that want it.
- Standard Webhooks secrets are per-destination and issued by us; a tenant who exfiltrates their own secret can forge their own webhooks, which harms only themselves.

## 4. Migration hazard (recorded at G-2)

Standard Webhooks secrets are base64 with a `whsec_` prefix, and the HMAC key is the decoded bytes. **Every existing Inkwell secret is an arbitrary ≥16-character string and is therefore unusable as a Standard Webhooks key.**

Consequence: switching a destination to `standard-webhooks` requires issuing a new secret and coordinating with whoever consumes it. It is not a config flip, cannot be done in bulk, and the scheme picker must default to `inkwell-v0` permanently rather than eventually flipping.
