# MHM Slotiva hooks reference

MHM Slotiva exposes a small set of WordPress actions and filters so other plugins can read booking data, add fields to the product tab and adjust a few values. Names, argument lists and return types listed here are a stable developer API.

## Hooks fired by Slotiva

One row per hook. `Since` is the first plugin version that fires the hook.

| Hook | Type | Arguments | Returns | Fires | Since |
| --- | --- | --- | --- | --- | --- |
| `slotiva_product_capacity` | filter | `int $capacity`, `int $product_id` | `int` | When the number of seats per time for a product is read. The default is `1`; Lite clamps the returned value to 1-50. | 1.1.0 |
| `slotiva_availability_slot` | filter | `array $slot`, `int $seats_left`, `int $capacity`, `int $product_id` | `array` | For every time returned by the availability REST route. `$slot` holds `start` and `label`; extra keys you add are returned to the browser. A non-array return value is ignored and the original `$slot` is used. | 1.1.0 |
| `slotiva_product_tab_fields` | action | `int $product_id` | none | In the Slotiva product data panel, right after the built-in fields; print extra fields here. | 1.1.0 |
| `slotiva_product_tab_save` | action | `int $product_id` | none | When the product is saved, after Lite's capability check (`edit_product` on this product) and its check of WooCommerce's product-save nonce (`woocommerce_meta_nonce`, action `woocommerce_save_data`), and after the built-in fields were handled. It does not fire when either check fails. Save extra fields here. | 1.1.0 |
| `slotiva_after_booking_saved` | action | `int $reservation_id`, `string $context` | none | After the database transaction has committed, once for each reservation that was written. It never fires for a rolled-back change. `$context` is one of `claim`, `replace`, `reschedule`, `reclaim`, `status` (see below). | 1.1.0 |
| `slotiva_reservation_status` | action | `int $reservation_id`, `string $from`, `string $to`, `array $context` | none | After commit, when a reservation changes status (`$from` is an empty string for a new reservation). `$context` holds `notify` (bool), `reason` (string), `order_id` (int or null). | 1.0.0 |
| `slotiva_reservation_rescheduled` | action | `int $reservation_id`, `\DateTimeImmutable $previous_start`, `bool $notify` | none | After commit, when a reservation was moved to another time. | 1.0.0 |
| `slotiva_hold_refreshed` | action | `int $order_id`, `\DateTimeImmutable $expires_at`, `list<int> $reservation_ids` | none | After commit, when the hold of an unpaid order was extended. | 1.0.0 |
| `slotiva_conflict` | action | `int $order_id`, `list<int> $reservation_ids`, `string $reason` | none | When a paid order could not get its time. `$reason` is `late_paid_taken`, `superseded_paid`, `admin_cancelled_paid` or `superseded_admin_cancelled_paid`. | 1.0.0 |
| `slotiva_schedule_changed` | action | none | none | After the weekly hours, the date exceptions, the booking rules or a product's schedule settings changed. | 1.0.0 |
| `slotiva_admin_page_data` | filter | `array $data`, `string $bundle` | `array` | Before the data for an admin screen is printed as `window.slotivaAdmin`. `$bundle` is the screen name. | 1.0.0 |
| `slotiva_outside_hours_batch` | filter | `int $batch` | `int` | Page size (default `500`) used when the schedule screen counts bookings outside working hours. | 1.0.0 |
| `slotiva_contact_url` | filter | `string $url` | `string` | Link shown when the booking horizon has no free times. Default is a `mailto:` link to the WooCommerce sender address, or an empty string. | 1.0.0 |
| `slotiva_card_hold_minutes` | filter | `int $minutes` | `int` | Minutes a card payment holds its time (minimum 15). | 1.0.0 |
| `slotiva_transfer_methods` | filter | `list<string> $gateway_ids` | `list<string>` | Payment gateway ids treated as bank-transfer style (on-hold with a deadline). | 1.0.0 |
| `slotiva_lock_timeout` | filter | `int $seconds` | `int` | Seconds to wait for a booking lock (default `10`; clamped to 0-60). | 1.0.0 |

