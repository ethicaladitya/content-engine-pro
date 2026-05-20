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
