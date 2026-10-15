# Quote-to-Booking Automation — Plan & Specification

**Status:** Draft for review · **Date:** October 2026
**Replaces:** the "embed BookingKoala / Launch27 / ZenMaid" approach in `technical-spec-wordpress.md` (§ Forms / Quote & booking).

---

## 1. Goal

Take a prospect from "I need a clean" to "paid booking with a confirmed cleaner" with no manual work in the normal case. Staff only step in for exceptions: bespoke quotes, nobody accepting a job, complaints and refunds.

### Decisions already made

| Topic | Decision |
|---|---|
| Build approach | Custom build on the existing WordPress site, delivered in phases |
| Payment | **Full payment upfront** at booking, via Stripe |
| Cleaners | **Mix** of employed staff and self-employed cleaners |
| Public prices | Still hidden. Prices appear only on the customer's private quote page, which is never indexed |

---

## 2. The flow at a glance

```
Customer                         System                                   Cleaner / Admin
────────                         ──────                                   ───────────────
1. Fills quote form  ─────────►  Validates postcode, prices the job,
   (picks date + arrival slot)   checks slot capacity
                                 │
                                 ├─ Auto-quotable ─► Quote page shown instantly
                                 │                   + "Your quote is ready" email
                                 └─ Bespoke ───────► "We'll be in touch" + admin alert ──► Admin prices it,
                                                                                            system emails quote link
2. Opens quote page, ticks T&Cs,
   "Accept & pay"  ────────────►  Re-checks slot, holds it 30 min,
                                 opens Stripe Checkout
3. Pays on Stripe  ────────────►  Stripe webhook → booking CONFIRMED
                                 Emails: customer confirmation + receipt,
                                 admin "new booking"
                                 │
                                 └─ Dispatch ─► employed cleaner free? ─► assigned ──────────► Cleaner notified
                                               otherwise offer to eligible
                                               self-employed cleaners ─► first to accept ───► Cleaner + admin
                                               nobody in time? ─► escalate ──────────────────► Admin alert
4. Reminders, clean happens,     Completion → review request → re-clean
   review request                guarantee window (72 h)
```

**The key change from the original idea:** the customer can only choose a date and arrival slot that has a cleaner available. If the slot is still free at payment, it's covered. This avoids taking money for a booking nobody can staff.

---

## 3. Quote form

A multi-step form on `/contact/` (renamed **Get a quote**). It replaces the current Bricks form, which only emails the admin. One question group per step, with a progress bar, and a running price shown from step 3 onward.

### Step 1 — Service and postcode
- Service (the 12 services, plus "Something else", which goes to a bespoke quote)
- Postcode, checked against covered postcode districts (the same data the area finder uses). If we don't cover it, offer "Ask us anyway", which goes to a bespoke quote.

### Step 2 — Property details (questions depend on the service)

| Service | Questions |
|---|---|
| Regular / one-off | Bedrooms, bathrooms, property type, frequency (regular only), hours (one-off, 3-hour minimum) |
| Deep / end of tenancy / move-in | Bedrooms, bathrooms, furnished or unfurnished (end of tenancy), condition (good / average / poor / very poor) |
| Oven | Oven type (single / double / range / AGA), hob, extractor, microwave |
| Carpet & upholstery | Rooms, stairs and landing, sofas, armchairs, rugs, stain protector, pet treatment |
| Holiday let | Bedrooms, bathrooms, linen change, changeover frequency |
| After builders, communal, office, commercial | Always a **bespoke quote**. Collect the details plus optional photos |

All services also ask: parking (free / paid / permit), pets, access (home / key safe / key collection), and optional photos. Photos are required when the condition is "poor" or "very poor".

### Step 3 — Add-ons
Taken from each service's existing add-ons list in Secure Custom Fields, for example oven clean, inside fridge, inside windows and carpets.

### Step 4 — Date and arrival slot
- Calendar showing only bookable dates. The minimum notice is configurable, for example 48 hours.
- Arrival slots: **Morning (8–10am)**, **Midday (11am–1pm)** and **Afternoon (2–4pm)**. Each slot is greyed out when there isn't capacity for the job's length and team size.

### Step 5 — Your details
Name, email, mobile (needed for text reminders), full address, access notes and marketing consent (opt-in).

