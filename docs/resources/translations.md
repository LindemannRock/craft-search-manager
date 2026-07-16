# Translations

Search Manager includes full translations for 12 languages out of the box.

## Supported Languages

| Language | Code |
|----------|------|
| English | `en` |
| German | `de` |
| French | `fr` |
| Dutch | `nl` |
| Spanish | `es` |
| Arabic | `ar` |
| Italian | `it` |
| Portuguese | `pt` |
| Japanese | `ja` |
| Swedish | `sv` |
| Danish | `da` |
| Norwegian | `no` |

Translations are automatically applied based on the user's preferred language in Craft's Control Panel settings.

## Overriding Translations

You can override any translation string by creating a static translation file in your project:

```
translations/
└── de/
    └── search-manager.php
```

The file returns only the strings you want to replace:

```php
<?php

return [
    'Settings' => 'Konfiguration',  // Override the default "Einstellungen"
];
```

Only the keys you include in your override file will be replaced — all other strings will use the plugin's built-in translations.

See [Craft's Static Translation Strings](https://craftcms.com/docs/5.x/system/sites.html#static-message-translations) for more details.

### Using Translation Manager

If you have [Translation Manager](https://github.com/LindemannRock/craft-translation-manager) installed, you can override translations directly from the Control Panel:

1. Add a new translation category using the plugin handle (`search-manager`)
2. Edit translations through the Translation Manager interface

Available languages are based on the site languages active in your Craft installation.

## Contributing Translations

If you find a translation error or want to improve a translation, please [open an issue](https://github.com/LindemannRock/craft-search-manager/issues) with:

- The language affected
- The current (incorrect) string
- Your suggested correction
