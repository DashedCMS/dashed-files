# Changelog

All notable changes to `dashed-files` will be documented in this file.

## v4.7.1 - 2026-10-23

### Fixed
- **`InvalidConversion` ("There is no conversion named `huge`") op de frontend.** Een conversie die `resolveMediaUrl` aan een item toevoegt wordt nu direct opgeslagen, zodat Spatie (die conversies via een lege `MediaLibraryItem` uit de database leest) hem kent. Daarnaast slaat de URL-fallback conversies over die wel als gegenereerd op de media staan maar niet op het item geregistreerd zijn, in plaats van te crashen.

## v4.7.0 - 2026-10-19

### Changed
- **Fal.ai-aanroepen in het AI-logboek.** `AiImageGenerator` en `AiImageOperations` gaan via `FalRequest::post()` uit dashed-ai zodra dat pakket aanwezig is (megapixels of afbeeldingen als eenheid, geschatte kosten); zonder dashed-ai blijft de kale HTTP-aanroep.

## v4.6.2 - 2026-10-15

### Fixed
- Drop-to-upload did nothing in the browser: the picker's drop-zone config was written as raw JSON into a data attribute, and Laravel's attribute bag writes `"` as `\"` instead of `&quot;`, which cut the attribute into pieces. The config is now base64-encoded and decoded in the script.

## v4.6.0 - 2026-10-15

### Added
- Drag-and-drop upload on every media picker (`UploadableMediaPicker`, used by `mediaHelper()->field()`): drop a file on the field, or use the "Upload" hint action, and it lands in the media library (default folder of the field, else root) and is linked to the field at once. Uploads go through Livewire's component file attachments and `Driver::createFile()`, so uploader, conversions and the upload guard apply. Browser side ships as a render hook (no `filament:assets` needed). Requires dashed-core >= 4.80.0 (for the i18n trait below).
- Package ships its own `nl.json`/`en.json` and translates the static labels of the files page.

## Unreleased

### Security
- Block executable/dangerous file uploads in the media library. `MediaObserver::creating()` now refuses any upload whose name contains a blocked extension (php/phtml/phar, cgi/asp/jsp, sh/exe/bat, .htaccess/.user.ini, etc.), checking every dot-component so `shell.php.jpg` is caught too. Configurable via `dashed-files.blocked_upload_extensions`. Prevents a leaked/compromised admin account from uploading a web shell.

## 1.0.0 - 202X-XX-XX

- initial release
