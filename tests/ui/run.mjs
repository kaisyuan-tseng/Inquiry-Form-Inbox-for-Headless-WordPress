/**
 * UI suite: render the four admin screens and assert they are actually usable.
 *
 * Screenshots alone are a weak signal — a blank label or a PHP notice still looks like a
 * screenshot. So every page is checked before it is captured: no notice text in the body,
 * no console error, no failed subresource, and no empty heading or table header. The empty
 * label scan is the important one: this plugin resolves every UI string through a dictionary
 * keyed by name, and a mistyped key renders as an empty string.
 *
 * Runs against the real local site because wp-admin needs a full admin bootstrap. Login is a
 * cookie injection, not a password; the suite reads whatever inquiries the site already holds.
 *
 * Run:
 *   IFI_ALLOW_TEST_WRITES=1 node tests/ui/run.mjs [--update] [--no-screenshots]
 */

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { copyFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const ROOT = dirname( dirname( dirname( fileURLToPath( import.meta.url ) ) ) );
const BASELINE_DIR = join( ROOT, 'tests', 'ui', '__screenshots__' );
// Screenshots and banners belong in the SVN /assets directory, not in the plugin zip.
const ASSETS_DIR = join( ROOT, 'wporg-assets' );
const CHROMIUM = 'C:\\Users\\Administrator\\AppData\\Local\\ms-playwright\\chromium-1140\\chrome-win\\chrome.exe';

const args = new Set( process.argv.slice( 2 ) );
const skipScreenshots = args.has( '--no-screenshots' );

/**
 * Run a PHP helper and return its stdout.
 *
 * @param {string[]} argv Arguments after the script path.
 * @return {string}
 */
function php( argv ) {
	return execFileSync( 'php', argv, { cwd: ROOT, encoding: 'utf8' } ).trim();
}

/**
 * Resolve playwright-core from the global npm root.
 *
 * Derived from the running node binary rather than by shelling out to `npm`: on Windows npm
 * is a .cmd shim, and spawning it without a shell fails with ENOENT.
 *
 * @return {object}
 */
function loadPlaywright() {
	const roots = [
		join( dirname( process.execPath ), 'node_modules' ),
		process.env.APPDATA ? join( process.env.APPDATA, 'npm', 'node_modules' ) : '',
	].filter( Boolean );

	for ( const root of roots ) {
		const candidates = [
			join( root, '@playwright', 'cli', 'node_modules', 'playwright-core' ),
			join( root, 'playwright-core' ),
			join( root, 'playwright' ),
		];

		for ( const candidate of candidates ) {
			if ( existsSync( join( candidate, 'package.json' ) ) || existsSync( candidate ) ) {
				return require( candidate );
			}
		}
	}

	throw new Error(
		`playwright-core not found under ${ roots.join( ', ' ) }. Install it: npm i -g playwright-core`
	);
}

/** Page definitions: URL, screenshot name, and the anchors that must be present. */
function pages() {
	return [
		{
			key: 'list',
			url: 'wp-admin/admin.php?page=ifi-inquiry',
			title: 'Inquiry list with the overview board',
			anchors: [ 'Inquiries', 'Pending', 'Contacted', 'Received' ],
			anchorsZh: [ '询盘', '待处理', '已联系', '接收时间' ],
			screenshot: 1,
		},
		{
			key: 'detail',
			url: 'wp-admin/admin.php?page=ifi-inquiry',
			title: 'Inquiry detail dialog',
			anchors: [ 'Inquiries' ],
			anchorsZh: [ '询盘' ],
			screenshot: 2,
			optional: true,
			after: async ( page ) => {
				if ( 0 === await page.locator( 'tr.type-ifi_inquiry' ).count() ) {
					throw new Error( 'NO_INQUIRY_ROWS' );
				}
				// WordPress hides row actions until the row is hovered, so a plain click
				// waits forever for an element that is technically present but not visible.
				await page.locator( 'tr.type-ifi_inquiry' ).first().hover();
				await page.locator( '.ifi-open' ).first().click( { timeout: 10000 } );
				await page.waitForTimeout( 800 );
			},
			emptyLabelScope: '.ifi-modal',
		},
		{
			key: 'spam',
			url: 'wp-admin/admin.php?page=ifi-spam',
			title: 'Block Rules screen',
			anchors: [ 'Block Rules', 'Honeypot', 'Blocked keywords', 'Maximum links' ],
			anchorsZh: [ '拦截规则', '蜜罐', '屏蔽关键字', '链接上限' ],
			screenshot: 3,
		},
		{
			key: 'template',
			url: 'wp-admin/admin.php?page=ifi-template',
			title: 'Email template editor with live preview',
			anchors: [ 'Email Template', 'Available variables', 'Live Preview' ],
			anchorsZh: [ '邮件模板', '可用变量', '实时预览' ],
			screenshot: 4,
			after: async ( page ) => {
				// The preview is fetched over AJAX; give it a moment to render into the iframe.
				await page.waitForTimeout( 1500 );
			},
		},
		{
			key: 'template-autoreply',
			url: 'wp-admin/admin.php?page=ifi-template&kind=autoreply',
			title: 'Auto-confirmation template with language groups',
			anchors: [ 'Auto-Confirmation Template', 'Language' ],
			anchorsZh: [ '自动确认', '语言' ],
			screenshot: 0,
		},
		{
			key: 'dashboard',
			url: 'wp-admin/index.php',
			title: 'Dashboard widget',
			anchors: [ 'Recent inquiries' ],
			anchorsZh: [ '最近询盘' ],
			screenshot: 0,
			// The dashboard hosts every other plugin's widgets too. Another plugin's error
			// notice is not this plugin's problem, and the site runs Rank Math and
			// WP Mail SMTP, so the notice assertion is scoped to our own widget.
			noticeScope: '#ifi_recent',
		},
	];
}

/**
 * Inspect a loaded page and return every problem found.
 *
 * @param {import('playwright-core').Page} page Page.
 * @param {object} def Page definition.
 * @param {string[]} consoleErrors Console errors seen so far.
 * @param {string[]} failedRequests Failed subresources seen so far.
 * @return {Promise<string[]>}
 */
async function inspect( page, def, consoleErrors, failedRequests ) {
	const problems = [];

	const url = page.url();
	if ( url.includes( 'wp-login.php' ) ) {
		problems.push( `redirected to the login screen (${ url })` );
	}
	if ( ! url.includes( def.url.split( '?' )[ 1 ]?.split( '&' )[ 0 ] ?? '\u0000' ) && def.url.includes( 'page=' ) ) {
		problems.push( `unexpected URL ${ url }` );
	}

	const body = await page.locator( 'body' ).innerText();
	for ( const marker of [ 'Notice:', 'Warning:', 'Fatal error', 'Deprecated:', 'Parse error' ] ) {
		if ( body.includes( marker ) ) {
			problems.push( `page body contains "${ marker }"` );
		}
	}

	const noticeScope = def.noticeScope ?? 'body';
	const noticeErrors = await page.locator( `${ noticeScope } .notice-error` ).count();
	if ( noticeErrors > 0 ) {
		const text = await page.locator( `${ noticeScope } .notice-error` ).first().innerText();
		problems.push( `${ noticeErrors } .notice-error block(s): ${ text.replace( /\s+/g, ' ' ).slice( 0, 160 ) }` );
	}

	// Detect the interface language from <html lang>, not from the presence of CJK
	// characters in the body: inquiry rows may include a Chinese sender name, which
	// made an English page look Chinese and selected the wrong anchor set.
	const htmlLang = ( await page.evaluate( () => document.documentElement.lang || '' ) ).toLowerCase();
	const pageIsChinese = htmlLang.startsWith( 'zh' );
	const anchors = pageIsChinese && def.anchorsZh ? def.anchorsZh : def.anchors;
	for ( const anchor of anchors ) {
		if ( ! body.includes( anchor ) ) {
			problems.push( `missing expected text "${ anchor }"` );
		}
	}

	// The dictionary-key scan. An empty heading, table header or field label means a string
	// key resolved to nothing — the exact failure mode t() used to hide.
	const emptyScope = def.emptyLabelScope ?? '.wrap';
	const empty = await page.evaluate( ( scope ) => {
		const root = document.querySelector( scope ) ?? document.body;
		const selector = 'th, h1, h2, h3, label, .ifi-field__label, .ifi-vars__label';
		const out = [];
		for ( const element of root.querySelectorAll( selector ) ) {
			if ( ! element.offsetParent ) {
				continue;
			}
			if ( element.classList.contains( 'screen-reader-text' ) ) {
				continue;
			}
			const text = ( element.textContent ?? '' ).replace( /\s+/g, ' ' ).trim();
			if ( text === '' ) {
				out.push( element.tagName.toLowerCase() + '.' + ( element.className || '(no class)' ) );
			}
		}
		return out;
	}, emptyScope );

	if ( empty.length ) {
		problems.push( `${ empty.length } empty label(s): ${ empty.slice( 0, 6 ).join( ', ' ) }` );
	}

	if ( consoleErrors.length ) {
		problems.push( `console: ${ consoleErrors.slice( 0, 3 ).join( ' | ' ) }` );
	}
	if ( failedRequests.length ) {
		problems.push( `failed requests: ${ failedRequests.slice( 0, 3 ).join( ' | ' ) }` );
	}

	return problems;
}

async function main() {
	if ( ! process.env.IFI_ALLOW_TEST_WRITES ) {
		process.stdout.write(
			'Skipped: set IFI_ALLOW_TEST_WRITES=1 to run the UI suite.\n'
		);
		return 0;
	}
	if ( ! existsSync( CHROMIUM ) ) {
		process.stdout.write( `Skipped: Chromium not found at ${ CHROMIUM }\n` );
		return 0;
	}

	const { chromium } = loadPlaywright();

	for ( const dir of [ BASELINE_DIR, ASSETS_DIR ] ) {
		if ( ! existsSync( dir ) ) {
			mkdirSync( dir, { recursive: true } );
		}
	}

	const cookie = JSON.parse( php( [ 'tests/bin/issue-auth-cookie.php' ] ) );
	const home = cookie.home.replace( /\/$/, '' );

	const browser = await chromium.launch( {
		executablePath: CHROMIUM,
		args: [ '--no-sandbox', '--disable-gpu', '--hide-scrollbars' ],
	} );

	let failures = 0;
	const summaries = [];

	try {
		for ( const def of pages() ) {
			const context = await browser.newContext( {
				viewport: { width: 1280, height: 960 },
				deviceScaleFactor: 1,
			} );

			await context.addCookies(
				cookie.cookies.map( ( entry ) => ( {
					name: entry.name,
					value: entry.value,
					domain: cookie.domain,
					path: '/',
					httpOnly: true,
					sameSite: 'Lax',
					expires: cookie.expires,
				} ) )
			);

			const page = await context.newPage();
			const consoleErrors = [];
			const failedRequests = [];

			page.on( 'console', ( message ) => {
				if ( message.type() === 'error' ) {
					consoleErrors.push( message.text() );
				}
			} );
			page.on( 'pageerror', ( error ) => consoleErrors.push( error.message ) );
			page.on( 'response', ( response ) => {
				if ( response.status() >= 400 ) {
					failedRequests.push( `${ response.status() } ${ response.url() }` );
				}
			} );

			const response = await page.goto( `${ home }/${ def.url }`, {
				waitUntil: 'domcontentloaded',
				timeout: 30000,
			} );

			const problems = [];
			if ( ! response || response.status() !== 200 ) {
				problems.push( `HTTP ${ response ? response.status() : 'no response' }` );
			}

			if ( def.after ) {
				try {
					await def.after( page );
				} catch ( error ) {
					if ( def.optional && error.message.includes( 'NO_INQUIRY_ROWS' ) ) {
						process.stdout.write( `  skip ${ def.key } — no inquiry rows\n` );
						summaries.push( { key: def.key, problems: [], skipped: 'no inquiry rows' } );
						await context.close();
						continue;
					}
					problems.push( `interaction failed: ${ error.message.split( '\n' )[ 0 ] }` );
				}
			}

			problems.push( ...( await inspect( page, def, consoleErrors, failedRequests ) ) );

			if ( ! skipScreenshots ) {
				const file = join( BASELINE_DIR, `${ def.key }.png` );
				const buffer = await page.screenshot( { fullPage: false } );

				/*
				 * The screenshot is an artifact, not an assertion.
				 *
				 * A pixel comparison was the original plan, but every one of these pages
				 * carries volatile data — inquiry numbers, received times, counts, chart
				 * geometry — so the diff would fail on every run for reasons that are not
				 * regressions. The structural assertions above are what actually catch
				 * breakage (they found the empty table headers and the phantom "1 File"
				 * attachment count), and the PNGs are refreshed so the readme always shows
				 * the current build.
				 */
				writeFileSync( file, buffer );

				if ( def.screenshot ) {
					copyFileSync( file, join( ASSETS_DIR, `screenshot-${ def.screenshot }.png` ) );
				}
			}

			await context.close();

			const status = problems.length ? 'FAIL' : 'ok  ';
			process.stdout.write( `  ${ status } ${ def.key } — ${ def.title }\n` );
			for ( const problem of problems ) {
				process.stdout.write( `         ${ problem }\n` );
			}
			if ( problems.length ) {
				failures++;
			}
			summaries.push( { key: def.key, problems } );
		}
	} finally {
		await browser.close();
		// The session token was created for this run only; remove it so the admin's session
		// list does not accumulate one entry per screenshot run.
		try {
			php( [
				'tests/bin/issue-auth-cookie.php',
				`--destroy=${ cookie.token }`,
				`--user=${ cookie.user_id }`,
			] );
		} catch ( error ) {
			process.stderr.write( `Could not revoke the test session: ${ error.message }\n` );
		}
	}

	process.stdout.write( `\n${ summaries.length - failures }/${ summaries.length } pages clean\n` );

	return failures === 0 ? 0 : 1;
}

main()
	.then( ( code ) => process.exit( code ) )
	.catch( ( error ) => {
		process.stderr.write( `${ error.stack }\n` );
		process.exit( 1 );
	} );
