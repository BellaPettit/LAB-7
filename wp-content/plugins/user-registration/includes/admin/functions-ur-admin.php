<?php
/**
 * UserRegistration Admin Functions
 *
 * @package  UserRegistration/Admin/Functions
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'wp_dashboard_setup', 'ur_add_dashboard_widget' );

/**
 * Register the user registration user activity dashboard widget.
 *
 * @since 1.5.8
 */
function ur_add_dashboard_widget() {

	if ( ! current_user_can( 'manage_user_registration' ) ) {
		return;
	}

	wp_add_dashboard_widget( 'user_registration_dashboard_status', __( 'User Registration Activity', 'user-registration' ), 'ur_status_widget' );
}

/**
 * Content to the user_registration_dashboard_status widget.
 *
 * @since 1.5.8
 */
function ur_status_widget() {

	wp_enqueue_script( 'user-registration-dashboard-widget-js' );
	wp_localize_script(
		'user-registration-dashboard-widget-js',
		'ur_widget_params',
		array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'loading'      => __( 'loading...', 'user-registration' ),
			'widget_nonce' => wp_create_nonce( 'dashboard-widget' ),
		)
	);

	ur_get_template( 'dashboard-widget.php' );
}

/**
 * Report for the user registration activity.
 *
 * @param int $form_id Form ID.
 * @return array
 */
function ur_get_user_report( $form_id ) {
	global $wpdb;
	$current_date = current_time( 'Y-m-d' );

	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT
				COUNT(*) AS total_users,
				SUM(CASE WHEN DATE(user_registered) = %s THEN 1 ELSE 0 END) AS today_users,
				SUM(CASE WHEN DATE(user_registered) > DATE_SUB(%s, INTERVAL 1 WEEK) THEN 1 ELSE 0 END) AS last_week_users,
				SUM(CASE WHEN DATE(user_registered) > DATE_SUB(%s, INTERVAL 1 MONTH) THEN 1 ELSE 0 END) AS last_month_users
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
			WHERE um.meta_key = 'ur_form_id' AND um.meta_value = %s
			",
			$current_date,
			$current_date,
			$current_date,
			$form_id
		)
	);

	$report = array();

	if ( $results ) {
		$report = array(
			'total_users'      => empty( $results[0]->total_users ) ? 0 : $results[0]->total_users,
			'today_users'      => empty( $results[0]->today_users ) ? 0 : $results[0]->today_users,
			'last_week_users'  => empty( $results[0]->last_week_users ) ? 0 : $results[0]->last_week_users,
			'last_month_users' => empty( $results[0]->last_month_users ) ? 0 : $results[0]->last_month_users,
		);

	}

	return $report;
}

/**
 * Get all UserRegistration screen ids.
 *
 * @return array
 */
function ur_get_screen_ids() {

	$ur_screen_id = sanitize_title( 'User Registration Membership' );
	$screen_ids   = array(
		'toplevel_page_user-registration',
		$ur_screen_id . '_page_user-registration-dashboard',
		$ur_screen_id . '_page_user-registration-analytics',
		$ur_screen_id . '_page_add-new-registration',
		$ur_screen_id . '_page_user-registration-users',
		$ur_screen_id . '_page_user-registration-login-forms',
		$ur_screen_id . '_page_user-registration-settings',
		$ur_screen_id . '_page_user-registration-mailchimp',
		$ur_screen_id . '_page_user-registration-status',
		$ur_screen_id . '_page_user-registration-addons',
		$ur_screen_id . '_page_user-registration-export-users',
		$ur_screen_id . '_page_user-registration-email-templates',
		$ur_screen_id . '_page_user-registration-content-restriction',
		$ur_screen_id . '_page_user-registration-coupons',
		'profile',
		'user-edit',
	);

	/**
	 * Filter to modify screen id's
	 *
	 * @param string $screen_ids Screen ID's
	 */
	return apply_filters( 'user_registration_screen_ids', $screen_ids );
}

