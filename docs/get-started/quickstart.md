# Quickstart

Get Search Manager running in under 5 minutes. By the end of this guide you'll have your content indexed and searchable from a Twig template.

## 1. Install the Plugin

See [Installation](installation.md) for full details including DDEV and Composer options.

## 2. Choose a Backend

Go to **Search Manager > Settings > Search Backend** and select a backend. For a quick local setup, **MySQL** or **PostgreSQL** works out of the box — no external services needed.

## 3. Create an Index

1. Go to **Search Manager > Indices**
2. Click **New Index**
3. Give it a name (e.g. "Main") — the handle is auto-generated from the name (e.g. `main`)
4. Select the sections and entry types you want to index
5. Click **Save**, then click **Rebuild** to index existing content

## 4. Search from a Template

Add a basic search form to any Twig template:

```twig
{% set results = craft.searchManager.search('main', 'hello world') %}

{% for result in results.hits %}
    <a href="{{ result.url }}">{{ result.title }}</a>
{% endfor %}
```

Replace `'main'` with the handle of the index you created in step 3. Load a page with this template — you should see matching entries from your index.

## What's Next

- [Configuration](configuration.md) — tune caching, BM25 ranking, and analytics
- [Feature Tour](../feature-tour/overview.md) — explore backends, query rules, promotions, and the frontend widget
