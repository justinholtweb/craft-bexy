---
title: FAQ
slug: faq
order: 50
summary: Pricing, scope, and the questions that come up before buying.
---

## Is Bexy made by bexio?

No. Bexy is an independent plugin built by Justin Holt. It is **not affiliated with, endorsed by,
or sponsored by bexio AG**. It talks to bexio's public API the same way any third-party integration
does.

“bexio” and the bexio logo are trademarks of bexio AG, used here only to identify the service Bexy
connects to.

## What does it cost?

$99, once. One edition, no renewal, no feature gates. Updates for Craft 5 are included.

## What does it actually do?

Completed Commerce orders become bexio invoices or bexio orders. Commerce charges are posted as
payments against them. bexio's status comes back into Commerce. Totals are checked before anything
is sent.

## Does a bexio outage break my checkout?

No. The push is always a queue job, and everything on the checkout path fails open. A customer can
pay while bexio is down; the order syncs when it comes back.

## Invoices or orders?

Either. Invoices can be issued, emailed and paid, so they are the usual choice. bexio orders are
the pre-invoice stage and payments cannot be posted against them.

## Why OAuth rather than a token?

bexio's personal access tokens expire 60 days after they are created, silently. A shop connected
with one stops syncing two months later and nothing says so. OAuth refresh tokens rotate and keep
working. Bexy supports both; OAuth is the default for that reason.

## Can it send the invoice to the customer?

Yes, through bexio's own delivery network rather than Craft's. The invoice has to be issued first.
Your email body must contain `[Network Link]` or the customer gets a mail with no invoice in it —
Bexy refuses to save one that doesn't.

## Will it create duplicate documents?

No. Bexy writes `api_reference` on every document and searches for it before creating anything, and
the local table is unique on the order ID. Push the same order twice and the second push adopts the
document that already exists.

## What about refunds and credit notes?

A full refund cancels the invoice where bexio permits it — which means only when nothing has been
paid against it. Everything else is flagged for a credit voucher.

bexio's API has no credit-note create endpoint, only a PDF read. Bexy will not fake a negative
payment to work around that, because it would leave your books saying something that never
happened.

## Does it touch my Commerce transactions?

No. Reconciliation moves the Commerce **order status** and nothing else. Bexy never fabricates a
Commerce transaction to make an order look paid.

## How does VAT work?

Each Commerce tax category maps to a bexio tax and a revenue account. `mwst_type` is read off the
order by default — a Commerce tax rate flagged *included in price* makes prices gross — and can be
forced if you prefer.

Only bexio's **active sales taxes** are offered, because bexio rejects any other kind on a
document.

## What if the totals disagree?

Bexy computes the document total before sending and compares it to Commerce's. A cent-sized gap
gets an untaxed rounding position. A larger one is refused and explained, because a difference that
size is a wrong tax mapping and closing it would hide the problem.

## Does it work with multiple Commerce stores or currencies?

Currencies yes, as long as the currency exists in your bexio company; if it doesn't, Bexy warns and
bexio books in the company default. Bexy connects one Craft install to one bexio company.

## Can I see what will be sent before it goes?

```sh
php craft bexy/sync/preview 1234
```

It runs the same builder as the real push, so the preview is byte-identical to what bexio receives.

## What languages is the control panel in?

English, German, French and Italian.

## Which Craft and Commerce versions?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Is there a trial?

Craft Plugin Store plugins can be trialled in a development environment for as long as you like.
You only pay when you go to production.

## How do I get help?

Email [justin@justinholt.com](mailto:justin@justinholt.com). Include the relevant entries from
**Bexy → Log** — secrets are already redacted — and the output of `php craft bexy/doctor`.