**Spam protection:** a hidden honeypot field, Cloudflare Turnstile and per-IP rate limiting.

---

## 4. Pricing engine

All rates are editable in **Business Settings → Pricing**. The engine reuses each service's existing `price_table` and `add_ons` fields, which are already stored but not shown publicly.

```
price = base (service × property size / hours)
      + add-ons
      × condition factor        (good 0.95 · average 1.00 · poor 1.25 · very poor → bespoke)
      × furnished factor        (end of tenancy furnished +10%)
      + surcharges              (weekend %, short notice < 48 h %, paid parking flat)
      → max(price, service minimum charge)
      → round to nearest £1
```

The engine also works out:
- **Estimated duration and team size.** Each row of the price table gets `hours` and `cleaners` values, for example a 3-bed end of tenancy is 2 cleaners × 4 hours. Slot capacity and cleaner offers both depend on these.
- **Bespoke triggers.** These send the quote to manual pricing: very poor condition, 5+ bedrooms or an HMO (rows priced `null`), rubbish removal, and after builders, communal, office or commercial work.

**Important:** the current seed prices are placeholders. Real rates must be confirmed before launch (see §12).

---

## 5. Quote page

- **Private URL:** `/quote/{reference}/{token}`, for example `/quote/Q-24817/k3V9…`.
  - The token is random (32 bytes), and only its hash is stored in the database.
  - The page is `noindex`, excluded from the sitemap, and sends `Cache-Control: private`. LiteSpeed must not cache it.
- **Contents:**
  - Service, property summary and add-ons
  - Date and arrival slot
  - Address (partly masked)
  - **Itemised price and total**
  - What's included, the re-clean guarantee, and the cancellation policy
- **Actions:**
  - **Accept & pay** (the main button)
  - Change details, which reopens the form pre-filled
  - Ask a question, which sends an email to the admin
- **Expiry:** 7 days, configurable. After expiry it shows "This quote has expired — get an updated price" and a re-quote link.
- **Required ticks before paying:**
  - Agree to the terms and the cancellation policy.
  - Bookings within 14 days: "I ask you to start the service within the 14-day cancellation period" (Consumer Contracts Regulations 2013 — see §11).
- **Follow-up emails** for quotes not accepted:
  - "Your quote is still available" after 24 hours.
  - "Your quote expires tomorrow" on day 6.
  - Both stop as soon as the customer pays or unsubscribes.

---

## 6. Payment (Stripe, full upfront)

- **Stripe Checkout** (a payment page hosted by Stripe):
  - Card details never touch our server. This is the lightest PCI compliance level, SAQ A.
  - Supports Apple Pay, Google Pay and Link.
- **Slot hold:** when the customer clicks "Accept & pay", the slot is held for 30 minutes. If payment doesn't complete, the hold is released.
- **Confirmation comes only from Stripe's webhook** (`checkout.session.completed`), never from the browser redirect.
  - The webhook endpoint checks the signature.
  - It handles repeat deliveries safely (idempotent, keyed by session ID).
- **Receipts:** Stripe's receipt plus our own booking confirmation email.
- **Refunds:** issued from the admin booking screen through Stripe's API, following the cancellation policy (§9).
- **Extra charges:** if the property doesn't match the description, the admin can send a **top-up payment link** (Stripe Payment Link) for an agreed amount.
- **Risk with full upfront payment:**
  - If a cleaner drops out, the admin is alerted immediately (§7).
  - Reassigning a cleaner, moving the date or refunding are one-click actions.

---

## 7. Cleaners and dispatch

### Cleaner profile (admin-managed)
- Type: **employed** or **self-employed**
- Contact details: mobile and email
- Areas covered: regions or postcode districts
- Services they can do
- Can lead a team (yes / no)
- Weekly working pattern, for example Mon–Fri 8am–4pm, plus time off and holidays
- Maximum jobs per day
- Pay rate: hourly or per job (used for the offer and for reports)
- Whether they have a key safe / car (optional)

### Dispatch rules (run automatically when payment is confirmed)
1. **Find eligible cleaners.** They must cover the area, do the service, be free for the slot and job length, and be under their daily limit.
2. **Employed first.** If an eligible employed cleaner exists, the system **assigns** the job directly, because you pay them either way. If there's a tie, it picks the least busy that day, preferring someone who has cleaned for this customer before.
3. **Then self-employed.** Remaining places, or all places if no employed cleaner is free, are **offered** to eligible self-employed cleaners by email and text.
   - The first to tap **Accept** gets the job. A database lock prevents two people winning the same place.
   - Everyone else gets "This job has been taken".
   - Team jobs have *N* places, and the first *N* to accept get them.
