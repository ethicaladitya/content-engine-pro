# Content Engine Pro — Deals Extension: Master Implementation Plan

## 1. Executive Summary

This document specifies the complete architecture for a **Deals Publishing Extension** to the existing `content-engine-pro` WordPress plugin (v1.2.0). The extension transforms global deal/coupon feeds into premium, SEO-optimized content at scale — targeting Google Search rankings, Google Discover, and AI Overviews.

### Production Environment (as inspected 2026-05-20)
- **WordPress:** 6.9.4 / **PHP:** 8.5.6 / **Host:** WPMU DEV
- **AI Provider:** Azure OpenAI (gpt-4o + gpt-4o-mini)
- **Published content:** 82 articles, 345 jobs, 3 reviews
- **Raw content queue:** 116 pending, 56 published, 841 below_threshold
- **Trending topics:** 153 queued, 4 duplicate
- **Active sources:** 16 RSS feeds
- **Cron jobs:** 11 active (crawl×3, articles, reviews×3, jobs, trending, SEO, log prune)
- **Niche:** WordPress (configurable via NicheManager presets)
- **SEO Plugin:** SmartCrawl / RankMath compatible (Publisher::write_seo_meta supports 6 plugins)

### What the Extension Must Do
1. **Ingest** deals from RSS feeds, affiliate APIs, coupon aggregators, and manual entry
2. **Score & Deduplicate** incoming deals using signal scoring + hash-based dedup
3. **Generate** premium editorial-quality deal articles via AI (not thin affiliate spam)
4. **Optimize** for SERP CTR, Google Discover, AI Overviews, and rich snippets
5. **Publish** with full schema markup (Offer, Product, AggregateOffer), affiliate links, and internal linking
6. **Refresh** deals automatically — expire old, update prices, republish seasonal content
7. **Scale** to 50-100 deals/day without hitting API limits or degrading site performance

---

## 2. Current Plugin Architecture Analysis

### 2.1 Architecture Strengths

| Strength | Evidence |
|----------|----------|
| **Clean modular design** | 14 namespaced modules under `ContentEnginePro\` with PSR-4 autoloading |
| **Settings-driven behavior** | Everything toggleable via `Settings::is_enabled()` — 80+ settings with filter hooks |
| **Niche-agnostic** | `NicheManager` supports 13 vertical presets with custom override capability |
| **Proven AI pipeline** | `AiClient` → `WebResearcher` → `ArticleAutopilot` pipeline handles research+generation+publishing |
| **Multi-provider AI** | OpenAI + Azure OpenAI with exponential backoff retry (5s/15s/30s) |
| **Robust deduplication** | SHA-256 content hash + URL dedup + slug dedup + title similarity (60% threshold) + focus keyword dedup |
| **Category-balanced publishing** | `pick_category_balanced_items()` ensures even coverage across categories |
| **Atomic job claiming** | `UPDATE ... WHERE status='pending'` prevents concurrent processing conflicts |
| **SEO plugin agnostic** | Writes meta to Yoast, RankMath, SmartCrawl, AIOSEO, SEOPress, and The SEO Framework |
| **Full lifecycle management** | Crawl → Score → Queue → Research → Generate → Publish → SEO Audit → Auto-fix |
| **Hook-rich extensibility** | `do_action('cep_before_publish')`, `apply_filters('cep_publish_prompt')`, etc. throughout |
| **DB-backed logging** | 134K log entries with level/context filtering and auto-pruning |

### 2.2 Architecture Weaknesses & Technical Debt

| Weakness | Impact | Deals Extension Implication |
|----------|--------|---------------------------|
| **No queue abstraction** | Raw SQL everywhere, no job queue interface | Deals needs a proper queue with priority, retry, TTL |
| **Monolithic autopilot classes** | `ArticleAutopilot` is 1021 lines — prompt, publish, image, linking all in one class | Deals module must be properly decomposed |
| **No rate limiting** | Only `sleep(2)` between AI calls, no token budget tracking | At 50-100 deals/day, will blow through API budgets |
| **No content refresh system** | Content is publish-and-forget — no mechanism to update stale articles | Deals REQUIRE expiry/refresh — prices change, deals end |
| **Sync conflict files present** | `.sync-conflict-*` files in reviews module | Git workflow needs attention before adding modules |
| **No caching layer** | Every `Settings::get()` does `get_option()` (cached by WP object cache, but no transient strategy for expensive queries) | Deal price lookups need caching |
| **SEO Agent tables missing in production** | `cep_seo_issues` and `cep_seo_runs` tables not created yet | DB migration system works but needs verification |
| **No provider abstraction for feeds** | Crawler handles RSS/HTML only — no structured API provider interface | Deals needs pluggable feed providers |
| **134K log entries** | Log table is large — pruning runs but may need optimization | Add log rotation or archive strategy |
| **No content versioning** | No way to track what changed when content is refreshed | Deals refresh needs before/after tracking |

### 2.3 Extension Points Available

The plugin provides these hooks the deals module can leverage:

```
# Actions (fire-and-forget)
cep_loaded                          → Register deals module
cep_modules_booted                  → Initialize deals components
cep_before_publish / cep_after_publish → Inject deal-specific meta
cep_after_crawl_window              → Trigger deal ingestion after crawl
cep_source_crawled                  → React to new source data
cep_register_rest_routes            → Add deals REST endpoints

