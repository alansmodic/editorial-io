<?php
/**
 * Core plugin class for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO
 *
 * Core plugin class for managing editorial workflow features.
 */
class Editorial_IO {

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Editorial_IO
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_editorial_panel_block' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'protect_special_revisions' ), 10, 3 );
	}

	/**
	 * Register post meta for editorial features.
	 */
	public function register_meta() {
		// Meta keys for revision posts (used by staged revisions).
		$revision_meta = array(
			'_editorial_staged_revision'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'_editorial_staged_status'       => array(
				'type'    => 'string',
				'default' => 'pending',
			),
			'_editorial_staged_publish_date' => array(
				'type'    => 'string',
				'default' => '',
			),
			'_editorial_staged_author'       => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'_editorial_staged_notes'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'_editorial_revision_type'       => array(
				'type'    => 'string',
				'default' => 'standard',
			),
		);

		foreach ( $revision_meta as $key => $args ) {
			register_post_meta(
				'',
				$key,
				array(
					'type'              => $args['type'],
					'single'            => true,
					'default'           => $args['default'],
					'show_in_rest'      => true,
					'auth_callback'     => array( $this, 'meta_auth_callback' ),
					'sanitize_callback' => $this->get_sanitize_callback( $args['type'] ),
				)
			);
		}

		// Register meta for parent posts to track editorial state.
		$public_post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $public_post_types as $post_type ) {
			$post_meta = array(
				'_editorial_has_staged_revision'   => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'_editorial_last_editor_session'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'_editorial_checklist_bypassed'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
				// Editorial Control Panel meta fields.
				'_editorial_workflow_status'       => array(
					'type'    => 'string',
					'default' => 'draft',
				),
				'_editorial_priority'              => array(
					'type'    => 'string',
					'default' => 'normal',
				),
				'_editorial_assigned_to'           => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'_editorial_due_date'              => array(
					'type'    => 'string',
					'default' => '',
				),
				'_editorial_internal_notes'        => array(
					'type'    => 'string',
					'default' => '',
				),
			);

			foreach ( $post_meta as $key => $args ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => $args['type'],
						'single'            => true,
						'default'           => $args['default'],
						'show_in_rest'      => true,
						'auth_callback'     => array( $this, 'meta_auth_callback' ),
						'sanitize_callback' => $this->get_sanitize_callback( $args['type'] ),
					)
				);
			}
		}
	}

	/**
	 * Get sanitize callback for meta type.
	 *
	 * @param string $type Meta type.
	 * @return callable
	 */
	private function get_sanitize_callback( $type ) {
		switch ( $type ) {
			case 'boolean':
				return 'rest_sanitize_boolean';
			case 'integer':
				return 'absint';
			case 'string':
			default:
				return 'sanitize_text_field';
		}
	}

	/**
	 * Auth callback for meta.
	 *
	 * @param bool   $allowed   Whether the user can add the post meta.
	 * @param string $meta_key  The meta key.
	 * @param int    $object_id The object ID.
	 * @return bool
	 */
	public function meta_auth_callback( $allowed, $meta_key, $object_id ) {
		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new Editorial_IO_REST_Controller();
		$controller->register_routes();
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_editor_assets() {
		global $post;

		if ( ! $post || ! $this->should_load_editor_assets( $post ) ) {
			return;
		}

		// Enqueue main editor script.
		wp_enqueue_script(
			'editorial-io-editor',
			EDITORIAL_IO_ASSETS_URL . 'js/editor.js',
			array(
				'wp-plugins',
				'wp-edit-post',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-api-fetch',
				'wp-i18n',
				'wp-blocks',
				'wp-core-data',
			),
			EDITORIAL_IO_VERSION,
			true
		);

		// Enqueue editor styles.
		wp_enqueue_style(
			'editorial-io-editor',
			EDITORIAL_IO_ASSETS_URL . 'css/editor.css',
			array(),
			EDITORIAL_IO_VERSION
		);

		// Pass configuration to JavaScript.
		$settings = Editorial_IO_Settings::get_instance();
		wp_localize_script(
			'editorial-io-editor',
			'editorialIOData',
			array(
				'postId'               => $post->ID,
				'stagedRevisionId'     => (int) get_post_meta( $post->ID, '_editorial_has_staged_revision', true ),
				'postPermalink'        => get_permalink( $post->ID ),
				'restNonce'            => wp_create_nonce( 'wp_rest' ),
				'siteUrl'              => get_site_url(),
				'features'             => $settings->get_enabled_features(),
				'config'               => $this->get_frontend_config(),
				'strings'              => $this->get_localized_strings(),
				'controlPanelEnabled'  => $settings->is_feature_enabled( 'editorial_control_panel' ),
				'users'                => $this->get_editorial_users(),
			)
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on Editorial.io admin pages.
		if ( strpos( $hook, 'editorial-io' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'editorial-io-admin',
			EDITORIAL_IO_ASSETS_URL . 'css/admin.css',
			array(),
			EDITORIAL_IO_VERSION
		);

		wp_enqueue_script(
			'editorial-io-admin',
			EDITORIAL_IO_ASSETS_URL . 'js/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			EDITORIAL_IO_VERSION,
			true
		);
	}

	/**
	 * Check if we should load editor assets for this post.
	 *
	 * @param WP_Post $post The current post.
	 * @return bool
	 */
	private function should_load_editor_assets( $post ) {
		// Load for any post that supports revisions.
		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return false;
		}

		// Load for published posts, drafts, and pending posts.
		$allowed_statuses = array( 'publish', 'draft', 'auto-draft', 'pending', 'future' );
		return in_array( $post->post_status, $allowed_statuses, true );
	}

	/**
	 * Get frontend configuration for JavaScript.
	 *
	 * @return array
	 */
	private function get_frontend_config() {
		$settings = Editorial_IO_Settings::get_instance();
		$config   = array();

		// Publication checklist config.
		if ( $settings->is_feature_enabled( 'publication_checklist' ) ) {
			$config['checklist'] = array(
				'enabled' => true,
				'items'   => $settings->get_checklist_items(),
			);
		}

		// Revision timeline config.
		if ( $settings->is_feature_enabled( 'revision_timeline' ) ) {
			$config['timeline'] = array(
				'enabled'           => true,
				'per_page'          => $settings->get_option( 'timeline_per_page', 50 ),
				'show_autosaves'    => $settings->get_option( 'timeline_show_autosaves', false ),
				'show_media_changes' => $settings->is_feature_enabled( 'media_change_tracking' ),
			);
		}

		// Diff viewer config.
		if ( $settings->is_feature_enabled( 'word_level_diffs' ) ) {
			$config['diffs'] = array(
				'enabled'      => true,
				'word_level'   => true,
				'show_media'   => $settings->is_feature_enabled( 'media_change_tracking' ),
				'context_lines' => $settings->get_option( 'diff_context_lines', 3 ),
			);
		}

		return $config;
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array
	 */
	private function get_localized_strings() {
		return array(
			'pluginTitle'       => __( 'Editorial.io', 'editorial-io' ),
			'save'              => __( 'Save', 'editorial-io' ),
			'cancel'            => __( 'Cancel', 'editorial-io' ),
			'loading'           => __( 'Loading...', 'editorial-io' ),
			'error'             => __( 'An error occurred', 'editorial-io' ),
			'success'           => __( 'Success', 'editorial-io' ),
			'noChanges'         => __( 'No changes detected', 'editorial-io' ),

			// Staged revisions.
			'saveAsRewrite'     => __( 'Save as Rewrite', 'editorial-io' ),
			'publishNow'        => __( 'Publish Now', 'editorial-io' ),
			'schedulePublish'   => __( 'Schedule Publishing', 'editorial-io' ),

			// Publication checklist.
			'checklistTitle'    => __( 'Before You Publish', 'editorial-io' ),
			'checklistSubtitle' => __( 'Please review the checklist below before publishing your changes.', 'editorial-io' ),
			'confirmAndPublish' => __( 'Confirm & Publish Now', 'editorial-io' ),
			'requiredItems'     => __( 'Please check all required items before publishing.', 'editorial-io' ),

			// Revision timeline.
			'revisionHistory'   => __( 'Revision History', 'editorial-io' ),
			'noRevisions'       => __( 'No revisions found.', 'editorial-io' ),
			'viewDiff'          => __( 'View Diff', 'editorial-io' ),
			'restore'           => __( 'Restore This Version', 'editorial-io' ),
			'restoreConfirm'    => __( 'Are you sure you want to restore this revision? This will replace the current content with this older version.', 'editorial-io' ),

			// Diffs.
			'diffTitle'         => __( 'Revision Diff', 'editorial-io' ),
			'inline'            => __( 'Inline', 'editorial-io' ),
			'sideBySide'        => __( 'Side by Side', 'editorial-io' ),
			'changed'           => __( 'Changed:', 'editorial-io' ),

			// Media changes.
			'mediaChanges'      => __( 'Media Changes', 'editorial-io' ),
			'mediaAdded'        => __( 'Added Media', 'editorial-io' ),
			'mediaRemoved'      => __( 'Removed Media', 'editorial-io' ),

			// Time/date.
			'ago'               => __( 'ago', 'editorial-io' ),
			'justNow'           => __( 'Just now', 'editorial-io' ),
		);
	}

	/**
	 * Protect special revisions from being overwritten by normal revision process.
	 *
	 * @param bool    $post_has_changed Whether the post has changed.
	 * @param WP_Post $last_revision    The last revision post object.
	 * @param WP_Post $post             The post object.
	 * @return bool
	 */
	public function protect_special_revisions( $post_has_changed, $last_revision, $post ) {
		// If the last revision is a staged revision, ensure WordPress creates a new revision.
		if ( get_metadata( 'post', $last_revision->ID, '_editorial_staged_revision', true ) ) {
			return true;
		}

		// If the last revision has special editorial metadata, protect it.
		$revision_type = get_metadata( 'post', $last_revision->ID, '_editorial_revision_type', true );
		if ( $revision_type && $revision_type !== 'standard' ) {
			return true;
		}

		return $post_has_changed;
	}

	/**
	 * Get supported post types for editorial features.
	 *
	 * @return array
	 */
	public static function get_supported_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		/**
		 * Filter the post types that support editorial features.
		 *
		 * @param array $post_types Array of post type names.
		 */
		return apply_filters( 'editorial_io_supported_post_types', $post_types );
	}

	/**
	 * Check if a post type supports editorial features.
	 *
	 * @param string $post_type The post type to check.
	 * @return bool
	 */
	public static function post_type_supports_editorial( $post_type ) {
		$supported_types = self::get_supported_post_types();
		return in_array( $post_type, $supported_types, true ) && post_type_supports( $post_type, 'revisions' );
	}

	/**
	 * Register the Editorial Control Panel block (backend-only, no frontend output).
	 */
	public function register_editorial_panel_block() {
		$settings = Editorial_IO_Settings::get_instance();
		if ( ! $settings->is_feature_enabled( 'editorial_control_panel' ) ) {
			return;
		}

		register_block_type(
			'editorial-io/editorial-panel',
			array(
				'render_callback' => '__return_empty_string',
			)
		);
	}

	/**
	 * Get list of users who can edit posts, for the Editorial Control Panel assignment field.
	 *
	 * @return array
	 */
	private function get_editorial_users() {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'display_name' ),
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);

		return array_map(
			function ( $user ) {
				return array(
					'id'   => (int) $user->ID,
					'name' => $user->display_name,
				);
			},
			$users
		);
	}
}