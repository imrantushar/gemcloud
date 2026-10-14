<?php
/**
 * Removes exactly what seed.php created, cloud copies included.
 *
 *     wp eval-file wp-content/plugins/gemcloudstorage/tests/fixture/cleanup.php
 *
 * phpcs:ignoreFile -- developer fixture.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$fx = get_option( 'gcs_fixture' );
if ( ! is_array( $fx ) ) {
	WP_CLI::error( 'No test content recorded (gcs_fixture option is missing).' );
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );

// Orders and their download permissions.
foreach ( $fx['orders'] as $order_id ) {
	$wpdb->delete( $wpdb->prefix . 'storeengine_downloadable_product_permissions', [ 'order_id' => $order_id ] );
	$order = \StoreEngine\Utils\Helper::get_order( $order_id );
	if ( $order && ! is_wp_error( $order ) ) {
		$order->delete( true );
	}
}
foreach ( $fx['prices'] as $price_id ) {
	$wpdb->delete( $wpdb->prefix . 'storeengine_product_price', [ 'id' => $price_id ] );
}

// Courses, products (their cloud copies are deleted by GemCloudStorage's hooks).
foreach ( $fx['posts'] as $post_id ) {
	if ( 'academy_courses' === get_post_type( $post_id ) ) {
		delete_user_meta( get_post_field( 'post_author', $post_id ), 'academy_instructor_course_id', $post_id );
	}
	wp_delete_post( $post_id, true );
}

// Lessons, in whichever storage Academy uses.
foreach ( $fx['lessons'] as $lesson_id ) {
	$wpdb->delete( $wpdb->prefix . 'academy_lessonmeta', [ 'lesson_id' => $lesson_id ] );
	$wpdb->delete( $wpdb->prefix . 'academy_lessons', [ 'ID' => $lesson_id ] );
	if ( 'academy_lessons' === get_post_type( $lesson_id ) ) {
		wp_delete_post( $lesson_id, true );
	}
}

// Users and their enrolments.
foreach ( $fx['users'] as $user_id ) {
	foreach ( get_posts( [ 'post_type' => 'any', 'post_status' => 'any', 'author' => $user_id, 'numberposts' => -1, 'fields' => 'ids' ] ) as $p ) {
		wp_delete_post( $p, true );
	}
	wp_delete_user( $user_id );
}

// Attachments last: deleting them queues deletion of their cloud copies.
foreach ( $fx['attachments'] as $attachment_id ) {
	wp_delete_attachment( $attachment_id, true );
}
foreach ( $fx['files'] as $file ) {
	if ( file_exists( $file ) ) {
		wp_delete_file( $file );
	}
}

delete_option( 'gcs_fixture' );
WP_CLI::success( 'Test content removed. Cloud copies are deleted by the background queue.' );
