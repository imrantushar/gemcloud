<?php
namespace GemCloudStorage\Integrations;

use GemCloudStorage\Contexts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Academy LMS: course content is private.
 *
 * Private: lesson videos (self-hosted) and their subtitles, lesson
 * attachments, assignment files, and student assignment submissions.
 * Public: course thumbnails, lesson featured images, intro videos, avatars.
 *
 * Who can open a private file, checked on every click:
 *  - lesson files: anyone Academy lets into one of the lessons using it
 *    (enrolled, the course instructor, a public course, a preview lesson);
 *  - assignment files: anyone Academy lets into one of the assignments;
 *  - submissions: the student who submitted and the course's instructors;
 *  - always: administrators and anyone who can edit the file.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery -- Academy's own meta tables, looked up by value.
 */
final class Academy extends Integration {

	const KEY = 'academy.course_files';

	/** @var array<string,int[]> per-request cache of topic => course ids */
	private array $courses = [];

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'Academy LMS', 'gemcloudstorage' );
	}

	public function detected(): bool {
		return defined( 'ACADEMY_VERSION' ) && class_exists( '\Academy\Helper' );
	}

	/** Lesson meta role => kind switch. */
	const ROLE_KIND = [
		'video'      => 'lesson_videos',
		'subtitle'   => 'subtitles',
		'attachment' => 'lesson_attachments',
	];

	public function kinds(): array {
		return [
			'lesson_videos'      => [
				'label'       => __( 'Lesson videos', 'gemcloudstorage' ),
				'description' => __( 'Self-hosted videos played in lessons. Links last at least 4 hours so a long lesson keeps playing.', 'gemcloudstorage' ),
			],
			'subtitles'          => [
				'label'       => __( 'Video subtitles', 'gemcloudstorage' ),
				'description' => __( 'Caption files (.vtt) of lesson videos, streamed through this site.', 'gemcloudstorage' ),
			],
			'lesson_attachments' => [
				'label'       => __( 'Lesson attachments', 'gemcloudstorage' ),
				'description' => __( 'Paid materials attached to lessons: PDFs, slides, ZIPs.', 'gemcloudstorage' ),
			],
			'assignment_files'   => [
				'label'       => __( 'Assignment files', 'gemcloudstorage' ),
				'description' => __( 'Files instructors attach to assignments.', 'gemcloudstorage' ),
			],
			'submissions'        => [
				'label'       => __( 'Assignment submissions', 'gemcloudstorage' ),
				'description' => __( 'Files students submit. Only the student and the course instructors can open them.', 'gemcloudstorage' ),
			],
		];
	}

	public function register_context(): void {
		Contexts::register( self::KEY, [
			'label'       => __( 'Academy LMS course files', 'gemcloudstorage' ),
			'description' => __( 'Lesson videos, subtitles and attachments, assignment files and student submissions. Kept in the private bucket and opened only by students who can access the lesson, the course instructors and admins.', 'gemcloudstorage' ),
			'visibility'  => 'private',
			'source'      => 'Academy LMS',
			'locked'      => [ 'visibility' ],
			'kinds'       => $this->kinds(),
		] );
	}

	public function hooks(): void {
		// The course builder saves lessons through Academy's REST API.
		add_filter( 'rest_request_after_callbacks', [ $this, 'after_rest' ], 10, 3 );

		// Assignment files and submissions are ordinary post and comment meta.
		add_action( 'added_post_meta', [ $this, 'on_post_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'on_post_meta' ], 10, 4 );
		add_action( 'added_comment_meta', [ $this, 'on_comment_meta' ], 10, 4 );
		add_action( 'updated_comment_meta', [ $this, 'on_comment_meta' ], 10, 4 );

		// Catch anything saved another way (imports, older builders) the first
		// time it is shown. Cheap once the files are already protected.
		add_action( 'academy/frontend/before_render_lesson', [ $this, 'on_render_lesson' ], 5, 3 );
		add_action( 'academy/templates/curriculums/before_render_lesson_content', [ $this, 'on_render_lesson' ], 5, 3 );
		add_action( 'academy_pro_assignments/frontend/before_render_assignment', [ $this, 'on_render_assignment' ], 5, 2 );
	}

	// ── Events ──────────────────────────────────────────────────────────────

	public function after_rest( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || is_wp_error( $response ) || 'GET' === $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#^/academy/v1/lessons(?:/(\d+))?$#', $request->get_route(), $m ) ) {
			return $response;
		}

		$lesson_id = (int) ( $m[1] ?? 0 );
		if ( ! $lesson_id ) {
			$data      = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
			$lesson_id = (int) ( is_array( $data ) ? ( $data['ID'] ?? $data['id'] ?? 0 ) : ( $data->ID ?? $data->id ?? 0 ) );
		}
		if ( $lesson_id ) {
			$this->protect( $this->lesson_attachment_ids( $lesson_id ) );
		}

		return $response;
	}

	public function on_post_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( 'academy_assignment_attachment' === $meta_key ) {
			$this->protect( [ (int) $meta_value ] );
		}
	}

	public function on_comment_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( 'academy_pro_assignment_attachment' === $meta_key ) {
			$this->protect( [ (int) $meta_value ] );
		}
	}

	public function on_render_lesson( $lesson, $course_id = 0, $lesson_id = 0 ): void {
		$lesson_id = (int) ( $lesson_id ?: ( is_array( $lesson ) ? ( $lesson['ID'] ?? 0 ) : 0 ) );
		if ( $lesson_id ) {
			$this->protect( $this->lesson_attachment_ids( $lesson_id ) );
		}
	}

	public function on_render_assignment( $course_id, $assignment_id ): void {
		$this->protect( [ (int) get_post_meta( (int) $assignment_id, 'academy_assignment_attachment', true ) ] );
	}

	// ── Integration ─────────────────────────────────────────────────────────

	public function uses( int $attachment_id, bool $all_kinds = false ): array {
		$reasons = [];
		$roles   = [
			'video'      => __( 'Video of lesson "%s"', 'gemcloudstorage' ),
			'subtitle'   => __( 'Subtitles of lesson "%s"', 'gemcloudstorage' ),
			'attachment' => __( 'Attachment of lesson "%s"', 'gemcloudstorage' ),
		];

		foreach ( $this->lessons_using( $attachment_id ) as $lesson_id => $role ) {
			if ( $this->kind_on( self::ROLE_KIND[ $role ], $all_kinds ) ) {
				$reasons[] = sprintf( $roles[ $role ], $this->lesson_title( $lesson_id ) );
			}
		}
		$assignments = $this->kind_on( 'assignment_files', $all_kinds ) ? $this->assignments_using( $attachment_id ) : [];
		foreach ( $assignments as $assignment_id ) {
			/* translators: %s: assignment title */
			$reasons[] = sprintf( __( 'File of assignment "%s"', 'gemcloudstorage' ), get_the_title( $assignment_id ) );
		}
		$submissions = $this->kind_on( 'submissions', $all_kinds ) ? $this->submissions_using( $attachment_id ) : [];
		foreach ( $submissions as $comment ) {
			$user = get_userdata( (int) $comment->user_id );
			/* translators: 1: assignment title, 2: student name */
			$reasons[] = sprintf( __( 'Submission to "%1$s" by %2$s', 'gemcloudstorage' ), get_the_title( (int) $comment->comment_post_ID ), $user ? $user->display_name : '#' . (int) $comment->user_id );
		}

		return $reasons;
	}

	public function grants( int $attachment_id ): bool {
		$user_id = get_current_user_id();

		foreach ( array_keys( $this->lessons_using( $attachment_id ) ) as $lesson_id ) {
			foreach ( $this->courses_with( $lesson_id, 'lesson' ) as $course_id ) {
				if ( \Academy\Helper::has_permission_to_access_lesson_curriculum( $course_id, $lesson_id, $user_id ) ) {
					return true;
				}
			}
		}

		foreach ( $this->assignments_using( $attachment_id ) as $assignment_id ) {
			foreach ( $this->courses_with( $assignment_id, 'assignment' ) as $course_id ) {
				if ( \Academy\Helper::has_permission_to_access_curriculum( $course_id, $user_id, $assignment_id, 'assignment' ) ) {
					return true;
				}
			}
		}

		foreach ( $this->submissions_using( $attachment_id ) as $comment ) {
			if ( $user_id && (int) $comment->user_id === $user_id ) {
				return true;
			}
			foreach ( $this->courses_with( (int) $comment->comment_post_ID, 'assignment' ) as $course_id ) {
				if ( $user_id && \Academy\Helper::is_instructor_of_this_course( $user_id, $course_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public function all_attachment_ids(): array {
		global $wpdb;

		$ids = [];
		foreach ( $this->lesson_meta_rows( "( meta_key = 'attachment' AND meta_value <> '' AND meta_value <> '0' ) OR ( meta_key = 'video_source' AND ( meta_value LIKE '%html5%' OR meta_value LIKE '%\"subtitle\":%' ) )" ) as $row ) {
			foreach ( self::ids_in_row( $row ) as $id => $role ) {
				if ( $this->kind_on( self::ROLE_KIND[ $role ] ) ) {
					$ids[] = $id;
				}
			}
		}

		if ( $this->kind_on( 'assignment_files' ) ) {
			$ids = array_merge( $ids, $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'academy_assignment_attachment' AND meta_value <> '' AND meta_value <> '0'" ) );
		}
		if ( $this->kind_on( 'submissions' ) ) {
			$ids = array_merge( $ids, $wpdb->get_col( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = 'academy_pro_assignment_attachment' AND meta_value <> '' AND meta_value <> '0'" ) );
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	// ── Lookups ─────────────────────────────────────────────────────────────

	/**
	 * Attachments a lesson uses: its attachment, its self-hosted video and
	 * the video's subtitles.
	 *
	 * @return int[]
	 */
	public function lesson_attachment_ids( int $lesson_id ): array {
		$ids   = [ (int) \Academy\Helper::get_lesson_meta( $lesson_id, 'attachment' ) ];
		$video = \Academy\Helper::get_lesson_meta( $lesson_id, 'video_source' );
		$video = is_string( $video ) ? json_decode( $video, true ) : $video;

		if ( is_array( $video ) ) {
			if ( 'html5' === ( $video['type'] ?? '' ) ) {
				$ids[] = (int) ( $video['id'] ?? 0 );
			}
			$ids[] = (int) ( $video['subtitle'] ?? 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * @return array<int,string> lesson id => video|subtitle|attachment
	 */
	private function lessons_using( int $attachment_id ): array {
		global $wpdb;

		$id    = (string) $attachment_id;
		$likes = [];
		foreach ( [ 'id', 'subtitle' ] as $field ) {
			$likes[] = $wpdb->prepare( 'meta_value LIKE %s', '%"' . $field . '":' . $id . ',%' );
			$likes[] = $wpdb->prepare( 'meta_value LIKE %s', '%"' . $field . '":' . $id . '}%' );
			$likes[] = $wpdb->prepare( 'meta_value LIKE %s', '%"' . $field . '":"' . $id . '"%' );
		}
		$where = $wpdb->prepare( "( meta_key = 'attachment' AND meta_value = %s )", $id ) . " OR ( meta_key = 'video_source' AND ( " . implode( ' OR ', $likes ) . ' ) )';

		$out = [];
		foreach ( $this->lesson_meta_rows( $where ) as $row ) {
			$role = self::ids_in_row( $row )[ $attachment_id ] ?? null;
			if ( $role && ( ! isset( $out[ (int) $row->lesson_id ] ) || 'video' === $role ) ) {
				$out[ (int) $row->lesson_id ] = $role;
			}
		}
		return $out;
	}

	/**
	 * Lesson meta rows matching $where, from whichever storage Academy uses.
	 */
	private function lesson_meta_rows( string $where ): array {
		global $wpdb;

		$hp = ! class_exists( '\Academy\Lesson\LessonApi\Lesson' ) || \Academy\Lesson\LessonApi\Lesson::is_hp();

		if ( $hp ) {
			$table = $wpdb->prefix . 'academy_lessonmeta';
			return (array) $wpdb->get_results( "SELECT lesson_id, meta_key, meta_value FROM {$table} WHERE {$where}" );
		}

		return (array) $wpdb->get_results(
			"SELECT pm.post_id AS lesson_id, pm.meta_key, pm.meta_value
			FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'academy_lessons'
			WHERE " . str_replace( [ 'meta_key', 'meta_value' ], [ 'pm.meta_key', 'pm.meta_value' ], $where )
		);
	}

	/**
	 * @return array<int,string> attachment id => role, as stored in one row
	 */
	private static function ids_in_row( $row ): array {
		if ( 'attachment' === $row->meta_key ) {
			$id = (int) $row->meta_value;
			return $id ? [ $id => 'attachment' ] : [];
		}

		$video = json_decode( (string) $row->meta_value, true );
		if ( ! is_array( $video ) ) {
			return [];
		}

		$out      = [];
		$subtitle = (int) ( $video['subtitle'] ?? 0 );
		if ( $subtitle ) {
			$out[ $subtitle ] = 'subtitle';
		}
		$id = (int) ( $video['id'] ?? 0 );
		if ( $id && 'html5' === ( $video['type'] ?? '' ) ) {
			$out[ $id ] = 'video';
		}
		return $out;
	}

	/**
	 * @return int[] assignment post ids
	 */
	private function assignments_using( int $attachment_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = 'academy_assignment_attachment' AND pm.meta_value = %s AND p.post_type = 'academy_assignments'",
			(string) $attachment_id
		) ) );
	}

	/**
	 * @return object[] comments with user_id and comment_post_ID
	 */
	private function submissions_using( int $attachment_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT c.comment_ID, c.user_id, c.comment_post_ID FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			WHERE cm.meta_key = 'academy_pro_assignment_attachment' AND cm.meta_value = %s",
			(string) $attachment_id
		) );
	}

	/**
	 * Courses whose curriculum contains this lesson or assignment. Same
	 * lookup Academy uses when it removes a topic from curriculums.
	 *
	 * @return int[]
	 */
	private function courses_with( int $topic_id, string $type ): array {
		global $wpdb;

		$cache = $type . ':' . $topic_id;
		if ( isset( $this->courses[ $cache ] ) ) {
			return $this->courses[ $cache ];
		}

		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = 'academy_course_curriculum'
			AND ( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )",
			'%"id";i:' . $topic_id . ';%',
			'%"id";s:' . strlen( (string) $topic_id ) . ':"' . $topic_id . '";%',
			'%"id":' . $topic_id . '%',
			'%"id":"' . $topic_id . '"%'
		) );

		$courses = [];
		foreach ( array_map( 'intval', $candidates ) as $course_id ) {
			if ( \Academy\Helper::is_course_curriculum( $course_id, $topic_id, $type ) ) {
				$courses[] = $course_id;
			}
		}

		return $this->courses[ $cache ] = $courses;
	}

	private function lesson_title( int $lesson_id ): string {
		global $wpdb;

		$hp = ! class_exists( '\Academy\Lesson\LessonApi\Lesson' ) || \Academy\Lesson\LessonApi\Lesson::is_hp();
		if ( $hp ) {
			$title = $wpdb->get_var( $wpdb->prepare( "SELECT lesson_title FROM {$wpdb->prefix}academy_lessons WHERE ID = %d", $lesson_id ) );
		} else {
			$title = get_the_title( $lesson_id );
		}

		return $title ? wp_strip_all_tags( (string) $title ) : '#' . $lesson_id;
	}
}
