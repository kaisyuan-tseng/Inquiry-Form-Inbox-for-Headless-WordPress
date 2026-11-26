<?php
/**
 * IFI_Capabilities — capability bootstrap and meta-cap mapping.
 *
 * Kept separate from the main class so activation, the admin self-heal and
 * map_meta_cap() all use the same capability list.
 *
 * @package Inquiry_Form_Inbox
 */

defined( 'ABSPATH' ) || exit;

final class IFI_Capabilities {

	/**
	 * Activation hook: grant the capabilities and leave a one-time success notice.
	 *
	 * No redirect and no output: WordPress can activate plugins from the install screen
	 * over AJAX, where anything printed before the JSON response breaks the request.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );
		self::init_capabilities();

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			set_transient( 'ifi_activation_notice_' . $user_id, 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Self-heal on admin requests: the activation hook does not run on plugin updates.
	 */
	public static function maybe_upgrade() {
		self::init_capabilities();
	}

	/**
	 * Grant the plugin capabilities to the roles that need them.
	 */
	public static function init_capabilities() {
		$caps = array(
			Inquiry_Form_Inbox::CAP_VIEW,
			Inquiry_Form_Inbox::CAP_EDIT,
			Inquiry_Form_Inbox::CAP_DELETE,
		);

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Map plugin-specific meta capabilities.
	 *
	 * @param string[] $caps    Primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Capability arguments.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		unset( $user_id );

		$map = array(
			'ifi_view_inquiry'   => Inquiry_Form_Inbox::CAP_VIEW,
			'ifi_edit_inquiry'   => Inquiry_Form_Inbox::CAP_EDIT,
			'ifi_delete_inquiry' => Inquiry_Form_Inbox::CAP_DELETE,
		);

		if ( ! isset( $map[ $cap ] ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || Inquiry_Form_Inbox::POST_TYPE !== $post->post_type ) {
			return array( 'do_not_allow' );
		}

		return array( $map[ $cap ] );
	}
}
