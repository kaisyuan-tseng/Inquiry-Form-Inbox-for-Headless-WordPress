/**
 * HTTP suite: the transport contract the front end actually depends on.
 *
 * The integration suite already covers what a submission *means*. What it cannot cover is
 * the wire: real multipart encoding, real file uploads (move_uploaded_file only works for a
 * genuine upload request), status codes, headers, and the visitor cookie. That is this file.
 *
 * Run:
 *   IFI_ALLOW_TEST_WRITES=1 node tests/e2e/run.mjs
 *
 * Every scenario resets the scratch schema first, so the order does not matter and a
 * failure does not leak into the next case.
 */

import { Check, Jar, BASE, pdfBytes, reset, startServer, state, submit } from './lib.mjs';

const BASE_FIELDS = {
	fullName: 'Wire Tester',
	email: 'wire@example.test',
	message: 'Posted over real HTTP.',
};

/** Collected results, one entry per scenario. */
const results = [];

/**
 * Run one scenario.
 *
 * @param {string}   name  Scenario name.
 * @param {Function} body  Receives a Check.
 */
async function scenario( name, body ) {
	const check = new Check( name );
	try {
		await reset();
		await body( check );
	} catch ( error ) {
		check.failures.push( `threw: ${ error.message }` );
	}
	results.push( check );

	const status = check.failures.length === 0 ? 'ok  ' : 'FAIL';
	process.stdout.write( `  ${ status } ${ name }` );
	if ( check.failures.length ) {
		process.stdout.write( `\n         ${ check.failures.join( '\n         ' ) }` );
	}
	process.stdout.write( '\n' );
}

/**
 * Read the single record the last submission should have produced.
 *
 * @return {Promise<object|null>}
 */
async function onlyRecord() {
	const snapshot = await state();
	return snapshot.count === 1 ? snapshot.records[ 0 ] : null;
}

