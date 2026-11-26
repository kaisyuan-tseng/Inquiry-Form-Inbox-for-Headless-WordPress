<?php
/**
 * Bootstrap for the integration suite: WordPress, the scratch schema, and the helpers.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

require_once __DIR__ . '/wp-base.php';

require_once __DIR__ . '/../lib/Reflect.php';
require_once __DIR__ . '/../lib/Scratch.php';
require_once __DIR__ . '/../lib/MailSpy.php';
require_once __DIR__ . '/../lib/IfiTestCase.php';