### `slotiva_after_booking_saved` context values

| Context | Written by |
| --- | --- |
| `claim` | A new reservation was created (first booking, or the new reservations that replace an earlier attempt of the same order). |
| `replace` | An earlier reservation was superseded by a new booking of the same order or of the same browser session. |
| `reschedule` | A reservation was moved to another time. |
| `reclaim` | An expired or conflicting reservation took its time back. |
| `status` | Any other status or payment-state change (cancel, complete, expire, release, manual change). It also fires, once, for a write that leaves the status unchanged (for example a payment-state update on a confirmed reservation). In that case no `slotiva_reservation_status` fires and no history entry is added, so do not treat this context as proof that the status changed: compare with the stored status yourself. |

Extending a hold (`slotiva_hold_refreshed`) does not fire `slotiva_after_booking_saved`.

`slotiva_product_tab_save` also fires when the built-in fields were rejected by validation or the product is not bookable, so extra fields are saved independently of them.

## Actions Slotiva listens to

| Hook | Type | Arguments | Effect | Since |
| --- | --- | --- | --- | --- |
| `slotiva_flush_availability` | action | none | Invalidates the cached availability. Call it after you change a value that `slotiva_product_capacity` returns. | 1.1.0 |

## JavaScript filters

The slot picker (checkout block) and the admin screens apply four filters with `wp.hooks.applyFilters`. Add yours with `wp.hooks.addFilter` in a script that depends on `wp-hooks` and is loaded before Slotiva's script runs on that page. With no callback registered, the screens render exactly as without the filters.

| Filter | Arguments | Expected return | Renders | Since |
| --- | --- | --- | --- | --- |
| `slotiva.picker.slotMeta` | `null $meta`, `{ start: string, label: string } $slot` | `string` | Small text under the label inside each time button. | 1.1.0 |
| `slotiva.picker.selectionLine` | `null $line`, `{ start: string, label: string, long: string, range: string }[] $selection` | `string` | One extra paragraph under the chosen time (single choice) or at the end of the selection summary (several times). Called also when nothing is chosen (`$selection` is empty). | 1.1.0 |
| `slotiva.admin.listColumns` | `[] $columns` | `{ id: string, label: string, render: ( appointment ) => string or ReactElement }[]` | One extra column per entry in the appointments table, placed before the actions column. `render` gets the appointment object of the row and returns the cell content: a string (shown as text) or a React element (shown as is). | 1.1.0 |
| `slotiva.admin.detailPanels` | `[] $panels`, `object $appointment` | `{ id: string, title: string, content: string or ReactElement }[]` | One extra sub-heading per entry on the appointment detail screen, after the history. `title` is text. `content` is a string (shown as a paragraph of text) or a React element (shown as is under the heading, for example a table). | 1.1.0 |

Rules that apply to every filter:

- The two picker filters accept strings only. The two admin filters accept a string or a React element (`wp.element.isValidElement`) for the cell or panel content. Strings are shown as plain text; markup in a string is escaped, never interpreted. Slotiva never inserts HTML strings.
- Anything that does not match the expected return is ignored: a picker meta or line that is not a string, content or a cell value that is neither a string nor a React element (numbers, plain objects, functions, arrays), a non-array list, entries that are not objects, entries without a non-blank string `id` and `label`/`title`, a column without a `render` function, a panel without valid `content`, and entries that repeat an earlier `id`. A column whose `render` throws, or returns something that is neither a string nor a React element, shows an empty cell. A callback that throws is ignored.
- `$appointment` and `$selection` are read-only views of the screen state; do not modify them.

```js
wp.hooks.addFilter( 'slotiva.admin.listColumns', 'my-plugin/columns', ( columns ) => [
	...columns,
	{ id: 'my-plugin-ref', label: 'Reference', render: ( appointment ) => `R-${ appointment.id }` },
] );
```
