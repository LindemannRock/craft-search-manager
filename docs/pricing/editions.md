# Editions

Search Manager is available in Standard and Pro. Standard is the complete search engine and developer toolkit; Pro adds the operational, measurement, merchandising, and reusable-branding surfaces teams use to run search day to day.

## Feature comparison

| Feature | Standard | Pro |
|---------|:--------:|:---:|
| **Search engine and APIs** | | |
| Local and external backends | ✓ | ✓ |
| BM25, fuzzy matching, operators, transformers, and native-search replacement | ✓ | ✓ |
| REST, GraphQL, API keys, rate limits, and retrievable-field controls | ✓ | ✓ |
| Privacy controls, analytics-data export, and permanent purge | ✓ | ✓ |
| **Frontend widget** | | |
| Complete modal widget, including search, results, hierarchy, snippets, recently viewed, and destination highlighting | ✓ | ✓ |
| Twig inline `styles:` overrides and public JavaScript events | ✓ | ✓ |
| Promotion badge, row-tint, and hidden display modes | — | ✓ |
| Built-in widget analytics and placement/idle settings | — | ✓ |
| Reusable Widget Style presets and style editor | — | ✓ |
| **Operations and merchandising** | | |
| Automatic index synchronization plus manual rebuild and clear tools | ✓ | ✓ |
| Pending-sync queue browser and row-level operations | — | ✓ |
| Analytics-driven cache warming | — | ✓ |
| Query rules and pinned promotions | — | ✓ |
| **Analytics** | | |
| Eight-tab analytics workspace and exports | — | ✓ |
| Analytics dashboard widgets | — | ✓ |

## Standard

Choose Standard when you need a production-ready search engine and a fully working frontend widget. It includes every backend, the search and autocomplete APIs, security and privacy controls, indexing correctness, developer integrations, and the widget's complete functional core.

The widget remains customizable from Twig with inline `styles:` overrides. Public JavaScript `CustomEvent`s also continue to fire, so a Standard site can connect its own analytics platform without enabling Search Manager's built-in analytics.

## Pro

Pro includes everything in Standard, plus:

- [Analytics](../feature-tour/analytics.md) and its four Craft dashboard widgets
- [Query rules](../feature-tour/query-rules.md) and [promotions](../feature-tour/promotions.md)
- Pending-sync operational controls and analytics-driven cache warming
- Widget promotion display settings and built-in analytics controls
- Reusable [Widget Style presets](../widget/styles.md) managed from the Control Panel or config file

## Downgrading safely

Downgrading never deletes Pro-created data or breaks a live widget. Existing query rules, promotions, analytics rows, and Widget Style presets remain stored.

In Standard, a widget that references a preset renders with the built-in default style, promotion markers are omitted, and built-in widget tracking silently stops. Its search, results, snippets, hierarchy, recently viewed history, destination highlighting, Twig inline styles, and public JavaScript events continue working. Re-upgrading restores the stored Pro configuration.

## Upgrading

You can upgrade from Standard to Pro at any time in the Craft Plugin Store. Existing data and configuration are preserved, with no migration required.
