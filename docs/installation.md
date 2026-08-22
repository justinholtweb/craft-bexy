---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and connecting Bexy to your bexio company.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A bexio account on a plan that includes API access

## Install

```sh
composer require justinholtweb/craft-bexy
php craft plugin/install bexy
```

Or find **Bexy** in the Craft Plugin Store and install it from there.

Bexy is a single paid edition, $99 one-off. There is no free tier and nothing is gated — the
plugin you install is the whole plugin.

## Nothing is sent until you connect

Installing Bexy does not touch bexio and does not touch your orders. Until you have connected a
bexio company and saved the settings, every completed order behaves exactly as it did before.

## Connect to bexio

Bexy speaks to bexio two ways. **Use OAuth unless you have a reason not to.**

### OAuth 2.0 (recommended)

bexio's personal access tokens expire 60 days after they are created, silently. OAuth refresh
tokens rotate and keep the connection alive indefinitely, which is why this is the default.

1. Sign in at [developer.bexio.com](https://developer.bexio.com) and create an app.
2. Copy the **Redirect URL** shown in **Bexy → Settings → Connection** into the app's
   *Allowed redirect URL* field in bexio. It has to match exactly.
3. Request these scopes. Read access is implied by write access, so asking for `contact_show`
   alongside `contact_edit` only makes the consent screen longer:

   ```
   openid profile offline_access
   contact_edit kb_invoice_edit kb_order_edit article_edit
   accounting monitoring_show
   ```

4. Paste the **Client ID** and **Client secret** into Bexy and save. Store the secret in an
   environment variable rather than in project config — project config is committed.
5. Click **Connect to bexio** and approve the consent screen.

`offline_access` is the one people forget. Without it bexio issues no refresh token and the
connection dies with the first access token.

### Personal access token

One paste, works immediately, and stops working 60 days later without warning. Reasonable for a
trial company; not something to run a shop on.

Switch **Authentication** to *Personal access token*, paste the token, save.

## Check it

```sh
php craft bexy/doctor
```

`doctor` reports the connection, the token's remaining life, the bexio lookup tables it can read,
your tax mapping and anything it thinks will fail before an order does. Run it after any settings
change.

## Fill in the bexio defaults

Once connected, click **Refresh lists from bexio** in the settings screen. The user, account, tax,
unit, bank account, payment type and language dropdowns fill in from your company.

At minimum, set:

- **bexio user** — bexio requires one on every contact and document it files
- **Default revenue account**
- **Default sales tax** — only *active sales taxes* appear here, because bexio rejects any other
  kind on a document

Then map your Commerce tax categories in **Tax and account mapping**. See
[Configuration](configuration).

## Your first order

1. Complete a test order in Commerce.
2. Open it. The **bexio** panel on the order's edit screen shows what happened.
3. Or push one by hand:

   ```sh
   php craft bexy/sync/preview 1234   # exactly what would be sent, sends nothing
   php craft bexy/sync/order 1234     # actually send it
   ```

`sync/preview` builds the document body through the same code path as the real push, so what it
prints is byte-for-byte what bexio would receive.
