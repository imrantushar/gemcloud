=== GemCloud ===
Contributors: kodezen, tusharimran
Tags: offload media, amazon s3, cloudflare r2, cdn, media library
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0+
License URI: http://www.gnu.org/licenses/gpl-3.0.txt

Offload media to Amazon S3, Cloudflare R2 or any S3-compatible storage — CDN for public files, signed links for private ones.

== Description ==

GemCloud moves your WordPress media library and protected files to cloud object storage. Public files load from your CDN domain; private files (course videos, downloadable products, backups) open only through short-lived signed links created at the moment of the click. The admin is a fast, single-page app with a dashboard, a Files screen and a setup guide for each provider.

= Storage providers =
* One S3-compatible driver with presets for Cloudflare R2, Amazon S3 and any S3-compatible service (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO)
* No AWS SDK — requests are signed with AWS Signature Version 4
* Two buckets: public media behind your CDN domain, protected files in a private bucket reachable only through signed URLs
* Step-by-step connection test and one-click CORS setup

= Offloading =
* Copy first: new uploads keep their local copy until the upload is verified
* Optionally remove generated sizes, or every local file; removed originals are downloaded back when the image editor or another plugin needs them
* Every image size, video, audio and document; files over 64 MB use resumable multipart uploads
* Offload existing media in the background, or download everything back to the server
* All cloud work runs in Action Scheduler jobs with retries; the queue pauses itself after repeated connection errors

= Security =
* Secrets encrypted with libsodium, or kept out of the database entirely with wp-config.php constants
* Private files are never public: signed links expire within minutes (at least 4 hours for video and audio, so a long lesson keeps playing)

= Academy LMS =
Detected automatically; can be switched off under Settings → Contexts. Self-hosted lesson videos and their subtitles, lesson attachments, assignment files and student submissions go to the private bucket. They open for anyone Academy lets into the lesson or assignment (enrolled, instructor, public course, preview lesson); submissions for the student and the course instructors. Course thumbnails and other images stay public.

= StoreEngine =
Detected automatically. Downloadable product files go to the private bucket. StoreEngine's download link still checks the order, permission, expiry and login, then sends the buyer to a signed link instead of reading the file from disk. A daily check (also `wp gemcloud reconcile`) keeps every product file in the right bucket.

= TruePlayer =
Detected automatically. Self-hosted videos, audio, posters and captions play from the cloud. A video set to "Private" in TruePlayer has its file and captions moved to the private bucket, and opens only for viewers TruePlayer lets play it, so a copied link doesn't work for anyone else.

= Database backups =
A compressed SQL copy of the site's tables, daily or weekly, kept 7 to 90 days, in the private bucket only. Large databases are written in 20-second slices in the background; the local file is deleted once the upload is verified. Download from the admin, restore through WP-CLI.

= For developers =
* WP-CLI: `wp gemcloud status|test|migrate|run|retry|verify|download-back|reconcile|backup`
* A PHP API other plugins use for their own files: `GemCloud\Manager::register_context()`, `upload()`, `url()`, `temporary_url()`, `stream()`, `delete()`, `status()`
* Credentials can be set in wp-config.php:

    define( 'GEMCLOUD_PROVIDER', 'r2' );            // r2 | aws | custom
    define( 'GEMCLOUD_ACCOUNT_ID', '…' );           // R2 only
    define( 'GEMCLOUD_ACCESS_KEY_ID', '…' );
    define( 'GEMCLOUD_SECRET_ACCESS_KEY', '…' );
    define( 'GEMCLOUD_KEY', '…' );                  // encryption key for stored secrets

== External services ==

GemCloud does not contact any service on a default install. It connects only to the storage service you set up under Settings → Connection, and only after you save your bucket details.

= Your storage provider =
When connected, GemCloud sends your files (media uploads, protected files and, if enabled, database backups) to your bucket, and reads, lists and deletes objects there. Requests go to the endpoint of the provider you chose and are signed with your access keys. Visitors' browsers load public files from your bucket or CDN domain, and private files through signed links to the private bucket. GemCloud and Kodezen never receive your files or keys.

