=== 9Router WP connector ===
Contributors: alirezach
Tags: ai, 9router, openai-compatible, connectors, ai-client
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later

Connects WordPress to a self-hosted 9Router server (OpenAI-compatible API).

== Description ==

9Router AI Connector registers your 9Router server as a first-class AI provider
for WordPress:

* **Settings → 9Router AI** — Base URL, API key, Default model, connection
  test, request logging with time-period retention (Week / Month / 3 Months /
  6 Months / Full).
* **Settings → Connectors** — the 9Router card accepts the API key; both
  screens share the same key automatically.
* **AI plugin (WP AI Client)** — the `9router` provider is registered with the
  WordPress AI Client, so AI features (editor experiments, alt text, …) use
  your self-hosted router.

Works with any reachable 9Router URL — a public domain
(`https://ai.example.com`) or a local endpoint
(`http://127.0.0.1:PORT`). There is no hardcoded address.

== Installation ==

1. Upload the `9router-wp-connector` folder to `wp-content/plugins/` and
   activate the plugin.
2. Go to **Settings → 9Router AI**:
   * **Base URL** — your 9Router address (e.g. `https://ai.example.com`).
   * **API Key** — a key created in your 9Router admin.
   * **Default model** — any model ID from your server (press the field to see
     an autocomplete list loaded from `/v1/models`). This model is used
     automatically when an AI feature has no explicit model choice.
   * Click **Save**, then use the **Debug** tab to test the connection.
3. Optionally set the key once on **Settings → Connectors** (9Router card) —
   it stays in sync with the plugin settings.

== Advanced configuration ==

Define these constants in `wp-config.php` to lock configuration (they take
priority over the settings screen):

    define( 'NROUTER_BASE_URL', 'https://ai.example.com' );
    define( 'NROUTER_API_KEY', 'sk-…' );

Filters: `nrouter_default_base_url`, `nrouter_ai_request_timeout` (seconds,
default 120).

== Notes ==

* **Key Encryption experiment:** if the AI plugin's "Key Encryption"
  experiment is enabled, the Connectors key is stored in the secrets store.
  This plugin handles that transparently — saving its settings form with an
  empty key never wipes the stored key.
* **Timeouts:** AI generation can take minutes; requests to the configured
  9Router URL use a 120-second timeout (filterable).
* **Logging:** optional; logs live in the `{prefix}nrouter_api_logs` table and
  are purged according to the retention setting on the Debug tab.

== External links ==

Plugin page: https://github.com/alirezach/9router-wp-connector

== Changelog ==

= 1.0.0 =
* Stable public release: default-model driven AI Client, domain-based base URL,
  universal HTTP-layer API logging with retention, Connectors screen integration.

= 1.3.0 =

= 1.3.0 =
* API request logging now captures ALL traffic to the 9Router base URL at the
  WordPress HTTP layer — including WP AI Client (AI plugin) requests, with
  model, status, latency and token counts. Previously only the plugin's own
  debug/models calls were logged.

= 1.2.0 =
* Default model setting now drives AI Client model selection (default model is
  served first to the AI Client when a prompt has no explicit model).
* Portable: base URL default is empty; any domain or local address supported
  (or pinned via NROUTER_BASE_URL / NROUTER_API_KEY constants).
* Key sync made safe with the AI plugin Key Encryption experiment.
* 120-second request timeout for chat completions (WordPress default was 5s).

= 1.1.0 =
* Registered 9Router as a WP AI Client provider (Connectors + AI plugin).
* Fixed Connectors "Set up" flow for custom providers (REST setting).

= 1.0.0 =
* Initial release.