# Filters (modify data)
cep_setting_defaults                → Add deals-specific settings
cep_register_niches                 → Add deals niche presets
cep_signal_score                    → Custom scoring for deal content
cep_post_insert_args                → Modify post creation args
cep_publish_prompt                  → Override AI prompts
cep_schema_data                     → Inject Offer/Product schema
cep_rest_format_post                → Format deal posts for API
cep_active_niche                    → Override niche for deals
```

### 2.4 Database Schema (Current — 10 Tables)

```
wp_cep_logs              → 134,650 rows (auto-pruned at 90 days)
wp_cep_sources           → 16 active feeds
wp_cep_raw_content       → 1,027 rows (116 pending, 841 below_threshold)
wp_cep_reviews           → 3 rows
wp_cep_product_discovery → product queue for review autopilot
wp_cep_jobs_raw          → job dedup tracking
wp_cep_clicks            → 0 rows (affiliate click tracking)
wp_cep_trending_topics   → 159 rows (153 queued)
wp_cep_seo_issues        → NOT YET CREATED in production
wp_cep_seo_runs          → NOT YET CREATED in production
```
# 3. System Design — Deals Extension Architecture

## 3.1 Module Breakdown

The deals extension adds **one new top-level module** (`modules/deals/`) with internal service decomposition:

```
modules/deals/
├── class-deal-manager.php           # Orchestrator — coordinates all deal operations
├── class-deal-ingester.php          # Feed ingestion (RSS, API, coupon feeds)
├── class-deal-scorer.php            # Deal-specific signal scoring
├── class-deal-deduplicator.php      # Multi-layer dedup (URL, title, product, merchant)
├── class-deal-generator.php         # AI content generation for deal articles
├── class-deal-publisher.php         # WordPress post creation + meta + schema
├── class-deal-refresher.php         # Expiry detection, price updates, content refresh
├── class-deal-scheduler.php         # Cron scheduling for deal pipeline
├── class-deal-schema.php            # Offer/Product/AggregateOffer JSON-LD
├── class-deal-image-handler.php     # Featured image sourcing (merchant OG → Pexels → AI)
│
├── providers/                       # Pluggable feed provider interface
│   ├── interface-deal-provider.php  # Contract: fetch_deals(): DealItem[]
│   ├── class-rss-provider.php       # RSS/Atom feed ingestion
│   ├── class-api-provider.php       # Generic REST API ingestion
│   ├── class-coupon-provider.php    # Coupon aggregator feeds
│   └── class-manual-provider.php    # Admin manual entry
│
└── admin/
    ├── class-deals-settings.php     # Settings tab for deals configuration
    └── class-deals-admin-page.php   # Admin dashboard for deal management
```

**Autoloader registration** — Add to `class-autoloader.php`:
```php
'Deals\\'  => 'modules/deals/',
```

## 3.2 Data Flow Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        INGESTION LAYER                              │
│                                                                     │
│  RSS Feeds ──┐                                                      │
│  API Feeds ──┼──→ DealIngester ──→ DealScorer ──→ DealDeduplicator │
│  Coupon APIs ┤                         │                │           │
│  Manual ─────┘                         │                │           │
│                                        ▼                ▼           │
│                               wp_cep_deals_raw (status: pending)    │
└─────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       PROCESSING LAYER                              │
│                                                                     │
│  DealGenerator                                                      │
│   ├── WebResearcher::research_urls() ← reuse existing module        │
│   ├── AiClient::complete() ← reuse existing AI pipeline             │
│   ├── prompt_architecture (deal-specific prompts)                   │
│   └── post_generation_validation (price accuracy, deal validity)    │
│                                        │                            │
│                                        ▼                            │
│  DealPublisher                                                      │
│   ├── wp_insert_post() with deal CPT or regular post                │
│   ├── write_seo_meta() ← reuse existing Publisher method            │
│   ├── DealSchema::inject() → Offer + Product JSON-LD               │
│   ├── DealImageHandler::assign() → featured image                   │
│   ├── InternalLinker → cross-link related deals                     │
│   └── AffiliateManager → inject affiliate tracking links            │
└─────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       LIFECYCLE LAYER                               │
│                                                                     │
│  DealRefresher (cron: twice daily)                                  │
│   ├── Check deal expiry dates → unpublish expired deals             │
│   ├── Re-fetch merchant pages → detect price changes                │
│   ├── AI-regenerate stale content (deals > 7 days old)              │
│   ├── Seasonal deal republishing (Black Friday, Prime Day, etc.)    │
│   └── Update schema markup with current prices                      │
│                                                                     │
│  SeoAgent (existing) → scans deal posts for SEO issues              │
│  Logger (existing) → logs all deal operations                       │
└─────────────────────────────────────────────────────────────────────┘
```

## 3.3 New Database Tables

### `wp_cep_deals_raw` — Ingested deal queue