* Amazon S3 (s3.amazonaws.com) — [Terms](https://aws.amazon.com/service-terms/), [Privacy](https://aws.amazon.com/privacy/)
* Cloudflare R2 (r2.cloudflarestorage.com) — [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/)
* Any other S3-compatible service — only the endpoint URL you enter is contacted; its terms and privacy policy are those of the service you chose.

== Source code and libraries ==

The admin JavaScript in `assets/build/` is compiled. Its human-readable source is included in the `dev_gemcloud/` folder (styles in `assets/scss/`) and is also public on GitHub: [github.com/imrantushar/gemcloud](https://github.com/imrantushar/gemcloud).

To rebuild it, run `npm install --legacy-peer-deps` and then `npm run build`. PHP dependencies are installed with `composer install --no-dev`.

GemCloud bundles these open-source libraries:

* [Action Scheduler](https://github.com/woocommerce/action-scheduler) — GPL-3.0-or-later
* [Redux Toolkit](https://github.com/reduxjs/redux-toolkit), [Redux](https://github.com/reduxjs/redux) and [React Redux](https://github.com/reduxjs/react-redux) — MIT
* [Immer](https://github.com/immerjs/immer) and [Reselect](https://github.com/reduxjs/reselect), included by Redux Toolkit — MIT
* [React Router](https://github.com/remix-run/react-router) — MIT
* [React Select](https://github.com/JedWatson/react-select) and [Emotion](https://github.com/emotion-js/emotion) — MIT
* [Axios](https://github.com/axios/axios) — MIT
* [Lucide](https://github.com/lucide-icons/lucide) icons — ISC
* [Tailwind CSS](https://github.com/tailwindlabs/tailwindcss) — MIT (build tool; only the generated styles ship)

React and WordPress packages are loaded from WordPress core, not bundled.

== Installation ==

1. Upload the `gemcloud` folder to `/wp-content/plugins/`, or install through Plugins → Add New.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open GemCloud → Settings → Connection, pick your provider and follow the setup guide.
4. Run the connection test, then offload existing media from the Tools page.

== Frequently Asked Questions ==

= Which storage services are supported? =
Cloudflare R2, Amazon S3 and any S3-compatible service, such as Wasabi, Backblaze B2, DigitalOcean Spaces or MinIO.

= Will my local files be deleted? =
Not unless you choose to. By default every file keeps its local copy. If you turn on removing local files, a file is removed only after its upload is verified, and it is downloaded back whenever WordPress or another plugin needs it.

= Do I need a private bucket? =
Only for protected files (Academy lessons, StoreEngine downloads, private TruePlayer videos) and backups. Without one, those files stay on your server.

= What happens if I deactivate GemCloud? =
Use Tools → Download back first to bring every file back to the server. Files whose local copy was removed would otherwise point to the bucket URL only while GemCloud is active.

= Can I keep my keys out of the database? =
Yes. Define the `GEMCLOUD_*` constants in wp-config.php (see Description). Keys saved in the admin are encrypted with libsodium.

== Changelog ==

= 1.0.0 =

* New: Offload media to Cloudflare R2, Amazon S3 or any S3-compatible service (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO), with no AWS SDK.
* New: Separate public and private buckets. Public files load from your CDN domain; private files open only through short-lived signed links.
* New: Copy first. Uploads are verified before any local file is removed. Optionally remove generated sizes or every local file, with automatic download-back when a file is needed on the server.
* New: Offload existing media in the background, plus download everything back to the server.
* New: Multipart uploads for files over 64 MB.
* New: Background jobs through Action Scheduler, with retries and an automatic pause after repeated connection errors.
* New: Secrets encrypted with libsodium, or kept in wp-config.php constants.
* New: Academy LMS integration. Lesson videos, subtitles, attachments, assignments and student submissions are protected, following Academy's access rules.
* New: StoreEngine integration. Downloadable product files are protected, and StoreEngine's download link redirects to a signed link.
* New: TruePlayer integration. Self-hosted videos, audio, posters and captions play from the cloud; private videos follow TruePlayer's login, drip and access rules, and TruePlayer Pro's signed stream link keeps working.
* New: Daily check that moves product files to the right bucket, also available as `wp gemcloud reconcile`.
* New: Scheduled database backups to the private bucket, daily or weekly, kept 7 to 90 days, with download from the admin and restore through WP-CLI.
* New: Admin dashboard with storage status, queue health and warnings.
* New: Files screen with search, source and visibility filters, an Access column, and a details modal to edit a file's title, alt text, caption, description and Private setting.
* New: Setup guide for each provider, with separate saved settings per provider and a step-by-step connection test.
* New: Tools to set up CORS, retry failed files, and pause or resume the queue.
* New: WP-CLI commands: status, test, migrate, run, retry, verify, download-back, reconcile and backup.
* New: PHP API for other plugins to store their own files: `GemCloud\Manager::register_context()`, `upload()`, `url()`, `temporary_url()`, `stream()`, `delete()`, `status()`.
