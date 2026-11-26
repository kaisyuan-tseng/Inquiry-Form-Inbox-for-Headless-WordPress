/**
 * Harness for the HTTP suite.
 *
 * Owns the PHP development server, a cookie jar, and the two test-only endpoints exposed by
 * router.php. Everything here runs from Node so the suite has exactly one process tree to
 * clean up.
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = dirname( dirname( dirname( fileURLToPath( import.meta.url ) ) ) );
export const BASE = 'http://127.0.0.1:8099';
export const TOKEN = 'ifi-e2e-local-only';
export const RUNTIME = join( ROOT, 'tests', 'runtime' );

/**
 * Wait for the server to answer, or give up.
 *
 * @param {number} timeoutMs Total wait budget.
 * @return {Promise<void>}
 */
async function waitForServer( timeoutMs = 20000 ) {
	const deadline = Date.now() + timeoutMs;
	let lastError = 'no attempt made';

	while ( Date.now() < deadline ) {
		try {
			const response = await fetch( `${ BASE }/wp-json/`, { signal: AbortSignal.timeout( 3000 ) } );
			if ( response.status === 200 ) {
				return;
			}
			lastError = `HTTP ${ response.status }`;
		} catch ( error ) {
			lastError = error.message;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
	}

	throw new Error( `PHP server did not become ready: ${ lastError }` );
}

/**
 * Start the PHP development server and wait until WordPress answers.
 *
 * @return {Promise<{stop: () => Promise<void>}>}
 */
export async function startServer() {
	if ( ! existsSync( RUNTIME ) ) {
		mkdirSync( RUNTIME, { recursive: true } );
	}

	const child = spawn(
		'php',
		[ '-d', 'display_errors=0', '-S', '127.0.0.1:8099', 'tests/e2e/router.php' ],
		{ cwd: ROOT, stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);

	const stderr = [];
	child.stderr.on( 'data', ( chunk ) => stderr.push( chunk.toString() ) );
	child.on( 'exit', ( code ) => {
		if ( code !== 0 && code !== null ) {
			process.stderr.write( `PHP server exited with code ${ code }\n${ stderr.join( '' ) }` );
		}
	} );

	await waitForServer();

	return {
		async stop() {
			child.kill();
			await new Promise( ( resolve ) => {
				child.on( 'exit', resolve );
				setTimeout( resolve, 2000 );
			} );
		},
	};
}

/**
 * A cookie jar that survives across requests.
 *
 * fetch() does not persist cookies, and the rate limiter keys on the signed visitor cookie
 * whenever the request arrives from a reserved address — which 127.0.0.1 always is. Without
 * a jar, every request looks like a brand-new visitor and the 429 path is unreachable.
 */
export class Jar {
	constructor() {
		this.cookies = new Map();
	}

	/**
	 * Absorb Set-Cookie headers from a response.
	 *
	 * @param {Response} response Fetch response.
	 */
	absorb( response ) {
		const headers = response.headers.getSetCookie?.() ?? [];
		for ( const header of headers ) {
			const [ pair ] = header.split( ';' );
			const index = pair.indexOf( '=' );
			if ( index > 0 ) {
				this.cookies.set( pair.slice( 0, index ).trim(), pair.slice( index + 1 ).trim() );
			}
		}
	}

	/**
	 * Render the jar as a Cookie header.
	 *
	 * @return {string}
	 */
	header() {
		return [ ...this.cookies.entries() ].map( ( [ name, value ] ) => `${ name }=${ value }` ).join( '; ' );
	}

	/**
	 * Whether a cookie with this name is held.
	 *
	 * @param {string} name Cookie name.
	 * @return {boolean}
	 */
	has( name ) {
		return this.cookies.has( name );
	}
}

/**
 * Call a test control endpoint.
 *
 * @param {string} path   Endpoint path.
 * @param {Jar}    jar    Shared jar (unused, kept for symmetry).
 * @return {Promise<any>}
 */
export async function control( path, jar ) {
	const response = await fetch( `${ BASE }${ path }`, {
		headers: { 'X-IFI-Test-Token': TOKEN, Cookie: jar?.header() ?? '' },
	} );
	return response.json();
}

/** Wipe the scratch schema and the uploads sandbox. */
export async function reset() {
	const response = await fetch( `${ BASE }/__test__/reset`, {
		headers: { 'X-IFI-Test-Token': TOKEN },
	} );
	if ( response.status !== 200 ) {
		throw new Error( `reset failed with HTTP ${ response.status }` );
	}
}

/** Read every inquiry record and its meta. */
export async function state() {
	const response = await fetch( `${ BASE }/__test__/state`, {
		headers: { 'X-IFI-Test-Token': TOKEN },
	} );
	return response.json();
}

/**
 * Post a multipart submission to the public endpoint.
 *
 * @param {Record<string, string>} fields Text fields.
 * @param {{group: string, name: string, bytes: Uint8Array, type: string}[]} files Attachments.
 * @param {Jar}    jar     Cookie jar.
 * @param {object} options Extra options: { failMail: boolean, headers: object }.
 * @return {Promise<Response>}
 */
export async function submit( fields = {}, files = [], jar = new Jar(), options = {} ) {
	const body = new FormData();

	for ( const [ key, value ] of Object.entries( fields ) ) {
		body.append( key, value );
	}
	for ( const file of files ) {
		body.append(
			`${ file.group }[]`,
			new Blob( [ file.bytes ], { type: file.type } ),
			file.name
		);
	}

	const headers = { ...( options.headers ?? {} ) };
	const cookie = jar.header();
	if ( cookie ) {
		headers.Cookie = cookie;
	}
	if ( options.failMail ) {
		headers[ 'X-IFI-Test-Fail-Mail' ] = '1';
	}

	const response = await fetch( `${ BASE }/wp-json/inquiry-form-inbox/v1/inquiry`, {
		method: 'POST',
		body,
		headers,
	} );
	jar.absorb( response );

	return response;
}

/**
 * Minimal valid PDF bytes, so the content sniff has something plausible to look at.
 *
 * @param {string} marker Distinguishing text, so two uploads are not byte-identical.
 * @return {Uint8Array}
 */
export function pdfBytes( marker = 'e2e' ) {
	const text = `%PDF-1.4\n% ${ marker }\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n`;
	return new TextEncoder().encode( text );
}

/**
 * Assertion helper that records pass/fail instead of throwing, so one scenario can check
 * several things and still report all of them.
 */
export class Check {
	constructor( name ) {
		this.name = name;
		this.failures = [];
		this.count = 0;
	}

	ok( condition, message ) {
		this.count++;
		if ( ! condition ) {
			this.failures.push( message );
		}
	}

	equal( actual, expected, message ) {
		this.ok( actual === expected, `${ message } (expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) })` );
	}
}
