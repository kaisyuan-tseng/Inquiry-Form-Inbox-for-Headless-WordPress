<?php
/**
 * Plugin Name: Inquiry Form Inbox for Headless WordPress
 * Plugin URI:  https://github.com/kaisyuan-tseng/Inquiry-Form-Inbox-for-Headless-WordPress
 * Description: Connect any frontend. Keep every inquiry in one place.
 * Version:     3.1.3
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      kaisyuan-tseng
 * Author URI:  https://profiles.wordpress.org/kaisyuan-tseng/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: inquiry-form-inbox
 * Domain Path: /languages
 *
 * @package Inquiry_Form_Inbox
 */

/*
 * Architecture, endpoint contract, template variables and the developer hook list live in
 * docs/ARCHITECTURE.md. The front-end contract is INTEGRATION.md. Keeping both out of this
 * header keeps the block short enough to stay parseable and the docs editable without
 * touching a file WordPress reads on every request.
 */

defined( 'ABSPATH' ) || exit;

define( 'IFI_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/trait-ifi-core.php';
require_once __DIR__ . '/includes/trait-ifi-i18n.php';
require_once __DIR__ . '/includes/trait-ifi-manifest.php';
require_once __DIR__ . '/includes/trait-ifi-rest.php';
require_once __DIR__ . '/includes/trait-ifi-upload.php';
require_once __DIR__ . '/includes/trait-ifi-rate-limiter.php';
require_once __DIR__ . '/includes/trait-ifi-template.php';
require_once __DIR__ . '/includes/trait-ifi-mailer.php';
require_once __DIR__ . '/includes/trait-ifi-storage.php';
require_once __DIR__ . '/includes/trait-ifi-cpt.php';
require_once __DIR__ . '/includes/trait-ifi-cpt-actions.php';
require_once __DIR__ . '/includes/trait-ifi-admin-view.php';
require_once __DIR__ . '/includes/trait-ifi-admin-modal.php';
require_once __DIR__ . '/includes/trait-ifi-dashboard.php';
require_once __DIR__ . '/includes/trait-ifi-settings.php';
require_once __DIR__ . '/includes/trait-ifi-settings-render.php';
require_once __DIR__ . '/includes/trait-ifi-spam.php';
require_once __DIR__ . '/includes/trait-ifi-spam-render.php';
require_once __DIR__ . '/includes/trait-ifi-assets.php';
require_once __DIR__ . '/includes/trait-ifi-endpoints.php';
require_once __DIR__ . '/includes/trait-ifi-install-health.php';
require_once __DIR__ . '/includes/class-ifi-locale.php';
require_once __DIR__ . '/includes/class-ifi-capabilities.php';
require_once __DIR__ . '/includes/class-ifi-privacy.php';
require_once __DIR__ . '/includes/class-inquiry-form-inbox.php';

// Register the activation hook from the main file, where WordPress expects it. The callback
// only grants capabilities and leaves a one-time notice flag; it never redirects or prints.
register_activation_hook( __FILE__, array( 'IFI_Capabilities', 'activate' ) );

Inquiry_Form_Inbox::init();