async function main() {
	if ( ! process.env.IFI_ALLOW_TEST_WRITES ) {
		process.stdout.write(
			'Skipped: set IFI_ALLOW_TEST_WRITES=1 to run the HTTP suite against the scratch schema.\n'
		);
		return 0;
	}

	const server = await startServer();
	const jar = new Jar();

	process.stdout.write( `\nHTTP suite against ${ BASE }\n` );

	try {
		await scenario( 'submit-ok', async ( check ) => {
			const response = await submit( BASE_FIELDS, [], jar );
			check.equal( response.status, 200, 'status' );
			check.equal( JSON.stringify( await response.json() ), '{"sent":true}', 'body' );

			const record = await onlyRecord();
			check.ok( record !== null, 'exactly one record stored' );
			if ( record ) {
				check.ok( /^IQ-\d{11}$/.test( record.meta.ifi_inquiry_no ), 'inquiry number format' );
				check.equal( record.meta.ifi_inquiry_email, 'wire@example.test', 'stored email' );
				check.equal( record.meta.ifi_inquiry_ip, '127.0.0.1', 'server-identified IP' );
			}
		} );

		await scenario( 'submit-missing-field', async ( check ) => {
			const response = await submit( { ...BASE_FIELDS, fullName: '' }, [], new Jar() );
			check.equal( response.status, 400, 'status' );
			const body = await response.json();
			check.equal( body.code, 'inquiry_missing_field', 'error code' );
			check.equal( ( await state() ).count, 0, 'nothing stored' );
		} );

		await scenario( 'submit-bad-email', async ( check ) => {
			const response = await submit( { ...BASE_FIELDS, email: 'not-an-email' }, [], new Jar() );
			check.equal( response.status, 400, 'status' );
			check.equal( ( await response.json() ).code, 'inquiry_invalid_email', 'error code' );
		} );

		await scenario( 'honeypot', async ( check ) => {
			const response = await submit( { ...BASE_FIELDS, companyWebsite: 'http://spam.example' }, [], new Jar() );
			check.equal( response.status, 200, 'status is a normal success' );
			const record = await onlyRecord();
			check.ok( record !== null, 'archived' );
			if ( record ) {
				check.equal( record.meta.ifi_inquiry_spam, 'honeypot', 'spam reason' );
				check.equal( record.meta.ifi_inquiry_mail_sent ?? '', '', 'no delivery state' );
			}
		} );

		await scenario( 'rate-3-per-minute', async ( check ) => {
			const local = new Jar();
			const first = await submit( BASE_FIELDS, [], local );
			const second = await submit( BASE_FIELDS, [], local );
			const third = await submit( BASE_FIELDS, [], local );
			check.equal( first.status, 200, 'first allowed' );
			check.equal( second.status, 200, 'second allowed' );
			check.equal( third.status, 200, 'third allowed' );

			const fourth = await submit( BASE_FIELDS, [], local );
			check.equal( fourth.status, 429, 'fourth rejected' );
			check.ok( fourth.headers.get( 'retry-after' ) !== null, 'Retry-After header present' );
			const body = await fourth.json();
			check.equal( body.code, 'inquiry_rate_limited', 'error code' );
			check.ok( Number( body.retry_after ) > 0, 'retry_after reported' );
			check.equal( ( await state() ).count, 3, 'rejected submission not stored' );
		} );

		await scenario( 'visitor-cookie-issued', async ( check ) => {
			const local = new Jar();
			await submit( BASE_FIELDS, [], local );
			check.ok( local.has( 'ifi_vid' ), 'ifi_vid cookie set' );
		} );

		await scenario( 'upload-pdf-ok', async ( check ) => {
			const response = await submit(
				BASE_FIELDS,
				[ { group: 'drawings', name: 'drawing.pdf', bytes: pdfBytes( 'ok' ), type: 'application/pdf' } ],
				new Jar()
			);
			check.equal( response.status, 200, 'status' );

			const record = await onlyRecord();
			check.ok( record !== null, 'record stored' );
			if ( record ) {
				const files = record.meta.ifi_inquiry_attachments;
				check.ok( Array.isArray( files ) && files.length === 1, 'one attachment recorded' );
				if ( Array.isArray( files ) && files[ 0 ] ) {
					check.equal( files[ 0 ].name, 'drawing.pdf', 'file name' );
					check.ok( files[ 0 ].url.startsWith( '/media/' ), 'relative web path' );

					const fetched = await fetch( `${ BASE }${ files[ 0 ].url }` );
					check.equal( fetched.status, 200, 'attachment URL resolves' );
					const bytes = new Uint8Array( await fetched.arrayBuffer() );
					check.ok( bytes.length > 0, 'attachment has bytes' );
				}
			}
		} );

		await scenario( 'upload-bad-extension', async ( check ) => {
			const response = await submit(
				BASE_FIELDS,
				[ { group: 'drawings', name: 'payload.exe', bytes: pdfBytes( 'bad' ), type: 'application/octet-stream' } ],
				new Jar()
			);
			check.equal( response.status, 400, 'status' );
			const body = await response.json();
			check.equal( body.code, 'inquiry_bad_upload', 'error code' );
			check.ok( /not allowed/.test( body.message ), 'message names the reason' );
			check.equal( ( await state() ).count, 0, 'nothing stored' );
		} );

		await scenario( 'upload-wrong-group', async ( check ) => {
			// A PNG is allowed for pictures, not for drawings.
			const response = await submit(
				BASE_FIELDS,
				[ { group: 'drawings', name: 'photo.png', bytes: pdfBytes( 'png' ), type: 'image/png' } ],
				new Jar()
			);
			check.equal( response.status, 400, 'status' );
			check.equal( ( await response.json() ).code, 'inquiry_bad_upload', 'error code' );
		} );

		await scenario( 'upload-too-many', async ( check ) => {
			// PHP's max_file_uploads (20 by default) drops extra files before the plugin sees
			// them, and the plugin's own cap is also 20 — so the plugin's "too many files"
			// rejection is unreachable over HTTP at the default settings. What is asserted
			// here is the behaviour a real site gets: the request succeeds and PHP's cap
			// decides how many files arrive. The plugin's own limit is covered in-process in
			// tests/integration/AttachmentsTest.php, where no ini cap applies.
			const files = [];
			for ( let index = 0; index < 21; index++ ) {
				files.push( {
					group: 'drawings',
					name: `part-${ index }.pdf`,
					bytes: pdfBytes( `many-${ index }` ),
					type: 'application/pdf',
				} );
			}

			const response = await submit( BASE_FIELDS, files, new Jar() );
			check.equal( response.status, 200, 'status' );

			const snapshot = await state();
			const phpCap = snapshot.limits.max_file_uploads || 20;
			const pluginCap = snapshot.limits.ifi_max_files || 20;
			const expected = Math.min( 21, phpCap, pluginCap );

			check.equal( snapshot.count, 1, 'record stored' );
			const stored = snapshot.records[ 0 ].meta.ifi_inquiry_attachments;
			check.equal( stored.length, expected, `stored ${ expected } files (php cap ${ phpCap })` );
		} );

		await scenario( 'mail-failure', async ( check ) => {
			const response = await submit( BASE_FIELDS, [], new Jar(), { failMail: true } );
			check.equal( response.status, 500, 'status' );
			check.equal( ( await response.json() ).code, 'inquiry_mail_failed', 'error code' );

			const record = await onlyRecord();
			check.ok( record !== null, 'record still archived' );
			if ( record ) {
				check.equal( record.meta.ifi_inquiry_mail_sent, 'no', 'delivery marked failed' );
				check.ok( ( record.meta.ifi_inquiry_mail_error ?? '' ).length > 0, 'reason recorded' );
			}
		} );

		await scenario( 'custom-fields-round-trip', async ( check ) => {
			const response = await submit(
				{
					...BASE_FIELDS,
					outerDiameter: '12mm',
					quantity: '500',
					quantityUnit: 'PCS',
					'fieldLabels[outerDiameter]': '外径',
				},
				[],
				new Jar()
			);
			check.equal( response.status, 200, 'status' );

			const record = await onlyRecord();
			if ( record ) {
				const values = record.meta.ifi_inquiry_field_values;
				check.equal( values.outerDiameter, '12mm', 'custom field stored' );
				check.equal( values.quantity, '500', 'quantity stored' );
				check.ok( record.content.includes( '外径' ), 'label from the form used in the body' );
			}
		} );

		await scenario( 'json-body-is-accepted-for-text', async ( check ) => {
			// INTEGRATION.md used to claim a JSON body was rejected as "missing required
			// fields". It is not: WP_REST_Request parses a JSON body into params, so a
			// text-only submission works. Files still need multipart. This pins the real
			// behaviour so the documentation cannot drift back.
			const response = await fetch( `${ BASE }/wp-json/inquiry-form-inbox/v1/inquiry`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { ...BASE_FIELDS, email: 'json@example.test' } ),
			} );
			check.equal( response.status, 200, 'status' );

			const record = await onlyRecord();
			check.ok( record !== null, 'record stored' );
			if ( record ) {
				check.equal( record.meta.ifi_inquiry_email, 'json@example.test', 'stored email' );
			}
		} );
	} finally {
		await server.stop();
	}

	const failed = results.filter( ( result ) => result.failures.length > 0 );
	const assertions = results.reduce( ( total, result ) => total + result.count, 0 );

	process.stdout.write(
		`\n${ results.length - failed.length }/${ results.length } scenarios passed, ${ assertions } assertions\n`
	);

	return failed.length === 0 ? 0 : 1;
}

main()
	.then( ( code ) => process.exit( code ) )
	.catch( ( error ) => {
		process.stderr.write( `${ error.stack }\n` );
		process.exit( 1 );
	} );
