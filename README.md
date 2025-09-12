# Cache Expiry (cacheexpiry)

Control **DokuWiki render cache** per page using simple `REFRESH` markers and optional **per-namespace defaults**. The plugin also **shows the next refresh time inline** (syntax-based rendering).

* Supported markers: 
    * `~~REFRESH-MINUTES(n)~~`
    * `~~REFRESH-HOURLY~~`
    * `~~REFRESH-DAILY~~`
    * `~~REFRESH-WEEKLY~~`


---

## Why use this?

* Keep “daily” pages fresh at midnight, “hourly” dashboards rolling on the hour, or journals refreshing every *n* minutes.
* Give readers a clear **“Next refresh: …”** timestamp right in the page.
* Use **namespace defaults** so you don’t have to tag every page.
* Works with your **server timezone** for predictable rollover.

---

## Installation

1. Upload or install the ZIP via **Extension Manager**.
2. Ensure the plugin directory is `lib/plugins/cacheexpiry/`.
3. (Optional) Clear caches or visit a page with `?purge=true` once.

---

## Quick start

Add one marker anywhere in a page’s wikitext:

```txt
~~REFRESH-DAILY~~
```

If the `plugin»cacheexpiry»show_next_refresh` is enabled, you’ll see something like on your page:

```
Next refresh: 2025-09-12 00:00 CDT
```

…and the page’s **render cache** will expire at that time.

---

## Markers

> If multiple markers appear on the same page, the **earliest** next boundary wins.

### Hourly

```txt
~~REFRESH-HOURLY~~
```

* Expires on the **next top of the hour** (e.g., 15:00 → 16:00).
* If `plugin»cacheexpiry»show_next_refresh` is enabled, inline shows that timestamp.

### Daily

```txt
~~REFRESH-DAILY~~
```

* Expires at **next midnight** in the server timezone.
* If `plugin»cacheexpiry»show_next_refresh` is enabled, inline shows that timestamp.

### Weekly

```txt
~~REFRESH-WEEKLY~~
```

Two modes (configured in admin):

* **Week-start mode (default):** expires at the next **week\_start weekday** at 00:00.
  Example with `week_start = 1 (Mon)`:

    * If it’s Thu 2025-09-11 15:00, next refresh is **Mon 2025-09-15 00:00**.
* **Same-weekday mode:** expires at the next **same weekday** at 00:00.
  Example:

    * If it’s Thu 2025-09-11 15:00, next refresh is **Thu 2025-09-18 00:00**.

### Every N minutes

```txt
~~REFRESH-MINUTES(5)~~
~~REFRESH-MINUTES(15)~~
~~REFRESH-MINUTES(1)~~
```

* Expires at the **next multiple of N minutes** for the current hour (and keeps stepping).
* Values are clamped to `1–1440`.
  Example for `~~REFRESH-MINUTES(15)~~` at 10:07 → next is **10:15**.
* If `plugin»cacheexpiry»show_next_refresh` is enabled, inline shows that timestamp.

---

## Namespace defaults (set-and-forget)

You can set namespaces that **implicitly** use a rule when a page has **no explicit marker**:

* `defaults_hourly_ns` — CSV list (e.g., `stats:,dash:`)
* `defaults_daily_ns` — CSV list (e.g., `journal:`)
* `defaults_weekly_ns` — CSV list (e.g., `reports:`)

**Notes**

* Namespace strings should end with `:` (the plugin will add it if missing).
* **Explicit page markers always win** over defaults.
* The plugin records found markers in page metadata for consistency.

---

## Admin settings (Config Manager → Plugins → cacheexpiry)

* **week\_start**
  First day of week for weekly expiry (0=Sun … 6=Sat). Used in “week-start mode”.

* **weekly\_same\_weekday** (on/off)
  If ON, `REFRESH-WEEKLY` uses **“same weekday at 00:00”** instead of week\_start.

* **min\_cache\_seconds**
  Minimum age safeguard to avoid hammering (default `60`). If a computed expiry is sooner than this, it’s clamped up to this minimum.

* **defaults\_hourly\_ns / defaults\_daily\_ns / defaults\_weekly\_ns**
  CSV namespaces for per-namespace defaults as described above.

* **show\_next\_refresh** (on/off)
  If ON, the **inline timestamp** is rendered via a **syntax plugin** (not a regex postprocess), so it’s robust across themes.

* **show\_next\_refresh\_template**
  Template for the inline text. Must include `%s`.
  Example: `Next refresh: %s`

