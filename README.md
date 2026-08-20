# Bexy

**bexio for Craft Commerce.** Turn completed orders into bexio invoices or orders, post the
payments that settle them, and pull bexio's verdict back into Commerce.

Craft CMS 5.3+ · Craft Commerce 5.0+ · PHP 8.2+ · **$99**, one perpetual licence per site.

---

## What it does

- **Orders become documents.** A completed Commerce order becomes a bexio invoice — or a bexio
  order, if you invoice later — with the line items, shipping, discounts and any custom adjustments
  as separate positions, priced in the order's own currency.
- **Customers become contacts.** Matched on email against Bexy's own map first, then bexio's
  contact list, and only created when neither has one. Companies file as companies, people as
  people, with the surname in `name_1` the way bexio files people.
- **Payments get posted.** Every successful Commerce charge becomes a payment against the invoice,
  so bexio does not leave paid orders sitting open for someone to reconcile by hand.
- **bexio answers back.** `craft bexy/reconcile/run` asks bexio what has been paid and moves the
  Commerce order to the status you mapped, so the shop and the books agree without anyone checking
  two screens.
- **Totals are checked before they are sent.** Bexy computes what bexio will make of the positions
  and compares it to Commerce's total. A few rappen out gets an explicit rounding line; more than
  that gets refused and explained, because a difference that size is a wrong tax mapping and
  closing it would hide the problem until a VAT return.
- **Everything is logged.** Method, endpoint, status, timing and both bodies, with secrets redacted.
- **Tokens are stored encrypted.** A bexio refresh token is a standing key to the company's whole
  accounting system; a database dump should not be one too.

## Why not the other one

`thekitchen.agency/craft-bexio-synchronizer` costs $199 plus $99 a year and covers the push. Bexy
is $99 once, and the difference is what happens after the push:

| | Bexio Synchronizer | Bexy |
|---|---|---|
| Authentication | personal access token only | OAuth 2.0 **and** personal access token |
| Connection lifetime | bexio expires every PAT after 60 days | refresh tokens; the PAT trade-off is spelled out in settings |
| Payments | not posted — invoices stay open in bexio | Commerce charges posted as invoice payments |
| Direction | push only | push **and** pull, bexio status → Commerce order status |
| Totals | bexio's arithmetic is taken on trust | computed and compared before the request goes out |
| Unknown adjusters | undocumented | any adjustment that is not tax/shipping/discount gets its own position |
| Preview | none | the exact JSON body, from the same builder that sends it |
| Refunds | undocumented | cancelled where bexio allows it, flagged where it does not |
| Log | sync status | full request/response per call |
| Console | none | `sync`, `reconcile`, `preview`, `meta`, `doctor` |

## Setting up

1. Install: `composer require justinholtweb/craft-bexy` then `php craft plugin/install bexy`.
2. **Connect.** At [developer.bexio.com](https://developer.bexio.com), create an app, copy its
   client ID and secret into Bexy's settings, and paste Bexy's **Redirect URL** into the app's
   allowed redirect URLs. Save, then press **Connect to bexio**.
   *Or* paste a [personal access token](https://developer.bexio.com/pat) instead — quicker, and it
   stops working 60 days later without warning, so put a reminder in the diary.
3. **Pick the defaults.** bexio user, revenue account, sales tax, unit, bank account. The lists
   fill in from bexio once you are connected; `craft bexy/meta/taxes` prints the same thing.
4. **Map the tax.** Each Commerce tax category to a bexio sales tax and revenue account. Anything
   unmapped falls back to the defaults.
5. **Check it.** `craft bexy/doctor` walks the whole setup and names what is missing.

Then complete a test order, or push an existing one from its edit screen.

## How a push works

Order completion writes a pending row and queues a job. Nothing on the request touches bexio — a
bexio outage, an expired token or a rate limit can delay a document, but can never stand between a
customer and their payment confirmation.

The job resolves the contact, builds the body, and **searches bexio for the order's
`api_reference` before creating anything**. bexio has no idempotency key, but `api_reference` is a
free field only the API can read or write, and `/search` matches on it. So a retry after a timeout
that actually went through adopts the existing document instead of invoicing the customer twice.

## Tax

`mwst_type` tells bexio how to read the prices you send: `0` they already include tax, `1` add it
on top, `2` there is none. Left on **From Commerce**, Bexy reads it off the order — a Commerce tax
rate flagged "included in price" makes every price gross.

Only *active sales taxes* may appear on a bexio document; anything else is rejected at push time.
The tax picker only offers those, and `craft bexy/doctor` checks the one you chose is still among
them.

## Refunds

bexio's API can raise an invoice and take a payment against it. It **cannot raise a credit note** —
the only credit-voucher endpoint it exposes fetches a PDF of one that already exists.

So Bexy does not invent a negative payment, which would balance the invoice and leave the VAT
wrong. A full refund against an invoice nothing has been paid on is cancelled, if you have asked
for that. Anything else is marked as needing a credit voucher in bexio and shown on the Documents
screen, so it is a task rather than a silent discrepancy.

## Console

```sh
craft bexy/doctor                  # every prerequisite, checked and explained
craft bexy/sync/order 1234         # push one order
craft bexy/sync/preview 1234       # the exact JSON, and what it comes to, without sending
craft bexy/sync/pending            # push everything waiting, retry what failed
craft bexy/sync/status             # counts by state
craft bexy/reconcile/run           # ask bexio what has been paid  ← put this on cron
craft bexy/meta/taxes              # bexio's tax IDs (also: accounts, users, currencies,
                                   # units, languages, payment-types, bank-accounts)
craft bexy/meta/flush              # drop the cached lookups
```

## Twig

```twig
{% set document = craft.bexy.documentForOrder(order) %}

{% if craft.bexy.isSynced(order) %}
    Invoice {{ craft.bexy.documentNumber(order) }}
    {% if document.getIsPaidInBexio() %}— paid{% endif %}
{% endif %}
```

`craft.bexy` is read-only: a template can show a customer their invoice status, and cannot raise
one.

## Permissions

- **View bexio documents** — the Documents screen and the order panel
  - **Push orders to bexio** — the "Send to bexio" button
  - **Cancel bexio documents** — cancelling, and forgetting Bexy's record
- **View the connection log**

## What Bexy does not do

- It does not raise credit notes, because bexio's API cannot (see **Refunds**).
- It does not create Commerce transactions when bexio reports an invoice paid. A transaction is a
  record of money moving through a gateway; fabricating one puts a payment in the shop's books that
  no gateway processed. Bexy changes the order status instead.
- It does not delete anything in bexio beyond an explicit cancellation. A bexio document is an
  accounting record and not the shop's to remove.
- It does not sync your catalogue into bexio. It will link line items to bexio items by SKU, and
  create the missing ones if you ask, but it is not a product manager.

## Licence

Proprietary. One Craft installation per licence — see `LICENSE.md`.
