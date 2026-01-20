# Search Manager: Strategic Plan

## Executive Summary

Transform Search Manager from a Craft CMS plugin into a standalone, MENA-first search platform that competes with Algolia, Meilisearch, and Typesense by leveraging data sovereignty compliance and superior Arabic language support.

---

## Table of Contents

1. [Current Product Analysis](#current-product-analysis)
2. [Competitive Landscape](#competitive-landscape)
3. [MENA Market Opportunity](#mena-market-opportunity)
4. [Positioning Strategy](#positioning-strategy)
5. [Craft CMS Plugin Strategy](#craft-cms-plugin-strategy)
6. [Product Roadmap](#product-roadmap)
7. [Go-to-Market Strategy](#go-to-market-strategy)
8. [Revenue Model](#revenue-model)
9. [Technical Architecture](#technical-architecture)
10. [Action Items](#action-items)

---

## Current Product Analysis

### Core Capabilities

| Category | Features |
|----------|----------|
| **Backends** | Algolia, Meilisearch, MySQL, PostgreSQL, Redis, File, Typesense |
| **Search Algorithm** | BM25 (Okapi BM25) with configurable k1, b parameters |
| **Query Operators** | Phrases, NOT, field-specific, wildcards, per-term boosting, AND/OR |
| **Fuzzy Matching** | N-gram similarity with configurable threshold (default: 0.50) |
| **Languages** | English, Arabic, German, French, Spanish (stop words + operators) |
| **Analytics** | Comprehensive tracking with IP hashing, device detection, geographic data |
| **Query Rules** | Synonyms, boosts (section/category/element), filters, redirects |
| **Promotions** | Pin specific elements to fixed positions |
| **Caching** | Two-tier: search results + device detection |

### Technical Stack

- **PHP 8.2+** with Craft CMS 5.0+
- **Architecture**: Service-oriented with plugin interface pattern
- **Key Components**:
  - `SearchEngine` - Main orchestrator
  - `BM25Scorer` - Ranking algorithm
  - `QueryParser` - Complex query parsing
  - `FuzzyMatcher` - Typo tolerance
  - `BackendService` - Backend abstraction
  - `AnalyticsService` - Tracking & metrics

### Unique Strengths

1. **7 backend options** vs competitors' single approach
2. **Full self-hosting** capability
3. **Arabic localized operators** (unique in market)
4. **Comprehensive analytics** built-in
5. **Query rules & promotions** at all tiers
6. **Hybrid config system** (database + file)

---

## Competitive Landscape

### Market Leaders

#### Algolia

| Aspect | Details |
|--------|---------|
| **Market Share** | 88.80% in search category |
| **Customers** | 37,195+ companies globally |
| **Pricing** | Usage-based (searches + records), opaque enterprise pricing |
| **Arabic Support** | Basic - still "working on improvements" for typo tolerance |
| **Data Location** | US/EU only - **not compliant with MENA data sovereignty** |
| **Strengths** | Brand recognition, AI features, speed |
| **Weaknesses** | Expensive, no self-hosting, limited Arabic NLP |

**Algolia Pricing Tiers:**
- Build: Free (10K searches/mo, 1M records) - no semantic search
- Grow: Pay-as-you-go - no AI features
- Premium: Enterprise pricing - AI features included
- Elevate: Custom - semantic search (NeuralSearch)

#### Meilisearch

| Aspect | Details |
|--------|---------|
| **License** | MIT (permissive) |
| **Pricing** | Open-source free, Cloud from $30/mo |
| **Arabic Support** | Limited |
| **Self-hosting** | Yes |
| **Strengths** | Developer-friendly, fast, AI-powered |
| **Weaknesses** | Enterprise sharding experimental, basic analytics |

#### Typesense

| Aspect | Details |
|--------|---------|
| **License** | GPL v3 |
| **Pricing** | Cloud from $7/mo (resource-based) |
| **Arabic Support** | Limited |
| **Self-hosting** | Yes |
| **Strengths** | Memory-efficient, typo tolerance |
| **Weaknesses** | RAM-limited scaling, basic analytics |

### Competitive Matrix

| Feature | Search Manager | Algolia | Meilisearch | Typesense |
|---------|---------------|---------|-------------|-----------|
| Self-hosted option | ✅ Full | ❌ No | ✅ Yes | ✅ Yes |
| Multi-backend | ✅ 7 options | ❌ Single | ❌ Single | ❌ Single |
| Arabic stop words | ✅ 122 words | ✅ Yes | ⚠️ Limited | ⚠️ Limited |
| Arabic operators | ✅ Localized | ❌ No | ❌ No | ❌ No |
| BM25 ranking | ✅ Built-in | ✅ Custom | ✅ Custom | ✅ Custom |
| Analytics dashboard | ✅ Comprehensive | ✅ Paid | ⚠️ Basic | ⚠️ Basic |
| Query rules | ✅ All tiers | ✅ Paid | ⚠️ Basic | ⚠️ Basic |
| Promotions | ✅ Yes | ✅ Paid | ❌ No | ❌ No |
| Data sovereignty | ✅ Full control | ❌ US/EU | ✅ Self-host | ✅ Self-host |
| Pricing model | Flat license | Per-search | Subscription | Resource |

### Market Gap

**Critical finding:** Zero dedicated search technology providers exist in the MENA region. The market relies entirely on US/EU solutions that:
- Cannot guarantee data sovereignty
- Have poor Arabic language support
- Charge in USD with unpredictable pricing

---

## MENA Market Opportunity

### Market Size & Growth

| Metric | Value | Source |
|--------|-------|--------|
| ME E-commerce (2024) | $1.888 Billion | IMARC Group |
| ME E-commerce (2033) | $10.957 Billion | IMARC Group |
| CAGR | 21.58% | IMARC Group |
| Cloud Apps Market (2025) | $5.88 Billion | Mordor Intelligence |
| Cloud Apps Market (2030) | $14.50 Billion | Mordor Intelligence |
| Saudi VC Share | 30%+ of MENA | Industry reports |

### Key Markets

#### Saudi Arabia (Primary Target)

**Regulatory Environment:**
- **PDPL (2021)**: Effective September 2023, compliance required by September 2024
- **Penalties**: Up to $1.3 million fines, potential imprisonment
- **Data Localization**: All data must remain within Saudi borders
- **Cloud First Policy**: Government entities must use licensed CSPs
- **Oversight**: CST (Communications, Space & Technology Commission), NCA (National Cybersecurity Authority)

**Market Characteristics:**
- 91% of Saudis shop online regularly
- 90%+ internet penetration
- Vision 2030 driving digital transformation
- Major cloud presence: Oracle Riyadh, Azure Jeddah, STC Cloud

#### UAE (Secondary Target)

**Regulatory Environment:**
- Federal Decree-Law No. 45 of 2021 (UAE PDPL)
- More flexible than Saudi for commercial SaaS
- DIFC and ADGM have separate data protection regimes

**Market Characteristics:**
- Dubai as regional tech hub
- Strong e-commerce ecosystem
- GITEX as major industry event

#### Egypt (Tertiary Target)

- Largest population in MENA
- Growing tech ecosystem
- Cost-effective development talent

### Pain Points in MENA

| Pain Point | Impact | Our Solution |
|------------|--------|--------------|
| **Data Sovereignty** | Legal non-compliance risk | Full self-hosting on local infrastructure |
| **Arabic Language Gaps** | Poor search experience | Native Arabic NLP with dialects |
| **Pricing Unpredictability** | Budget planning impossible | Flat licensing model |
| **USD Pricing** | Currency risk | Local currency options |
| **No Local Support** | Timezone issues | Arabic-speaking team in region |
| **Western-centric UX** | RTL issues | Full RTL interface |

### Target Segments

| Segment | Priority | Rationale | Deal Size |
|---------|----------|-----------|-----------|
| **Government/Semi-Gov** | High | Must comply with data localization | $50K-200K/yr |
| **E-commerce** | High | Core use case, high volume | $5K-50K/yr |
| **Financial Services** | High | Strict regulations, high budget | $30K-100K/yr |
| **Healthcare** | Medium | Patient data sensitivity | $20K-50K/yr |
| **Education/EdTech** | Medium | Growing sector | $5K-20K/yr |
| **Media/Publishing** | Medium | Content search needs | $10K-30K/yr |

---

## Positioning Strategy

### Brand Positioning

**Tagline Options:**
- "The search engine that speaks Arabic and respects your data"
- "Search without borders. Data within yours."
- "Enterprise search, Arabic-first"

### Value Proposition

**For MENA Enterprises:**
> Deploy world-class search technology on your own infrastructure, with native Arabic language support and full compliance with local data sovereignty laws — at a fraction of Algolia's cost.

**For Global Market:**
> The only multi-backend search platform that lets you choose: self-host anywhere, use any database, or connect to cloud services — all with the same API.

### Key Differentiators (MENA)

| Differentiator | Message |
|----------------|---------|
| **Data Sovereignty** | "Your data never leaves your country" |
| **Arabic-First** | "Built for Arabic from day one" |
| **Predictable Pricing** | "Know your costs before you scale" |
| **Local Presence** | "Support in your language, your timezone" |
| **No Lock-in** | "Switch backends without changing code" |

### Competitive Responses

**vs Algolia:**
- "Same features, your infrastructure, 70% less cost"
- "Data sovereignty compliant out of the box"
- "Real Arabic language support, not an afterthought"

**vs Meilisearch/Typesense:**
- "Production-ready analytics included"
- "Enterprise query rules at every tier"
- "Arabic localized from the start"

---

## Craft CMS Plugin Strategy

### Product Architecture Decision

The Craft CMS plugin will evolve from a standalone product into a **connector** to the broader Search Manager platform. This enables:

1. **Unified codebase** - Core engine maintained once, used everywhere
2. **Clear upgrade path** - Free → Pro → Cloud
3. **Network effects** - Same cloud serves Craft, WordPress, Laravel users
4. **Revenue diversification** - One-time licenses + recurring subscriptions

### Architecture Model

```
┌─────────────────────────────────────────────────────────┐
│           Search Manager Platform (Core)                │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Self-Hosted Engine  OR  Cloud Service          │   │
│  └─────────────────────────────────────────────────┘   │
└───────────────────────┬─────────────────────────────────┘
                        │
        ┌───────────────┼───────────────┐
        ↓               ↓               ↓
   Craft Plugin    WordPress Plugin  Laravel Package
   (Connector)      (Connector)       (Connector)
```

### Craft Plugin Tiers

| Tier | Price | License | Target |
|------|-------|---------|--------|
| **Lite** | Free | MIT | Hobbyists, small sites |
| **Pro** | $99/year | Commercial | Agencies, businesses |
| **Cloud** | $29/mo+ | Subscription | Scale, managed service |

### Feature Matrix

| Feature | Lite (Free) | Pro ($99/yr) | Cloud ($29/mo+) |
|---------|-------------|--------------|-----------------|
| **Backends** | | | |
| File backend | ✅ | ✅ | - |
| MySQL backend | ✅ | ✅ | - |
| PostgreSQL backend | ❌ | ✅ | - |
| Redis backend | ❌ | ✅ | - |
| Cloud backend | ❌ | ❌ | ✅ |
| **Limits** | | | |
| Records | 10,000 | Unlimited | Plan-based |
| Searches/month | Unlimited | Unlimited | Plan-based |
| Indices | 1 | Unlimited | Plan-based |
| **Search Features** | | | |
| Basic search | ✅ | ✅ | ✅ |
| Fuzzy matching | ✅ | ✅ | ✅ |
| Query operators | Basic | Full | Full |
| Autocomplete | ✅ | ✅ | ✅ |
| Highlighting | ✅ | ✅ | ✅ |
| **Advanced Features** | | | |
| Query rules | ❌ | ✅ | ✅ |
| Synonyms | ❌ | ✅ | ✅ |
| Promotions/Pinning | ❌ | ✅ | ✅ |
| Custom transformers | ❌ | ✅ | ✅ |
| Replace native search | ❌ | ✅ | ✅ |
| **Arabic NLP** | | | |
| Arabic stop words | Basic (50) | Full (300+) | Full (300+) |
| Arabic stemming | ❌ | ✅ | ✅ |
| Diacritics handling | ❌ | ✅ | ✅ |
| Dialect support | ❌ | ✅ | ✅ |
| Localized operators | ❌ | ✅ | ✅ |
| **Analytics** | | | |
| Basic stats | ❌ | ✅ | ✅ |
| Full dashboard | ❌ | ❌ | ✅ |
| Content gaps | ❌ | ❌ | ✅ |
| Geographic data | ❌ | ❌ | ✅ |
| Export (CSV/JSON) | ❌ | ✅ | ✅ |
| **Support** | | | |
| Community (GitHub) | ✅ | ✅ | ✅ |
| Email support | ❌ | ✅ | ✅ |
| Priority support | ❌ | ❌ | ✅ |
| SLA | ❌ | ❌ | Enterprise |
| **Extras** | | | |
| Commercial use | ✅ | ✅ | ✅ |
| Branding removal | ❌ | ✅ | ✅ |
| Updates | 1 year | 1 year | Always |

### Upgrade Paths

```
┌──────────────────────────────────────────────────────────────┐
│                     Customer Journey                          │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────┐    Hits 10K     ┌─────────┐    Wants managed   │
│  │  Lite   │ ──────────────→ │   Pro   │ ─────────────────→ │
│  │  Free   │    records      │  $99/yr │    or analytics    │
│  └─────────┘                 └─────────┘                     │
│       │                           │                          │
│       │ Wants Pro features        │ Enterprise needs         │
│       │ immediately               │                          │
│       ↓                           ↓                          │
│  ┌─────────┐                ┌───────────┐                   │
│  │   Pro   │                │   Cloud   │                   │
│  │  $99/yr │                │  $29/mo+  │                   │
│  └─────────┘                └───────────┘                   │
│                                   │                          │
│                                   ↓                          │
│                            ┌───────────┐                    │
│                            │ Enterprise │                    │
│                            │  Custom    │                    │
│                            └───────────┘                    │
└──────────────────────────────────────────────────────────────┘
```

### Cloud Tier Pricing (Connected to Platform)

| Cloud Plan | Records | Searches/mo | Price |
|------------|---------|-------------|-------|
| **Starter** | 50,000 | 100,000 | $29/mo |
| **Growth** | 250,000 | 500,000 | $79/mo |
| **Business** | 1,000,000 | 2,000,000 | $199/mo |
| **Enterprise** | Unlimited | Unlimited | Custom |

Cloud plans include:
- Managed infrastructure (no server management)
- Automatic scaling
- Full analytics dashboard
- 99.9% uptime SLA (Enterprise: 99.99%)
- Daily backups
- Multi-region deployment (Enterprise)

### Implementation Phases

#### Phase 1: Tiered Licensing (Current Plugin)

| Task | Description |
|------|-------------|
| License validation | Add license key system |
| Feature flags | Gate Pro features behind license |
| Lite limitations | Enforce 10K record limit |
| Plugin Store listing | Update for Lite (free) version |

#### Phase 2: Core Extraction

| Task | Description |
|------|-------------|
| Extract `search-manager-core` | Standalone Composer package |
| Refactor Craft plugin | Use core as dependency |
| Maintain backward compatibility | Existing installs keep working |

#### Phase 3: Cloud Backend

| Task | Description |
|------|-------------|
| Build Cloud API | REST endpoints for search operations |
| Add CloudBackend adapter | New backend option in plugin |
| Account management | API keys, usage tracking |
| Billing integration | Stripe for subscriptions |

### Revenue Model (Craft Plugin Only)

| Year | Lite Users | Pro Sales | Cloud MRR | Total |
|------|------------|-----------|-----------|-------|
| Y1 | 500 | 50 × $99 = $4,950 | 20 × $50 = $1,000/mo | $16,950 |
| Y2 | 2,000 | 150 × $99 = $14,850 | 80 × $60 = $4,800/mo | $72,450 |
| Y3 | 5,000 | 300 × $99 = $29,700 | 200 × $80 = $16,000/mo | $221,700 |

**Assumptions:**
- 10% of Lite users convert to Pro
- 4% of Lite users convert to Cloud
- Average cloud ARPU increases as users scale

### Comparison: Scout (Competitor)

| Aspect | Scout | Search Manager |
|--------|-------|----------------|
| Price | $99/yr | Free / $99/yr / $29mo+ |
| Backends | Algolia, Meilisearch, Typesense | 7 backends + Cloud |
| Self-hosted option | ❌ (needs external service) | ✅ Full |
| Analytics | ❌ Relies on backend | ✅ Built-in |
| Query rules | ❌ Relies on backend | ✅ Built-in |
| Arabic support | ❌ None | ✅ Native |
| Free tier | ❌ | ✅ Lite |

### Migration Strategy (Existing Users)

**For current users (pre-tiering):**

| Scenario | Action |
|----------|--------|
| Purchased before tiering | Grandfather to Pro for life |
| Using advanced features | Require Pro license at renewal |
| Under 10K records, basic features | Can stay on Lite |
| Want cloud features | Upgrade to Cloud subscription |

**Communication plan:**
1. Announce tiering 60 days before launch
2. Existing customers get automatic Pro upgrade
3. New pricing only applies to new purchases
4. No features removed from existing installs

---

## Product Roadmap

### Phase 1: MENA Enhancement (Q1-Q2)

**Arabic Language Improvements:**

| Feature | Description | Priority |
|---------|-------------|----------|
| Arabic Light Stemmer | Reduce words to roots while preserving meaning | Critical |
| Diacritics Normalization | Handle tashkeel (حَرَكَات) intelligently | Critical |
| Dialect Support | Gulf, Egyptian, Levantine variants | High |
| Arabic Character Substitutions | ة↔ه, ى↔ي fuzzy matching | High |
| Extended Stop Words | Expand from 122 to 300+ words | Medium |
| Arabic Synonyms Dictionary | Common synonyms pre-loaded | Medium |

**Infrastructure:**

| Feature | Description | Priority |
|---------|-------------|----------|
| RTL Admin Interface | Full right-to-left UI support | Critical |
| Arabic Documentation | Complete docs in Arabic | High |
| Docker Deployment | One-click local deployment | High |
| Health Monitoring | API endpoints for uptime monitoring | Medium |

### Phase 2: Standalone Extraction (Q2-Q3)

**Core Engine Extraction:**

```
search-manager-core/
├── src/
│   ├── Engine/
│   │   ├── SearchEngine.php
│   │   ├── BM25Scorer.php
│   │   ├── QueryParser.php
│   │   ├── FuzzyMatcher.php
│   │   └── Tokenizer.php
│   ├── Storage/
│   │   ├── StorageInterface.php
│   │   ├── MySqlStorage.php
│   │   ├── PostgreSqlStorage.php
│   │   ├── RedisStorage.php
│   │   └── FileStorage.php
│   ├── Language/
│   │   ├── StopWords.php
│   │   ├── Stemmer/
│   │   │   ├── ArabicStemmer.php
│   │   │   └── EnglishStemmer.php
│   │   └── Normalizer/
│   │       └── ArabicNormalizer.php
│   ├── Analytics/
│   │   └── AnalyticsEngine.php
│   └── Api/
│       └── SearchApi.php
├── composer.json
└── README.md
```

**Deliverables:**

| Deliverable | Description |
|-------------|-------------|
| `search-manager-core` | Standalone PHP package (Composer) |
| REST API Server | Docker-deployable, framework-agnostic |
| JavaScript SDK | Frontend autocomplete, InstantSearch alternative |
| API Documentation | OpenAPI/Swagger spec |

### Phase 3: Platform Expansion (Q3-Q4)

**CMS/Framework Integrations:**

| Platform | Priority | Market Share | Effort |
|----------|----------|--------------|--------|
| WordPress | High | 43% of web | Medium |
| Laravel | High | Popular in MENA | Low |
| Shopify | Medium | E-commerce focus | Medium |
| Magento | Medium | Enterprise e-commerce | Medium |
| Drupal | Low | Enterprise CMS | Medium |

**Advanced Features:**

| Feature | Description | Priority |
|---------|-------------|----------|
| Arabic Semantic Search | AraBERT/CAMeL embeddings | High |
| Voice Search | Arabic speech-to-text | Medium |
| AI Query Understanding | Intent classification | Medium |
| Federated Search | Multi-source aggregation | Low |
| Real-time Sync | Database change streams | Low |

### Phase 4: Global Expansion (Q4+)

- Multi-region cloud offering
- Additional language support (Turkish, Farsi, Urdu)
- Enterprise features (SSO, audit logs, SLA)
- Marketplace (plugins, integrations)

---

## Go-to-Market Strategy

### MENA Launch Plan

#### Pre-Launch (Month 1-2)

| Activity | Owner | Timeline |
|----------|-------|----------|
| Legal entity setup (UAE or Saudi) | Business | Month 1 |
| Arabic website/landing page | Marketing | Month 1 |
| Cloud partnerships (STC/Oracle) | Business | Month 1-2 |
| Beta customer recruitment (5) | Sales | Month 1-2 |
| Arabic documentation | Product | Month 2 |

#### Launch (Month 3)

| Activity | Owner | Timeline |
|----------|-------|----------|
| Public launch announcement | Marketing | Week 1 |
| Case study publication | Marketing | Week 2 |
| PR outreach (Arabic tech media) | Marketing | Week 2-4 |
| Webinar: "Search & Data Sovereignty" | Sales | Week 3 |

#### Post-Launch (Month 4-6)

| Activity | Owner | Timeline |
|----------|-------|----------|
| Conference presence (GITEX/LEAP) | Marketing | As scheduled |
| Partner program launch | Business | Month 4 |
| Government certification | Business | Month 4-5 |
| Enterprise sales push | Sales | Ongoing |

### Partnership Strategy

#### Technology Partners

| Partner Type | Examples | Value |
|--------------|----------|-------|
| **Cloud Providers** | STC Cloud, Oracle Riyadh, Azure Jeddah | Infrastructure, co-marketing |
| **E-commerce Platforms** | Salla, Zid | Pre-installation, referrals |
| **System Integrators** | Robusta Studio, Above Limits | Implementation, reach |

#### Channel Partners

| Partner Type | Commission | Requirements |
|--------------|------------|--------------|
| **Resellers** | 20-30% | Certified, minimum revenue |
| **Referral Partners** | 10-15% | Registered, qualified leads |
| **Technology Partners** | Revenue share | Integration maintained |

### Marketing Channels

| Channel | Strategy | Budget Allocation |
|---------|----------|-------------------|
| **Content (Arabic)** | SEO for "محرك بحث", technical blogs | 25% |
| **Events** | GITEX Dubai, LEAP Riyadh, local meetups | 30% |
| **Paid Ads** | LinkedIn (enterprise), Google (SME) | 20% |
| **PR** | Arabic tech media, case studies | 15% |
| **Community** | Developer relations, open source | 10% |

### Sales Model

| Segment | Model | Team |
|---------|-------|------|
| **SME** | Self-serve + inside sales | 1 AE |
| **Mid-Market** | Inside sales + demo | 1-2 AEs |
| **Enterprise** | Field sales + solutions | 1 AE + 1 SE |
| **Government** | Direct + partner | Founder + partner |

---

## Revenue Model

### Pricing Strategy

#### MENA Pricing (Launch)

| Tier | Target | Records | Price (Annual) |
|------|--------|---------|----------------|
| **Starter** | SME, startups | 100K | $499/yr ($42/mo) |
| **Business** | Mid-market | 1M | $1,999/yr ($167/mo) |
| **Professional** | Growth companies | 5M | $4,999/yr ($417/mo) |
| **Enterprise** | Large orgs | Unlimited | Custom ($15K-60K/yr) |

**Comparison to Algolia:**
- Starter: 70% cheaper than Algolia Grow
- Business: 60% cheaper than Algolia Premium entry
- Enterprise: 50% cheaper with more features included

#### Global Pricing (Later)

| Tier | Records | Price (Annual) |
|------|---------|----------------|
| **Developer** | 10K | Free |
| **Starter** | 100K | $588/yr ($49/mo) |
| **Business** | 1M | $2,388/yr ($199/mo) |
| **Professional** | 5M | $5,988/yr ($499/mo) |
| **Enterprise** | Unlimited | Custom |

### Revenue Projections

#### MENA Only (Conservative)

| Year | Customers | ARR | Notes |
|------|-----------|-----|-------|
| Y1 | 50 | $150K | Focus on pilot customers |
| Y2 | 200 | $600K | Channel partnerships active |
| Y3 | 500 | $1.5M | Government contracts landing |
| Y4 | 1,000 | $3M | Regional market leader |
| Y5 | 2,000 | $6M | Expansion to adjacent markets |

#### Global (Optimistic)

| Year | MENA | Global | Total ARR |
|------|------|--------|-----------|
| Y1 | $150K | $50K | $200K |
| Y2 | $600K | $400K | $1M |
| Y3 | $1.5M | $1.5M | $3M |
| Y4 | $3M | $4M | $7M |
| Y5 | $6M | $9M | $15M |

### Unit Economics

| Metric | Target |
|--------|--------|
| **CAC (SME)** | $500 |
| **CAC (Enterprise)** | $5,000 |
| **LTV:CAC** | >3:1 |
| **Gross Margin** | >80% |
| **Net Revenue Retention** | >110% |
| **Churn (Annual)** | <10% |

---

## Technical Architecture

### Current Architecture (Craft Plugin)

```
┌─────────────────────────────────────────────────────────┐
│                     Craft CMS                           │
├─────────────────────────────────────────────────────────┤
│                  Search Manager Plugin                  │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Services Layer                                  │   │
│  │  ├── BackendService                             │   │
│  │  ├── IndexingService                            │   │
│  │  ├── AnalyticsService                           │   │
│  │  └── TransformerService                         │   │
│  └─────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Search Engine                                   │   │
│  │  ├── QueryParser                                │   │
│  │  ├── BM25Scorer                                 │   │
│  │  ├── FuzzyMatcher                               │   │
│  │  └── Highlighter                                │   │
│  └─────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Backends                                        │   │
│  │  ├── MySQL    ├── PostgreSQL  ├── Redis         │   │
│  │  ├── File     ├── Algolia     ├── Meilisearch   │   │
│  │  └── Typesense                                  │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### Target Architecture (Standalone Platform)

```
┌─────────────────────────────────────────────────────────┐
│                 Search Manager Platform                  │
├─────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │
│  │  REST API   │  │  Admin UI   │  │  Dashboard  │     │
│  │  Server     │  │  (React)    │  │  (Analytics)│     │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘     │
│         │                │                │             │
│  ┌──────┴────────────────┴────────────────┴──────┐     │
│  │              Core Engine (PHP)                 │     │
│  │  ┌─────────────────────────────────────────┐  │     │
│  │  │  Search    Query     BM25      Fuzzy    │  │     │
│  │  │  Engine    Parser    Scorer    Matcher  │  │     │
│  │  └─────────────────────────────────────────┘  │     │
│  │  ┌─────────────────────────────────────────┐  │     │
│  │  │  Arabic NLP Module                      │  │     │
│  │  │  ├── Stemmer    ├── Normalizer          │  │     │
│  │  │  ├── Tokenizer  └── Stop Words          │  │     │
│  │  └─────────────────────────────────────────┘  │     │
│  │  ┌─────────────────────────────────────────┐  │     │
│  │  │  Analytics Engine                       │  │     │
│  │  └─────────────────────────────────────────┘  │     │
│  └───────────────────────────────────────────────┘     │
│                          │                              │
│  ┌───────────────────────┴───────────────────────┐     │
│  │              Storage Adapters                  │     │
│  │  ┌───────┐ ┌───────┐ ┌───────┐ ┌───────┐     │     │
│  │  │ MySQL │ │ Postgres│ │ Redis │ │ File  │     │     │
│  │  └───────┘ └───────┘ └───────┘ └───────┘     │     │
│  │  ┌───────┐ ┌───────┐ ┌───────┐               │     │
│  │  │Algolia│ │Meili  │ │Typesense│              │     │
│  │  └───────┘ └───────┘ └───────┘               │     │
│  └───────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │  Craft  │    │WordPress│    │ Laravel │
    │ Plugin  │    │ Plugin  │    │ Package │
    └─────────┘    └─────────┘    └─────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │   JS    │    │ Python  │    │  PHP    │
    │   SDK   │    │   SDK   │    │   SDK   │
    └─────────┘    └─────────┘    └─────────┘
```

### API Design

**Base URL:** `https://api.searchmanager.io/v1`

**Endpoints:**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/indices` | Create index |
| DELETE | `/indices/{name}` | Delete index |
| POST | `/indices/{name}/documents` | Index documents |
| DELETE | `/indices/{name}/documents/{id}` | Delete document |
| GET | `/indices/{name}/search` | Search |
| GET | `/indices/{name}/autocomplete` | Autocomplete |
| GET | `/analytics/searches` | Search analytics |
| GET | `/analytics/gaps` | Content gaps |

**Search Request:**
```json
{
  "query": "محرك بحث",
  "filters": {
    "category": "technology",
    "language": "ar"
  },
  "sort": ["_score:desc", "date:desc"],
  "limit": 20,
  "offset": 0,
  "highlight": true,
  "facets": ["category", "author"]
}
```

**Search Response:**
```json
{
  "hits": [...],
  "total": 150,
  "took_ms": 12,
  "facets": {
    "category": {"technology": 50, "business": 30}
  },
  "query_rules_applied": ["synonym_expansion"],
  "promotions_applied": 1
}
```

### Deployment Options

| Option | Use Case | Infrastructure |
|--------|----------|----------------|
| **Docker** | Self-hosted | Customer infrastructure |
| **Kubernetes** | Enterprise self-hosted | Customer K8s cluster |
| **Managed Cloud** | SaaS customers | Our infrastructure |
| **On-Premise** | Government/Finance | Air-gapped deployment |

---

## Action Items

### Immediate (Next 30 Days)

| # | Task | Owner | Priority |
|---|------|-------|----------|
| 1 | Implement Arabic light stemmer | Dev | Critical |
| 2 | Add diacritics normalization | Dev | Critical |
| 3 | Build RTL admin interface | Dev | Critical |
| 4 | Create Arabic landing page | Marketing | High |
| 5 | Draft partnership proposals | Business | High |
| 6 | Research UAE/Saudi entity setup | Business | High |

### Short-term (60 Days)

| # | Task | Owner | Priority |
|---|------|-------|----------|
| 7 | Complete Arabic dialect support | Dev | High |
| 8 | Docker deployment setup | Dev | High |
| 9 | Write Arabic documentation | Content | High |
| 10 | Recruit 5 beta customers | Sales | High |
| 11 | Finalize pricing strategy | Business | Medium |
| 12 | Begin STC Cloud partnership talks | Business | Medium |

### Medium-term (90 Days)

| # | Task | Owner | Priority |
|---|------|-------|----------|
| 13 | Extract core engine to standalone package | Dev | High |
| 14 | Build REST API server | Dev | High |
| 15 | Create JavaScript SDK | Dev | Medium |
| 16 | Launch beta program | Product | High |
| 17 | Publish first case study | Marketing | Medium |
| 18 | Apply for government certification | Business | Medium |

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Algolia enters MENA with local data center | Medium | High | First-mover advantage, deeper Arabic support |
| Regulatory changes | Low | Medium | Stay connected with CST/NCA |
| Slow enterprise sales cycles | High | Medium | Strong SME self-serve funnel |
| Technical debt from extraction | Medium | Medium | Clean architecture, comprehensive tests |
| Currency fluctuations | Medium | Low | Multi-currency pricing |
| Competition from Meilisearch | Medium | Medium | Superior Arabic NLP, analytics |

---

## Success Metrics

### Year 1 KPIs

| Metric | Target |
|--------|--------|
| ARR | $150K |
| Paying Customers | 50 |
| Enterprise Customers | 5 |
| NPS | >40 |
| Churn Rate | <10% |
| Support Response Time | <4 hours |

### Year 2 KPIs

| Metric | Target |
|--------|--------|
| ARR | $600K |
| Paying Customers | 200 |
| Enterprise Customers | 20 |
| NPS | >50 |
| Net Revenue Retention | >110% |
| Partner-sourced Revenue | >30% |

---

## Appendix

### A. Arabic NLP Resources

- **CAMeL Tools**: Open-source Arabic NLP toolkit
- **AraBERT**: Arabic BERT for semantic search
- **MADAMIRA**: Morphological analyzer
- **AlKhalil**: Stemmer and lemmatizer (Apache 2.0)

### B. MENA Cloud Providers

| Provider | Location | Certifications |
|----------|----------|----------------|
| STC Cloud | Riyadh | CST, NCA |
| Oracle Cloud | Riyadh | CST, NCA |
| Microsoft Azure | Jeddah | CST, NCA |
| AWS | Bahrain | Regional |
| Google Cloud | Doha | Regional |

### C. Regulatory References

- Saudi PDPL: [SDAIA Website](https://sdaia.gov.sa)
- KSA Cloud First Policy: [MCIT](https://www.mcit.gov.sa)
- UAE PDPL: Federal Decree-Law No. 45 of 2021
- CST Regulations: [CST Website](https://www.cst.gov.sa)

### D. Competitor Links

- Algolia: https://www.algolia.com
- Meilisearch: https://www.meilisearch.com
- Typesense: https://typesense.org
- Elasticsearch: https://www.elastic.co

---

## Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2025-12-29 | LindemannRock | Initial draft |
| 1.1 | 2025-12-29 | LindemannRock | Added Craft CMS Plugin Strategy (Lite/Pro/Cloud tiers) |

---

*This document is confidential and intended for internal use only.*