* **show\_next\_refresh\_format**
  PHP `DateTime::format` string for the timestamp.
  Example: `Y-m-d H:i T` → `2025-09-11 16:10 CDT`

* **enable\_debug\_log** (on/off)
  If ON, writes detailed lines to DokuWiki’s debug log (or PHP error log if `dbglog()` isn’t available).

---

## Timezone behavior

* The plugin uses DokuWiki’s `$conf['timezone']` (Admin → Configuration → “Your timezone”).
* If unset or invalid, it falls back to PHP’s default timezone.

All boundaries (hourly/daily/weekly/minutes) are computed in **that** timezone.

---

## How it works (in short)

1. On render, the plugin **scans wikitext first** for markers, and stores them in metadata.
2. If none found, it applies **namespace defaults** (if configured).
3. It computes the **seconds until the next boundary** and sets the cache’s `depends['age']`.
4. A **syntax plugin** consumes the `~~REFRESH-…~~` token and renders the inline timestamp; it does **not** rely on fragile HTML post-processing.

---

## Examples

Daily journal page:

```txt
====== Thursday ======
~~REFRESH-DAILY~~
Notes...
```

Hourly dashboard:

```txt
===== Ops Dashboard =====
~~REFRESH-HOURLY~~
  * Queue depth …
  * Errors per minute …
```

Rapidly changing “now” page:

```txt
~~REFRESH-MINUTES(1)~~
* Last sync at {{date>Y-m-d H:i:s}}
```

Weekly with week-start Monday (default):

```txt
~~REFRESH-WEEKLY~~
* KPIs compiled weekly
```

Per-namespace defaults:

* In Config:

    * `defaults_daily_ns = journal:`
    * `defaults_hourly_ns = stats:,dash:`
* Behavior:

    * Any `journal:*` page with **no marker** acts as `DAILY`.
    * Any `stats:*` or `dash:*` page with **no marker** acts as `HOURLY`.
    * A page with an explicit marker (e.g., `REFRESH-MINUTES(5)`) **overrides** the default.

Custom inline text:

* Template: `⏱ Next update at %s`
* Format: `D M j, Y g:i A T` → `Thu Sep 11, 2025 4:15 PM CDT`

---

## Troubleshooting

* **Inline timestamp doesn’t appear**

    * Ensure **show\_next\_refresh = ON**.
    * Don’t wrap markers in `<code>`/`<nowiki>` — the syntax plugin won’t parse inside those.
    * Visit with `?purge=true` once to break any stale cache after changing markers.

* **Log says “no rules for page”**

    * No explicit marker and no namespace default matched that page. Add one or set defaults.

* **Weekly didn’t land where I expected**

    * Check if **weekly\_same\_weekday** is enabled.
    * If OFF, week boundary is controlled by **week\_start** (e.g., Monday 00:00).

* **Seeing too-frequent refreshes**

    * Increase **min\_cache\_seconds** (e.g., `300` for a 5-minute floor).

---

## Styling

The inline timestamp is wrapped with:

```html
<span class="cacheexpiry-nextrefresh">…</span>
```

Add CSS (e.g., in your template’s stylesheet):

```css
.cacheexpiry-nextrefresh {
  opacity: .8;
  font-style: italic;
}
```

---

## Debug logging

Typical line:

```
cacheexpiry: page=journal:day:2025:09:08 tz=America/Chicago weekly_mode=week_start(1) source=wikitext markers=REFRESH-MINUTES(1) seconds=60 expires_at=2025-09-11 11:04:27
```

What the fields mean:

* `page` — current page ID
* `tz` — timezone used
* `weekly_mode` — `week_start(n)` or `same_weekday`
* `source` — `wikitext`, `namespace(daily/hourly/weekly)`, or `none`
* `markers` — what was detected
* `seconds` — cache age set
* `expires_at` — computed expiry timestamp

---

## Notes & limitations

* Only the **supported `REFRESH-…` markers** are recognized.
* Markers inside **non-parsed regions** (e.g., code blocks) won’t render inline.
* If you include content from other pages, the marker must appear in the **final parsed content** to render inline.

---

## Changelog (high-level)

* **Current**: Syntax-only inline rendering; wikitext-first parsing; robust logging; namespace defaults; weekly “same weekday” option.

---

## License

GPL3.

---

### That’s it!

* Put a marker on pages that need predictable refreshes.
* Use namespace defaults for whole sections of your wiki.
* Let your readers know **exactly when** the next refresh happens.
