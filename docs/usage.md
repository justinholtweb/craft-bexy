---
title: Usage
slug: usage
order: 30
summary: The order panel, the Documents screen, the log, and every console command.
---

## What happens when an order completes

1. Commerce marks the order complete.
2. Bexy queues a job. **The push never runs inline**, so a bexio outage cannot stop a customer
   paying.
3. The job resolves a contact, builds the document, checks the total against Commerce's, and
   creates it in bexio.
4. If configured, the invoice is issued and emailed.
5. Successful Commerce charges are posted as payments against it.
6. `bexy/reconcile/run` later pulls bexio's status back and moves the Commerce order to match.

Every step is written to the connection log, with both bodies, secrets redacted.

## The order panel

Commerce's order edit screen gains a **bexio** panel showing the document, its number, whether it
was issued and emailed, the order total against the document total, the `api_reference`, attempts,
and when it was last synced and reconciled.

From there you can **push**, **push again**, **refresh from bexio**, **view the PDF**, **cancel in
bexio**, and **forget** Bexy's record of the order. Forget removes the local row only; the bexio
document is left alone.

## Documents

**Bexy → Documents** lists every order Bexy knows about, filterable by status and searchable by
order or bexio number. *Needs attention* is the one to watch: a failed push, a total mismatch, a
refund that needs a credit voucher.

Opening a document shows what was sent, what came back, the payments posted against it, and the
last message from bexio.

## Log

**Bexy → Log** is every HTTP request the plugin has made, with the action, the endpoint, the
status, and the request and response bodies. Filter by action and by level.

Tokens, secrets and access codes are redacted on the way in, so the log is safe to read and safe
to paste into a support email.

## Console commands

```sh
php craft bexy/doctor              # connection, tokens, lookups, mapping, likely problems
```

### Syncing

```sh
php craft bexy/sync/preview 1234   # print exactly what would be sent; sends nothing
php craft bexy/sync/order 1234     # push one order
php craft bexy/sync/pending        # push everything not yet in bexio
php craft bexy/sync/status         # counts by state
```

`sync/preview` goes through the same builder as the real push, so a preview is byte-identical to
what bexio receives. Use it before a first live push, and to diagnose a total mismatch.

### Reconciling

```sh
php craft bexy/reconcile/run
```

Put this on a schedule — hourly is plenty:

```
0 * * * * cd /path/to/craft && php craft bexy/reconcile/run >> /dev/null 2>&1
```

### Lookups

```sh
php craft bexy/meta/taxes           # only active sales taxes
php craft bexy/meta/accounts
php craft bexy/meta/users
php craft bexy/meta/currencies
php craft bexy/meta/units
php craft bexy/meta/languages
php craft bexy/meta/payment-types
php craft bexy/meta/bank-accounts
php craft bexy/meta/flush           # drop the cached lists and re-fetch
```

## Twig

```twig
{% set doc = craft.bexy.document(order) %}
{% if doc %}
    {{ doc.bexioNumber }} — {{ doc.status }}
{% endif %}
```

`craft.bexy.document()` returns `null` for an order Bexy has never seen, and handles a null order
without complaining.

## Idempotency

Bexy writes `api_reference` on every document and searches for it before creating anything. On top
of that, `bexy_documents` is unique on `orderId` and `bexy_payments` is unique on `transactionId`.

The practical effect: pushing the same order twice adopts the existing bexio document rather than
creating a second one, and the same Commerce charge is never posted as two payments. If a document
was created in bexio before Bexy knew about it, the adopt path picks it up and says so.

## Refunds

- A **full refund** with nothing paid against the invoice in bexio cancels the invoice, if you
  have that switched on.
- Anything else is **flagged for a credit voucher**. bexio's API has no credit-note create
  endpoint, so this needs a human in bexio.

Bexy never posts a negative payment to paper over the gap.
