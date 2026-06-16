# Changelog

All notable changes to `dashed-files` will be documented in this file.

## Unreleased

### Security
- Block executable/dangerous file uploads in the media library. `MediaObserver::creating()` now refuses any upload whose name contains a blocked extension (php/phtml/phar, cgi/asp/jsp, sh/exe/bat, .htaccess/.user.ini, etc.), checking every dot-component so `shell.php.jpg` is caught too. Configurable via `dashed-files.blocked_upload_extensions`. Prevents a leaked/compromised admin account from uploading a web shell.

## 1.0.0 - 202X-XX-XX

- initial release
