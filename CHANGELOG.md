# Changelog

All notable changes to this project are documented here. The WordPress.org
listing keeps its own copy in [`qr-document-verification/readme.txt`](qr-document-verification/readme.txt).

## Unreleased

### Added

- Document fingerprints: the SHA-256 of every stored PDF is shown on the verification page and in the entry editor.
- "Check your copy" on the verification page: a visitor picks a PDF and the page reports whether it is byte-for-byte the issued document. Hashing happens in the browser (Web Crypto); the file is never uploaded.
- The raw PDF endpoint sends the digest as an RFC 9530 `Repr-Digest: sha-256=:...:` header.
- Settings -> Document: show / hide the fingerprint and rename its label.
- `tests/fingerprint-test.php`, run in CI.

### Changed

- Fingerprints are cached in post meta with the file size and mtime and recomputed when the stored file changes; entries created before this release get one on first view.

### Security

- Uploaded files must contain a PDF header (`%PDF-` within the first 1024 bytes). Files that only have a `.pdf` name and MIME type are rejected and deleted.

## Earlier changes

- Document expiry dates and revocation with Expired / Revoked states (#4).
- Bulk import of entries from an uploaded CSV file (#3).
- CI: PHP lint on 8.1-8.3, informational PHPCS job, install docs (#1).
- 1.0.0: initial release.