// Hook into exporter and eraser tool.
add_filter( 'wp_privacy_personal_data_exporters', 'user_registration_register_data_exporter', 10 );
add_filter( 'wp_privacy_personal_data_erasers', 'user_registration_register_data_eraser' );

/**
 * Add user registration data to exporters.
 *
 * @param  array $exporters Exporters.
 * @return array
 */
function user_registration_register_data_exporter( $exporters ) {

	$exporters['user-registration'] = array(
		'exporter_friendly_name' => esc_html__( 'User Extra Information', 'user-registration' ),
		'callback'               => 'user_registration_data_exporter',
	);

	return $exporters;
}

/**
 * Get user registration data to export.
 *
 * @param  string  $email_address user's email address.
 * @param  integer $page Page.
 * @return array exporting data
 */
function user_registration_data_exporter( $email_address, $page = 1 ) {

	global $wpdb;

	$form_data = array();
	$posts     = get_posts( 'post_type=user_registration' );

	// Get array of field name label mapping of user registration fields.
	foreach ( $posts as $post ) {
		$post_content       = isset( $post->post_content ) ? $post->post_content : '';
		$post_content_array = json_decode( $post_content );
		foreach ( $post_content_array as $post_content_row ) {
			foreach ( $post_content_row as $post_content_grid ) {
				foreach ( $post_content_grid as $field ) {
					if ( isset( $field->field_key ) && isset( $field->general_setting->field_name ) ) {
						$form_data[ $field->general_setting->field_name ] = $field->general_setting->label;
					}
				}
			}
		}
	}

	$user     = get_user_by( 'email', $email_address );
	$user_id  = isset( $user->ID ) ? $user->ID : 0;
	$usermeta = $wpdb->get_results( "SELECT * FROM $wpdb->usermeta WHERE meta_key LIKE 'user_registration\_%' AND user_id = " . $user_id . ' ;' ); // phpcs:ignore

	$export_items = array();
	if ( $usermeta && is_array( $usermeta ) ) {

		foreach ( $usermeta as $meta ) {
			$strip_prefix = substr( $meta->meta_key, 18 );

			if ( array_key_exists( $strip_prefix, $form_data ) ) {

				if ( is_serialized( $meta->meta_value ) ) {
					$meta->meta_value = ur_maybe_unserialize( $meta->meta_value );
					$meta->meta_value = implode( ',', $meta->meta_value );
				}

				$data[] =
					array(
						'name'  => $form_data[ $strip_prefix ],
						'value' => $meta->meta_value,
					);
			}
		}

		$export_items[] = array(
			'group_id'    => 'user-registration',
			'group_label' => esc_html__( 'User Extra Information', 'user-registration' ),
			'item_id'     => "user-registration-{$meta->umeta_id}",
			'data'        => $data,
		);
	}

	return array(
		'data' => $export_items,
		'done' => true,
	);
}

/**
 * Add user registration data to the eraser tool.
 *
 * @param  array $erasers Erasers.
 * @return array
 */
function user_registration_register_data_eraser( $erasers = array() ) {
	$erasers['user-registration'] = array(
		'eraser_friendly_name' => esc_html__( 'WordPress User Extra Information', 'user-registration' ),
		'callback'             => 'user_registration_data_eraser',
	);
	return $erasers;
}

/**
 * Get user registration data to erase.
 *
 * @param  string  $email_address user's email address.
 * @param  integer $page Page.
 * @return array
 */
