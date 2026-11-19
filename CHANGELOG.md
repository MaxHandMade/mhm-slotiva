# Changelog

All notable changes to MHM Slotiva. Versions follow [Semantic Versioning](https://semver.org/).

## 1.1.0 — 2026-11-18

First release on WordPress.org. Earlier versions were distributed privately.

- New: first release on WordPress.org. Updates now come through WordPress.org; the separate update screen is gone.
- New: documented hooks and filters for developers (PHP and JavaScript), listed in the hooks reference.
- Changed: every code path that writes an appointment now takes the same booking lock, so two customers cannot take
  the same time at the same moment.
- Changed: a WordPress.org language pack is used first; the bundled Turkish translation fills the gaps.
- Changed: the setup wizard's final step counts its own steps.
- Changed: stricter escaping, nonce and capability checks across the admin screens.
- Changed: an appointment can no longer be marked completed or no-show before it starts (the admin status route
  answers 409).

## 1.0.3 — 2026-11-16

### Fixed
- Running the setup wizard again no longer resets an existing bookable product's own length, minimum hours or pricing.
  Step 3 says when a product keeps its own length; the preview and the summary show that length.
- The default service type shows on the wizard summary even when no card was clicked.
- Appointment length is limited to 480 minutes on every save path (settings, product tab, wizard); the wizard rejects
  prices above 10^12 for simple products. An old out-of-range length no longer blocks saving other settings.
- An activation that cannot start the setup wizard (WP-CLI, network-wide, no user) clears an old redirect flag.

### Changed
- Requires `mhm/ui-core` 0.23.
- Update hint names the WordPress 7.1 menu: Plugins → Add Plugin → Upload Plugin.
- Settings → Bookable products says "Per slot", like the product tab; the payment check says where to turn a method on.
- Turkish admin texts use the informal voice used elsewhere in the plugin.

### Added
- Help links to the documentation site: wizard summary ("First-time setup guide") and checkout-check fixes. Turkish
  admins get the Turkish pages.

## 1.0.2 — 2026-11-16

### Added
- A setup wizard that opens after activation: service type and appointment length, working hours, the bookable product,
  a checkout page check, and a summary. It can be skipped and run again from Slotiva → Settings.
- A setup checklist on the Appointments page that shows what is still missing and hides itself once setup is complete.
- A "Bookable products" card in Slotiva → Settings and help text on the product fields.
- Turkish translations for all new texts.

### Changed

### Fixed
- The selected day and time are readable on themes that have no colour palette.
- Pressing Place order without choosing a time now shows the error, scrolls to the time picker and focuses it.
- Links that open in a new tab (Google Calendar on the appointment card, the update notice) now say so to screen
  readers.

## 1.0.1 — 2026-11-11

### Fixed
- Cancelling a booking in conflict (paid, its time taken by another booking) no longer says the time becomes free:
  the appointment detail confirmation and the order note now say it held no time.
- The customer's cancellation email no longer says the time slot was released; it says the booking has been cancelled
  (a bank-deadline cancellation keeps its own wording).
- `readme.txt` has its own changelog section instead of pointing to `CHANGELOG.md`, which is not shipped.
- Version header and tag now agree (1.0.1). Sites on 1.0.0-rc1 (header 1.0.0) see it as newer.

## 1.0.0-rc1 — 2026-11-10

First release candidate.

### Booking at checkout
- Day strip and time grid inside the WooCommerce Checkout block (desktop and 390 px mobile), single or multiple times
  per order, also across days.
- Per-slot pricing keeps the cart quantity equal to the number of chosen times; fixed pricing always has quantity 1.
- The server validates every request: booking window, 15-minute grid, opening hours, overlaps, duplicates, quantity,
  and the slot length always comes from the product, never from the browser.
- Two customers can never hold the same time: unit-level unique keys, named order/session/IP locks and row locks.
  The loser gets a 409 with the taken time and a refreshed list; the customer's details are kept.
- Hold quota: at most two orders awaiting payment per WooCommerce session and per IP address (429 above that).
- Classic checkout cannot sell bookable services (rejected before an order exists); shop managers see a notice.

### Payments and lifecycle
- Card payments hold the time for a configurable window (default 45 minutes, follows the PayTR timeout); a failed payment
  keeps the hold and the order-pay page can retry. Card orders are never cancelled automatically.
- Bank transfer holds until the deadline (default 24 hours, can be extended by the shop); then the order is cancelled
  and the time released.
- Pay on site and free orders confirm at once.
- Late payment after expiry rebooks the time when free, otherwise marks a conflict and emails the shop. Payments for
  superseded or admin-cancelled bookings never rebook silently.
- Full refund cancels the booking with a single cancellation email per order; partial refund changes nothing.
- Trashing or deleting an order releases its times (restoring the order does not bring the booking back).
- The order-pay page follows the shop's changes: a time the shop moved stays moved, a booking the shop cancelled is
  not taken again. Unexpected errors on the payment path show a neutral message, never internal details.
- Scheduled jobs carry the expected sequence and hold end, so stale jobs do nothing.

### Notifications
- WooCommerce emails: new booking (shop), booking conflict (shop), booking confirmed, reminder, time changed, booking
  cancelled (customer). Overridable templates, HTML and plain text.
- Calendar files (.ics) with stable UID and increasing SEQUENCE, including cancellations; Google Calendar link.
- Appointment card on the thank-you page and in My account; a paid booking in conflict shows a neutral note.

### Admin
- Slotiva menu: Appointments (tabs, stats, CSV export for Turkish Excel), appointment detail (reschedule with
  lock-before-release, cancel, completed, no-show, extend bank hold, history), manual appointments. Bookings in
  conflict can be given a new time or cancelled. CSV keeps the leading 0 of phone numbers.
- Working hours with several ranges per day, closed days and extra hours, booking rules, settings (policy text,
  retention, uninstall behaviour, email settings link). Only administrators can turn on data deletion at uninstall.
- Uninstall removes Slotiva's scheduled jobs; with data deletion on, it also removes product settings and email
  settings.
- Product tab: bookable, slot length, minimum/maximum slots, pricing, location, earliest/latest booking, policy.

### Privacy
- Personal data exporter and eraser for appointments and their history.
- Automatic anonymisation after the retention period (default 24 months).

### Translations
- English source strings; complete Turkish (tr_TR) translation: `.po`, `.mo`, `.l10n.php` and JavaScript JSON files.

### Requirements
- WordPress 7.0+, PHP 8.1+, WooCommerce 11.2+ with the Checkout block, InnoDB.
