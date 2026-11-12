# Changelog

## 0.5.2
- Fix: category descriptions, tag pages and contact pages were not transformed — their content.prepare contexts (`com_content.category.description`, `com_tags.tag`, `com_contact.*`) were missing
- New: `content-contexts` option — configurable context allowlist, one per line with `*` wildcard support

## 0.5.1
- Fix: keep existing width/height attributes on transformed article images; fill missing ones from intrinsic size (new `content-set-dimensions` option)
- New: `content-intro-params` / `content-fulltext-params` transform `image_intro`/`image_fulltext` in the article images field (originals kept as `_image_intro`/`_image_fulltext`)
- New: category description texts are also processed
- New: en-GB and de-DE language files

## 0.5.0
- New: optional `onContentPrepare` rewriting of `<img>` tags in com_content/mod_custom texts with srcset, sizes and lazy loading
- New: class-based size definitions, `no-transform` opt-out, article/category exclusion lists
- Change: plugin now implements `SubscriberInterface` (Joomla 6 compatibility)
- New: update server for com_installer updates

## 0.4.1
- Optional LIVE fallback for missing source images