function user_registration_data_eraser( $email_address, $page = 1 ) {

	global $wpdb;

	if ( empty( $email_address ) ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$user = get_user_by( 'email', $email_address );

	$messages       = array();
	$items_removed  = false;
	$items_retained = false;

	if ( $user && $user->ID ) {
		$user_id         = $user->ID;
		$delete_usermeta = $wpdb->get_results( "DELETE FROM $wpdb->usermeta WHERE meta_key LIKE 'user_registration\_%' AND user_id = " . $user_id . ' ;' ); // phpcs:ignore

		$delete_form_data = $wpdb->get_results( "DELETE FROM $wpdb->usermeta WHERE meta_key = 'ur_form_id' AND user_id = " . $user_id . ' ;' ); // phpcs:ignore

		if ( $delete_usermeta && $delete_form_data ) {
			$items_removed = true;
		}
	}

	return array(
		'items_removed'  => $items_removed,
		'items_retained' => $items_retained,
		'messages'       => $messages,
		'done'           => true,
	);
}

/**
 * Create a page and store the ID in an option.
 *
 * @param  mixed  $slug         Slug for the new page.
 * @param  string $option       Option name to store the page's ID.
 * @param  string $page_title   (default: '') Title for the new page.
 * @param  string $page_content (default: '') Content for the new page.
 * @param  int    $post_parent  (default: 0) Parent for the new page.
 *
 * @return int page ID
 */
function ur_create_page( $slug, $option = '', $page_title = '', $page_content = '', $post_parent = 0 ) {
	global $wpdb;

	$option_value = get_option( $option );
	$page_object  = get_post( $option_value );

	if ( $option_value > 0 && $page_object ) {
		if ( 'page' === $page_object->post_type && ! in_array(
			$page_object->post_status,
			array(
				'pending',
				'trash',
				'future',
				'auto-draft',
			)
		)
		) {
			// Valid page is already in place.
			return $page_object->ID;
		}
	}

	if ( strlen( $page_content ) > 0 ) {
		// Search for an existing page with the specified page content (typically a shortcode).
		$valid_page_found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status NOT IN ( 'pending', 'trash', 'future', 'auto-draft' ) AND post_content LIKE %s LIMIT 1;", "%{$page_content}%" ) );
	} else {
		// Search for an existing page with the specified page slug.
		$valid_page_found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status NOT IN ( 'pending', 'trash', 'future', 'auto-draft' )  AND post_name = %s LIMIT 1;", $slug ) );
	}

	/**
	 * Filter to create Page ID
	 *
	 * @param string $valid_page_found Valid Page
	 * @param mixed $slug Page Slug
	 * @param string $page_content Page Content
	 */
	$valid_page_found = apply_filters( 'user_registration_create_page_id', $valid_page_found, $slug, $page_content );

	if ( $valid_page_found ) {
		if ( $option ) {
			update_option( $option, $valid_page_found );
		}

		return $valid_page_found;
	}

	// Search for a matching valid trashed page.
	if ( strlen( $page_content ) > 0 ) {
		// Search for an existing page with the specified page content (typically a shortcode).
		$trashed_page_found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status = 'trash' AND post_content LIKE %s LIMIT 1;", "%{$page_content}%" ) );
	} else {
		// Search for an existing page with the specified page slug.
		$trashed_page_found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status = 'trash' AND post_name = %s LIMIT 1;", $slug ) );
	}

	if ( $trashed_page_found ) {
		$page_id   = $trashed_page_found;
		$page_data = array(
			'ID'          => $page_id,
			'post_status' => 'publish',
		);
		wp_update_post( $page_data );
	} else {
		$page_data = array(
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_author'    => 1,
			'post_name'      => sanitize_text_field( $slug ),
			'post_title'     => sanitize_text_field( $page_title ),
			'post_content'   => $page_content,
			'post_parent'    => $post_parent,
			'comment_status' => 'closed',
		);
		$page_id   = wp_insert_post( $page_data );
	}

	if ( $option ) {
		update_option( $option, $page_id );
	}

	return $page_id;
}

/**
 * Output admin fields.
 *
 * Loops though the user registration options array and outputs each field.
 *
 * @param array $options Opens array to output.
 */
function user_registration_admin_fields( $options ) {

	if ( ! class_exists( 'UR_Admin_Settings', false ) ) {
		include __DIR__ . '/class-ur-admin-settings.php';
	}

	UR_Admin_Settings::output_fields( $options );
}

/**
 * Update all settings which are passed.
 *
 * @param array $options Options to save.
 * @param array $data Data.
 */
function user_registration_update_options( $options, $data = null ) {

	if ( ! class_exists( 'UR_Admin_Settings', false ) ) {
		include __DIR__ . '/class-ur-admin-settings.php';
	}

	UR_Admin_Settings::save_fields( $options, $data );
}

/**
 * Get a setting from the settings API.
 *
 * @param mixed $option_name Option name.
 * @param mixed $default Default option value.
 *
 * @return string
 */
function user_registration_settings_get_option( $option_name, $default = '' ) {

	if ( ! class_exists( 'UR_Admin_Settings', false ) ) {
		include __DIR__ . '/class-ur-admin-settings.php';
	}

	return UR_Admin_Settings::get_option( $option_name, $default );
}

/**
 * General settings area display
 *
 * @param int $form_id Form ID.
 */
function ur_admin_form_settings( $form_id = 0 ) {

	echo '<div id="general-settings" ><h3>' . esc_html__( 'General', 'user-registration' ) . '</h3>';

	$arguments = ur_admin_form_settings_fields( $form_id );

	foreach ( $arguments as $args ) {
		user_registration_form_field( $args['id'], $args );
	}

	echo '</div>';
}

/**
 * Update Settings of the form.
 *
 * @param array $setting_data Settings data in name value array pair.
 * @param int   $form_id      Form ID.
 */
function ur_update_form_settings( $setting_data, $form_id ) {
	$remap_setting_data = array();

	$setting_data = ur_format_setting_data( $setting_data );
	foreach ( $setting_data as $setting ) {

		if ( isset( $setting['name'] ) ) {

			if ( '[]' === substr( $setting['name'], -2 ) ) {
				$setting['name'] = substr( $setting['name'], 0, -2 );
			}

			$remap_setting_data[ $setting['name'] ] = $setting;
		}
	}

	/**
	 * Filter to modify Form settings save
	 *
	 * @param array General Form Settings
	 * @param mixed $form_id Form ID
	 * @param string $setting_data Setting Data
	 */
	$setting_fields = apply_filters( 'user_registration_form_settings_save', ur_admin_form_settings_fields( $form_id ), $form_id, $setting_data );

	foreach ( $setting_fields as $field_data ) {
		if ( isset( $field_data['id'] ) && isset( $remap_setting_data[ $field_data['id'] ] ) ) {

			if ( isset( $remap_setting_data[ $field_data['id'] ]['value'] ) ) {

				// Check if any settings value contains array.
				if ( is_array( $remap_setting_data[ $field_data['id'] ]['value'] ) ) {
					$remap_setting_data[ $field_data['id'] ]['value'] = array_map( 'sanitize_text_field', $remap_setting_data[ $field_data['id'] ]['value'] );
					$remap_setting_data[ $field_data['id'] ]['value'] = maybe_serialize( $remap_setting_data[ $field_data['id'] ]['value'] );
				} else {
					$remap_setting_data[ $field_data['id'] ]['value'] = sanitize_text_field( $remap_setting_data[ $field_data['id'] ]['value'] );
				}

				update_post_meta( absint( $form_id ), sanitize_text_field( $field_data['id'] ), $remap_setting_data[ $field_data['id'] ]['value'] );
			}
		} else {
				// Update post meta if any setting value is not set for field data id.
				update_post_meta( absint( $form_id ), sanitize_text_field( $field_data['id'] ), '' );
		}
	}
}

/**
 * Format settings data for same name. e.g. multiselect
 * Encloses all values in array for same name in settings.
 *
 * @param   array $setting_data unformatted settings data.
 * @return  array $settings     formatted settings data.
 */
function ur_format_setting_data( $setting_data ) {

	$key_value = array();
	foreach ( $setting_data as $value ) {

		if ( array_key_exists( $value['name'], $key_value ) ) {
			$value_array = array();

			if ( is_array( $key_value[ $value['name'] ] ) ) {

				$value_array                 = $key_value[ $value['name'] ];
				$value_array[]               = $value['value'];
				$key_value[ $value['name'] ] = $value_array;
			} else {
				$value_array[]               = $key_value[ $value['name'] ];
				$value_array[]               = $value['value'];
				$key_value[ $value['name'] ] = $value_array;
			}
		} else {
			$key_value[ $value['name'] ] = $value['value'];
		}
	}

	$settings = array();
	foreach ( $key_value as $key => $value ) {
		$settings[] = array(
			'name'  => $key,
			/**
			 * Filter to modify Form settings based on Key
			 *
			 * @param array $value Setting Data
			 */
			'value' => apply_filters( 'user_registration_form_setting_' . $key, $value ),
		);
	}

	return $settings;
}

/**
 * Check for plugin activation date.
 *
 * True if user registration has been installed for 10 and 14 days ago according to the days supplied in the parameter.
 *
 * @param int $days Number of days to check for activation.
 *
 * @since 1.5.8
 *
 * @return bool
 */
function ur_check_activation_date( $days ) {

	// Plugin Activation Time.
	$activation_date  = get_option( 'user_registration_activated' );
	$days_to_validate = strtotime( 'now' ) - $days * DAY_IN_SECONDS;
	$days_to_validate = date_i18n( 'Y-m-d', $days_to_validate );

	if ( ! empty( $activation_date ) ) {
		if ( $activation_date < $days_to_validate ) {
			return true;
		}
	}

	return false;
}

/**
 * Check for plugin updation date.
 *
 * True if user registration has been updated ago according to the days supplied in the parameter.
 *
 * @param int $days Number of days to check for activation.
 *
 * @since 2.3.2
 *
 * @return bool
 */
function ur_check_updation_date( $days ) {

	// Plugin Updation Time.
	$updated_date     = get_option( 'user_registration_updated_at' );
	$days_to_validate = strtotime( 'now' ) - $days * DAY_IN_SECONDS;
	$days_to_validate = date_i18n( 'Y-m-d', $days_to_validate );

	if ( ! empty( $updated_date ) ) {
		if ( $updated_date < $days_to_validate ) {
			return true;
		}
	}

	return false;
}

/**
 * Links for Promotional Notices.
 *
 * @param array $notice_target_links Notice target links.
 */
function promotional_notice_links( $notice_target_links, $is_permanent_dismiss ) {
	?>
		<ul class="user-registration-notice-ul">
			<?php
			foreach ( $notice_target_links as $key => $link ) {
				if ( ! empty( $link['link'] ) && ! is_string( $link['link'] ) ) {
					$url          = isset( $link['link']['link_function'] ) ? $link['link']['link_function'] : 'admin_url';
					$url          = function_exists( $url ) ? $url() : '';
					$link['link'] = $url . ( isset( $link['link']['link_params'] ) ? $link['link']['link_params'] : '#' );
				}
				?>
			<li><a class="button <?php esc_attr_e( $link['class'], 'user-registration' ); ?>" href="<?php echo esc_url( $link['link'] ); ?>" target="<?php echo esc_attr( $link['target'] ); ?>" rel="noreferrer noopener"><span class="dashicons <?php echo esc_attr( $link['icon'] ); ?>"></span><?php esc_html_e( $link['title'], 'user-registration' ); ?></a></li>
				<?php
			}
			?>
	</ul>
	<?php
	if ( $is_permanent_dismiss ) {

		?>
			<a href="#" class="notice-dismiss notice-dismiss-permanently"><?php esc_html_e( 'Never show again', 'user-registration' ); ?></a>
		<?php
	}
}

if ( ! function_exists( 'ur_check_all_functions' ) ) {

	/**
	 * Check common functions.
	 *
	 * @return bool
	 */
	function ur_check_all_functions( $conditions ) {
		$valid_function = false;
		if ( empty( $conditions ) ) {
			return true;
		}

		$main_operator   = 'AND';
		$valid_condition = array();

		foreach ( $conditions as $key => $value ) {
			if ( 'operator' == $key ) {
				$main_operator = $value;
			} else {

				$params = array();
				if ( isset( $value['params'] ) ) {
					$params = explode( ',', $value['params'] );
				}
				$result = function_exists( $key ) ? call_user_func_array( $key, $params ) : '';

				$expected_value        = isset( $value['expected_value'] ) ? $value['expected_value'] : '';
				$condition_to_validate = isset( $value['condition_to_validate'] ) ? $value['condition_to_validate'] : '==';

				$response_value = $result;

				if ( isset( $value['expected_attribute'] ) ) {
					$expected_attribute = $value['expected_attribute'];
					if ( is_array( $result ) && ! empty( $result ) ) {
						$response_value = isset( $result[ $expected_attribute ] ) ? $result[ $expected_attribute ] : '';
					} elseif ( is_object( $result ) && ! empty( $result ) ) {
						$response_value = isset( $result->$expected_attribute ) ? $result->$expected_attribute : '';
					}
				}
				if ( ur_check_condition_operator( $condition_to_validate, $expected_value, $response_value ) ) {
					array_push( $valid_condition, true );
				}
			}
		}
		if ( 'AND' === $main_operator && ( count( $conditions ) - 1 ) === count( $valid_condition ) ) {
			$valid_function = true;
		} elseif ( 'OR' === $main_operator && 1 === count( $valid_condition ) ) {
			$valid_function = true;
		}

		return $valid_function;
	}
}

if ( ! function_exists( 'ur_check_products_version' ) ) {

	/**
	 * Check products version.
	 *
	 * @return bool
	 */
	function ur_check_products_version( $conditions ) {
		$valid_product = false;
		if ( empty( $conditions ) ) {
			return true;
		}

		$main_operator   = 'AND';
		$valid_condition = array();

		foreach ( $conditions as $key => $value ) {
			if ( 'operator' == $key ) {
				$main_operator = $value;
			} else {
				if ( 'plugins' === $key ) {
					$valid_plugins = array();
					$sub_operator  = 'AND';

					foreach ( $value as $plugin_slug => $version_to_compare ) {
						if ( 'operator' == $plugin_slug ) {
							$sub_operator = $version_to_compare;
						} else {
							$plugin_version = get_plugin_version( $plugin_slug );
							// Extract the operator and the number
							preg_match( '/([<>!=]=?)(\d+(\.\d+)+)/', $version_to_compare, $matches );
							$numeric_operator   = $matches[1];
							$version_to_compare = $matches[2];

							if ( ! empty( $plugin_version ) ) {
								$valid = version_compare( $plugin_version, $version_to_compare, $numeric_operator );
								if ( $valid && $sub_operator == 'OR' ) {
									array_push( $valid_plugins, $valid );
									break;
								} elseif ( $valid && $sub_operator == 'AND' ) {
									array_push( $valid_plugins, $valid );
									continue;
								}
							}
						}
					}
					if ( 'AND' === $sub_operator && ( count( $value ) - 1 ) === count( $valid_plugins ) ) {
						array_push( $valid_condition, true );
					} elseif ( 'OR' === $sub_operator && 1 === count( $valid_plugins ) ) {
						array_push( $valid_condition, true );
					}
				}
				if ( 'themes' === $key ) {
					foreach ( $value as $theme_slug => $version_to_compare ) {
						$theme_version = get_theme_version( $theme_slug );
						// Extract the operator and the number
						preg_match( '/([<>!=]=?)(\d+(\.\d+)+)/', $version_to_compare, $matches );
						$numeric_operator   = $matches[1];
						$version_to_compare = $matches[2];

						if ( ! empty( $theme_version ) ) {
							$valid = version_compare( $theme_version, $version_to_compare, $numeric_operator );
							if ( $valid ) {
								array_push( $valid_condition, $valid );
								break;
							}
						}
					}
				}
			}
		}

		if ( 'AND' === $main_operator && ( count( $conditions ) - 1 ) === count( $valid_condition ) ) {
			$valid_product = true;
		} elseif ( 'OR' === $main_operator && 1 === count( $valid_condition ) ) {
			$valid_product = true;
		}

		return $valid_product;
	}
}

if ( ! function_exists( 'ur_check_condition_operator' ) ) {

	/**
	 * Check condition operator.
	 *
	 * @return bool
	 */
	function ur_check_condition_operator( $condition_to_validate, $expected_value, $response_value ) {
		// Extract the operator and the number
		$condition_met = false;

		switch ( $condition_to_validate ) {
			case '==':
				$condition_met = ( $expected_value == $response_value );
				break;
			case '===':
				$condition_met = ( $expected_value === $response_value );
				break;
			case '!=':
				$condition_met = ( $expected_value != $response_value );
				break;
			case '!==':
				$condition_met = ( $expected_value !== $response_value );
				break;
			case '>':
				$condition_met = ( $expected_value > $response_value );
				break;
			case '<':
				$condition_met = ( $expected_value < $response_value );
				break;
			case '>=':
				$condition_met = ( $expected_value >= $response_value );
				break;
			case '<=':
				$condition_met = ( $expected_value <= $response_value );
				break;
			default:
				$condition_met = false;
				break;
		}

		return $condition_met;
	}
}
if ( ! function_exists( 'ur_check_numeric_operator' ) ) {

	/**
	 * Check numeric operator.
	 *
	 * @return bool
	 */
	function ur_check_numeric_operator( $value, $condition ) {
		// Extract the operator and the number
		preg_match( '/([<>]=?|==|!=|<=|>=)?(\d+)/', $condition, $matches );
		$operator = $matches[1];
		$number   = (int) $matches[2];

		$condition = false;
		switch ( $operator ) {
			case '>':
				$condition = $value > $number;
				break;
			case '>=':
				$condition = $value >= $number;
				break;
			case '<':
				$condition = $value < $number;
				break;
			case '<=':
				$condition = $value <= $number;
				break;
			case '!=':
				$condition = $value != $number;
				break;
			default:
				$condition = $value == $number;
				break;
		}

		return $condition;
	}
}


if ( ! function_exists( 'get_plugin_version' ) ) {
	/**
	 * Get Plugin Version.
	 *
	 * @since 3.3.0
	 *
	 * @param  string $plugin_slug Plugin Slug.
	 */
	function get_plugin_version( $plugin_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, $plugin_slug ) !== false ) {
				return $plugin_data['Version'];
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_theme_version' ) ) {
	/**
	 * Get Theme Version.
	 *
	 * @since 3.3.0
	 *
	 * @param  string $theme_slug Theme Slug.
	 */
	function get_theme_version( $theme_slug ) {
		$theme = wp_get_theme( $theme_slug );

		if ( $theme->exists() ) {
			return $theme->get( 'Version' );
		}

		return false;
	}

}

if ( ! function_exists( 'ur_check_notice_already_permanent_dismissed' ) ) {
	/**
	 * Check whether provided notice type already dismissed notice permanently.
	 *
	 * @since 3.3.0
	 *
	 * @param string $notice_type Notice Type.
	 */
	function ur_check_notice_already_permanent_dismissed( $notice_type ) {
		return get_option( 'user_registration_' . $notice_type . '_notice_dismissed', false );
	}
}
