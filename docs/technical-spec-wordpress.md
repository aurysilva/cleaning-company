# Technical Specification — WordPress Website

**Prepared:** 14 October 2026
**Related documents:** [`website-and-seo-plan.md`](./website-and-seo-plan.md), [`hull-east-yorkshire-cleaning-services-report.md`](./hull-east-yorkshire-cleaning-services-report.md)
**Brand placeholder:** `[Brand]`, with code prefix `cc_` (rename once the brand is chosen)

---

## 1. Stack

| Layer | Choice | Notes |
|-------|--------|-------|
| CMS | WordPress (latest) | PHP 8.2+ |
| Theme / builder | **Bricks** + **Bricks child theme** | All custom code lives in the child theme |
| Custom content | **Secure Custom Fields (SCF)** | Post types, taxonomies, field groups, options page. Field API is ACF-compatible, so Bricks reads it as ACF |
| Styling | **SCSS → CSS** compiled into the child theme, plus Bricks global classes and variables | Optional: Automatic.css or Core Framework instead of hand-written tokens (decide before building) |
| Images | Real photoshoot; **Unsplash for placeholders only** | Self-hosted WebP/AVIF, never hotlinked |
| SEO | **Rank Math Pro** | Titles, sitemaps, redirects, base schema; custom schema added from SCF data |
| Forms | Bricks Forms (simple) or **WS Form Pro** (calculators, conditional logic) | |
| Quote & booking | **BookingKoala / Launch27 / ZenMaid** embedded | Handles pricing, scheduling, payments, recurring cleans and review requests |
| Hosting | Managed WordPress (e.g. Kinsta / WP Engine / Rocket.net) + **Cloudflare** | Staging + production |

> **Compatibility check (do first):** Bricks integrates with ACF. SCF is the ACF fork and uses the same functions (`get_field()`, repeaters, options pages), so Bricks dynamic data should work unchanged. Build a quick test page early to confirm that repeaters, relationship fields and options-page fields all render in Bricks query loops and dynamic tags.

---

## 2. Content Model (Secure Custom Fields)

Post types and taxonomies are registered **in code** (`inc/post-types.php`). Field groups live as **SCF Local JSON** in `acf-json/` (see §7), so they can still be edited in the SCF admin UI and every change is saved as a JSON file in Git.

### 2.1 Post types

| Post type | Slug | URL | Purpose |
|-----------|------|-----|---------|
| `cc_service` | `services` | `/services/end-of-tenancy-cleaning/` | Core service pages |
| `cc_area` | `areas` | `/areas/beverley/` | Town / area pages |
| `cc_local_service` | — (custom rewrite) | `/services/end-of-tenancy-cleaning/hull/` | Service × town pages (see §4) |
| `cc_team` | `team` | `/our-team/jane-smith/` (or no single page) | Cleaner / staff profiles |
| `cc_review` | not public | — | Curated testimonials, linked to services and areas |
| `cc_case_study` | `case-studies` | `/case-studies/6-bed-hmo-turnover-hu5/` | Job write-ups with before/after |
| `post` (built-in) | `guides` | `/guides/end-of-tenancy-cleaning-cost-hull/` | SEO guides / blog |
| `page` (built-in) | — | `/about/`, `/pricing/`, etc. | Static pages |

> **URL change from the SEO plan:** the plan suggested root-level URLs like `/end-of-tenancy-cleaning-hull/`. Nesting them as `/services/end-of-tenancy-cleaning/hull/` is cleaner, builds a clear hierarchy, and is equally good for SEO. Use this instead.

### 2.2 Taxonomies

| Taxonomy | Applies to | Terms |
|----------|-----------|-------|
| `cc_service_group` | `cc_service` | Home, Moving & Property, Landlords & Hosts, Business, Exterior |
| `cc_customer_type` | `cc_service`, `cc_case_study`, `cc_review` | Homeowner, Tenant, Landlord, Letting Agent, Holiday Host, Business |
| `category` (built-in) | `post` | Content clusters: End of Tenancy, Deep Cleaning, Oven & Kitchen, After Builders, Holiday Lets, Business Cleaning, Hiring a Cleaner |

### 2.3 Field group — Service (`cc_service`)

Use tabs to keep the editor tidy.

