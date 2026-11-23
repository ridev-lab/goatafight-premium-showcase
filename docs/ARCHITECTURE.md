# Technical architecture

## Overview

**GoataFight Premium** is a WordPress plugin built around the WordPress hook system. It uses PHP to control article output and access decisions, and a small amount of browser-side JavaScript to coordinate the reader interface.

## Main flows

### 1. Article separation

- `the_content` filter detects the `[goata_paywall]` marker in singular content.
- The plugin renders the public section and a subscription call-to-action in the initial HTML.
- The post's subscriber-only section is stored on the WordPress side until requested through AJAX.

### 2. Subscriber access

- The client integrates with Google Reader Revenue Manager's entitlement flow.
- PHP validates Google-signed entitlements against the expected issuer, audience, subscription product and Google's public certificate.
- After verification, PHP creates a short-lived signed access cookie.
- A second path grants access to eligible WordPress users through administrator-managed complimentary subscriptions.
- The premium-content AJAX handler checks server-side access before rendering the protected section.

### 3. Administrative access management

- A dedicated WordPress administration screen lists complimentary subscriptions.
- Administrators can grant or revoke access, set expiry dates and optionally notify users.
- Actions use WordPress capabilities and nonce checks.

### 4. Reader experience and SEO

- Google Site Kit / AdSense configuration is adapted for premium readers.
- Front-end scripts suppress ads and premium promotions based on the reader's entitlement state.
- Rank Math JSON-LD is extended with paid-content metadata.
- Premium content responses use cache-control headers to avoid caching personalized content.

## Principal WordPress integration points

| Hook / endpoint | Responsibility |
| --- | --- |
| `the_content` | Splits public and premium article sections |
| `wp_head` / `wp_footer` | Installs client-side entitlement and reader UX hooks |
| `wp_ajax_...` / `wp_ajax_nopriv_...` | Creates a short session and delivers gated HTML |
| `admin_post_...` | Handles complimentary subscription management |
| `option_googlesitekit_adsense_settings` | Adjusts ad snippet settings |
| `rank_math/json_ld` | Adds paid-content schema metadata |

## Configurations and dependencies

- WordPress **6.0+**, PHP **7.4+**
- A separately configured Google Reader Revenue Manager product identifier
- Google Site Kit, Rank Math and LiteSpeed Cache integrations are optional or site-specific

The sample intentionally excludes production account identifiers. It documents a custom integration pattern, not a packaged Google subscription service. Validate subscriptions, theme compatibility, caching and rendering in a local WordPress environment before any production use.
