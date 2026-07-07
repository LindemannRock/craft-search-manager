# API Keys

**CP:** Search Manager → API Keys

A CRUD surface for generating, scoping, and revoking API keys that gate access to the public search, autocomplete, and analytics tracking endpoints.

> [!IMPORTANT]
> **What keys gate.** When **Require API Key** is enabled (Settings → General → API Access), the public **search** and **autocomplete** endpoints require a valid key in the `X-Search-Manager-Key` header — requests without a valid, active, in-scope key are rejected (`401` for a missing/invalid key, `403` for a disabled or expired key). When the setting is disabled (the default), those endpoints stay anonymous and behave exactly as before.
>
> The `track-search` / `track-click` analytics endpoints are gated too when the setting is on — same key + referrer + allowed-indices checks — so analytics writes also require a valid key. Tracking pings are **not** rate-limited (they're noisy by design). When the setting is off, all four endpoints stay anonymous.

## What a key is

Each key is generated once on the server. The plaintext value looks like:

```
sm_pub_a1b2c3d4e5f6...      (public key, intended for browser code)
sm_srv_a1b2c3d4e5f6...      (server key, intended for server-side callers)
```

All keys store an authentication **hash** and a 15-character **display prefix**. The hash is `HMAC-SHA256(plaintext, Craft.securityKey)` — keyed by your install's security key so a leaked DB dump cannot be replayed against a different install. Public keys can also store encrypted plaintext material so CP-managed widgets can send a selected public key from browser-rendered HTML. Server keys remain hash-only and unrecoverable.

### Shown once

The plaintext is shown **once** — on the screen immediately after you create the key. The CP never displays the full key again. External callers still need to copy the key once unless they are using a CP-managed widget selector.

If you lose a server key, or a public key used outside the CP-managed widget selector, the only recovery is to create a new key and update wherever the old one was used.

### Public vs server

The `type` field describes the **intended exposure**, not a restriction-bypass:

| Type | Prefix | Use case |
|------|--------|----------|
| **Public** | `sm_pub_…` | Safe to embed in browser-side code (widget selector, config-file widget override, JS fetch). Pair with strict `allowedReferrers`. |
| **Server** | `sm_srv_…` | Intended for server-to-server calls only. Should never appear in HTML, JS bundles, or mobile-app binaries. |

Both types accept the same restrictions. The distinction exists so operators (and code reviewers) can tell at a glance whether a key was provisioned for the browser or for a backend caller. Type is locked once the key is generated — it's encoded in the prefix and changing it would invalidate the hash.

### Which type should I use?

| Caller | Use | Why |
|--------|-----|-----|
| Website search page using browser JavaScript | **Public** key | Browser users can see the key in DevTools and network requests. Scope it to the page's indices and add strict referrer patterns. |
| Search Manager frontend widget | **Public** key | CP widgets select a public key by name/prefix; config-file widgets can still provide a public key override. The widget emits the resolved key into page HTML and sends it from browser-side JavaScript. Never use a server key. |
| External server or backend service | **Server** key | The key stays server-side and does not rely on a browser `Referer` header. |
| Mobile app through your own backend | **Server** key on your backend | Recommended. The mobile app calls your backend, and your backend calls Search Manager with the server key. |
| Mobile app calling Search Manager directly | **Avoid when possible** | Native apps cannot use browser referrer restrictions reliably. Prefer a backend proxy; if direct calls are unavoidable, scope the key narrowly and use expiry/rate limits. |

Server keys skip the public-key referrer check, but they still respect enabled/disabled state, expiry, allowed indices, max hits per page, and rate limits.

## Restrictions

Every key is scoped by a small set of restrictions. Restrictions are stored per-key and, when **Require API Key** is enabled, checked on every request.

### Allowed indices

Which search indices the key may query.

- **`*` (all indices)** — wildcard. Grants access to every currently enabled index, **plus any indices added later**. Use this for trusted server keys; avoid it for public widget keys.
- **Explicit handles** — a list of specific index handles (e.g. `docs-en`, `blog-en`). Adding a new index does **not** automatically extend the key — you must edit it.
- **Empty list** — only allowed while the key is disabled. Enabled keys must either allow all indices or include at least one specific index.

> [!TIP]
> Pick the narrowest list that satisfies the caller. The `*` wildcard is a convenience for trusted server-side integrations; it is rarely the right choice for keys embedded in browsers.

### Allowed referrers

Restricts which `Referer` header values the key will be accepted from. Useful for keys embedded in browser-side widgets.

Patterns:

| Pattern | Matches |
|---------|---------|
| `example.com` | Exact host only — `https://example.com/…` |
| `*.example.com` | Any subdomain of `example.com`, at **any depth** — `app.example.com`, `staging.app.example.com`, `a.b.c.example.com` |
| *(empty list)* | No referrer restriction — any caller is allowed |

Two intentional limits:

- **No regex.** Only the literal `*.` prefix is supported. Full regex would expose request-handling to ReDoS attacks at enforcement time.
- **No protocol or path.** Patterns match the host portion of the referrer only.

Mixing both forms is fine — e.g. `example.com` + `*.example.com` accepts the bare apex and every subdomain.

### Max hits per page

Optional integer clamp on the `hitsPerPage` query parameter. Requests asking for more are reduced to this value.

Useful for public keys to bound bandwidth and result-set size without hard-coding a low cap site-wide.

### Valid until

Optional expiry datetime. After it passes, the key's status flips to **Expired** and, with enforcement enabled, requests are rejected (`403`). Leave it empty for a key that never expires.

### Rate limit

Optional per-key cap on requests **per minute**. When set, requests beyond the cap are rejected with `429 Too Many Requests`, counted in a fixed one-minute window per key (across both the search and autocomplete endpoints combined). Leave it empty for no limit.

Only enforced on authenticated requests — i.e. when **Require API Key** is enabled and a valid key is presented. Anonymous traffic (when the setting is off) is never rate-limited.

## Lifecycle

A key moves through three operator-controlled states:

### Active

Default after creation. With enforcement enabled, the key is accepted on every request that matches its restrictions.

### Disabled (paused)

The **Enabled** lightswitch on the edit page. Toggling it off **pauses** the key:

- The row, hash, prefix, and all restrictions are kept.
- With enforcement enabled, every request presenting this key is rejected immediately (`403`).
- Re-enable it later by toggling the switch back on — no new key needed.

Use Disable when you want to temporarily block a caller (e.g. a third-party integration is misbehaving) without losing the configuration or forcing the caller to rotate.

Public keys selected by widget configs are dependency-protected. Search Manager blocks disabling, expiring, deleting, or narrowing a public key's allowed indices in a way that would invalidate those widgets. Remove or reassign the key from the widget configs first.

### Revoked (deleted)

The **Revoke** action on the index page or the **Delete** button on the edit page. Revoking **permanently deletes** the row:

- The hash, prefix, and all configuration are removed from the database.
- There is no undo. Recovery requires creating a new key and updating every caller.
- A request presenting the old plaintext is a lookup miss (no row with that prefix) and is rejected exactly the same way an unknown key is — `401`.

Use Revoke when the key is leaked, the integration is decommissioned, or the configuration is wrong enough that re-issuing is simpler than fixing.

> Past [analytics](analytics.md#api-key-attribution) rows that were attributed to a revoked key stay readable: they keep a snapshot of the key's prefix and type, so historical traffic remains correlatable after the key row is gone.

| Status | What it means | Triggered by |
|--------|---------------|--------------|
| **Active** | In service | Default state after create |
| **Disabled** | Temporarily paused, config preserved | Operator toggles **Enabled** off |
| **Expired** | `validUntil` has passed | Automatic / time-based |
| *(removed)* | Row no longer exists | Operator clicks **Revoke** |

Priority for display when more than one applies: **Disabled** beats **Expired** beats **Active**. Disabled is shown first because it reflects an intentional operator action; expiry is automatic.

## Permissions

API key management is gated by four permissions, granted via **Settings → Users → User Groups → Search Manager**:

| Permission | Grants |
|------------|--------|
| `searchManager:manageApiKeys` | Open the section, view the list, view individual keys |
| `searchManager:createApiKeys` | Generate new keys |
| `searchManager:editApiKeys` | Change a key's name, restrictions, or enabled state |
| `searchManager:revokeApiKeys` | Delete a key |

`manageApiKeys` is the parent — without it, the section is hidden entirely. The three child permissions are independent: a user can be granted edit without revoke, or revoke without create, depending on what your governance model requires.

See [Permissions](../developers/permissions.md) for the full permission matrix.

## Provisioning workflows

### From the Control Panel

1. Search Manager → API Keys → **New API key**.
2. Pick the type, name, restrictions, and (optionally) expiry.
3. Save. The full plaintext key is revealed in a copy-to-clipboard banner.
4. Copy it into your secrets store or environment file if external callers need the full value. CP widget configs select public keys by name/prefix and do not display the full key. The banner cannot be re-displayed once you leave the page.

### From the command line

For automated provisioning (CI bootstrap, fresh install, scripted deploys), use the console command:

```bash
php craft search-manager/api-keys/create \
  --name="Primary widget key" \
  --type=public \
  --indices=docs-en,blog-en \
  --referrers=example.com,*.example.com
```

The plaintext is printed to stdout exactly once and never logged. See [Console Commands](../developers/console-commands.md#api-keys) for the full option list.

For a backend integration, create a server key and keep the plaintext on the server:

```bash
php craft search-manager/api-keys/create \
  --name="Backend search API" \
  --type=server \
  --indices=docs-en,blog-en \
  --max-hits=50 \
  --rate-limit=120
```

Then send it from the server-side caller:

```text
GET /actions/search-manager/api/search?q=test&indices=docs-en
X-Search-Manager-Key: sm_srv_a1b2c3d4e5f6...
```

## Next steps

- [Console Commands → API Keys](../developers/console-commands.md#api-keys) — automated provisioning
- [Permissions](../developers/permissions.md) — full permission matrix
