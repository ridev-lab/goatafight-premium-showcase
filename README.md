# GoataFight Premium

[![PHP checks](https://github.com/ridev-lab/goatafight-premium-showcase/actions/workflows/php-checks.yml/badge.svg)](https://github.com/ridev-lab/goatafight-premium-showcase/actions/workflows/php-checks.yml)

[**Live editorial platform**](https://goatafight.com/) · [**Technical architecture**](docs/ARCHITECTURE.md)

**WordPress subscription and content-access plugin** · PHP · JavaScript · WordPress Hooks · AJAX · Google Reader Revenue Manager

A custom plugin developed for **[GoataFight](https://goatafight.com/)**, an independent MMA publication. It combines subscription-based article access with a WordPress administration interface, an ad-free reader experience and structured-data integration.

This repository contains a **portfolio-oriented code sample** derived from the original plugin (version 2.1.0). The production configuration is deliberately kept separate from the published source.

## Highlights

- **Partial article paywall:** separates the public article from subscriber-only sections using the `[goata_paywall]` marker.
- **On-demand premium content:** loads protected article sections through WordPress AJAX handlers after an access check.
- **Subscription integration:** processes Google Reader Revenue Manager entitlements, including signed-entitlement verification on the server.
- **Complimentary subscriptions:** administrators can grant time-limited or unlimited WordPress-account access through a dedicated dashboard.
- **Ad-free experience:** integrates with Google Site Kit / AdSense for subscribed visitors.
- **SEO integration:** adds paid-content structured-data properties through a Rank Math filter.
- **Cache-aware delivery:** keeps public article pages separate from authenticated premium-content requests.

## Technology and engineering

| Area | Implementation |
| --- | --- |
| Back end | PHP 7.4+, WordPress hooks, permissions, nonces, user metadata |
| Front end | Vanilla JavaScript, DOM updates, Fetch API |
| Subscription access | Google Reader Revenue Manager, JWT signature validation |
| CMS integrations | Site Kit, Rank Math, LiteSpeed Cache |
| Delivery | AJAX (`admin-ajax.php`), short-lived signed cookies |
| Quality checks | PHP syntax lint and focused PHP smoke tests via GitHub Actions |

## Architecture

```text
WordPress article
  ├── Public section                 → rendered for every reader
  └── [goata_paywall] marker         → premium section held on server
             │
             ▼
Reader Revenue Manager / WordPress complimentary access
             │
             ▼
PHP access check + AJAX content endpoint
             │
             ▼
Premium article HTML delivered to authorized reader
```

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the implementation and integrations.

## Repository structure

```text
goatafight-premium.php     Plugin entry point and integration logic
readme.txt                WordPress plugin readme
config.example.php        Example site-specific configuration
docs/ARCHITECTURE.md      Architecture overview
tests/smoke.php           Focused self-contained checks
.github/workflows/       Automated checks
LICENSE                  GPL-2.0-or-later
```

## Local setup

1. Install a local WordPress environment (WordPress **6.0+**, PHP **7.4+**).
2. Copy this repository into `wp-content/plugins/goatafight-premium/`.
3. Configure the Google Reader Revenue Manager product identifier in **`wp-config.php`**. See [`config.example.php`](config.example.php); the sample values must be replaced with an identifier from your own integration.
4. Activate **GoataFight Premium** from the WordPress Plugins screen.
5. If applicable, configure Site Kit / Reader Revenue Manager and Rank Math on the WordPress site.

To split an article into public and subscriber-only content, insert:

```text
Public introduction and analysis

[goata_paywall]

Premium-only analysis and conclusion
```

This sample does **not** include a Google publisher account, subscription product or live deployment credentials. A real integration must be configured in your own environment.

## Automated checks

The repository includes a CI workflow and lightweight tests that can run independently of a full WordPress installation:

```bash
php -l goatafight-premium.php
php tests/smoke.php
```

These checks cover syntax, WordPress hook registration, gift-access rules, paywall content splitting and basic RSS/REST/excerpt protection cases. **They are not a substitute for WordPress integration tests or a production security review.**

## Project background

This project illustrates practical development around a real editorial use case: combining third-party billing entitlement checks, WordPress users, content access and CMS compatibility in one custom extension.

The source is intended as a technical portfolio sample. It is **not** a drop-in replacement for an existing live deployment without integration testing.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