4. **Timeout and escalation.**
   - If nobody accepts within **3 hours** (configurable, and shorter for jobs within 48 hours), the admin gets an urgent email and text.
   - The admin can then assign manually, re-offer with a bonus, move the booking or refund it.
5. **Cleaner drops out after accepting.** The job goes back through dispatch, and the admin is alerted.

### What a cleaner sees
- **The offer:** service, date and slot, length, team size, **postcode district only** (e.g. HU5), and pay.
- **After accepting:** full address, access and parking notes, and the customer's first name and contact number. The number is shown only from 24 hours before the job.
- **Their links, all without a login:**
  - Each link is unique and tied to that cleaner and job.
  - The links are Accept, Decline, Can't make it anymore, and Mark job complete (with photos).
  - A simple "My upcoming jobs" page is added in phase 2.

---

## 8. Notifications

| Event | Customer | Admin | Cleaner | Channel |
|---|---|---|---|---|
| Quote ready (auto) | ✓ link to quote | — | — | Email |
| Bespoke quote requested | ✓ "we'll be in touch" | ✓ action needed | — | Email |
| Quote reminder (24 h / day 6) | ✓ | — | — | Email |
| Booking paid and confirmed | ✓ confirmation + receipt | ✓ | — | Email |
| Job assigned (employed) | — | ✓ | ✓ | Email + text |
| Job offer (self-employed) | — | — | ✓ eligible cleaners | Email + text |
| Job accepted | ✓ "your cleaner is …" (optional) | ✓ | ✓ confirmation | Email (+ text to cleaner) |
| Nobody accepted in time | — | ✓ **urgent** | — | Email + text |
| Reminder 48 h / 24 h before | ✓ | — | ✓ | Email + text |
| Rescheduled / cancelled | ✓ | ✓ | ✓ | Email + text |
| Job marked complete | ✓ thank you + re-clean guarantee link | ✓ | — | Email |
| Review request (+2 h) | ✓ Google review link | — | — | Email |
| Re-clean claim | ✓ acknowledgement | ✓ action needed | — | Email |

All emails use branded HTML templates, and the wording is editable in the admin.

---

## 9. After booking

- **Manage booking link** in every customer email lets them:
  - **Reschedule.** Free up to 48 hours before, and only into available slots.
  - **Cancel.** Refunds follow the policy below.
- **Proposed cancellation policy** (the owner must decide):
  - More than 48 hours before: full refund.
  - 24–48 hours before: 50% refund.
  - Under 24 hours, or no access on the day: no refund.
- **Completion.** The cleaner taps "Mark complete" and can add before/after photos. This sends the thank-you email, starts the 72-hour re-clean window and schedules the review request.
- **Re-clean claims.** The customer submits a form with photos inside the 72-hour window. The admin approves it and books a free return visit, which goes back through dispatch with £0 pay noted.

---

## 10. Admin

All in the WordPress admin under a **Bookings** menu:
- **Dashboard:** today's and tomorrow's jobs, unassigned jobs (highlighted), bespoke quotes waiting, and recent payments.
- **Quotes:** list with status (draft / sent / viewed / accepted / expired). Bespoke quotes are priced here and then sent.
- **Bookings:** list and calendar view. Booking detail shows the timeline, assigned cleaners, payment, refund, reassignment and a top-up payment link.
- **Cleaners:** profiles, availability, time off, and upcoming jobs per cleaner.
- **Settings:**
  - Prices and surcharges
  - Slots and minimum notice
  - Dispatch timeouts
  - Cancellation policy
  - Email and text templates
  - Stripe and SMS keys (stored as constants in `wp-config.php`, not in the database)
- **Reports, phase 3:** bookings and revenue by service and area, quote conversion rate, and cleaner hours and pay export for payroll and self-employed invoices.

---

## 11. Technical approach