```sql
CREATE TABLE {prefix}cep_deals_raw (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,     -- FK to cep_sources or 0 for API/manual
    provider_type   VARCHAR(30) NOT NULL DEFAULT 'rss',      -- rss, api, coupon, manual
    content_hash    CHAR(64) NOT NULL,                       -- SHA-256 for dedup
    
    -- Deal data
    title           VARCHAR(500) NOT NULL DEFAULT '',
    merchant_name   VARCHAR(255) NOT NULL DEFAULT '',
    merchant_url    VARCHAR(500) NOT NULL DEFAULT '',
    deal_url        VARCHAR(500) NOT NULL DEFAULT '',         -- Direct link to the deal
    affiliate_url   VARCHAR(500) NOT NULL DEFAULT '',         -- Affiliate-tracked URL
    
    original_price  DECIMAL(10,2) NULL,
    sale_price      DECIMAL(10,2) NULL,
    discount_pct    TINYINT UNSIGNED NULL,                    -- 0-100
    currency        CHAR(3) NOT NULL DEFAULT 'USD',
    coupon_code     VARCHAR(100) NOT NULL DEFAULT '',
    
    category        VARCHAR(60) NOT NULL DEFAULT 'general',
    product_name    VARCHAR(255) NOT NULL DEFAULT '',
    product_brand   VARCHAR(100) NOT NULL DEFAULT '',
    product_image   VARCHAR(500) NOT NULL DEFAULT '',
    
    deal_starts_at  DATETIME NULL,
    deal_expires_at DATETIME NULL,
    
    description     TEXT NULL,
    raw_data        LONGTEXT NULL,                           -- Full JSON from provider
    
    -- Processing state
    score           DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending','processing','published','duplicate',
                         'rejected','expired','below_threshold') 
                    NOT NULL DEFAULT 'pending',
    wp_post_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    
    -- Metadata
    region          VARCHAR(10) NOT NULL DEFAULT 'US',
    language        VARCHAR(10) NOT NULL DEFAULT 'en',
    
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    published_at    DATETIME NULL,
    
    UNIQUE KEY idx_hash (content_hash),
    KEY idx_status (status),
    KEY idx_merchant (merchant_name(100)),
    KEY idx_expires (deal_expires_at),
    KEY idx_score_status (score, status),
    KEY idx_category (category),
    KEY idx_region (region)
) {charset};
```

### `wp_cep_deal_sources` — Deal-specific feed registry

```sql
CREATE TABLE {prefix}cep_deal_sources (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    provider_type   ENUM('rss','api','coupon','manual') NOT NULL DEFAULT 'rss',
    feed_url        VARCHAR(500) NOT NULL,
    
    -- API-specific fields
    api_key         VARCHAR(255) NOT NULL DEFAULT '',
    api_config      LONGTEXT NULL,                           -- JSON config for provider
    
    -- Targeting
    category        VARCHAR(60) NOT NULL DEFAULT 'general',
    region          VARCHAR(10) NOT NULL DEFAULT 'US',
    language        VARCHAR(10) NOT NULL DEFAULT 'en',
    merchant_name   VARCHAR(255) NOT NULL DEFAULT '',
    
    -- Health tracking
    crawl_interval  VARCHAR(20) NOT NULL DEFAULT 'twicedaily', -- hourly, twicedaily, daily
    last_crawled_at DATETIME NULL,
    last_success_at DATETIME NULL,
    consecutive_fails INT NOT NULL DEFAULT 0,
    reliability_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_feed_url (feed_url(191)),
    KEY idx_active_interval (is_active, crawl_interval),
    KEY idx_provider (provider_type)
) {charset};
```

### `wp_cep_deal_refresh_log` — Content refresh audit trail

```sql
CREATE TABLE {prefix}cep_deal_refresh_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deal_raw_id     BIGINT UNSIGNED NOT NULL,
    wp_post_id      BIGINT UNSIGNED NOT NULL,
    refresh_type    ENUM('price_update','expiry','content_refresh',
                         'seasonal_republish','manual') NOT NULL,
    old_price       DECIMAL(10,2) NULL,
    new_price       DECIMAL(10,2) NULL,
    changes_made    TEXT NULL,                                -- JSON diff summary
    refreshed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_deal (deal_raw_id),
    KEY idx_post (wp_post_id),
    KEY idx_refreshed (refreshed_at)
) {charset};
```

## 3.4 Settings Architecture

New settings to register via `cep_setting_defaults` filter:

```php
// ── Deals Extension ──────────────────────────────────────────
'deals_enabled'                => '0',          // Master toggle
'deals_autopilot_enabled'      => '1',          // Auto-publish or queue for review
'deals_max_per_run'            => '10',         // Deals to process per cron run
'deals_min_discount_pct'       => '15',         // Minimum discount % to qualify
'deals_default_currency'       => 'USD',
'deals_default_region'         => 'US',
'deals_expire_after_days'      => '30',         // Auto-expire deals after N days
'deals_refresh_interval'       => 'twicedaily', // How often to check for updates
'deals_use_dedicated_cpt'      => '0',          // 0 = use primary post type, 1 = dedicated 'deal' CPT
'deals_cpt_slug'               => 'deal',
'deals_cpt_singular'           => 'Deal',
'deals_cpt_plural'             => 'Deals',
'deals_cpt_archive_slug'       => 'deals',
'deals_tax_slug'               => 'deal-category',
'deals_ai_temperature'         => '0.65',
'deals_featured_image_source'  => 'merchant_first',  // merchant_first, pexels_first, ai_generate
'deals_affiliate_disclosure'   => 'This post contains affiliate links. We may earn a commission at no extra cost to you.',
'deals_enable_price_tracking'  => '1',
'deals_enable_schema'          => '1',
'deals_enable_faq'             => '1',
'deals_content_min_words'      => '600',
'deals_title_style'            => 'editorial',   // editorial, deal_focused, comparison
```

## 3.5 Cron Architecture

New scheduled events to add in `DealScheduler`:

| Hook | Recurrence | Offset | Purpose |
|------|-----------|--------|---------|
| `cep_deals_ingest` | `twicedaily` | 6h | Fetch deals from all active sources |
| `cep_deals_generate` | `daily` | 10h | Process pending deals → AI articles |
| `cep_deals_refresh` | `twicedaily` | 14h | Check for price changes, expire old deals |
| `cep_deals_cleanup` | `weekly` | 3h | Prune expired raw deals, archive old refresh logs |

Integrates with existing `Scheduler::schedule_all()` pattern.

