# Bexy — Craft CMS 5 Plugin

## Project Overview

Bexy connects Craft Commerce 5 to **bexio**, the Swiss accounting platform: completed orders become
bexio invoices (or bexio orders), Commerce charges become payments against them, and bexio's status
comes back into Commerce. Distributed as `justinholtweb/craft-bexy`. **Single paid edition, $99** —
there are no edition gates anywhere in the code.

## Why it exists

`thekitchen.agency/craft-bexio-synchronizer` ($199 + $99/yr, v1.0.0, zero installs) already pushes
orders to bexio. Bexy's edge is everything after the push, and the connection itself: OAuth instead
of a token that dies every 60 days, payments posted so invoices do not sit open, status pulled back,
totals checked before they are sent, a real log, and console commands.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies beyond Craft's own Guzzle

## Architecture

- Namespace `justinholtweb\bexy`, package `justinholtweb/craft-bexy`, handle `bexy`

### The three invariants

1. **`services\Builder::forOrder()` is the only place a Commerce order becomes a bexio document
   body.** The CP preview, `bexy/sync/preview` and the real push all call it, so a preview is
   byte-identical to what bexio receives.
2. **`services\Documents::record()` is the only place a `bexy_documents` row is written.** The
   queue job, the CP button, the console command and the adopt-existing path all land there, so the
   idempotency decision is made once and cannot contradict itself.
3. **`services\Api::request()` is the only place an HTTP request leaves the plugin.** So the
   token, the rate limit, the retry policy, the redaction and the log entry are decided once.

### Data model

- `{{%bexy_documents}}` — one row per order, **unique on `orderId`**; that index is the local
  idempotency guarantee, and `apiReference` is the remote one.
- `{{%bexy_payments}}` — **unique on `transactionId`**; why a charge is never posted twice.
- `{{%bexy_contacts}}` — email → bexio contact, so repeat customers do not multiply.
- `{{%bexy_tokens}}` — one row, tokens **encrypted** with Craft's security key and base64'd.
  **Not project config**: tokens are secrets and environment-specific, and project config is
  committed.
- `{{%bexy_log}}` — the connection log.

### Protocol notes (read from the spec, not guessed)

The bexio OpenAPI 3.0.2 document is embedded in the Redoc bundle at <https://docs.bexio.com> — 381
paths, extracted to `docs/bexio-openapi.json` (git-tracked, `export-ignore`d so it stays out of the
composer dist). The per-resource doc URLs 404; the SPA is the only source.

- **Base** `https://api.bexio.com`, versions mixed per resource: contacts/invoices/orders/articles
  are `/2.0`, taxes/currencies/users/banking are `/3.0`, some payments are `/4.0`.
- **IdP** is Keycloak at `https://auth.bexio.com/realms/bexio` (`idp.bexio.com` was decommissioned
  2025-03-31). Token-endpoint parameters must be in the **body**; query params are no longer
  accepted. Refresh tokens **rotate** — store the new one or the next refresh fails.
- **Read scopes are implied by write scopes.** Asking for `contact_show` alongside `contact_edit`
  only lengthens the consent screen.
- **PATs expire 60 days after creation**, silently. That is the incumbent's structural weakness and
  the reason OAuth is the default.
- **`api_reference`** is a free string only the API can read or write, and `/search` matches on it.
  It is the only idempotency mechanism bexio offers.
- **Position types** are sent verbatim in `type`: `KbPositionCustom`, `KbPositionArticle`,
  `KbPositionText`, `KbPositionSubtotal`, `KbPositionPagebreak`, `KbPositionDiscount`. A discount
  is a **magnitude** with `is_percentual`, not a negative amount.
- **`mwst_type`**: 0 prices include tax, 1 tax added, 2 exempt. `mwst_is_net` is only consulted
  when `mwst_type` is 0.
- **Only active sales taxes** are valid on a document — `GET /3.0/taxes?types=sales_tax&scope=active`.
- **Contacts require `user_id` *and* `owner_id`**, and `name_1` is the **surname**.
  The combined `address` field was **deprecated on writes 2025-12-09**; send `street_name` +
  `house_number`.
- **PDFs come back base64 inside a JSON envelope**, not as a binary body.
- **There is no credit-note create endpoint** — only `GET /2.0/kb_credit_voucher/{id}/pdf`. Refunds
  therefore cancel or get flagged; Bexy never fakes a negative payment.
