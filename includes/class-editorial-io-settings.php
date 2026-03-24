<?php
/**
 * Settings class for Editorial.io
 *
 * @package EditorialIO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Editorial_IO_Settings
 *
 * Handles plugin settings and feature toggles.
 */
class Editorial_IO_Settings {

	/**
	 * Settings option names.
	 */
	const OPTION_FEATURES = 'editorial_io_features';
	const OPTION_CHECKLIST_ITEMS = 'editorial_io_checklist_items';
	const OPTION_GENERAL = 'editorial_io_general';

	/**
	 * Available features.
	 *
	 * @var array
	 */
	private $available_features = array(
		'staged_revisions'      => array(
			'label'       => 'Staged Revisions',
			'description' => 'Save changes to published posts without immediately publishing them.',
			'default'     => true,
			'requires'    => array(),
		),
		'publication_checklist' => array(
			'label'       => 'Publication Checklist',
			'description' => 'Show a customizable checklist before publishing changes.',
			'default'     => true,
			'requires'    => array(),
		),
		'scheduled_publishing'  => array(
			'label'       => 'Scheduled Publishing',
			'description' => 'Schedule staged revisions to be published at specific times.',
			'default'     => true,
			'requires'    => array( 'staged_revisions' ),
		),
		'revision_timeline'     => array(
			'label'       => 'Visual Revision Timeline',
			'description' => 'Enhanced revision history with visual timeline and metadata.',
			'default'     => true,
			'requires'    => array(),
		),
		'word_level_diffs'      => array(
			'label'       => 'Word-level Diffs',
			'description' => 'Show detailed word-by-word differences between revisions.',
			'default'     => true,
			'requires'    => array( 'revision_timeline' ),
		),
		'media_change_tracking'   => array(
			'label'       => 'Media Change Tracking',
			'description' => 'Track and highlight changes to images, videos, and other media.',
			'default'     => true,
			'requires'    => array( 'revision_timeline' ),
		),
		'editorial_control_panel' => array(
			'label'       => 'Editorial Control Panel',
			'description' => 'Add a compact editorial workflow panel at the top of the block editor to manage status, priority, assignments, due dates, and internal notes without cluttering the sidebar.',
			'default'     => true,
			'requires'    => array(),
		),
	);

	/**
	 * Singleton instance.
	 *
	 * @var Editorial_IO_Settings|null
	 */
	private static $instance = null;

