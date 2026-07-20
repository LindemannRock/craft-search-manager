# Privacy & Security

Every visitor IP gets salted and hashed before it ever touches your database — the plaintext is discarded immediately, geo-lookup runs asynchronously on a copy, and analytics stays GDPR-friendly by default.

## What you'll use it for

- Track unique visitors in analytics without ever storing a raw IP address
- Add subnet masking so visitors on the same network share one hash instead of being individually trackable
- Show country/city breakdowns in analytics without slowing down search responses
- Set a retention period so old analytics data ages out automatically
- Export analytics data to satisfy a data-subject access request

## IP hashing

Every IP address is hashed with a salt before storage using SHA256. The original IP is discarded immediately — it cannot be recovered from the hash.

Generate the salt (run once after installation):

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

The command adds `SEARCH_MANAGER_IP_SALT` to your `.env` file automatically.

### How it works

1. Visitor makes a search request
2. Search Manager reads their IP
3. The IP is kept aside for asynchronous geo-lookup (if enabled) — country/city resolve later in a background queue job
4. IP is hashed with salt: `hash('sha256', $ip . $salt)`
5. Only the hash is stored — original IP is discarded
6. Same IP always produces the same hash (for unique visitor tracking)

### Salt security

- Never commit the salt to version control
- Store it securely (password manager recommended)
- Use the **same salt** across all environments (dev, staging, production)
- **Never regenerate** the salt in production — it breaks unique visitor tracking history

## Control Panel settings

The most commonly changed privacy settings live at **Settings → Analytics**, alongside the analytics toggle itself:

- **Geographic Detection** — the **Enable Geographic Detection** lightswitch turns on country/city lookups; **Geo Provider** picks the lookup service (`ip-api.com`, `ipapi.co`, or `ipinfo.io`), and an optional **API Key** unlocks a paid tier (e.g. HTTPS for `ip-api.com`).
- **IP Address Privacy** — the **Anonymize IP Addresses** lightswitch turns on subnet masking (see below).
- **Data Retention** — the **Analytics Retention** field sets how many days of analytics data to keep (0 for unlimited, max 3650), with a **Clean Up Now** button to run the cleanup job immediately.

Each of these maps to a config-file setting below, and locks (with a warning) when a `config/search-manager.php` entry overrides it.

## Subnet masking

For additional privacy, enable subnet masking to replace the last octet of IPv4 addresses with 0 before hashing:

```php
'anonymizeIpAddress' => true,
```

This means `192.168.1.42` becomes `192.168.1.0` before hashing. Multiple visitors on the same subnet will share a hash, making individual tracking impossible.

## Geo-location

When enabled, Search Manager detects the visitor's country and city using their IP address:

```php
'enableGeoDetection' => true,
'geoProvider' => 'ip-api.com',  // ip-api.com, ipapi.co, ipinfo.io
'geoApiKey' => null,            // For paid tiers (enables HTTPS for ip-api.com)
```

### Async processing

Geo-lookup runs as a queue job after the search response is sent. This means:
- Search responses are never delayed by geo-lookups
- If the queue job fails, the search still works — just without geo data
- Geo data is extracted **before** IP hashing, then the IP is discarded

### Local development

Local/private IPs can't be geolocated. Set defaults for testing:

```php
// config/search-manager.php
'defaultCountry' => 'US',
'defaultCity' => 'New York',
```

Or via `.env`:

```bash
SEARCH_MANAGER_DEFAULT_COUNTRY=US
SEARCH_MANAGER_DEFAULT_CITY="New York"
```

These settings only affect private IP addresses (127.0.0.1, 192.168.x.x, 10.x.x.x). Real visitor IPs in production always use actual geo-location.

### Supported locations for local dev

US, GB, AE, SA, DE, FR, NL, SE, DK, NO, CA, AU, JP, SG, IN — with common cities for each country.

## Bot filtering

Search Manager uses Matomo DeviceDetector to identify bot traffic. Bot searches are flagged in analytics so you can filter them out when reviewing search data.

## GDPR considerations

Search Manager's default configuration is GDPR-friendly:

- **No plain-text IPs** — only salted hashes are stored
- **No cookies** — analytics uses IP hash for visitor identification
- **Optional geo-detection** — disabled by default
- **Configurable retention** — set `analyticsRetention` to auto-delete old data
- **Subnet masking** — optional additional anonymization
- **Data export** — analytics can be exported for data subject requests

For maximum privacy compliance:
1. Generate the IP hash salt
2. Enable subnet masking (`anonymizeIpAddress: true`)
3. Set a retention period (`analyticsRetention: 90`)
4. Consider disabling geo-detection if not needed
