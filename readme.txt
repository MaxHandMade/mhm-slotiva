=== MHM Slotiva ===
Contributors: maxhandmade
Tags: woocommerce, appointments, booking, scheduling, time slots
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 11.2
WC tested up to: 11.3
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Service appointment booking for WooCommerce: customers pick a day and time on the Checkout block and pay in the same order.

== Description ==

MHM Slotiva turns a WooCommerce product into a bookable service. The customer chooses a day and time inside the
Checkout block and pays for the order in one step. The time slot is held while the payment is pending, so two
customers can never book the same time.

= For customers =

* Day strip and time grid inside the WooCommerce Checkout block, on desktop and mobile.
* One time or several times (also on different days) per order. For per-slot pricing the cart quantity follows the
  number of chosen times.
* Clear messages when a time was just taken, when no time is free in the booking window, or while times are loading.
* Appointment card on the thank-you page and in My account, with a calendar file (.ics) and a Google Calendar link.
* Emails: booking confirmed, reminder before the appointment, time changed, booking cancelled. Each email carries a
  calendar file.

= For the shop =

* Appointments list with Today / Upcoming / Past / Cancelled tabs, payment badges and CSV export that opens correctly in
  Excel with Turkish settings (UTF-8 BOM, `;` separator, formula-safe cells).
* Appointment detail: change the time (the new time is locked before the old one is released), cancel, mark completed or
  no-show, extend a bank-transfer payment window, full history.
* Add appointments by hand (phone or walk-in bookings).
* Working hours per weekday with several ranges, closed days and extra opening hours, booking rules (length, earliest and
  latest booking, break between appointments, payment hold times, reminder time).
* Per-product settings on the product's Slotiva tab: slot length, minimum and maximum number of slots, per-slot or fixed
  pricing, location, policy text.

= Payments =

Slotiva works with any payment method that supports the WooCommerce Checkout block.

* Card payments (any card gateway that supports the Checkout block): the time is held for the payment window; a failed payment keeps the hold so the
  customer can retry from the order-pay page. Card orders are never cancelled automatically.
* Bank transfer: the time is held until the transfer deadline; when it passes, the order is cancelled and the time opens
  again.
* Pay on site (cash on delivery) and free orders: the appointment is confirmed at once.
* Late payments are handled safely: if the time is still free it is booked again, otherwise the shop gets a conflict email.

= Privacy =

* WordPress personal data export and erasure include appointments and their history.
* Personal details of past appointments are anonymised after a retention period you choose (default 24 months).
* Deleting the plugin keeps your data unless you turn on "Delete all Slotiva data when the plugin is deleted".

== Requirements ==

* WordPress 7.0 or later, PHP 8.1 or later.
* WooCommerce 11.2 or later, with the Checkout block on the checkout page (the classic checkout shortcode cannot sell
  bookable services; the plugin shows a notice).
* MySQL or MariaDB with InnoDB.

== Installation ==

1. Install and activate WooCommerce, then install and activate MHM Slotiva from Plugins → Add Plugin.
2. The setup wizard opens after activation and walks you through your working hours, your bookable product and your checkout page. (You can skip it and run it again later from Slotiva → Settings.)
3. Fine-tune working hours and booking rules in Slotiva → Working hours.
4. To make another product bookable, tick "Bookable product" on its Slotiva tab and set the slot length.
5. Make sure the checkout page uses the Checkout block.

== Screenshots ==

1. The time picker at checkout: the customer picks a day, then one or more free times, and sees the total.
2. The Appointments list in the admin, with today, this week, awaiting payment and pay-on-site counts.
3. An appointment's details, history and status actions, with a grid to move it to another time.
4. Working hours: a weekly schedule with a lunch break, closed days and extra open hours, and booking rules.
5. The setup wizard asks when you work and shows what customers will see at checkout.
6. Settings: your bookable products, the cancellation policy and how long personal details are kept.

== External services ==

MHM Slotiva makes no server-side calls to external services. Nothing is sent from your server to a third party, and the plugin loads no remote scripts, fonts or images.

One link goes to a third party, and only when a customer clicks it: the "Google Calendar" button on the appointment card (thank-you page and My account). It opens https://calendar.google.com/calendar/render in a new tab, with the appointment in the address so Google can pre-fill an event:

* the service name (event title),
* the start and end time of the appointment,
* the site name (event details),
* the appointment location, if you set one on the product.

No customer name, email address or phone number is part of the link. Nothing is sent until the customer clicks, and the customer then decides in Google Calendar whether to save the event. This is a service of Google LLC: [Terms of Service](https://policies.google.com/terms) · [Privacy Policy](https://policies.google.com/privacy).

The calendar file (.ics) in the emails and on the appointment card is created on your own site and is not sent to any service.

== Languages ==

English source strings with a complete Turkish (tr_TR) translation included. If a WordPress.org language pack exists for your language it is used first; the bundled Turkish fills any text the pack lacks. Dates, times and prices follow the
WordPress and WooCommerce settings.

== For developers ==

Slotiva offers general extension points: PHP actions and filters (for example after a booking is saved, on the product tab, for the card hold time) and JavaScript filters for the time picker and the admin screens. Names, arguments and return values are listed in the hooks reference: https://github.com/MaxHandMade/mhm-slotiva/blob/main/docs/hooks.md

== Source code ==

The plugin ships compiled JavaScript and CSS. The full source, including the unminified React sources, is public at https://github.com/MaxHandMade/mhm-slotiva

To build the plugin from source, from a clean checkout run:

`composer install --no-dev && npm ci && npm run build`

== Changelog ==

= 1.1.0 - 2026-11-18 =
* New: first release on WordPress.org. Updates now come through WordPress.org; the separate update screen is gone.
* New: documented hooks and filters for developers (PHP and JavaScript), listed in the hooks reference.
* Changed: every code path that writes an appointment now takes the same booking lock, so two customers cannot take the same time at the same moment.
* Changed: a WordPress.org language pack is used first; the bundled Turkish translation fills the gaps.
* Changed: the setup wizard's final step counts its own steps.
* Changed: stricter escaping, nonce and capability checks across the admin screens.
* Changed: an appointment can no longer be marked completed or no-show before it starts (the admin status route answers 409).

Earlier versions were distributed privately.

== Upgrade Notice ==

= 1.1.0 =
First WordPress.org release. Your appointments and settings are kept.