	/**
	 * Cached feature settings.
	 *
	 * @var array|null
	 */
	private $feature_cache = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Editorial_IO_Settings
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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_editorial_io_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_editorial_io_reset_features', array( $this, 'ajax_reset_features' ) );
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'editorial_io_settings', self::OPTION_FEATURES );
		register_setting( 'editorial_io_settings', self::OPTION_CHECKLIST_ITEMS );
		register_setting( 'editorial_io_settings', self::OPTION_GENERAL );
	}

	/**
	 * Set default feature states (called on activation).
	 */
	public static function set_default_features() {
		$instance = self::get_instance();
		$existing = get_option( self::OPTION_FEATURES, array() );

		// Only set defaults for features that haven't been configured yet.
		$defaults = array();
		foreach ( $instance->available_features as $key => $feature ) {
			if ( ! isset( $existing[ $key ] ) ) {
				$defaults[ $key ] = $feature['default'];
			}
		}

		if ( ! empty( $defaults ) ) {
			$merged = array_merge( $existing, $defaults );
			update_option( self::OPTION_FEATURES, $merged );
		}

		// Set default checklist items if none exist.
		if ( ! get_option( self::OPTION_CHECKLIST_ITEMS ) ) {
			$default_checklist = array(
				array(
					'label'    => __( 'I have reviewed all changes', 'editorial-io' ),
					'required' => true,
				),
				array(
					'label'    => __( 'Content has been proofread for errors', 'editorial-io' ),
					'required' => true,
				),
				array(
					'label'    => __( 'Links have been verified', 'editorial-io' ),
					'required' => false,
				),
				array(
					'label'    => __( 'SEO meta data is complete', 'editorial-io' ),
					'required' => false,
				),
			);
			update_option( self::OPTION_CHECKLIST_ITEMS, $default_checklist );
		}

		// Set default general options.
		if ( ! get_option( self::OPTION_GENERAL ) ) {
			$default_general = array(
				'timeline_per_page'       => 50,
				'timeline_show_autosaves' => false,
				'diff_context_lines'      => 3,
				'cleanup_old_revisions'   => false,
				'cleanup_days'            => 30,
			);
			update_option( self::OPTION_GENERAL, $default_general );
		}
	}

	/**
	 * Check if a feature is enabled.
	 *
	 * @param string $feature_key The feature key.
	 * @return bool
	 */
	public function is_feature_enabled( $feature_key ) {
		$features = $this->get_enabled_features();
		return isset( $features[ $feature_key ] ) && $features[ $feature_key ];
	}

	/**
	 * Get all enabled features.
	 *
	 * @return array
	 */
	public function get_enabled_features() {
		if ( null === $this->feature_cache ) {
			$this->feature_cache = get_option( self::OPTION_FEATURES, array() );

			// Apply defaults for missing features.
			foreach ( $this->available_features as $key => $feature ) {
				if ( ! isset( $this->feature_cache[ $key ] ) ) {
					$this->feature_cache[ $key ] = $feature['default'];
				}
			}
		}

		return $this->feature_cache;
	}

	/**
	 * Get available features with their metadata.
	 *
	 * @return array
	 */
	public function get_available_features() {
		return $this->available_features;
	}

	/**
	 * Enable a feature.
	 *
	 * @param string $feature_key The feature key.
	 * @return bool Success.
	 */
	public function enable_feature( $feature_key ) {
		if ( ! isset( $this->available_features[ $feature_key ] ) ) {
			return false;
		}

		$features = $this->get_enabled_features();
		$features[ $feature_key ] = true;

		return update_option( self::OPTION_FEATURES, $features );
	}

	/**
	 * Disable a feature.
	 *
	 * @param string $feature_key The feature key.
	 * @return bool Success.
	 */
	public function disable_feature( $feature_key ) {
		if ( ! isset( $this->available_features[ $feature_key ] ) ) {
			return false;
		}

		$features = $this->get_enabled_features();
		$features[ $feature_key ] = false;

		return update_option( self::OPTION_FEATURES, $features );
	}

	/**
	 * Get checklist items.
	 *
	 * @return array
	 */
	public function get_checklist_items() {
		return get_option( self::OPTION_CHECKLIST_ITEMS, array() );
	}

	/**
	 * Update checklist items.
	 *
	 * @param array $items The checklist items.
	 * @return bool Success.
	 */
	public function update_checklist_items( $items ) {
		// Sanitize items.
		$sanitized_items = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['label'] ) ) {
				$sanitized_items[] = array(
					'label'    => sanitize_text_field( $item['label'] ),
					'required' => ! empty( $item['required'] ),
				);
			}
		}

		return update_option( self::OPTION_CHECKLIST_ITEMS, $sanitized_items );
	}

	/**
	 * Get a general option.
	 *
	 * @param string $option_key The option key.
	 * @param mixed  $default    Default value.
	 * @return mixed
	 */
	public function get_option( $option_key, $default = null ) {
		$options = get_option( self::OPTION_GENERAL, array() );
		return isset( $options[ $option_key ] ) ? $options[ $option_key ] : $default;
	}

	/**
	 * Update a general option.
	 *
	 * @param string $option_key The option key.
	 * @param mixed  $value      The value.
	 * @return bool Success.
	 */
	public function update_option( $option_key, $value ) {
		$options = get_option( self::OPTION_GENERAL, array() );
		$options[ $option_key ] = $value;
		return update_option( self::OPTION_GENERAL, $options );
	}

	/**
	 * Check if feature dependencies are met.
	 *
	 * @param string $feature_key The feature key.
	 * @return bool
	 */
	public function check_feature_dependencies( $feature_key ) {
		if ( ! isset( $this->available_features[ $feature_key ] ) ) {
			return false;
		}

		$feature = $this->available_features[ $feature_key ];
		$enabled_features = $this->get_enabled_features();

		foreach ( $feature['requires'] as $required_feature ) {
			if ( empty( $enabled_features[ $required_feature ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get features that depend on a given feature.
	 *
	 * @param string $feature_key The feature key.
	 * @return array
	 */
	public function get_dependent_features( $feature_key ) {
		$dependents = array();

		foreach ( $this->available_features as $key => $feature ) {
			if ( in_array( $feature_key, $feature['requires'], true ) ) {
				$dependents[] = $key;
			}
		}

		return $dependents;
	}

	/**
	 * AJAX handler for saving settings.
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'editorial_io_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'editorial-io' ) ) );
		}

		$section = sanitize_key( $_POST['section'] ?? '' );

		switch ( $section ) {
			case 'features':
				$this->save_features_settings();
				break;
			case 'checklist':
				$this->save_checklist_settings();
				break;
			case 'general':
				$this->save_general_settings();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Invalid section.', 'editorial-io' ) ) );
		}
	}

	/**
	 * Save features settings.
	 */
	private function save_features_settings() {
		$features = $_POST['features'] ?? array();
		$sanitized = array();

		foreach ( $this->available_features as $key => $feature_info ) {
			$sanitized[ $key ] = ! empty( $features[ $key ] );
		}

		// Check dependencies and warn if necessary.
		$warnings = array();
		foreach ( $sanitized as $key => $enabled ) {
			if ( ! $enabled ) {
				$dependents = $this->get_dependent_features( $key );
				foreach ( $dependents as $dependent ) {
					if ( $sanitized[ $dependent ] ) {
						$sanitized[ $dependent ] = false;
						$warnings[] = sprintf(
							/* translators: %1$s and %2$s are feature names */
							__( 'Disabled %1$s because it requires %2$s.', 'editorial-io' ),
							$this->available_features[ $dependent ]['label'],
							$this->available_features[ $key ]['label']
						);
					}
				}
			}
		}

		update_option( self::OPTION_FEATURES, $sanitized );
		$this->feature_cache = null; // Clear cache.

		$response = array(
			'message'  => __( 'Features updated successfully.', 'editorial-io' ),
			'features' => $sanitized,
		);

		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Save checklist settings.
	 */
	private function save_checklist_settings() {
		$items = $_POST['items'] ?? array();
		$this->update_checklist_items( $items );

		wp_send_json_success( array(
			'message' => __( 'Checklist updated successfully.', 'editorial-io' ),
		) );
	}

	/**
	 * Save general settings.
	 */
	private function save_general_settings() {
		$options = $_POST['options'] ?? array();
		$sanitized = array();

		// Define allowed options and their sanitization.
		$allowed_options = array(
			'timeline_per_page'       => 'absint',
			'timeline_show_autosaves' => 'rest_sanitize_boolean',
			'diff_context_lines'      => 'absint',
			'cleanup_old_revisions'   => 'rest_sanitize_boolean',
			'cleanup_days'            => 'absint',
		);

		foreach ( $allowed_options as $key => $sanitize_callback ) {
			if ( isset( $options[ $key ] ) ) {
				$sanitized[ $key ] = call_user_func( $sanitize_callback, $options[ $key ] );
			}
		}

		update_option( self::OPTION_GENERAL, $sanitized );

		wp_send_json_success( array(
			'message' => __( 'General settings updated successfully.', 'editorial-io' ),
		) );
	}

	/**
	 * AJAX handler for resetting features to defaults.
	 */
	public function ajax_reset_features() {
		check_ajax_referer( 'editorial_io_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'editorial-io' ) ) );
		}

		$defaults = array();
		foreach ( $this->available_features as $key => $feature ) {
			$defaults[ $key ] = $feature['default'];
		}

		update_option( self::OPTION_FEATURES, $defaults );
		$this->feature_cache = null; // Clear cache.

		wp_send_json_success( array(
			'message'  => __( 'Features reset to defaults successfully.', 'editorial-io' ),
			'features' => $defaults,
		) );
	}
}