**Tab: Hero & summary**
| Field | Type | Notes |
|-------|------|-------|
| `hero_heading` | Text | Defaults to "[Service] in Hull & East Yorkshire" if empty |
| `hero_subheading` | Textarea | 1–2 sentences |
| `hero_image` | Image | Real job photo |
| `price_from` | Number | e.g. 150 |
| `price_unit` | Text | e.g. "per clean", "per visit", "per hour" |
| `typical_duration` | Text | e.g. "4–8 hours" |
| `short_description` | Textarea | Used on cards and the services hub |
| `icon` | Image (SVG) | Card icon |

**Tab: Content**
| Field | Type | Notes |
|-------|------|-------|
| `intro` | WYSIWYG | 200–300 words |
| `who_its_for` | Repeater → `label` (Text), `description` (Textarea) | |
| `checklist` | Repeater → `room` (Text), `tasks` (Textarea, one per line) | Room-by-room "what's included" |
| `not_included` | Repeater → `item` (Text) | |
| `local_section` | WYSIWYG | Hull neighbourhoods / property types |
| `body_extra` | WYSIWYG | Optional long-form content |

**Tab: Pricing**
| Field | Type | Notes |
|-------|------|-------|
| `price_table` | Repeater → `property_size` (Text), `price` (Number), `note` (Text) | Studio → 5-bed |
| `addons` | Repeater → `name` (Text), `price` (Number), `description` (Text) | Oven, carpets, inside windows… |
| `price_disclaimer` | Textarea | |

**Tab: Trust & proof**
| Field | Type | Notes |
|-------|------|-------|
| `use_global_guarantee` | True/False | Default true |
| `guarantee_override` | WYSIWYG | Shown if above is false |
| `before_after` | Repeater → `before` (Image), `after` (Image), `caption` (Text), `area` (Post Object → `cc_area`) | |
| `reviews` | Relationship → `cc_review` | |
| `case_studies` | Relationship → `cc_case_study` | |

**Tab: FAQs & links**
| Field | Type | Notes |
|-------|------|-------|
| `faqs` | Repeater → `question` (Text), `answer` (WYSIWYG) | 8–12 per service |
| `related_services` | Relationship → `cc_service` | |
| `related_guides` | Relationship → `post` | |

**Tab: Booking & schema**
| Field | Type | Notes |
|-------|------|-------|
| `booking_url` | URL | Deep link into the booking software for this service |
| `schema_service_type` | Text | e.g. "End of tenancy cleaning" |

### 2.4 Field group — Area (`cc_area`)

| Field | Type | Notes |
|-------|------|-------|
| `hero_heading` | Text | "Cleaners in Beverley" |
| `hero_image` | Image | Local photo |
| `postcodes` | Text | "HU17" |
| `intro` | WYSIWYG | 300–500 words, unique |
| `neighbourhoods` | Repeater → `name` (Text) | Villages / districts |
| `local_notes` | WYSIWYG | Property types, popular services, local context |
| `popular_services` | Relationship → `cc_service` | |
| `team_members` | Relationship → `cc_team` | "Your Beverley team" |
| `reviews` | Relationship → `cc_review` | |
| `latitude` / `longitude` | Number | For map + schema `areaServed` |
| `map_embed` | Textarea / oEmbed | Google Maps embed (or Google Map field with API key) |
| `faqs` | Repeater → `question`, `answer` | Area-specific |

### 2.5 Field group — Local Service (`cc_local_service`)

Each page must have **genuinely unique content**. Never just swap the town name.

| Field | Type | Notes |
|-------|------|-------|
| `service` | Post Object → `cc_service` | Required |
| `area` | Post Object → `cc_area` | Required |
| `hero_heading` | Text | "End of Tenancy Cleaning in Hull" |
| `local_intro` | WYSIWYG | 400–800 unique words |
| `local_price_note` | Textarea | e.g. local job averages |
| `before_after` | Repeater (same as Service) | Local jobs only |
| `reviews` | Relationship → `cc_review` | Filtered to this service + area |
| `case_study` | Post Object → `cc_case_study` | |
| `faqs` | Repeater | Local FAQs |

Shared content (checklist, price table, add-ons) is pulled from the parent `service` so it's only maintained in one place.

### 2.6 Field group — Team Member (`cc_team`)