## 3.6 Provider Interface Design

```php
namespace ContentEnginePro\Deals\Providers;

interface DealProvider {
    /**
     * Unique identifier for this provider type.
     */
    public function get_type(): string;

    /**
     * Fetch deals from the configured source.
     *
     * @param array $source Row from wp_cep_deal_sources.
     * @return DealItem[] Array of normalized deal items.
     */
    public function fetch(array $source): array;

    /**
     * Validate that a source configuration is valid for this provider.
     */
    public function validate_config(array $source): bool;
}
```

```php
/**
 * Normalized deal item returned by all providers.
 */
class DealItem {
    public string $title;
    public string $merchant_name;
    public string $merchant_url;
    public string $deal_url;
    public string $affiliate_url;
    public ?float $original_price;
    public ?float $sale_price;
    public ?int   $discount_pct;
    public string $currency;
    public string $coupon_code;
    public string $category;
    public string $product_name;
    public string $product_brand;
    public string $product_image;
    public ?string $deal_starts_at;
    public ?string $deal_expires_at;
    public string $description;
    public string $region;
    public array  $raw_data;
}
```
# 4. AI Content Strategy & Prompt Architecture

## 4.1 Content Quality Philosophy

The #1 risk with deal content is **thin affiliate spam**. Google has explicitly penalized "deal roundup" sites that produce low-effort, AI-generated affiliate content. The deals extension must produce content that:

1. **Provides genuine buying analysis** — not just "X is 30% off, buy it now"
2. **Compares alternatives** — "Why this deal matters vs. Y and Z"
3. **Explains value context** — "This is the lowest price since Black Friday 2025"
4. **Demonstrates expertise** — specific technical details about the product
5. **Varies structurally** — no two deal articles should follow the same template

## 4.2 Article Type Templates

The AI should select from multiple article structures based on deal type:

### Type A: Single Deal Deep Dive (high-value deals, >$50 savings)
```
Hook → Why This Deal Matters → Product Deep Dive → Price History Context →
Who Should Buy This → Alternatives Worth Considering → The Bottom Line
```

### Type B: Category Roundup (multiple deals in same category)
```
Hook → Quick Picks Summary → Individual Deal Breakdowns (H2 per deal) →
How We Evaluated These → What to Watch For → Best Overall Value
```

### Type C: Flash/Time-Sensitive Deal (expires within 48h)
```
Quick Take (price + savings + link) → What Makes This Worth Rushing →
Key Specs → Is It Really a Good Deal? → Quick Verdict
```

### Type D: Comparison Deal ("X vs Y — Which Sale Is Better?")
```
The TL;DR → Side-by-Side Breakdown → Feature Comparison Table →
Price History for Both → Who Should Pick Which → Our Recommendation
```

### Type E: Seasonal Roundup ("Best Memorial Day Tech Deals")
```
Overview + Event Context → Category Sections (H2) → Individual Picks (H3) →
Price Tracking Notes → Deals We're Skipping → What to Expect Next
```

## 4.3 Anti-AI-Footprint Strategy

### Forbidden Patterns (enforced in prompt + post-generation validation)
```
- "In today's fast-paced world..."
- "Whether you're looking for..."
- "It's worth noting that..."
- "Don't miss out on..."
- "Look no further!"
- "This deal is a steal"
- "For a limited time only"
- "Hurry before it's gone"
- Any phrase that appears in >5% of AI-generated deal content online
```

### Humanization Techniques
1. **Injected personality markers** — opinions, preferences, caveats ("We'd skip this if you already have...")
2. **Specific comparisons** — "That's $47 less than Amazon's current price and $12 below the 90-day average"
3. **Hedge language variety** — rotate between "our testing shows", "based on user reviews", "according to price trackers"
4. **Structural randomization** — AI selects from templates; no fixed section order
5. **Contextual hooks** — tie deals to current events, seasons, product cycles

### Post-Generation Validation Pipeline
```
1. AI generates deal article
2. Validate: word count ≥ 600
3. Validate: focus keyword appears in title + first paragraph + ≥2 H2s
4. Validate: no forbidden phrases (regex scan)
5. Validate: price data matches source (if available)
6. Validate: coupon code format is plausible
7. Validate: no hallucinated product specs (cross-reference research data)
8. Validate: content uniqueness score (compare against last 50 published deals)
9. If any validation fails → log warning, flag for human review
```

## 4.4 Prompt Architecture

### System Prompt (deal-specific)
```
You are a senior deals editor at "{brand}" — a trusted publication that helps readers 
find genuinely good deals, not just any sale. You have strong opinions about value. 
You know the difference between a real deal and manufactured urgency. You write with 
authority because you actually research products. You never sound like an AI and you 
never sound like a desperate affiliate marketer. Your readers trust you because you 
tell them when to skip a deal, not just when to buy.
```

### User Prompt Template (see DealGenerator::build_prompt)
The prompt includes:
- Deal data (merchant, prices, discount, coupon, expiry)
- Research data (from WebResearcher)
- Article type selection instruction
- Category-specific tone guide
- SEO requirements (focus keyword, title formula, meta description)
- Forbidden phrases list
- Affiliate partner context
- Output JSON schema

### Title Formulas (CTR-optimized, non-spammy)
```
Single Deal:
  "{Product} Drops to ${sale_price} ({discount}% Off) — Is It Worth It?"
  "The {Product} Deal Everyone's Talking About — {discount}% Off Right Now"
  "We Tested {Product} at Full Price — At ${sale_price}, It's a No-Brainer"

Roundup:
  "{N} {Category} Deals Worth Your Money This {Month/Event}"
  "The Only {Category} Deals Actually Worth Buying in {Month} {Year}"

Comparison:
  "{Product A} vs {Product B}: Which {Event} Sale Is Actually Better?"

Flash:
  "{Product} Just Hit Its Lowest Price Ever — Here's What to Know"
```

