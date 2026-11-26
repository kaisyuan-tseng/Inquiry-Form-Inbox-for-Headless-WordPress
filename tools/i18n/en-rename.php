<?php
/**
 * English source-string renames — 3.0.15 terminology pass.
 *
 * The English source is normally the master. Here it is the thing being corrected: a few
 * names described the mechanism rather than the result, or were ungrammatical. The `key`
 * column is deliberately not listed — internal dictionary keys are identifiers, not user-
 * facing names, and renaming 47 `spam_*` keys would add churn without changing a word on
 * screen. "Spam" as a *view* name is also kept: it is WordPress's own term and the place
 * blocked submissions are archived. Only the rules *screen* becomes "Block Rules", because
 * these rules also cover IP blocks and duplicate submissions, not just spam content.
 *
 * Applied by rewriting `__()` calls in includes/trait-ifi-i18n.php, then:
 *   composer pot
 *   php tools/wp-cli.phar i18n update-po languages/inquiry-form-inbox.pot languages/
 *   php tools/fill-translations.php languages/inquiry-form-inbox-zh_CN.po tools/i18n/zh_CN-renamed.php
 *
 * @package Inquiry_Form_Inbox
 */

return array(
	// Rules screen: the rules block, they do not merely label content as spam.
	'Spam Rules'                                     => 'Block Rules',

	// Two columns that used to mean almost the same thing.
	'Automatic Status'                               => 'Delivery status',
	'Email status'                                   => 'Emails',

	// Delivery vs sent: a successful SMTP hand-off is "sent", not "delivered" to the inbox.
	'Email Delivered'                                => 'Emails sent',
	'All emails delivered'                           => 'All emails sent',
	'The message could not be delivered. Please email us directly.'
		=> 'Your inquiry could not be sent. Please email us directly.',
	'Ignore undelivered warning'                     => 'Ignore unsent warning',

	// Grammar and naturalness.
	'Daily new'                                      => 'New today',
	'%d notification failed'                         => '%d notifications failed',
	'%d confirmation failed'                         => '%d confirmations failed',
	'Network and repeats'                            => 'Network and duplicates',
	'Run check'                                      => 'Run test',
	'No rule matches'                                => 'No rules matched',
	'Fill in at least one field to run the check.'    => 'Fill in at least one field to run the test.',
	'Dropped at the door'                            => 'Dropped on arrival',
	'Duplicate'                                      => 'Duplicate submission',
	'These IP rules are not valid addresses or ranges and will never match: %s'
		=> 'These IP entries are not valid addresses or ranges and will never match: %s',

	// Do not name a third-party plugin in a generic error message.
	'Test email failed. Check WP Mail SMTP.'         => 'Test email failed. Check your mailer configuration.',

	// A fallback sender name, not a statement about knowledge.
	'Unknown'                                        => 'Unnamed',
);
