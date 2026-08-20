# Bexy — build plan

**bexio integration for Craft Commerce 5.** Package `justinholtweb/craft-bexy`, handle `bexy`,
namespace `justinholtweb\bexy`. Single paid edition, **$99**, no edition gates in the code.

## Why it exists

`thekitchen.agency/craft-bexio-synchronizer` ($199 up front + $99/yr, v1.0.0, zero installs) already
pushes completed Commerce orders into bexio as orders or invoices. Bexy's edge is what happens
*after* the push, plus the connection itself:

| | Bexio Synchronizer | Bexy |
|---|---|---|
| Auth | Personal Access Token only | OAuth 2.0 authorization code flow **and** PAT |
| Connection lifetime | PAT expires 60 days after creation | refresh tokens; PAT expiry is counted down in the CP |
| Payments | not pushed — every invoice stays open in bexio | Commerce transactions posted as invoice payments |
| Direction | push only | push **and** pull (bexio status → Commerce order status) |
| Totals | trusts bexio's arithmetic | payload total is checked against the Commerce total before sending |
| Non-standard adjusters | undocumented | any adjustment that is not tax/shipping/discount becomes its own position |
| Preview | none | the exact JSON body, from the same builder that sends it |
| Log | sync status | full request/response per call, redacted |
| Console | none | sync, reconcile, preview, meta, doctor |

Price: **$99 perpetual**, against $199 + $99/yr.

## The two invariants

1. **`services\Builder::forOrder()` is the only place a Commerce order becomes a bexio document
   body.** The CP preview, `bexy/sync/preview` and the real push all call it, so a preview is
   byte-identical to what bexio receives.
2. **`services\Documents::record()` is the only place a `bexy_documents` row is written.** The
   queue job, the CP "Push now" button, the console command and the adopt-existing path all land
   there, so the idempotency decision is made once and cannot disagree with itself.

## Idempotency

bexio has no idempotency key. It does have `api_reference` — a free string that only the API can
read or write, and that `POST /2.0/kb_invoice/search` can search on. Bexy writes
`bexy:<order reference>` there and searches for it **before** creating anything. A retry after a
timeout that actually succeeded finds the document and adopts it instead of billing the customer
twice.

## Data model

- `{{%bexy_documents}}` — one row per Commerce order, unique on `orderId`. Holds the bexio document
  id, type, number, status, the totals as sent, and the last error.
- `{{%bexy_payments}}` — unique on `(transactionId)`; that index is why a transaction is never
  posted to bexio twice.
- `{{%bexy_contacts}}` — Commerce email/user → bexio `contact_id`, so a repeat customer is not
  re-created.
- `{{%bexy_tokens}}` — a single row holding the OAuth access/refresh token. **Not** project config:
  tokens are secrets and environment-specific, and project config is version controlled.
- `{{%bexy_log}}` — the connection log.

## Money

bexio takes decimals as **strings**, unit price and discount to 6 places, and computes the document
total itself. Bexy sends `unit_price` × `amount` per position and then compares bexio's arithmetic
to Commerce's before the request goes out. If they disagree by more than the rounding tolerance the
payload gets an explicit, labelled rounding position rather than a silent mismatch between the shop
and the books — up to `roundingLimit`. Past that the difference is not rounding but a wrong tax
mapping, and Bexy refuses to close it and says so, because a document that balances and is still
wrong is worse than one that visibly does not.

## Tax

`mwst_type` describes the prices you send: `0` including tax, `1` excluding, `2` exempt.
`mwst_is_net` only matters when `mwst_type` is `0`. Commerce's own tax rates decide the default —
if any applicable rate has "included in price" set, prices are gross and Bexy sends `0`/`false`;
otherwise net and `1`/`true`. Per-position `tax_id` comes from a mapping table of Commerce tax
category → bexio tax, with a default for everything unmapped. Only active sales taxes are valid on
a document, so the picker is fed from `GET /3.0/taxes?types=sales_tax&scope=active`.

## Refunds

bexio's API can create an invoice payment but **cannot create a credit voucher** — only fetch one's
PDF. So a refund is handled honestly: a full refund on an unissued or unpaid invoice cancels it via
`POST /2.0/kb_invoice/{id}/cancel`; anything else is recorded on the document row as needing manual
attention in bexio and surfaced in the CP. Bexy does not fabricate a negative payment.

## Failing open

Order completion never blocks on bexio. The completion handler writes a pending row and pushes a
queue job; every network call lives in the job. A bexio outage, a 429 or an expired token can delay
a document but can never stop a customer paying or hold up checkout.