| Area | Approach |
|---|---|
| Where the code lives | A dedicated plugin, `cc-bookings`, not the theme. Business logic survives theme changes and stays out of Bricks |
| Data | Custom database tables for `quotes`, `bookings`, `booking_items`, `cleaners`, `cleaner_availability`, `job_offers`, `payments` and `events` (audit log). The tables are needed for slot-capacity queries and locking; posts aren't suitable |
| Background jobs | **Action Scheduler** (the queue library WooCommerce uses), bundled. It runs reminders, offer timeouts, follow-ups and slot-hold expiry |
| Cron | WP-Cron stays on, so jobs still run on page visits. Add a Hostinger hPanel cron job every 5 minutes for reliable timing (see §14). Only set `DISABLE_WP_CRON` once that job is confirmed running |
| Stripe | Stripe REST API through `wp_remote_post` (no Composer needed), plus a webhook route at `/wp-json/cc/v1/stripe` with signature checks. Test mode first |
| Email | A transactional email service (Brevo, Postmark or Amazon SES) through an SMTP plugin, with SPF, DKIM and DMARC on the domain. Hostinger's PHP mail is not reliable enough for receipts |
| Text messages | Twilio or a UK provider such as The SMS Works (roughly 3–5p per message). WhatsApp is a possible later addition |
| Front end | The form is plain JavaScript in `main.js` style and progressively enhanced. Price previews use a REST endpoint, and **the server always re-prices** at submission and payment, so the browser total is never trusted |
| Security | Hashed tokens, nonces on admin actions, rate limits, Turnstile, capability checks, an audit log of every status change, and no card data stored |
| Privacy (UK GDPR) | Update the privacy policy. Self-employed cleaners only see addresses after accepting. Delete or anonymise customer data from unbooked quotes after 12 months |
| Caching | Exclude `/quote/*`, `/booking/*`, `/job/*` and the REST routes from LiteSpeed cache |

### Legal points to confirm with an adviser
- **Consumer Contracts Regulations 2013:**
  - Online bookings come with a 14-day cancellation right.
  - Cleans within that period need the customer's express request to start early.
  - A cancellation is then charged for any work already done.
  - The quote page's checkbox and the terms cover this.
- **Terms and conditions:** price changes if the property differs from the description, access and no-shows, keys, breakages, and pets.
- **VAT:** if the business is VAT-registered, quotes and receipts must show it.
- **Self-employed cleaners:** a contractor agreement that includes data protection, because they receive customer addresses.

---

## 12. Delivery phases

### Phase 1 — Instant quote to paid booking (the biggest win)
- Multi-step quote form, pricing engine, bespoke-quote route
- Private quote page with expiry and quote follow-up emails
- Stripe Checkout, webhook and confirmation emails
- Admin: quotes, bookings, refunds, manual cleaner assignment, which sends the cleaner an email
- **Slot capacity is simple in this phase:** the admin sets "max jobs / cleaner-hours per slot per day", because cleaner calendars come in phase 2.

**Done when:** a test customer can get an instant price, pay in Stripe test mode, and both sides receive the right emails. The admin sees the booking and can assign and refund.

### Phase 2 — Cleaners and automatic dispatch
- Cleaner profiles, working patterns and time off
- Slot availability calculated from real cleaner calendars
- Employed auto-assignment, self-employed offers, first-to-accept, timeouts and escalation
- Text messages, cleaner job links and a "My upcoming jobs" page

**Done when:** a paid booking is assigned or accepted with no admin action, and an unaccepted job alerts the admin within the timeout.

### Phase 3 — After-booking automation
- Customer reschedule and cancel with policy-based refunds
- 48-hour and 24-hour reminders, completion with photos, review requests and re-clean claims
- Reports and pay exports

