=== QR Document Verification ===
Contributors: saizulamin
Tags: qr code, pdf, verification, document, certificate
Requires at least: 5.5
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload PDFs with a name and ID; each gets a secret link and QR code that opens a fully customizable "Verified" page showing the document.

== Description ==

QR Document Verification lets you publish PDF documents (certificates, permits,
letters, results, IDs) behind secret, unguessable links. Each entry gets a QR
code — scanning it opens a clean, theme-integrated page that displays the PDF
with a "Verified" badge and the holder's details.

Everything on the verification page is customizable from the settings — nothing
is hard-coded:

* URL slug — choose what comes after your domain, e.g. example.com/verify/...
  (supports a language prefix like en/verify).
* Header — title text and gradient colors.
* Verified badge — show/hide, text, and colors.
* Fields — show/hide the Name and ID fields and rename their labels to anything
  (e.g. "Roll No", "Permit No", "Certificate ID").
* Document — optional open/download link and its text.
* Validity — optional expiry date per entry and one-click revocation (single
  or bulk). Expired and revoked documents show a clear "Expired" / "Revoked"
  state instead of "Verified"; revoked PDFs are no longer served.

How it works (two steps):

1. Create an entry (name + ID). The plugin generates a secret random link and a
   QR code. Download the QR.
2. Place the QR into your PDF design, then upload the finished PDF to the entry.

Scanning the QR now opens the verification page. Links are long random tokens,
and PDF files are stored in a web-protected folder and streamed only through the
link — so no one can reach another entry's document without its QR/link.

The verification page renders inside your active theme (header + footer), so it
matches the rest of your site, including page builders like Elementor.

Bulk tools are included: create many entries from an uploaded CSV file (preview and per-row errors first) or a pasted "Name, ID" list, and
attach many PDFs at once by matching each file name to its ID.

== Installation ==

1. Upload the plugin to /wp-content/plugins/ or install it from the Plugins
   screen, then activate it.
2. Go to "PDF Verify -> Settings" to set the URL slug and customize the header,
   badge, and labels.
3. Add entries under "PDF Verify -> Add New", download the QR, and upload the PDF.

== Frequently Asked Questions ==

= Can visitors guess other people's documents? =
No. Each link uses a long random token, and PDFs live in a protected folder that
is only streamed through the verification link.

= Can I rename "Name" and "ID Number"? =
Yes. Both labels are fully editable in Settings and also apply to the admin screens.

= Does it work with Elementor / my theme? =
Yes. The verification page is a real page rendered inside your theme, so your
header and footer appear normally.

= Can I withdraw a certificate that was issued by mistake? =
Yes. Tick "Revoke this document" on the entry (or use the Revoke bulk action).
The QR keeps working but now shows "Revoked", optionally with your reason, and
the PDF is no longer served. Untick it to reinstate the document.

= The link shows "not found" right after install. =
Visit Settings -> Permalinks and click Save once, or re-save the plugin settings,
to refresh permalinks.

== Changelog ==

= Unreleased =
* Document expiry and revocation: optional expiry date per entry (inclusive), revoke / reinstate with an optional public reason, "Expired" / "Revoked" states on the verification page, revoked PDFs answer 410 Gone, a Status column and Revoke / Reinstate bulk actions, plus settings for the texts and for hiding expired PDFs.
* Bulk import from an uploaded CSV file: preview of the first rows, per-row validation errors (empty fields, duplicates, existing IDs) and a confirm step before entries are created.

= 1.0.0 =
* Initial release.