`photo` (Image), `role` (Text), `bio` (Textarea), `areas` (Relationship → `cc_area`), `services` (Relationship → `cc_service`), `start_year` (Number), `dbs_checked` (True/False), `fun_fact` (Text).

### 2.7 Field group — Review (`cc_review`)

`reviewer_name` (Text), `rating` (Number 1–5), `review_text` (Textarea), `review_date` (Date), `source` (Select: Google, Trustpilot, Facebook, Checkatrade, Direct), `source_url` (URL), `service` (Post Object → `cc_service`), `area` (Post Object → `cc_area`), `featured` (True/False).

> The live Google review feed comes from a widget (e.g. Trustindex). `cc_review` holds **curated** reviews so the right ones appear on each service and area page.

### 2.8 Field group — Case Study (`cc_case_study`)

`summary` (Textarea), `service` (Post Object), `area` (Post Object), `property_type` (Text), `challenge` (WYSIWYG), `solution` (WYSIWYG), `result` (WYSIWYG), `before_after` (Repeater), `duration` (Text), `review` (Post Object → `cc_review`).

---

## 3. Business Settings (Global Options Page)

**Use an SCF Options Page, not the WordPress Customizer.** Bricks reads options-page fields directly as dynamic data, whereas Customizer values would need custom PHP functions and Bricks `{echo:...}` allow-listing. This page is the **single source of truth** for business details, so the name, address and phone number (NAP) are identical everywhere: header, footer, contact page, schema.

- **Menu:** `Business Settings` (top level, dashicon `dashicons-building`)
- If your SCF version does not show "Options Pages" in the UI, register it in code with `acf_add_options_page()` (see §6.4).

| Tab | Fields |
|-----|--------|
| **Company** | `brand_name`, `legal_name`, `company_number`, `vat_number`, `logo` (Image), `logo_white` (Image), `founded_year` |
| **Contact & location** | `phone` (raw, e.g. +441482000000), `phone_display`, `whatsapp`, `email`, `address_street`, `address_locality` (Hull), `address_region` (East Riding of Yorkshire), `postcode`, `latitude`, `longitude`, `google_business_url`, `google_maps_embed` |
| **Opening hours** | Repeater → `days` (Checkbox Mon–Sun), `opens` (Time), `closes` (Time) |
| **Trust** | `google_rating` (Number), `review_count` (Number), `insurance_amount` (Text, e.g. "£10m"), `accreditations` (Repeater → `name`, `logo`, `url`), `trust_points` (Repeater → `text`) |
| **Guarantee** | `guarantee_title`, `guarantee_short` (Textarea), `guarantee_full` (WYSIWYG), `reclean_window_hours` (Number, e.g. 72) |
| **Booking** | `quote_url`, `booking_url`, `booking_embed` (Textarea, embed code), `cta_primary_label`, `cta_secondary_label` |
| **Social** | Repeater → `network` (Select), `url` |
| **Announcement bar** | `announcement_enabled` (True/False), `announcement_text`, `announcement_link` |
| **Service areas** | `areas_served` (Relationship → `cc_area`), used by schema and the footer |

---

## 4. URLs & Rewrites

| Content | URL pattern |
|---------|-------------|
| Services hub | `/services/` (Bricks page or `cc_service` archive) |
| Service | `/services/{service}/` |
| Local service | `/services/{service}/{area}/` |
| Areas hub | `/areas/` |
| Area | `/areas/{area}/` |
| Guides | `/guides/{post}/` (set via Permalinks: `/guides/%postname%/`) |
| Case studies | `/case-studies/{case-study}/` |

**Local service rewrite approach** (in `inc/rewrites.php`):

