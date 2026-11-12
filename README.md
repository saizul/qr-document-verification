# Saizul QR Verify: QR Document Verification (WordPress plugin)

[![CI](https://github.com/saizul/qr-document-verification/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/qr-document-verification/actions/workflows/ci.yml)

WordPress plugin to issue documents/certificates with a QR code and let anyone verify them online. Approved on the WordPress.org plugin directory. The plugin code lives in [`qr-document-verification/`](qr-document-verification/); its [`readme.txt`](qr-document-verification/readme.txt) is the WordPress.org listing.

License: GPLv2 or later.

## What it does

Publish PDF documents (certificates, permits, letters, results, IDs) behind secret, unguessable links. Each entry gets a QR code; scanning it opens a page rendered inside your active theme (header and footer included, so it also works with page builders like Elementor) that shows the PDF with a "Verified" badge and the holder's details.

- **Secret links:** each entry uses a long random token. PDFs are stored in a web-protected folder and streamed only through that link, so nobody can reach another entry's document without its QR or link.
- **Customisable page:** the URL slug (for example `example.com/verify/...`, language prefixes like `en/verify` are supported), header title and gradient colours, the "Verified" badge (show/hide, text, colours), the Name and ID fields (show/hide and rename, for example "Roll No" or "Permit No"), and an optional open/download link.
- **Expiry and revocation:** give an entry an optional expiry date (valid up to and including that day) or revoke it, with an optional public reason. The link and QR keep working, but the page shows an "Expired" or "Revoked" state instead of "Verified". A revoked entry's PDF is never served (the PDF URL answers `410 Gone`); an expired entry's PDF is shown unless you tick **Hide the PDF of expired documents**. The entry list has a Status column and **Revoke** / **Reinstate** bulk actions.
- **Bulk tools:** create many entries from a CSV file (with a preview and per-row validation errors before anything is created) or a pasted "Name, ID" list, and attach many PDFs at once by matching each file name to its ID.

## Installation

1. Copy the `qr-document-verification` folder (the one that contains `qr-document-verification.php`) to `wp-content/plugins/`, or zip that folder and upload it under **Plugins -> Add New -> Upload Plugin**. Then activate the plugin.
2. Go to **PDF Verify -> Settings** and set the URL slug, header, badge and labels.
3. If a verification link shows "not found" right after install, go to **Settings -> Permalinks** and click **Save** once (or re-save the plugin settings) to refresh the rewrite rules.

Requirements from `readme.txt`: WordPress 5.5 or newer (tested up to 7.0), PHP 7.0 or newer.

## Usage

1. Open **PDF Verify -> Add New**, enter a name and an ID. The plugin generates a secret link and a QR code. Download the QR.
2. Place the QR into your PDF design (certificate, permit, letter, ...), then upload the finished PDF to the same entry.
3. Anyone who scans the QR lands on the verification page showing the document and the holder's details.

To withdraw a document, tick **Revoke this document** on the entry (or select entries in the list and choose **Revoke** under Bulk actions). Untick it, or use **Reinstate**, to make it valid again. Set an **Expiry date** for documents that are only valid for a limited time; the badge and label texts for these states are under **Settings -> Validity**.

For many documents at once, use the bulk tools to create entries from a "Name, ID" list and to attach PDFs whose file names match the IDs.

## Development

CI runs on every push and pull request (`.github/workflows/ci.yml`):

- `php -l` on every PHP file with PHP 8.1, 8.2 and 8.3.
- Dependency-free unit tests: `php tests/csv-test.php` (CSV importer) and `php tests/status-test.php` (expiry / revocation rules).
- PHP_CodeSniffer with the WordPress Coding Standards (`phpcs.xml.dist`). This job is allowed to fail: it exists to give visibility into style findings and does not block merging.

To lint locally:

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

## Author

[Saizul Amin](https://saizul.com)