- **Rate limiting** is per company per minute, with `RateLimit-Limit/Remaining/Reset` headers and a
  429. `services\Api` honours `Reset`, bounded to 3s in a web request and 60s in console/queue.

## Traps found while building this

- **`craft\console\Controller` already has a public `table()`.** A `private function table()` in a
  plugin's console controller is a *fatal compile error* that takes `craft help` down for the whole
  site, not just that command. Named `printTable()`. (`craft-bird` in the shared harness still has
  this bug, so `craft help` fails there — not Bexy's doing.)
- **`OrderStatusEvent` carries the order directly** as `$event->order`. Reaching through
  `$event->orderHistory->getOrder()` reloads the element and loses the current request's changes.
- **Do not override `getSettingsResponse()`** to redirect — that method *is* what renders the
  settings screen, so a redirect gives an infinite loop rather than a saved form.
- **Craft namespaces plugin settings HTML itself.** A field named `settings[foo]` in
  `settings.twig` posts as `settings[settings][foo]` and saves nothing, silently, while Craft
  reports success. Names are bare.
- **`Money::differs()` is inclusive at the tolerance** — a gap of exactly `roundingTolerance` is
  *not* a mismatch. Caught by a fixture that landed precisely on the boundary.
- **A rounding position must be untaxed**, or it moves the gross total by the delta *plus tax* and
  misses again.
- **A rounding position needs an upper bound.** Without one, a whole mis-mapped 7.7% tax charge
  gets closed by a "Rounding" line and the document balances while being wrong. `roundingLimit`
  (default 1.00) refuses past that and explains instead.
- **`Commerce::getTransactions()->createTransaction()` needs a payment gateway** and fatals on a
  fixture order that has none. Build the `Transaction` model directly in tests.
- **Commerce refuses an address element it does not own** — pass an *array* of attributes and let
  Commerce build the owned element.
- **`Security::encryptByKey()` returns raw binary**, and a text column on a utf8mb4 connection
  rejects it with `SQLSTATE[22007] Invalid datetime format: 1366 Incorrect string value` — an error
  naming a *datetime* problem for a *token* column. Base64 both ways. (Inherited from
  `[[craft-knox-gotchas]]`; Bexy did it right the first time because of that note.)
- **Never mark plugin settings `required`** — it makes a fresh install unsavable.
- **`/Users/jholt/Sites` is itself an uncommitted git repo**, so a plugin folder must be
  `git init`ed before any `git add`.

Bexy sits in the accounting-integration family: `[[project_craft_sevvies]]` (sevDesk, DE),
`[[project_craft_econz]]` (e-conomic, DK), `[[project_craft_knox]]` (Fortnox, SE),
`[[project_craft_holding]]` (Holded, ES), `[[project_craft_datevz]]` (DATEV export, DE) —
Bexy is the **CH** one. See also `[[craft-plugin-gotchas]]` and `[[craft-knox-gotchas]]` in the
shared memory, and `[[project_craft_shipper]]` for the sibling Commerce-plus-external-API plugin
whose conventions this follows.

**Bexy differs from most of the family in having a single paid edition** — no `editions()`, no
`isPro()`, no edition gates. Do not add one without being asked.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-bexy/tests/integration/checks.php   # 139 checks
ddev exec bash -c 'find /var/www/craft-bexy/src -name "*.php" -print0 | xargs -0 -n1 php -l'
ddev exec php craft bexy/doctor
```

`checks.php` seeds bexio's lookup tables straight into Craft's cache, which is where
`services\Meta` reads them from — that is what lets the builder, the tax mapping and the totals
arithmetic be exercised in full **without a bexio account**. The boundary is the HTTP call itself:
a real create, issue, payment or reconcile is *not* covered, and should be walked through by hand
against a bexio trial company before release. It restores every fixture and the original settings
in a `finally`.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save fatals** while it is
enabled. `checks.php` detaches that handler in-process (never persisted). That is a bug in Penny.

## Coding conventions

- `Craft::t('bexy', '…')` for user-facing strings; `src/translations/en/bexy.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — build and submit one from outside the settings form
- Never mark plugin settings `required`
- Anything on the checkout path fails **open**: a bexio outage must never stop a customer paying
