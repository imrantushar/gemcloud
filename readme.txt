=== GemCloudStorage - Offload Media to Amazon S3 & Cloudflare R2 ===
Contributors: kodezen
Tags: offload, s3, cloudflare r2, cdn, media
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Offload the media library and protected files to Amazon S3 or Cloudflare R2. Public files load from your CDN; private files are served through short-lived signed URLs.

== Description ==

* One S3-compatible driver with presets for Cloudflare R2, Amazon S3 and any S3-compatible service (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO). No AWS SDK: requests are signed with AWS Signature Version 4.
* Two buckets: public media behind your CDN domain, protected files in a private bucket reachable only through signed URLs created at the moment of the click.
* Copy first: new uploads keep their local copy until the upload is verified. Optionally remove generated sizes, or every local file; removed originals are downloaded back when the image editor or another plugin needs them.
* Every image size, video, audio and document. Files over 64 MB use resumable multipart uploads.
* All cloud work runs in Action Scheduler jobs with retries; the queue pauses itself after repeated connection errors.
* Secrets are encrypted with libsodium, or kept out of the database entirely with wp-config.php constants.
* WP-CLI: `wp gemcloudstorage status|test|migrate|run|retry|verify|download-back`.
* A PHP API other plugins use for their own files: `GemCloudStorage\Manager::register_context()`, `upload()`, `url()`, `temporary_url()`, `stream()`, `delete()`, `status()`.

== Academy LMS and StoreEngine ==

Both are detected automatically. Their files are protected once a private bucket is set up; each can be switched off under Settings → Contexts.

* Academy LMS: self-hosted lesson videos and their subtitles, lesson attachments, assignment files and student submissions go to the private bucket. They open for anyone Academy lets into the lesson or assignment (enrolled, instructor, public course, preview lesson); submissions for the student and the course instructors. Course thumbnails and other images stay public.
* StoreEngine: downloadable product files go to the private bucket. StoreEngine's download link still checks the order, permission, expiry and login, then sends the buyer to a signed link instead of reading the file from disk. Files outside the media library are copied up and kept on the server. Invoices are built on demand, so nothing is stored.
* A file a product made private stays private after the product stops using it, until an admin unticks "Private".
* Video and audio links last at least 4 hours so a long lesson keeps playing; subtitles are streamed through the site.
* `wp gemcloudstorage reconcile` checks every product file now; it also runs daily.

== TruePlayer ==

Detected automatically; switched off under Settings → Contexts → TruePlayer videos.

* Self-hosted videos, audio, posters and captions play from the cloud. The video's saved settings keep the local URL; the cloud URL is swapped in when the player is shown.
* A video switched to "Private" in TruePlayer (Protection tab) has its file and captions moved to the private bucket. They open only for viewers TruePlayer lets play the video: its login, drip and access rules (for example Academy enrolment through TruePlayer Pro), so a copied link doesn't work for anyone else. Needs a private bucket; until one is added, private files stay on the server.
* TruePlayer Pro's signed stream link keeps working: once the file is in the cloud it sends the viewer to a signed link (at least 4 hours) instead of streaming from the server.
* Files picked by URL from outside the media library (ZenCommunity uploads, for example) are copied up; the server keeps its copy.
* Posters always stay public. YouTube, Vimeo, Bunny, Gumlet and Mux videos are left alone.
* Captions or posters loaded from the public bucket need CORS: Tools → Set up CORS.

== Database backups ==

Settings → Backups. A compressed SQL copy of the site's tables (orders, products, license keys, customers, posts, settings), daily or weekly, kept 7 to 90 days, in the private bucket only. Large databases are written in 20-second slices in the background; the local file is deleted once the upload is verified.

    wp gemcloudstorage backup create --sync
    wp gemcloudstorage backup list
    wp gemcloudstorage backup url <id>
    wp gemcloudstorage backup restore <id>

Restore replaces every table. The file is plain SQL, so phpMyAdmin or the mysql client can import it too.

== Constants ==

    define( 'GEMCLOUDSTORAGE_PROVIDER', 'r2' );            // r2 | aws | custom
    define( 'GEMCLOUDSTORAGE_ACCOUNT_ID', '…' );           // R2 only
    define( 'GEMCLOUDSTORAGE_ACCESS_KEY_ID', '…' );
    define( 'GEMCLOUDSTORAGE_SECRET_ACCESS_KEY', '…' );
    define( 'GEMCLOUDSTORAGE_KEY', '…' );                  // encryption key for stored secrets

== Development ==

The admin app source is in dev_gemcloudstorage/ (React) and assets/scss/. Build with:

    npm install --legacy-peer-deps
    npm run build
    composer install --no-dev

== Changelog ==

= 1.0.0 =
* First release.
