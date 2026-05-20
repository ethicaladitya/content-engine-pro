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
