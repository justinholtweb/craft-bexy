---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it does, and the ones that matter for a correct VAT return.
---

All of this lives in **Bexy → Settings**. Nothing here is required, so a fresh install always
saves.

## What to create

| Setting | Notes |
|---|---|
| **Document type** | *Invoice* or *Order*. Invoices can be issued, emailed and paid. Orders are the pre-invoice stage, and payments cannot be posted against them. |
| **Send orders automatically** | Queues a push when an order completes. The push itself always runs on the queue, so bexio can never hold up a checkout. |
| **Only when the order reaches** | Leave every status unchecked to push as soon as the order completes. Tick one or more to wait until somebody moves the order there instead. |
| **Issue the invoice** | Books it and gives it a number. Until then it is a draft in bexio and cannot be emailed or paid. |
| **Have bexio email it** | Sends from bexio's own delivery network, not Craft's. Requires *Issue the invoice*. |

### Email body

bexio puts the document where `[Network Link]` appears. **Without that placeholder the customer
gets an email with no invoice in it**, so Bexy refuses to save an email body that omits it.
`{number}`, `{name}` and `{email}` are also replaced.

### Document title

Tokens: `{number}`, `{reference}`, `{date}`, `{total}`, `{currency}`, `{name}`, `{email}`.

## bexio defaults

Filled in by **Refresh lists from bexio**.

| Setting | Notes |
|---|---|
| **bexio user** | Required by bexio on every contact and document. Defaults to whoever authorised the connection. |
| **Default revenue account** | Where a position is booked when its tax category has no account of its own. |
| **Default sales tax** | Only active sales taxes are offered — bexio rejects any other kind on a document. |
| **Unit**, **Bank account**, **Payment type**, **Document language** | Document defaults. |
| **Letterhead ID** | bexio's `logopaper_id`. Empty means the company default. |
| **Payment term** | Days until the invoice is due. Default 30. |

## Tax

This is the section to get right. Everything else is cosmetic by comparison.

### How prices are sent

`mwst_type` on the bexio document: 0 prices include tax, 1 tax added on top, 2 exempt.

- **From Commerce** (default) reads it off the order. A tax rate flagged *included in price* makes
  every price gross.
- The three explicit options override that for every document.

### Tax and account mapping

One row per Commerce tax category, giving the bexio tax and the revenue account its line items are
booked to. Anything left out falls back to the defaults above.

A missing tax mapping is not an error — bexio books the position at the document default, which
may be the wrong rate, and Bexy records a warning on the document. Check the log before you trust
a quarter's numbers.

Shipping has its own label, tax and account, because it is rarely the same category as the goods.

## Totals

bexio works the document total out for itself. Bexy works out the same figure before sending and
compares it to Commerce's, so a mismatch surfaces here rather than during a VAT return.

| Setting | Default | Notes |
|---|---|---|
| **Close a mismatch with a rounding position** | on | Adds an untaxed line for the difference. Off, the difference is only reported. |
| **Tolerance** | 0.01 | How far the two totals may drift before Bexy does anything. Inclusive: a gap of exactly the tolerance is not a mismatch. |
| **Largest difference to close** | 1.00 | Beyond this Bexy refuses to adjust and explains instead. 0 removes the limit. |
| **Rounding label** | Rounding | What the line is called on the document. |

Two things worth knowing about the rounding position:

- **It is untaxed on purpose.** A taxed rounding line moves the gross total by the delta *plus
  tax*, and misses again.
- **The upper bound is the point.** Without it, a whole mis-mapped 7.7% tax charge gets quietly
  closed by a line called "Rounding" and the document balances while being wrong. A difference
  that size is a wrong tax mapping, not rounding.

## Contacts

| Setting | Default | Notes |
|---|---|---|
| **Create contacts** | on | Bexy matches on email first — its own map, then bexio's contact list — and only creates when neither has one. |
| **Update existing contacts** | off | Pushes the order's address onto the bexio contact. Off by default: the contact record belongs to the accountant, and a delivery address typed into the shop should not overwrite it. |
| **Contact group IDs** | — | bexio contact group IDs for newly created contacts, comma separated. |

Bexy sends `street_name` and `house_number` separately. bexio deprecated the combined `address`
field on writes on 9 December 2025.

## Items

| Setting | Default | Notes |
|---|---|---|
| **Match line items to bexio items by SKU** | off | Turns a line into a real bexio item position, which is what makes bexio's per-item reporting work. A SKU with no match falls back to a custom position. |
| **Create items that don't exist** | off | Adds the SKU to bexio's item list the first time it is sold. |

## Payments

| Setting | Default | Notes |
|---|---|---|
| **Post payments to bexio** | on | Every successful Commerce charge becomes a payment against the invoice, so bexio does not leave paid orders sitting open. Invoices only. |
| **Payments bank account** | — | Falls back to the bank account above. |
| **Cancel the invoice on a full refund** | off | Only when nothing has been paid against it in bexio. Anything else is flagged for a credit voucher. |

bexio has no credit-note create endpoint, only a PDF read. Bexy never fakes a negative payment to
work around that; a refund it cannot cancel is flagged for a human.

## Reconciliation

| Setting | Default | Notes |
|---|---|---|
| **Pull status back from bexio** | on | Needs `craft bexy/reconcile/run` on a schedule. Without it, an invoice marked paid in bexio never reaches Commerce. |
| **Status mapping** | — | bexio status to Commerce order status. |
| **Only check documents from the last** | 120 | Days. Paid and cancelled documents drop out anyway. 0 checks everything. |

Bexy changes the Commerce order status and nothing else. **It never fabricates a Commerce
transaction to make an order look paid**, because that would put a payment in your Commerce
reports that never happened.

## Log

| Setting | Default | Notes |
|---|---|---|
| **Log requests** | on | |
| **Keep request and response bodies** | on | Tokens and secrets are redacted either way. |
| **Keep log entries for** | 30 | Days. 0 keeps everything. Pruned whenever a sync or reconcile command runs. |