1. Register `cc_local_service` with `'rewrite' => false`.
2. On save, automatically set its slug to `{service-slug}--{area-slug}` from the two Post Object fields.
3. Add a rewrite rule (priority `top`, so it beats WordPress's attachment rules under `/services/`):
   `^services/([^/]+)/([^/]+)/?$` → `index.php?post_type=cc_local_service&name=$matches[1]--$matches[2]`
4. Filter `post_type_link` so `get_permalink()` returns `/services/{service}/{area}/`.
5. Flush permalinks once after deploying (Settings → Permalinks → Save).

---

## 5. Bricks Build

### 5.1 Templates

| Template | Type | Condition |
|----------|------|-----------|
| Header | Header | Entire website |
| Footer | Footer | Entire website |
| Single — Service | Single | Post type `cc_service` |
| Single — Local Service | Single | Post type `cc_local_service` |
| Single — Area | Single | Post type `cc_area` |
| Single — Case Study | Single | Post type `cc_case_study` |
| Single — Team | Single | Post type `cc_team` (optional) |
| Single — Guide | Single | Post type `post` |
| Archive — Guides | Archive | Blog / categories |
| Search results | Search | |
| 404 | Error | |

Static pages (Home, Services hub, Areas hub, About, Pricing, Landlords, Business, Guarantee, Reviews, FAQ, Careers, Contact) are built as **Bricks pages**. They use query loops for services, areas, reviews and team members.

### 5.2 Reusable components (Bricks Components)

Build each one once and reuse it everywhere:

- **Trust bar:** rating, review count, insurance, DBS, guarantee (from Business Settings)
- **CTA band:** heading + quote / call buttons (from Business Settings)
- **Service card:** icon, title, short description, "from £", link
- **Price table:** loops the `price_table` repeater
- **Checklist:** loops the `checklist` repeater (room → tasks)
- **Add-ons list**
- **Before/after slider**
- **Review card / carousel**
- **FAQ accordion:** loops the `faqs` repeater
- **How it works:** 3 steps
- **Areas list / map**
- **Team card**
- **Breadcrumbs** (Rank Math breadcrumbs)

### 5.3 Dynamic data

- Post fields: Bricks ACF dynamic tags (field picker → ACF / SCF fields).
- Options page fields: Bricks dynamic data → ACF options fields.
- Repeaters: Bricks **query loop** on the repeater field.
- Relationships: Bricks query loop on the relationship field.
- Any custom PHP output (e.g. formatted opening hours) uses `{echo:cc_function_name}`. Allow-list the function names with the `bricks/code/echo_function_names` filter.

### 5.4 Bricks settings

- **CSS loading method:** External files
- Disable unused features (e.g. emojis, embeds) via Bricks settings / performance plugin
- Load Google Fonts **locally** (no external font requests)
- Use Bricks **global classes** and **CSS variables** for all styling. Avoid per-element inline styles.
- Export templates and components to `/bricks-templates/` in Git as a backup (they live in the database).

---

## 6. Child Theme

### 6.1 Folder structure

```
wp-content/themes/bricks-child/
├── style.css                    # Theme header only
├── functions.php                # Loads /inc files
├── inc/
│   ├── setup.php                # Theme supports, image sizes, enqueue
│   ├── post-types.php           # CPTs + taxonomies
│   ├── rewrites.php             # Local service URLs (§4)
│   ├── scf.php                  # Local JSON paths + Business Settings options page
│   ├── schema.php               # Service / Area JSON-LD (§8)
│   ├── bricks.php               # Echo allow-list, custom dynamic tags
│   ├── helpers.php              # cc_get_phone(), cc_opening_hours(), etc.
│   └── performance.php          # Dequeue unused assets, preload hero image
├── acf-json/                    # SCF Local JSON (auto-saved field groups, CPTs, taxonomies)
├── assets/
│   ├── scss/
│   │   ├── main.scss
│   │   ├── abstracts/
│   │   │   ├── _tokens.scss     # Colours, spacing, radii, shadows → CSS variables
│   │   │   ├── _breakpoints.scss
│   │   │   └── _mixins.scss
│   │   ├── base/
│   │   │   └── _typography.scss # Fluid type scale
│   │   ├── components/
│   │   │   ├── _buttons.scss
│   │   │   ├── _cards.scss
│   │   │   ├── _price-table.scss
│   │   │   ├── _checklist.scss
│   │   │   ├── _faq.scss
│   │   │   ├── _before-after.scss
│   │   │   └── _trust-bar.scss
│   │   ├── utilities/
│   │   │   └── _utilities.scss
│   │   └── vendors/
│   │       └── _booking-widget.scss   # Style overrides for embedded booking software
│   ├── css/
│   │   └── main.css             # Compiled output (enqueued)
│   ├── js/
│   │   └── main.js
│   └── images/                  # Icons / SVGs only (photos go in Media Library)
├── bricks-templates/            # Exported Bricks templates & components (JSON)
├── package.json                 # Sass build scripts
└── .gitignore
```

### 6.2 SCSS rules

- `_tokens.scss` outputs **CSS custom properties** on `:root` (e.g. `--cc-color-primary`, `--cc-space-m`). Bricks theme styles and global classes reference these variables, so there is one design system rather than two competing ones.
- Keep SCSS for: tokens, typography scale, complex components, third-party overrides.
- Don't restyle what Bricks already handles (layout, spacing on elements, basic typography settings).
- Build: `sass assets/scss/main.scss assets/css/main.css --style=compressed --source-map` (development with `--watch`).
- Enqueue `main.css` with `filemtime()` versioning so caches refresh automatically after each build.
- **If you choose Automatic.css or Core Framework,** let it own the tokens and utilities, and reduce SCSS to components and overrides only.

### 6.3 Echo allow-list (example)

```php
add_filter( 'bricks/code/echo_function_names', function () {
	return [
		'cc_opening_hours_today',
		'cc_phone_link',
		'cc_years_trading',
	];
} );
```

### 6.4 Options page fallback (example)

```php
add_action( 'acf/init', function () {
	if ( function_exists( 'acf_add_options_page' ) ) {
		acf_add_options_page( [
			'page_title' => 'Business Settings',
			'menu_slug'  => 'cc-business-settings',
			'icon_url'   => 'dashicons-building',
			'redirect'   => false,
		] );
	}
} );
```

---

## 7. Version Control & Workflow

- **This Git repo** holds: the child theme, SCF Local JSON (`acf-json/`), exported Bricks templates, SCSS, docs.
- **Not in Git:** WordPress core, third-party plugins, uploads, `wp-config.php`, the database.
- **SCF Local JSON:** with the `acf-json/` folder present in the theme, SCF saves every field group / post type / taxonomy change as JSON. On another environment, use **Sync** in the SCF admin to import changes.
- **Environments:**
  1. **Local** (LocalWP) for development
  2. **Staging** on the host (client review, plugin updates tested here first)
  3. **Production**
- **Deploy:** `scripts/deploy-theme.ps1` builds the SCSS and uploads the child theme to Hostinger over SSH (host alias `hostinger-ews` in `~/.ssh/config`). Move database content (Bricks pages, posts) staging → production with the host's push tool or WP Migrate.
- **Current setup:** the site is being built directly on the Hostinger temporary domain (`deeppink-woodcock-284086.hostingersite.com`) with "Discourage search engines" switched on. **Switch it off at launch** on the real domain.
- **Rule:** never edit field groups directly on production; make changes locally or on staging and sync.

---

## 8. SEO & Schema Implementation

### 8.1 Rank Math

- Titles & meta templates per post type, e.g.
  - Service: `%title% in Hull & East Yorkshire | From £%price_from% | [Brand]` (add a custom Rank Math variable for `price_from`)
  - Local service: `%title% | Deposit-Back Guarantee | [Brand]`
  - Area: `Cleaners in %title% | Domestic & Commercial | [Brand]`
- XML sitemaps for services, local services, areas, guides, case studies. **Exclude** `cc_review` and `cc_team` if they have no single pages.
- Breadcrumbs enabled (used in the Bricks templates).
- Local SEO module: business type **LocalBusiness** (schema.org has no dedicated house-cleaning type), with name, address, phone, hours and geo taken from the same details as Business Settings.

### 8.2 Custom JSON-LD (in `inc/schema.php`)

Hook into Rank Math's `rank_math/json_ld` filter and add JSON-LD built from SCF fields:

| Page | Schema |
|------|--------|
| Service | `Service`: `serviceType`, `provider` (the LocalBusiness), `areaServed` (list of areas), `offers` (`price_from`, `priceCurrency: GBP`) |
| Local service | `Service` with a single `areaServed` (City / Place with geo) |
| Area | `LocalBusiness` reference + `areaServed` |
| All pages with FAQs | `FAQPage` |
| Case study | `Article` |
| Guides | `Article` / `BlogPosting` with author `Person` |

**Notes:**
- Google currently shows FAQ rich results only for a small set of authoritative sites. The `FAQPage` markup is still worth adding for clarity, but don't expect FAQ snippets in search results.
- Don't add `AggregateRating` / `Review` markup about the business itself on your own site. Google treats these as self-serving and won't show stars. Reviews belong on Google Business Profile.

---

## 9. Plugins

Keep this list short. Every extra plugin costs speed and adds security risk.

| Purpose | Plugin |
|---------|--------|
| Custom fields / CPTs | Secure Custom Fields |
| SEO | Rank Math Pro |
| Forms | WS Form Pro (or Bricks Forms only) |
| Caching / performance | Host caching + **FlyingPress** or **WP Rocket** |
| Image optimisation | ShortPixel or Imagify (WebP/AVIF) |
| Reviews widget | Trustindex (Google reviews) |
| Email delivery | FluentSMTP (+ Postmark / Brevo) |
| Cookie consent (UK GDPR / PECR) | Complianz |
| Security | Host firewall + Wordfence or Solid Security |
| Backups | Host daily backups + off-site (e.g. BlogVault / UpdraftPlus to cloud storage) |
| Optional design system | Automatic.css or Core Framework |

Booking software (BookingKoala / Launch27 / ZenMaid) is embedded via their script or iframe. Use their WordPress plugin only if it adds something the embed doesn't.

---

## 10. Performance Targets

| Metric (mobile) | Target |
|-----------------|--------|
| LCP | < 2.0 s |
| CLS | < 0.05 |
| INP | < 200 ms |
| PageSpeed Insights (mobile) | 90+ |
| Total page weight (service page) | < 1 MB |

Tactics:
- Hero image: correctly sized, WebP/AVIF, `fetchpriority="high"`, preloaded; all other images lazy-loaded.
- Self-hosted fonts, max 2 families, `font-display: swap`.
- Load the booking widget only on interaction or on the booking page, not on every page.
- Defer third-party scripts (chat, analytics, reviews widget).
- Cloudflare CDN + full-page caching.

---

## 11. Images

- **Unsplash:** placeholders during build, plus neutral textures / backgrounds. Download, compress, convert to WebP, and credit where reasonable. Never hotlink.
- **Before launch, replace with real photography:** team, vans, uniforms, real before/after jobs, local landmarks for area pages.
- **File naming:** `end-of-tenancy-kitchen-hull-hu5.webp`
- **Alt text:** descriptive and natural, e.g. "Kitchen after end of tenancy clean in a Hull terrace"
- **Image sizes** registered in `inc/setup.php`: `cc-hero` (1920w), `cc-card` (640w), `cc-thumb` (320w), `cc-before-after` (1200w).

---

## 12. Security & Compliance

- Strong admin passwords + 2FA for all users; rename / limit login access.
- Automatic minor core updates; plugin updates tested on staging weekly.
- Daily off-site backups, 30-day retention.
- Cookie consent banner blocking analytics / marketing scripts until consent (UK GDPR + PECR).
- Privacy policy covering forms, booking software, call tracking and analytics.
- Form spam protection: honeypot + Cloudflare Turnstile (no reCAPTCHA cookie issues).

---

## 13. Build Order

1. **Environment:** LocalWP site, Bricks + child theme, SCF, Git repo connected; Local JSON working.
2. **Content model:** CPTs, taxonomies, field groups, Business Settings options page. Run the Bricks compatibility test (§1).
3. **Design system:** tokens, typography, buttons, global classes (or Automatic.css / Core Framework setup).
4. **Components:** trust bar, CTA band, cards, price table, checklist, FAQ, before/after, reviews.
5. **Templates:** header, footer, Service, Area, Local Service, Guide.
6. **Pages:** Home, Services hub, Areas hub, About, Pricing, Contact, Landlords, Business, Guarantee, Reviews, FAQ, Careers.
7. **Rewrites & schema:** local service URLs, JSON-LD, Rank Math configuration.
8. **Integrations:** booking software, forms, reviews widget, call tracking, GA4, Search Console.
9. **Content entry:** 8 launch services, 4 launch areas, 6 Hull local service pages, 8 guides.
10. **QA:** performance, accessibility (WCAG 2.2 AA), mobile, schema validation (Rich Results Test), broken links, redirects.
11. **Launch:** production deploy, sitemap submitted, Google Business Profile linked, monitoring on.
