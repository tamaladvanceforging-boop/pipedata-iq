# PipeDoc IQ — Installation (LocalWP, Windows 10)

**Requirements:** WordPress 6.0+, PHP 7.4+ (8.x recommended) with `zip` and `openssl`/`sodium`, MySQL 5.7+/MariaDB 10.3+ (LocalWP defaults are fine).

1. In LocalWP open your site (e.g. *Forging-Calculation-Report*) → **Go to site folder** → `app/public/wp-content/plugins/`.
2. Unzip `pipedoc-iq.zip` there so you get `plugins/pipedoc-iq/pipedoc-iq.php`. (Or WordPress admin → Plugins → Add New → Upload Plugin.)
3. WordPress admin → **Plugins** → activate **PipeDoc IQ**. Activation creates all `pdq_*` tables (with your table prefix) and a private upload folder `wp-content/uploads/pdq-private` (web access denied).
4. Click **PipeDoc IQ** in the admin menu → **Open PipeDoc IQ** (full-screen app).
5. *(Optional, for XLSX import)* In the plugin folder run `composer install --no-dev` (needs Composer). Without it, export sheets to CSV and import those.
6. *(Optional, for PDF output)* Install LibreOffice; PipeDoc IQ finds `soffice` automatically (Settings → Server capabilities shows status).

Raise `upload_max_filesize`/`post_max_size` (LocalWP → site → PHP settings, or php.ini) for large datasets.

## First-run checklist
Company Profiles → Import engineering data → add Customers/POs → Heat numbers (verify) → upload a template in Template Studio → Document Generator.

## Security note (Phase 1)
No custom login exists. Access = WordPress administrator login. Run on LocalWP or a private network only; never expose publicly. Encrypted Gemini key uses WordPress salts; optionally define `PDQ_ENCRYPTION_KEY` in `wp-config.php`.

## Deployment
Same steps on any WordPress host (HTTPS, PHP 8.x, keep `wp-config.php` salts stable — changing them makes the stored Gemini key unreadable; just re-enter it). Back up DB + `uploads/pdq-private`.
