<?php
/**
 * Test content for GemCloudStorage's Academy LMS and StoreEngine integrations.
 *
 *     wp eval-file wp-content/plugins/gemcloudstorage/tests/fixture/seed.php
 *     wp eval-file wp-content/plugins/gemcloudstorage/tests/fixture/cleanup.php
 *
 * Creates, through each product's own APIs so their hooks run:
 *  - Academy: a free course with a video lesson (video + subtitles), a
 *    lesson with a PDF attachment and a free preview lesson; an enrolled
 *    student and a student who isn't enrolled.
 *  - StoreEngine: a digital product with one download from the media
 *    library and one from storeengine_uploads, and a paid order for the
 *    student that grants both downloads.
 *
 * Everything created is recorded in the gcs_fixture option; cleanup.php
 * removes exactly that. Not shipped with the plugin.
 *
 * phpcs:ignoreFile -- developer fixture.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( get_option( 'gcs_fixture' ) ) {
	WP_CLI::error( 'Test content already exists. Run cleanup.php first.' );
}
if ( ! defined( 'ACADEMY_VERSION' ) || ! defined( 'STOREENGINE_VERSION' ) ) {
	WP_CLI::error( 'Academy LMS and StoreEngine must both be active.' );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const GCS_FIXTURE_PASSWORD = 'GcsTest@2026';

$admin = (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
wp_set_current_user( $admin );

$fx = [ 'attachments' => [], 'posts' => [], 'lessons' => [], 'users' => [], 'orders' => [], 'files' => [], 'prices' => [] ];
add_filter( 'upload_mimes', function ( $m ) {
	$m['vtt'] = 'text/vtt';
	return $m;
} );

// ── Files ───────────────────────────────────────────────────────────────────

$attach = function ( string $name, string $bytes, string $title ) use ( &$fx ) {
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, $bytes );
	$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], 0, $title );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not add $name: " . $id->get_error_message() );
	}
	update_post_meta( $id, '_gcs_fixture', 1 );
	$fx['attachments'][] = (int) $id;
	return (int) $id;
};

$pdf = function ( string $title, string $line ) {
	$text    = str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $title );
	$sub     = str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $line );
	$stream  = "BT /F1 26 Tf 72 700 Td ($text) Tj ET\nBT /F1 13 Tf 72 664 Td ($sub) Tj ET\n";
	$objects = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream',
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	];
	$out     = "%PDF-1.4\n";
	$offsets = [];
	foreach ( $objects as $i => $body ) {
		$offsets[] = strlen( $out );
		$out      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
	}
	$xref = strlen( $out );
	$out .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $o ) {
		$out .= sprintf( "%010d 00000 n \n", $o );
	}
	return $out . "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
};

$zip = function ( string $path, array $entries ) {
	$z = new ZipArchive();
	$z->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $content ) {
		$z->addFromString( $name, $content );
	}
	$z->close();
	return file_get_contents( $path );
};

// A real, playable video: reuse an MP4 already on this site.
$video_source = '';
foreach ( get_posts( [ 'post_type' => 'attachment', 'post_mime_type' => 'video/mp4', 'numberposts' => 20, 'fields' => 'ids' ] ) as $vid ) {
	$file = get_attached_file( $vid );
	if ( $file && file_exists( $file ) && filesize( $file ) < 200 * MB_IN_BYTES ) {
		$video_source = $file;
		break;
	}
}
if ( ! $video_source ) {
	WP_CLI::error( 'No MP4 found in the media library to use as the test video. Upload one first.' );
}

$f = [];
$f['video']    = $attach( 'gcs-test-lesson-video.mp4', file_get_contents( $video_source ), 'GCS test: lesson video' );
$f['subtitle'] = $attach( 'gcs-test-lesson-video.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:04.000\nGemCloudStorage test subtitles.\n\n00:00:04.000 --> 00:00:09.000\nThese captions are streamed through the site.\n", 'GCS test: subtitles' );
$f['notes']    = $attach( 'gcs-test-lesson-notes.pdf', $pdf( 'Lesson notes', 'GemCloudStorage test: only enrolled students should open this.' ), 'GCS test: lesson notes' );
$f['preview']  = $attach( 'gcs-test-preview-handout.pdf', $pdf( 'Preview handout', 'GemCloudStorage test: anyone may open this, the lesson is a free preview.' ), 'GCS test: preview handout' );
$f['thumb']    = $attach( 'gcs-test-course-thumbnail.png', file_get_contents( ABSPATH . 'wp-admin/images/w-logo-blue.png' ), 'GCS test: course thumbnail' );
$f['ebook']    = $attach( 'gcs-test-ebook.zip', $zip( wp_tempnam( 'gcs-ebook' ), [ 'GCS Test eBook.pdf' => $pdf( 'GCS Test eBook', 'A purchased download, stored in the media library.' ), 'readme.txt' => "GemCloudStorage StoreEngine test download (media library).\n" ] ), 'GCS test: ebook download' );

// A download kept outside the media library, the way StoreEngine's protected folder is used.
$se_dir = wp_upload_dir()['basedir'] . '/storeengine_uploads';
wp_mkdir_p( $se_dir );
$software_path = $se_dir . '/gcs-test-software.zip';
$zip( $software_path, [ 'readme.txt' => "GemCloudStorage StoreEngine test download (outside the media library).\n", 'app/config.json' => '{"name":"gcs-test"}' ] );
$fx['files'][] = $software_path;

// ── Academy LMS ─────────────────────────────────────────────────────────────

$lesson = function ( array $body ) use ( &$fx ) {
	$req = new WP_REST_Request( 'POST', '/academy/v1/lessons' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	$id   = (int) ( is_array( $data ) ? ( $data['ID'] ?? $data['id'] ?? 0 ) : 0 );
	if ( ! $id ) {
		WP_CLI::error( 'Could not create lesson "' . $body['lesson_title'] . '": ' . wp_json_encode( $data ) );
	}
	$fx['lessons'][] = $id;
	return $id;
};

$l_video = $lesson( [
	'lesson_title'   => 'GCS test: video lesson',
	'lesson_status'  => 'publish',
	'lesson_content' => '<p>Self-hosted video with subtitles. Enrolled students only.</p>',
	'meta'           => [ 'video_source' => [ 'type' => 'html5', 'url' => '', 'id' => $f['video'], 'subtitle' => $f['subtitle'], 'subtitle_enabled' => true ] ],
] );
$l_notes = $lesson( [
	'lesson_title'   => 'GCS test: lesson with notes',
	'lesson_status'  => 'publish',
	'lesson_content' => '<p>Download the attached notes. Enrolled students only.</p>',
	'meta'           => [ 'attachment' => $f['notes'] ],
] );
$l_preview = $lesson( [
	'lesson_title'   => 'GCS test: free preview lesson',
	'lesson_status'  => 'publish',
	'lesson_content' => '<p>A free preview: anyone can open this lesson and its handout.</p>',
	'meta'           => [ 'attachment' => $f['preview'], 'is_previewable' => true ],
] );

$course = wp_insert_post( [
	'post_type'    => 'academy_courses',
	'post_status'  => 'publish',
	'post_title'   => 'GCS Test Course',
	'post_content' => 'A course for testing GemCloudStorage: lesson video, subtitles and attachments are kept in the private bucket.',
	'post_author'  => $admin,
] );
$fx['posts'][] = $course;
update_post_meta( $course, '_gcs_fixture', 1 );
update_post_meta( $course, 'academy_course_type', 'free' );
set_post_thumbnail( $course, $f['thumb'] );
update_post_meta( $course, 'academy_course_curriculum', [
	[
		'title'   => 'GemCloudStorage tests',
		'content' => '',
		'topics'  => [
			[ 'id' => $l_preview, 'name' => 'GCS test: free preview lesson', 'type' => 'lesson' ],
			[ 'id' => $l_video, 'name' => 'GCS test: video lesson', 'type' => 'lesson' ],
			[ 'id' => $l_notes, 'name' => 'GCS test: lesson with notes', 'type' => 'lesson' ],
		],
	],
] );
add_user_meta( $admin, 'academy_instructor_course_id', $course );

$user = function ( string $login, string $name ) use ( &$fx ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		WP_CLI::error( "User $login already exists; remove it or run cleanup.php." );
	}
	$id = wp_insert_user( [ 'user_login' => $login, 'user_pass' => GCS_FIXTURE_PASSWORD, 'user_email' => $login . '@example.test', 'display_name' => $name, 'first_name' => $name, 'role' => 'subscriber' ] );
	update_user_meta( $id, '_gcs_fixture', 1 );
	$fx['users'][] = (int) $id;
	return (int) $id;
};
$student  = $user( 'gcs_student', 'GCS Student' );
$outsider = $user( 'gcs_outsider', 'GCS Outsider' );
\Academy\Helper::do_enroll( $course, $student );

// ── StoreEngine ─────────────────────────────────────────────────────────────

$product = new \StoreEngine\Classes\Product\SimpleProduct();
$product->set_name( 'GCS Test eBook' );
$product->set_status( 'publish' );
$product->set_type( 'simple' );
$product->set_shipping_type( 'digital' );
$product->set_digital_auto_complete( true );
$product->set_downloadable_files( [
	'gcs-ebook'    => [ 'id' => 'gcs-ebook', 'name' => 'GCS Test eBook (media library)', 'file' => wp_get_attachment_url( $f['ebook'] ), 'enabled' => true ],
	'gcs-software' => [ 'id' => 'gcs-software', 'name' => 'GCS Test Software (storeengine_uploads)', 'file' => wp_upload_dir()['baseurl'] . '/storeengine_uploads/gcs-test-software.zip', 'enabled' => true ],
] );
$product->save();
$product_id    = (int) $product->get_id();
$fx['posts'][] = $product_id;
update_post_meta( $product_id, '_gcs_fixture', 1 );

$price = new \StoreEngine\Classes\Price();
$price->set_price_name( 'Standard' );
$price->set_price_type( 'onetime' );
$price->set_price( 9 );
$price->set_product_id( $product_id );
$price->save();
$fx['prices'][] = (int) $price->get_id();

$order = new \StoreEngine\Classes\Order();
$order->set_customer_id( $student );
$order->set_billing_email( 'gcs_student@example.test' );
$order->set_billing_first_name( 'GCS' );
$order->set_currency( 'USD' );
$order->set_payment_method( 'cod' );
$order->add_product( (int) $price->get_id(), 1 );
$order->calculate_totals();
$order->set_paid_status( 'paid', 'gcs-fixture' );
$order->set_status( 'completed' );
$order->save();
$fx['orders'][] = (int) $order->get_id();

// Grant the downloads the way a paid checkout does, in case the status hook didn't.
\StoreEngine\Hooks\DownloadPermissionHooks::init()->grant_product_to_order( $order, $product_id );

update_option( 'gcs_fixture', $fx, false );

// ── Report ──────────────────────────────────────────────────────────────────

global $wpdb;
// Re-read the saved order: an unsaved one invents a new key on every call.
$order     = \StoreEngine\Utils\Helper::get_order( $order->get_id() );
$downloads = [];
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT download_id FROM {$wpdb->prefix}storeengine_downloadable_product_permissions WHERE order_id = %d", $order->get_id() ) ) as $row ) {
	$downloads[] = add_query_arg( [
		'download_file' => $product_id,
		'order'         => $order->get_order_key(),
		'uid'           => hash( 'sha256', 'gcs_student@example.test' ),
		'key'           => $row->download_id,
	], home_url( '/' ) );
}

WP_CLI::success( 'Test content created.' );
WP_CLI::log( '' );
WP_CLI::log( 'Logins (password for both: ' . GCS_FIXTURE_PASSWORD . ')' );
WP_CLI::log( '  gcs_student   enrolled in the course, bought the product' );
WP_CLI::log( '  gcs_outsider  not enrolled, bought nothing' );
WP_CLI::log( '' );
WP_CLI::log( 'Academy LMS' );
WP_CLI::log( '  Course:        ' . get_permalink( $course ) );
WP_CLI::log( '  Start course:  ' . \Academy\Helper::get_start_course_permalink( $course ) );
WP_CLI::log( "  Lessons:       video #$l_video, notes #$l_notes, free preview #$l_preview" );
WP_CLI::log( '  Attachments:   video #' . $f['video'] . ', subtitles #' . $f['subtitle'] . ', notes #' . $f['notes'] . ', preview handout #' . $f['preview'] . ', thumbnail #' . $f['thumb'] . ' (stays public)' );
WP_CLI::log( '' );
WP_CLI::log( 'StoreEngine' );
WP_CLI::log( '  Product:       ' . get_permalink( $product_id ) . " (#$product_id, \$9)" );
WP_CLI::log( '  Order:         #' . $order->get_id() . ' for gcs_student, paid' );
foreach ( $downloads as $i => $url ) {
	WP_CLI::log( '  Download ' . ( $i + 1 ) . ':    ' . $url );
}
WP_CLI::log( '  Media-library download: attachment #' . $f['ebook'] . '; other download: ' . str_replace( ABSPATH, '', $software_path ) );
