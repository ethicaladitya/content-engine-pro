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
