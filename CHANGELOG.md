# Changelog

## 5.0.0 — 2026-08-20

Initial release.

- Completed Commerce orders become bexio invoices or orders, on the queue, so bexio can never hold
  up a checkout.
- OAuth 2.0 authorization code flow with PKCE and refresh tokens, plus personal access tokens.
- Contacts matched on email against Bexy's map and then bexio's list before anything is created;
  companies and people filed the way bexio files them, with the street split into name and number
  now that bexio has deprecated the combined address field.
- Line items, shipping, discounts and any third-party adjustment become their own positions.
  Optional SKU matching to bexio items.
- Tax mapping per Commerce tax category, with `mwst_type` read off the order by default.
- The document total is computed and compared to Commerce's before the request goes out. A
  cent-sized difference gets an explicit rounding position; a larger one is refused and explained.
- Idempotent pushes via `api_reference`, searched before anything is created.
- Successful Commerce charges posted as invoice payments, once each.
- Reconciliation pulls bexio's status back and moves the Commerce order status to match.
- Refunds cancelled where bexio permits it, flagged for a credit voucher where it does not.
- OAuth tokens stored encrypted with Craft's security key, base64'd so the ciphertext survives a
  utf8mb4 text column.
- Connection log with both bodies and redacted secrets; retention pruning.
- Console commands: `doctor`, `sync/order`, `sync/preview`, `sync/pending`, `sync/status`,
  `reconcile/run`, `meta/*`.
- 139 integration checks.