### Phase 4 — Regular cleans (needs a decision, see below)
- With full upfront payment:
  - Each visit is paid before it happens.
  - The first visit is paid at booking, and the card is saved.
  - Later visits are charged automatically 48 hours before (card saved with the customer's consent).
  - The customer can skip or pause visits.
  - The same cleaner is kept where possible.

Phase 1 can go live on its own, with the admin assigning cleaners by hand until phase 2 is done.

---

## 13. Decisions needed from the owner

1. **Real prices:** base prices per service and size, add-ons, minimum charges, and the hours and team size for each size.
2. **Surcharges:** weekend, short notice (and what counts as short notice) and parking. Or none at all?
3. **Working hours and slots:** days of operation, arrival windows and minimum notice (48 hours suggested).
4. **Which services are auto-quoted** and which are always bespoke (the proposal in §4).
5. **Cancellation and reschedule policy** (the proposal in §9).
6. **Dispatch:** offer timeout, and whether employed cleaners can decline a job.
7. **Regular cleans:** charge each visit 48 hours before with a saved card (proposed), or keep them out of online booking for now.
8. **Accounts and costs:**
   - Stripe account. Fees are about 1.5% + 20p per UK card.
   - Transactional email provider.
   - Text-message provider and budget.
9. **VAT status**, and the business name and address for receipts.
10. **Cleaner pay:** hourly or per job, and whether the pay is shown in job offers.

---

## 14. Phase 1 — setup and operations (built)

Phase 1 is live in the `cc-bookings` plugin. The quote form replaces the contact-page form through the `{cc_quote_form}` dynamic tag. If the plugin is switched off, the old enquiry form shows again automatically.

### Works now, with no keys
- Instant prices, bespoke requests, private quote pages, follow-up emails and the admin screens under **Bookings**.
- Without Stripe keys, "Accept quote" records the customer's acceptance and their two consents, then emails the admin to take payment by phone. The admin then uses **Record payment & confirm booking** on the quote, which confirms the booking through the same code as Stripe.

### Owner steps (one-off)
1. **Prices:** fill in each service's price table in WordPress, including the new **hours** and **cleaners** columns. An empty price means that size is quoted personally. Check **Bookings → Settings** for surcharges, minimums, slots and capacity.
2. **Stripe** (test mode first). Add to `wp-config.php` on the deeppink site:
   - `define( 'CC_STRIPE_SECRET', 'sk_test_…' );`
   - In Stripe → Developers → Webhooks, add the endpoint `https://<site>/wp-json/cc/v1/stripe` with the events `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `charge.refunded`.
   - `define( 'CC_STRIPE_WEBHOOK_SECRET', 'whsec_…' );`
   - **Bookings → Settings → Setup** shows whether each key is set.
3. **Turnstile** (optional spam protection): create a Cloudflare Turnstile widget for the domain and add `CC_TURNSTILE_SITE` and `CC_TURNSTILE_SECRET`. Until then, the form relies on a honeypot, a minimum fill time and rate limits.
4. **Email:** set up a transactional email provider (Brevo, Postmark or SES) through an SMTP plugin, with SPF, DKIM and DMARC. Then set the "from" address and admin address under **Settings → Emails**. Every email is recorded under **Bookings → Activity → Emails only**.
5. **Server cron** (hPanel → Advanced → Cron Jobs), every 5 minutes:
   `cd ~/domains/deeppink-woodcock-284086.hostingersite.com/public_html && wp cron event run --due-now >/dev/null 2>&1`
   Once it has run for a day, you may add `define( 'DISABLE_WP_CRON', true );`. Without the cron job, reminders and hold expiry still run, but only when someone visits the site.

### Technical notes
- **Background jobs:** Action Scheduler 4.2.0 is bundled in `cc-bookings/lib/`, in the group `cc-bookings`. If WooCommerce is ever installed, the newer copy loads automatically.
- **Private pages:** `/quote/{ref}/{token}/` and `/booking/{ref}/{token}/` are sent with `no-store` and `noindex`, and LiteSpeed is told not to cache them. The same applies to `/wp-json/cc/v1/*`. Only a hash of each token is stored. Photos live in `uploads/cc-bookings-private/`, which is blocked from direct access.
- **No prices in public HTML:** prices appear only on private pages, in the REST price preview, in emails and in admin.
- **Deploying:** run `scripts/deploy-plugin.ps1`.
- **WP-CLI** (`wp cc-bookings …`):

| Command | What it does |
|---|---|
| `status` | Shows keys, queue and table counts |
| `price <service> --answers='{…}'` | Prices a job without saving it |
| `simulate-payment <ref> [--twice]` | Runs the real webhook confirmation path without Stripe |
| `test-signature` | Self-tests webhook signature checking |
| `mail-log` | Lists recent emails |
| `setting <path> [<value>]` | Reads or changes a setting, e.g. `emails.log_only 1` to log emails without sending |
| `purge-test --yes` | Deletes test quotes and bookings |

- **Test data:** a quote counts as test data when the email ends in `@example.com`, `.org` or `.net`, or the name contains `[test]`.
- **Hooks for later phases:**
  - `cc_booking_confirmed`, `cc_booking_assigned`, `cc_booking_unassigned`, `cc_booking_status_changed`, `cc_booking_refunded`, `cc_booking_cancelled`, `cc_booking_completed`.
  - `cc_quote_created`, `cc_quote_sent`, `cc_quote_viewed`, `cc_quote_expired`.
  - Filters: `cc_bookings_statuses`, `cc_bookings_transitions`, `cc_bookings_price`, `cc_bookings_slot_available`, `cc_bookings_send_sms`.
  - Admin extension points: `cc_bookings_admin_pages`, `cc_bookings_admin_action_{do}`.

### Phase 2 — cleaners and dispatch (built)

Phase 2 lives in the same plugin, in its own files (`class-dispatch*.php`, `class-availability.php`, `class-sms.php`, `class-job-links.php`, `class-job-pages.php`, `admin/class-cleaner-profiles-screen.php`, `admin/class-dispatch-screen.php`, `templates/job*.php`, `templates/cleaner-jobs.php`). `Plugin::boot()` calls `Dispatch::init()`; everything else is wired through hooks.

**What it does**
- **Cleaner profiles** (**Bookings → Cleaners**): type, contact details, areas (Area pages) and extra districts, services, team lead, car, weekly hours ("any time", or set times per day), time off (whole or part days), max jobs per day, pay (hourly or per job) and notes. Each profile has a "My jobs" link button.
- **Slots from cleaner calendars:** once there is at least one active cleaner, the quote form only offers an arrival slot if enough cleaners who cover the postcode and do the service are free for the whole job: inside their weekly hours, not on time off, under their daily limit, with a travel buffer around other jobs. Unstaffed bookings and live slot holds count against the spare cleaners. With no active cleaners, the Phase 1 capacity settings apply as before. **Bookings → Dispatch** can switch the source to "both" (calendars and capacity) or "capacity only".
- **Dispatch on payment** (`cc_booking_confirmed`):
  1. Employed cleaners are assigned directly. A cleaner who has cleaned for this customer before comes first, then whoever is least busy that day and week. Team jobs keep one place for a team lead.
  2. Any places still open are offered to every eligible self-employed cleaner by email and text. The first to accept wins. This is enforced in the database by an atomic `UPDATE … SET filled = filled + 1 WHERE filled < seats` on `wp_cc_dispatch` under a named lock, so a second accept gets "Sorry, this job has been taken".
  3. If places are still open at the deadline, the admin gets an urgent email and text. The deadline defaults to 3 hours, or 60 minutes for jobs within 48 hours, and is never later than 2 hours before the job. Offers stay open after escalation, so a late accept still fills the job.
- **Cleaner changes:** "Can't make it" (or an admin unassign) takes the cleaner off the job, re-runs dispatch and alerts the admin. A reschedule (`cc_booking_rescheduled`, fired by Phase 3) quietly takes the crew off, re-runs dispatch for the new time and tells anyone who wasn't put back on. A cancellation withdraws open offers and tells the crew.
- **Admin, on each booking:** dispatch state, offers with each cleaner's link, completion photos, **Run dispatch again**, **Re-offer with a bonus** (includes cleaners who declined) and **Assign** (free cleaners listed first). **Bookings → Dispatch** lists jobs waiting for a cleaner, holds the dispatch settings and the editable text messages, and shows recent texts.
- **Cleaner pages** (signed links, `no-store`, `noindex`):
  - `/job/{offer}/{sig}/`: an offer shows only the district, date and slot, hours, team size and pay, with Accept and Decline. Once the cleaner is on the job, it adds the full address, the customer's first name, access notes and a map link. The customer's phone appears from 24 hours before the job.
  - The same page has "Can't make it" (with an optional reason) and, from 2 hours before the start, "Mark job complete" with optional photos. Photos are stored privately in `uploads/cc-bookings-private/{ref}-done/` and shown on the admin booking screen. Completing fires `cc_booking_completed`; Phase 3 handles everything after that.
  - `/job/me/{cleaner}/{sig}/` lists the cleaner's upcoming jobs.
  - Links are HMAC-signed with a per-row random key and `wp_salt('auth')`, so they can be re-sent at any time. **Revoke links** on a profile rotates the key.

**Owner steps**
1. **Text messages (Twilio).** Until this is done, every text is written in full to **Bookings → Activity** (`sms_logged`) instead of being sent. Add to `wp-config.php`:
   - `define( 'CC_TWILIO_SID', 'AC…' );`
   - `define( 'CC_TWILIO_TOKEN', '…' );`
   - `define( 'CC_TWILIO_FROM', '+44…' );` (or an approved alphanumeric sender), or `define( 'CC_TWILIO_MESSAGING_SID', 'MG…' );`
   - Then set **Bookings → Dispatch → Admin mobile** for urgent alerts. "Log texts only" there pauses sending without removing the keys. Another provider can answer the `cc_bookings_send_sms` filter at a priority below 20 and return `true`.
2. **Cleaners:** add each cleaner with their areas, services, weekly hours, daily limit and pay, then use **My jobs link → Email link** so they have their jobs page.
3. **Check the dispatch settings** (offer timeout, urgent window, travel buffer, slot source, whether customers are emailed when a cleaner is assigned).
4. The **server cron** from Phase 1 also runs offer timeouts on time.

**Technical notes**
- **Schema:** separate installer `Dispatch_Schema` (option `cc_bookings_dispatch_schema`, version 1). It adds the table `wp_cc_dispatch` (one row per booking: seats, filled, status, round, bonus, deadline) and the columns `cleaners.areas`, `job_offers.photos` and `job_offers.note`. The core schema version is unchanged. Offer status `accepting` is added through `cc_bookings_statuses`.
- **Settings** are stored under `dispatch.*` in `cc_bookings_settings`. Defaults are in `Dispatch::DEFAULTS`.
- **Queue actions:** `cc_bookings_dispatch_run( $booking_id, $reason )` (retry and deferred runs) and `cc_bookings_dispatch_timeout( $booking_id, $round )`.
- **Hooks fired:** `cc_dispatch_offered`, `cc_dispatch_offer_accepted`, `cc_dispatch_offer_declined` (each with `$booking_id, $offer_id, $cleaner_id`), `cc_dispatch_cleaner_dropped( $booking_id, $offer_id, $cleaner_id, $reason )`, `cc_dispatch_escalated( $booking_id, $reason )`, `cc_dispatch_filled( $booking_id )`.
- **Listens to:** `cc_booking_confirmed` (priority 20), `cc_booking_rescheduled( $booking_id, $old_start )`, `cc_booking_status_changed`, `cc_booking_assigned` / `cc_booking_unassigned` (to keep the seat count in step), and `cc_bookings_admin_alert` (texts the admin mobile for urgent alerts). It replaces the Phase 1 `cleaner_assigned` email with one that links to the job page.
- **Filters:** `cc_bookings_send_sms` (Twilio at priority 20), `cc_bookings_sms_provider`, `cc_bookings_dispatch_cleaners` (the cleaners dispatch and slots consider), `cc_bookings_dispatch_notify_cancelled`.
- **Public helper:** `CC\Bookings\Sms::send( $to, $text, [ 'booking', $id ] )` normalises UK numbers to +44 and sends, or logs the text when no provider is set.
- **New email templates** (editable under **Settings → Emails**): `job_offer`, `job_accepted`, `job_taken`, `job_removed`, `dispatch_admin`, `cleaner_customer`, `cleaner_jobs_link`, plus a new version of `cleaner_assigned`.
- **WP-CLI** (`wp cc-bookings dispatch …`):

| Command | What it does |
|---|---|
| `status <ref> [--links]` | Shows dispatch state, crew and offers, optionally with job links |
| `run <ref> [--bonus=<£>]` | Runs dispatch now, or re-offers with a bonus |
| `timeout <ref>` | Runs the queued offer timeout now |
| `act <accept\|decline\|drop\|complete> <offer> [--reason=]` | Acts as the cleaner |
| `slots [--service --postcode --hours --cleaners --days]` | Shows slot availability from cleaner calendars |
| `cleaner-link <id>` | Prints a cleaner's "My jobs" link |
| `sms-log [--limit]` | Lists recent texts: sent, logged or failed |
| `purge-p2-test --yes` | Deletes only cleaners and customers named "P2 Test…" and their data |
