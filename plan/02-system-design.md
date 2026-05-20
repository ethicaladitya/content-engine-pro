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
