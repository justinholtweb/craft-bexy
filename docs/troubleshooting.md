---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What each failure actually means, and the fix.
---

Start here:

```sh
php craft bexy/doctor
```

Then **Bexy → Log**, filtered to *Error*. Both bodies are there, with secrets redacted.

## Connection

### "bexio refused the credentials. Reconnect, or issue a new access token."

A 401. On a personal access token this almost always means the 60 days ran out — bexio expires
them silently. Issue a new one, or switch to OAuth.

On OAuth it means the refresh failed. Reconnect.

### The OAuth connection dies after an hour

`offline_access` was missing from the scopes, so bexio never issued a refresh token. Add it to the
app in bexio and connect again.

### "The authorization state did not match. Start the connection again."

The callback did not come back to the session that started it. Usually a stale tab, a different
browser, or a redirect URL that does not match the one registered in bexio exactly.

### "bexio sent no authorization code back."

The consent screen was cancelled, or the redirect URL in bexio points somewhere other than the one
Bexy shows in the settings screen.

### Refresh worked once and then stopped

bexio **rotates** refresh tokens: each refresh returns a new one and invalidates the old. If two
processes refresh at the same time, one of them ends up holding a dead token. Reconnect. Bexy
stores the rotated token every time, so this only bites if something outside Bexy is also
refreshing.

## Documents

### "The document total (…) does not match the order total (…)"

The difference is inside the tolerance but the rounding position is switched off, so Bexy reported
it rather than closing it. Either switch **Close a mismatch with a rounding position** on, or fix
the cause.

### "That is too large to be rounding: check the tax mapping…"

The gap exceeded **Largest difference to close**, so Bexy refused to adjust. This is working as
intended. A difference of that size is one of:

- a Commerce tax category with no bexio tax mapped, so bexio applied the document default
- the wrong **How prices are sent** — gross prices sent as net, or the reverse
- a third-party adjustment that is not what you assumed it was

`php craft bexy/sync/preview <orderId>` prints the positions and the arithmetic. The wrong line is
usually obvious.

Raising the limit to make the message go away closes a real accounting error with a line called
"Rounding". Don't.

### "No bexio contact was resolved for this order."

bexio rejects a document with no contact. Either **Create contacts** is off and no match was
found, or contact creation failed — the log entry above this one says why. The usual cause is no
**bexio user** selected in Bexy's settings; bexio requires `user_id` *and* `owner_id` on every
contact.

### "No bexio tax is mapped for …"

A warning, not a failure. The position was booked at the document default, which may be the wrong
rate. Add the mapping in **Tax and account mapping** and re-push.

### "bexio has no currency called …"

The order's currency is not set up in the bexio company. The document was booked in the company
default currency, which means the figures are wrong. Add the currency in bexio.

### "bexio accepted the document but returned no ID."

Rare. The document usually does exist — check bexio, and use **Refresh from bexio** on the order
panel, or push again and let the `api_reference` search adopt it.

### The same order appears twice in bexio

It shouldn't. `bexy_documents` is unique on `orderId` and Bexy searches `api_reference` before
creating. A duplicate means the second one was created outside Bexy. Cancel it there, then
**Refresh from bexio**.

## Payments

### An invoice sits open in bexio although Commerce says paid

- **Post payments to bexio** is off, or
- the document type is *Order* — payments cannot be posted against a bexio order, or
- the invoice was never issued, so there is nothing to pay against.

### "Payment of … could not be posted to bexio"

Usually a missing **Payments bank account**, or the invoice is in a state bexio will not accept a
payment against. The response body in the log names the field.

## Reconciliation

### Statuses never come back

`bexy/reconcile/run` is not running. It is a scheduled command; nothing calls it for you.

Check **Only check documents from the last** too — a document older than that window is skipped.

### "Could not move order … to …"

The Commerce status handle in **Status mapping** no longer exists, or a Commerce event refused the
change. Re-pick the status.

## Refunds

### "…this needs a credit voucher in bexio."

Expected. bexio's API cannot raise a credit note, so a refund that cannot be handled by cancelling
the invoice is flagged for a human. Raise the credit voucher in bexio.

### "…the bexio invoice could not be cancelled"

bexio will not cancel an invoice that has been paid against. Raise a credit voucher instead.

## Rate limits

### "bexio's rate limit was hit and stayed hit."

bexio limits per company per minute. Bexy honours the `RateLimit-Reset` header, waiting up to 3
seconds in a web request and up to 60 in console and queue contexts. If it still gives up,
something else is also hammering the same company. Retry the queue job.

## Nothing is happening at all

- Is Craft's queue running? The push is always a queue job.
- Is **Send orders automatically** on?
- Is **Only when the order reaches** set to a status the order never reaches?
- `php craft bexy/sync/status` shows the counts. `php craft bexy/sync/pending` pushes the backlog.