## 4.5 SEO Strategy

### Schema Markup (DealSchema class)
Every deal article gets **three** schema types:

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "...",
      "author": { "@type": "Person", "name": "..." },
      "datePublished": "...",
      "dateModified": "..."
    },
    {
      "@type": "Product",
      "name": "Product Name",
      "brand": { "@type": "Brand", "name": "..." },
      "offers": {
        "@type": "Offer",
        "price": "49.99",
        "priceCurrency": "USD",
        "availability": "https://schema.org/InStock",
        "url": "...",
        "priceValidUntil": "2026-06-30",
        "seller": { "@type": "Organization", "name": "Merchant" }
      }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        { "@type": "Question", "name": "...", "acceptedAnswer": { "@type": "Answer", "text": "..." } }
      ]
    }
  ]
}
```

### Google Discover Optimization
1. **High-quality featured images** — minimum 1200px wide, compelling visuals
2. **Timely content** — deals inherently qualify as "timely" content
3. **Strong E-E-A-T signals** — author bylines, editorial disclaimers, methodology notes
4. **Engagement-optimized titles** — curiosity-driven, not clickbait
5. **Web Stories potential** — future expansion opportunity

### AI Overview Optimization
1. **FAQ sections** — structured Q&A that AI can cite
2. **Comparison tables** — structured data AI assistants can parse
3. **Direct answers** — "Is this deal worth it?" answered clearly in first 100 words
4. **Price context** — historical price data helps AI summarize deal value

### Internal Linking Strategy
1. **Cross-link deal articles** with related product reviews (from existing review CPT)
2. **Category hub pages** — deals link to category archives
3. **Temporal linking** — "See also: Best deals from last month"
4. **Product linking** — deals for same product link to each other

## 4.6 EEAT Strategy for Deal Content

| Signal | Implementation |
|--------|---------------|
| **Experience** | "We've been tracking {product} prices since {date}" — AI references research data timestamps |
| **Expertise** | Technical product details from WebResearcher data, not generic descriptions |
| **Authority** | Consistent brand voice, editorial standards, affiliate disclosures |
| **Trust** | Price verification notes, "deals we're skipping" sections, honest recommendations |
# 5. Phased Implementation Roadmap

## Phase 1: Foundation (Week 1) — Infrastructure & Ingestion

### Objective
Establish the deals module skeleton, database tables, settings, autoloader registration, and basic RSS ingestion. By end of Phase 1, deals can be ingested from RSS feeds into the raw queue.

### Tasks

#### 1.1 Module Skeleton
- [ ] Create `modules/deals/` directory structure (all files listed in §3.1)
- [ ] Add `'Deals\\'  => 'modules/deals/'` to `class-autoloader.php` `$ns_map`
- [ ] Create `DealManager` class with `register()` method, hooked to `cep_modules_booted`
- [ ] Register deals settings defaults via `cep_setting_defaults` filter
- [ ] Add deals feature toggle to `class-plugin.php` → `boot_modules()`

#### 1.2 Database Migration
- [ ] Add `wp_cep_deals_raw`, `wp_cep_deal_sources`, `wp_cep_deal_refresh_log` table creation to `class-activator.php`
- [ ] Bump `CEP_DB_VERSION` to trigger auto-migration
- [ ] Test table creation on production via `maybe_create_tables()`

#### 1.3 Provider Interface & RSS Provider
- [ ] Create `DealProvider` interface (`providers/interface-deal-provider.php`)
- [ ] Create `DealItem` value object class
- [ ] Implement `RssProvider` — adapts existing `Crawler::parse_feed()` logic but extracts deal-specific fields (price from title regex, merchant from domain, coupon from content)
- [ ] Create `DealIngester` class that:
  - Iterates `wp_cep_deal_sources` where `is_active=1`
  - Instantiates correct provider by `provider_type`
  - Calls `provider->fetch()` → normalizes → scores → deduplicates → inserts to `wp_cep_deals_raw`

#### 1.4 Deal Scoring
- [ ] Create `DealScorer` class with signals:
  - Discount percentage (higher = better score)
  - Merchant reputation (configurable tier list)
  - Deal freshness (newer = higher score)
  - Category relevance (matches niche keywords)
  - Price threshold (deals above min value)
  - Coupon code presence (bonus points)
- [ ] Register `cep_signal_score` filter for deal-type content

#### 1.5 Deduplication
- [ ] Create `DealDeduplicator` class with layers:
  - SHA-256 hash of `deal_url + product_name + merchant_name`
  - URL-based dedup against existing `wp_cep_deals_raw`
  - Title similarity check (60% threshold, reuse ArticleAutopilot pattern)
  - Merchant + product combo dedup (same product, same merchant = same deal)

#### 1.6 Cron Registration
- [ ] Create `DealScheduler` class following existing `Scheduler` pattern
- [ ] Register `cep_deals_ingest` hook (twicedaily)
- [ ] Wire into `Plugin::schedule_crons()`

### Dependencies
- None (builds on existing infrastructure)

### Estimated Complexity
- **Medium** — mostly boilerplate following existing patterns

### Risks
- RSS feeds may not contain structured price/discount data → mitigate with regex extraction + AI fallback
- Database migration on production → mitigated by existing `maybe_create_tables()` mechanism

### Testing
- [ ] Verify table creation on local + production
- [ ] Test RSS provider with 3-5 known deal RSS feeds
- [ ] Verify dedup catches same deal from different sources
- [ ] Verify cron scheduling doesn't conflict with existing hooks

---

## Phase 2: AI Generation Pipeline (Week 2) — Content Creation

### Objective
Build the AI content generation pipeline that transforms raw deal data into publishable articles. By end of Phase 2, deals can be auto-generated into draft posts.

### Tasks

#### 2.1 Deal Generator
- [ ] Create `DealGenerator` class with:
  - `generate(array $deal_raw): array` — returns article data (title, content, excerpt, SEO meta)
  - Article type selection logic (single deal vs roundup vs flash based on deal attributes)
  - Deal-specific system prompt (§4.4)
  - Category-aware tone mapping
  - Forbidden phrases enforcement
  - Post-generation validation pipeline (§4.3)
- [ ] Build deal-specific prompt template with:
  - Deal data injection (prices, merchant, discount, coupon)
  - Research data from `WebResearcher::research_urls()` on merchant page
  - Title formula library (§4.4)
  - SEO requirements block
  - JSON output schema
- [ ] Implement content uniqueness check — compare against last 50 published deals using title word overlap

#### 2.2 Deal Publisher
- [ ] Create `DealPublisher` class following existing `Publisher::publish_from_raw()` pattern:
  - Creates post (deals CPT or regular post based on setting)
  - Assigns taxonomy (deal-category)
  - Saves deal-specific post meta:
    - `_cep_deal_original_price`, `_cep_deal_sale_price`, `_cep_deal_discount_pct`
    - `_cep_deal_merchant`, `_cep_deal_url`, `_cep_deal_coupon`
    - `_cep_deal_expires_at`, `_cep_deal_region`, `_cep_deal_currency`
  - Writes SEO meta via existing `Publisher::write_seo_meta()` pattern
  - Affiliate link injection via existing `AffiliateManager`
  - Internal link injection via existing `ArticleAutopilot::inject_internal_links()` pattern
- [ ] Add affiliate disclosure block insertion (configurable position: top, bottom, or both)

#### 2.3 Image Handling
- [ ] Create `DealImageHandler` class:
  - Priority 1: Extract OG image from merchant URL (reuse `extract_source_og_image()`)
  - Priority 2: Extract product image from deal data
  - Priority 3: Pexels search by product name (reuse `fetch_pexels_image()`)
  - Priority 4: Default category placeholder image
  - Sideload to WordPress media library with proper alt text

#### 2.4 Deal Autopilot Orchestrator
- [ ] Create `DealManager::run_autopilot()`:
  - Called by `cep_deals_generate` cron hook
  - Picks top N pending deals from `wp_cep_deals_raw` (ordered by score DESC)
  - Atomic claim pattern (reuse `ArticleAutopilot` approach)
  - Rate limiting: `sleep(3)` between AI calls + configurable max per run
  - Error handling: failed items go back to pending (up to 3 retries, then rejected)
  - Logging throughout

### Dependencies
- Phase 1 (database + ingestion must work)

### Estimated Complexity
- **High** — core AI pipeline, most complex phase

### Risks
- AI may hallucinate prices → mitigate with post-generation validation against source data
- Token costs at scale → mitigate with gpt-4o-mini for initial research extraction, gpt-4o for final content
- Rate limiting on Azure → already handled by AiClient retry logic

### Testing
- [ ] Generate 5 test deal articles from manually inserted raw deals
- [ ] Validate SEO meta written correctly for RankMath/SmartCrawl
- [ ] Verify no forbidden phrases in output
- [ ] Verify word count ≥ 600
- [ ] Test with expired deal (should not publish)
- [ ] Test dedup (same deal from 2 sources should only generate 1 article)

---

## Phase 3: Schema & SEO (Week 3) — Rich Results & Discovery

### Objective
Add Offer/Product/FAQPage schema, optimize for Google Discover, and implement deal-specific SEO enhancements.

### Tasks

#### 3.1 Schema Markup
- [ ] Create `DealSchema` class:
  - `build_offer_schema(int $post_id): array` — Product + Offer JSON-LD
  - `build_faq_schema(int $post_id): array` — FAQPage from AI-generated Q&A
  - Hook into `cep_schema_data` filter to inject deal schemas alongside existing Review schema
- [ ] Add `@graph` wrapper to support multiple schema types per page
- [ ] Schema validation: test output against Google Rich Results Test API

#### 3.2 FAQ Generation
- [ ] Add FAQ generation to `DealGenerator` prompt:
  - 3-5 Q&A pairs per deal article
  - Stored as post meta: `_cep_deal_faqs` (JSON array)
  - Injected into post content as collapsible accordion (frontend)
  - Used in FAQPage schema
- [ ] Questions should target actual search queries:
  - "Is {product} worth buying at {sale_price}?"
  - "How long does this {merchant} deal last?"
  - "Is there a coupon code for {product}?"

#### 3.3 Google Discover Optimization
- [ ] Enforce minimum image dimensions (1200×675) for featured images
- [ ] Add `max-image-preview:large` robots meta tag for deal posts
- [ ] Implement article freshness signals (dateModified updates on price changes)
- [ ] Add WebStory-compatible structured data (future-proofing)

#### 3.4 Deal-Specific SEO Enhancements
- [ ] URL structure: `/{deals_archive_slug}/{product-name}-deal/` or `/{primary_archive}/{deal-slug}/`
- [ ] Breadcrumb schema injection
- [ ] Price drop structured data (PriceSpecification with validThrough)

### Dependencies
- Phase 2 (articles must be publishable)

### Estimated Complexity
- **Medium** — schema is well-documented, FAQ generation reuses AI pipeline

### Testing
- [ ] Validate all schema output via Google Rich Results Test
- [ ] Verify FAQ accordion renders correctly on frontend
- [ ] Test schema with SmartCrawl's schema analyzer
- [ ] Verify no duplicate schema conflicts with SEO plugin output

---

## Phase 4: Lifecycle Management (Week 4) — Refresh, Expiry, Analytics

### Objective
Implement deal lifecycle: automatic expiry, price update detection, content refresh for stale deals, and basic analytics.

### Tasks

#### 4.1 Deal Refresher
- [ ] Create `DealRefresher` class (cron: `cep_deals_refresh`, twicedaily):
  - **Expiry check**: Query deals where `deal_expires_at < NOW()` → change post status to 'draft' or 'private', add expired notice
  - **Price monitoring**: Re-fetch merchant page → extract current price → if changed >5%, update post meta + schema + log to `wp_cep_deal_refresh_log`
  - **Content staleness**: Deals published >7 days ago with no price change → flag for potential content refresh
  - **Seasonal republishing**: Configurable event calendar (Black Friday, Prime Day, etc.) → resurface relevant archived deals

#### 4.2 Price Change Detection
- [ ] Implement `DealRefresher::check_price(int $post_id)`:
  - Fetch current price from merchant URL (WebResearcher + regex extraction)
  - Compare against stored `_cep_deal_sale_price`
  - If price increased → add "Price has changed" notice to article
  - If price decreased further → update article with "Price just dropped again!"
  - Log all changes to `wp_cep_deal_refresh_log`

#### 4.3 Content Refresh
- [ ] Implement `DealRefresher::refresh_content(int $post_id)`:
  - Re-run AI generation with updated data
  - Update `dateModified` in schema
  - Preserve original URL slug
  - Store refresh diff in log table
  - Limit: max 1 content refresh per deal per week

#### 4.4 Deal Analytics (Basic)
- [ ] Extend existing `wp_cep_clicks` table for deal-specific tracking
- [ ] Track: deal views (post meta counter), outbound clicks (via ClickTracker), conversion hints
- [ ] Add deals stats to REST API `/cep/v1/stats`
- [ ] Add deals section to admin dashboard page

#### 4.5 Admin Interface
- [ ] Add "Deals" admin page (`admin/class-deals-admin-page.php`):
  - Deal sources management (CRUD for `wp_cep_deal_sources`)
  - Deal queue view (pending, processing, published, expired)
  - Manual deal entry form
  - Bulk actions (approve, reject, refresh, expire)
  - Stats overview (deals today, active deals, expired, clicks)
- [ ] Add deals settings tab to existing settings page (`admin/class-deals-settings.php`)
- [ ] Add deal-specific meta boxes for the post editor

### Dependencies
- Phase 3 (schema must exist to update)

### Estimated Complexity
- **Medium-High** — price detection is fragile, admin UI is significant effort

### Testing
- [ ] Test expiry: create deal with past date → verify auto-draft
- [ ] Test price change: mock merchant page with different price → verify update
- [ ] Test content refresh: verify AI regeneration preserves slug
- [ ] Test admin CRUD operations for deal sources
- [ ] Test manual deal entry flow

---

## Phase 5: Advanced Features (Week 5-6) — Scale, Providers, Polish

### Objective
Add API/coupon providers, regional targeting, content comparison tables, and production hardening.

### Tasks

#### 5.1 Additional Providers
- [ ] `ApiProvider` — generic REST API connector with configurable field mapping
- [ ] `CouponProvider` — specialized for coupon aggregator feeds (RetailMeNot, Coupons.com patterns)
- [ ] Provider auto-discovery: admin can paste a URL, system detects if RSS/API/coupon

#### 5.2 Regional Targeting
- [ ] Multi-region support: deals tagged by region, content localized
- [ ] Currency conversion display (optional)
- [ ] Region-specific deal sources

#### 5.3 Comparison Tables
- [ ] Auto-generate HTML comparison tables for roundup articles
- [ ] Sortable by price, discount, rating
- [ ] Mobile-responsive design

#### 5.4 REST API Extensions
- [ ] `GET /cep/v1/deals` — list published deals with filtering
- [ ] `GET /cep/v1/deals/{id}` — single deal with full schema data
- [ ] `POST /cep/v1/deals/ingest` — admin-only: trigger manual ingestion
- [ ] `POST /cep/v1/deals/refresh/{id}` — admin-only: trigger single deal refresh

#### 5.5 WP-CLI Commands
- [ ] `wp cep deals ingest [--source=<id>]` — manual ingestion
- [ ] `wp cep deals generate [--limit=<n>]` — manual generation
- [ ] `wp cep deals refresh [--post=<id>]` — manual refresh
- [ ] `wp cep deals expire` — force expiry check
- [ ] `wp cep deals stats` — pipeline statistics

#### 5.6 Production Hardening
- [ ] API token budget tracking (monthly usage counter with configurable limits)
- [ ] Circuit breaker for failing providers (auto-disable after N consecutive failures)
- [ ] Rate limiting for outbound requests (max N requests/minute to any single domain)
- [ ] Graceful degradation: if AI unavailable, queue deals for later rather than failing silently

### Dependencies
- Phase 4 (full lifecycle must work)

### Estimated Complexity
- **High** — multiple provider integrations, production hardening

---

# 6. Security & QA Strategy

## 6.1 Security Checklist

| Area | Requirement |
|------|-------------|
| **Input sanitization** | All user input via `sanitize_text_field()`, `sanitize_textarea_field()`, `esc_url_raw()` |
| **Output escaping** | All HTML output via `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` |
| **SQL injection** | All queries via `$wpdb->prepare()` — no raw string interpolation |
| **Nonce verification** | All admin form submissions verified with `wp_verify_nonce()` |
| **Capability checks** | All admin actions gated by `current_user_can('manage_options')` |
| **API authentication** | Admin-only REST endpoints use `permission_callback => check_admin` |
| **Affiliate URL encryption** | Continue using AES-256-CBC via existing `cep_encrypt_affiliate_url()` |
| **API key storage** | Stored in `wp_options` (encrypted at rest via WP constants) |
| **Rate limiting** | Provider-level rate limiting to prevent abuse |
| **XSS prevention** | Deal content sanitized before storage, escaped before display |

## 6.2 Testing Matrix

| Test Type | Scope | Method |
|-----------|-------|--------|
| Unit | Provider parsing | Mock RSS/API responses, verify DealItem normalization |
| Unit | Scoring | Fixed inputs → expected scores |
| Unit | Deduplication | Same deal via different sources → single entry |
| Integration | Full pipeline | RSS → ingest → score → generate → publish |
| Integration | Refresh cycle | Published deal → price change → content update |
| Integration | Expiry | Deal with past date → auto-drafted |
| Schema | JSON-LD validation | Google Rich Results Test API |
| SEO | Meta output | Verify correct SEO plugin meta keys written |
| Performance | Cron execution | 100 deals in queue → all processed within timeout |
| Security | Input fuzzing | Malformed RSS/API data → no errors, graceful skip |
| Compatibility | WP versions | Test on WP 6.7, 6.8, 6.9 |

## 6.3 Performance Considerations

- **Cron timeout**: WP-Cron has a 30s default timeout. Deal generation uses `sleep(3)` between items. At 10 deals/run, that's 30s of sleep + AI time. Consider using **WP Background Processing** library or splitting into smaller batches.
- **Database growth**: `wp_cep_deals_raw` will grow quickly. Add cleanup job to archive deals older than 90 days to prevent table bloat.
- **API costs**: At 10 deals/day × 30 days = 300 AI calls/month. At ~2000 tokens/call on gpt-4o = ~600K tokens/month ≈ $3-5/month. Scale linearly.
- **Image sideloading**: Each deal fetches 1-2 images. At 10/day = 300 images/month. Monitor disk usage and implement media cleanup for expired deals.

---

# 7. Exact Next Steps for Sonnet

## First PR: Phase 1 Foundation

Sonnet should implement in this exact order:

1. **Create directory structure**: `modules/deals/` with all files as empty PHP class stubs
2. **Update autoloader**: Add `Deals` namespace mapping
3. **Create database tables**: Add 3 new tables to `Activator::create_tables()`
4. **Register settings**: Add deals settings to `Settings::$defaults` via filter
5. **Create `DealItem` value object**: Simple class with typed properties
6. **Create `DealProvider` interface**: Single `fetch()` method contract
7. **Implement `RssProvider`**: Adapt existing `Crawler::parse_feed()` for deals
8. **Implement `DealScorer`**: Multi-signal scoring with filterable weights
9. **Implement `DealDeduplicator`**: Hash + URL + title similarity layers
10. **Implement `DealIngester`**: Orchestrates provider → score → dedup → insert
11. **Create `DealScheduler`**: Register cron hooks
12. **Wire into `Plugin::boot_modules()` and `Plugin::schedule_crons()`**
13. **Test**: Insert 3 test deal sources, run ingestion, verify `wp_cep_deals_raw` populated

## Key Code Patterns to Follow

- **Singleton for DealManager** (matches Plugin, AiClient pattern)
- **Static methods for pipeline steps** (matches ArticleAutopilot, Crawler pattern)
- **`apply_filters()` on all configurable values** (matches Settings, NicheManager pattern)
- **`do_action()` before/after each pipeline step** (matches existing hooks)
- **`Logger::log()` for all operations** (matches all existing modules)
- **`Settings::is_enabled()` guards** on every entry point (matches all modules)
- **Atomic status transitions** via `UPDATE WHERE status='pending'` (matches ArticleAutopilot)
- **`$wpdb->prepare()` for all queries** (matches all existing code)

## File-by-File Creation Guide for Sonnet

```
# Create in this order — each file can be tested independently

1. modules/deals/providers/interface-deal-provider.php  (10 lines)
2. modules/deals/class-deal-item.php                     (30 lines — value object)
3. modules/deals/class-deal-scorer.php                   (80 lines)
4. modules/deals/class-deal-deduplicator.php             (60 lines)
5. modules/deals/providers/class-rss-provider.php        (120 lines)
6. modules/deals/class-deal-ingester.php                 (100 lines)
7. modules/deals/class-deal-scheduler.php                (40 lines)
8. modules/deals/class-deal-manager.php                  (80 lines — orchestrator)

# Phase 2 (after Phase 1 verified):
9. modules/deals/class-deal-generator.php                (250 lines — AI prompts)
10. modules/deals/class-deal-publisher.php               (150 lines)
11. modules/deals/class-deal-image-handler.php           (80 lines)
12. modules/deals/class-deal-schema.php                  (120 lines)

# Phase 3-4:
13. modules/deals/class-deal-refresher.php               (150 lines)
14. modules/deals/admin/class-deals-settings.php         (100 lines)
15. modules/deals/admin/class-deals-admin-page.php       (200 lines)
```
