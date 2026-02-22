# Privacy & Security

Search Manager is designed with privacy as a core principle. IP addresses are never stored in plain text, geo-location is extracted asynchronously, and all analytics features respect GDPR guidelines.

## IP Hashing

Every IP address is hashed with a salt before storage using SHA256. The original IP is discarded immediately — it cannot be recovered from the hash.

Generate the salt (run once after installation):

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

The command adds `SEARCH_MANAGER_IP_SALT` to your `.env` file automatically.

### How It Works

1. Visitor makes a search request
2. Search Manager reads their IP
3. Country/city are extracted via geo-lookup (if enabled)
4. IP is hashed with salt: `hash('sha256', $ip . $salt)`
5. Only the hash is stored — original IP is discarded
6. Same IP always produces the same hash (for unique visitor tracking)

### Salt Security

- Never commit the salt to version control
- Store it securely (password manager recommended)
- Use the **same salt** across all environments (dev, staging, production)
- **Never regenerate** the salt in production — it breaks unique visitor tracking history

## Subnet Masking

For additional privacy, enable subnet masking to replace the last octet of IPv4 addresses with 0 before hashing:

```php
'anonymizeIpAddress' => true,
```

This means `192.168.1.42` becomes `192.168.1.0` before hashing. Multiple visitors on the same subnet will share a hash, making individual tracking impossible.

## Geo-Location

When enabled, Search Manager detects the visitor's country and city using their IP address:

```php
'enableGeoDetection' => true,
'geoProvider' => 'ip-api.com',  // ip-api.com, ipapi.co, ipinfo.io
'geoApiKey' => null,            // For paid tiers (enables HTTPS for ip-api.com)
```

### Async Processing

Geo-lookup runs as a queue job after the search response is sent. This means:
- Search responses are never delayed by geo-lookups
- If the queue job fails, the search still works — just without geo data
- Geo data is extracted **before** IP hashing, then the IP is discarded

### Local Development

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

### Supported Locations for Local Dev

US, GB, AE, SA, DE, FR, CA, AU, JP, SG, IN — with common cities for each country.

## Bot Filtering

Search Manager uses Matomo DeviceDetector to identify bot traffic. Bot searches are flagged in analytics so you can filter them out when reviewing search data.

## GDPR Considerations

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
