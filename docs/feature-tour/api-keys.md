# API Keys

**CP:** Search Manager → API Keys

A CRUD surface for generating, scoping, and revoking API keys that gate access to the public search and autocomplete endpoints.

> [!IMPORTANT]
> **What keys gate.** When **Require API Key** is enabled (Settings → General → API Access), the public **search** and **autocomplete** endpoints require a valid key in the `X-Search-Manager-Key` header — requests without a valid, active, in-scope key are rejected (`401` for a missing/invalid key, `403` for a disabled or expired key). When the setting is disabled (the default), those endpoints stay anonymous and behave exactly as before.
>
> Two endpoints are **not** gated yet: the `track-search` / `track-click` analytics endpoints stay anonymous (planned for the widget/tracking slice), and per-key **rate limiting** is stored but **not yet enforced** (planned for a later slice).

## What a key is

Each key is generated once on the server. The plaintext value looks like:

```
sm_pub_a1b2c3d4e5f6...      (public key, intended for browser code)
sm_srv_a1b2c3d4e5f6...      (server key, intended for server-side callers)
```

Only the **hash** and a 15-character **display prefix** are stored. The hash is `HMAC-SHA256(plaintext, Craft.securityKey)` — keyed by your install's security key so a leaked DB dump cannot be replayed against a different install.

### Shown once

The plaintext is shown **once** — on the screen immediately after you create the key. Search Manager has no way to retrieve it again afterwards, because nothing in the system retains the plaintext: only the hash and prefix are persisted.

If you lose the plaintext, the only recovery is to create a new key and update wherever the old one was used.

### Public vs server

The `type` field describes the **intended exposure**, not a restriction-bypass:

| Type | Prefix | Use case |
|------|--------|----------|
| **Public** | `sm_pub_…` | Safe to embed in browser-side code (widget config, JS fetch). Pair with strict `allowedReferrers`. |
| **Server** | `sm_srv_…` | Intended for server-to-server calls only. Should never appear in HTML, JS bundles, or mobile-app binaries. |

Both types accept the same restrictions. The distinction exists so operators (and code reviewers) can tell at a glance whether a key was provisioned for the browser or for a backend caller. Type is locked once the key is generated — it's encoded in the prefix and changing it would invalidate the hash.

## Restrictions

Every key is scoped by a small set of restrictions. Restrictions are stored per-key and, when **Require API Key** is enabled, checked on every request.

### Allowed indices

Which search indices the key may query.

- **`*` (all indices)** — wildcard. Grants access to every currently enabled index, **plus any indices added later**. Use this for trusted server keys; avoid it for public widget keys.
- **Explicit handles** — a list of specific index handles (e.g. `docs-en`, `blog-en`). Adding a new index does **not** automatically extend the key — you must edit it.
- **Empty list** — the key is non-functional. The UI surfaces a warning, and with enforcement enabled the endpoint rejects the key.

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

### Rate limit *(slice 3)*

Per-key requests-per-minute cap. The field exists in slice 1 so operators can provision values in advance, but **rate-limit enforcement is a later slice** and the value is currently unused at request time.

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

### Revoked (deleted)

The **Revoke** action on the index page or the **Delete** button on the edit page. Revoking **permanently deletes** the row:

- The hash, prefix, and all configuration are removed from the database.
- There is no undo. Recovery requires creating a new key and updating every caller.
- A request presenting the old plaintext is a lookup miss (no row with that prefix) and is rejected exactly the same way an unknown key is — `401`.

Use Revoke when the key is leaked, the integration is decommissioned, or the configuration is wrong enough that re-issuing is simpler than fixing.

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
4. Copy it into your secrets store / environment file / widget config. The banner cannot be re-displayed once you leave the page.

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

## Next steps

- [Console Commands → API Keys](../developers/console-commands.md#api-keys) — automated provisioning
- [Permissions](../developers/permissions.md) — full permission matrix
