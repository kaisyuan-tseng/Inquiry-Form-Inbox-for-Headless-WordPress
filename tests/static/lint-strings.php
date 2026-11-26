<?php
/**
 * String-key linter for Inquiry Form Inbox.
 *
 * The plugin routes every UI string through Inquiry_Form_Inbox::t( 'key' ), backed by one
 * dictionary built in includes/trait-ifi-i18n.php. A key that is called but not declared
 * resolves to an empty string, which renders as a blank label, a blank table header or a
 * blank button with no error anywhere. This linter closes that gap:
 *
 *   - called but not declared  -> FAIL (blank UI)
 *   - declared but never used  -> WARN (dead copy weight)
 *   - non-literal call t( $x ) -> reported, cannot be checked statically
 *
 * Both sides are read from source with token_get_all(), so the check needs no WordPress,
 * no database and no network. Exit code: 0 clean, 1 missing keys, 2 usage error.
 *
 * Usage:
 *   php tests/static/lint-strings.php [--json=<path>] [--strict-unused]
 */

declare( strict_types = 1 );

$root = dirname( __DIR__, 2 );
$json_out = '';
$strict_unused = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--json=' ) ) {
		$json_out = substr( $arg, 7 );
	} elseif ( '--strict-unused' === $arg ) {
		$strict_unused = true;
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

/**
 * Decode a PHP string literal token to its runtime value.
 *
 * @param string $literal Raw token text, quotes included.
 * @return string
 */
function ifi_lint_unquote( string $literal ): string {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace(
			array( "\\'", '\\\\' ),
			array( "'", '\\' ),
			$body
		);
	}

	return str_replace(
		array( '\\"', '\\\\', '\\n', '\\t', '\\r' ),
		array( '"', '\\', "\n", "\t", "\r" ),
		$body
	);
}

/**
 * Collect every string literal passed to self::t( ... ) across the plugin source.
 *
 * @param string[] $files Absolute file paths.
 * @return array{keys: array<string, string[]>, dynamic: string[]}
 */
function ifi_lint_called_keys( array $files ): array {
	$keys    = array();
	$dynamic = array();
	$partial = array();

	foreach ( $files as $file ) {
		$code   = (string) file_get_contents( $file );
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$short  = str_replace( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR, '', $file );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || 't' !== $tokens[ $i ][1] ) {
				continue;
			}
			// Must be preceded by "::" (allow whitespace/comments between).
			$j = $i - 1;
			while ( $j >= 0 && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$j--;
			}
			if ( $j < 1 || ! is_array( $tokens[ $j ] ) || T_DOUBLE_COLON !== $tokens[ $j ][0] ) {
				continue;
			}

			// Find the opening parenthesis.
			$k = $i + 1;
			while ( $k < $count && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$k++;
			}
			if ( $k >= $count || '(' !== $tokens[ $k ] ) {
				continue;
			}

			// Walk the argument list, balancing nested parentheses.
			$depth    = 0;
			$literals = array();
			$has_var  = false;
			for ( $m = $k; $m < $count; $m++ ) {
				$tok = $tokens[ $m ];
				if ( '(' === $tok || '[' === $tok || '{' === $tok ) {
					$depth++;
					continue;
				}
				if ( ')' === $tok || ']' === $tok || '}' === $tok ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
					continue;
				}
				if ( 1 !== $depth ) {
					continue;
				}
				if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
					$literals[] = ifi_lint_unquote( $tok[1] );
				} elseif ( is_array( $tok ) && T_VARIABLE === $tok[0] ) {
					$has_var = true;
				}
			}

			// A select-expression such as t( $ok ? 'a' : 'b' ) yields both literals and a
			// variable. The literals are real keys, so always register them; only flag the
			// call as dynamic when the key cannot be resolved to any literal at all.
			foreach ( $literals as $literal ) {
				$keys[ $literal ][] = $short . ':' . $tokens[ $i ][2];
			}
			if ( ! $literals ) {
				$dynamic[] = $short . ':' . ( is_array( $tokens[ $i ] ) ? $tokens[ $i ][2] : 0 );
			} elseif ( $has_var ) {
				$partial[] = $short . ':' . ( is_array( $tokens[ $i ] ) ? $tokens[ $i ][2] : 0 );
			}
		}
	}

	ksort( $keys );
	return array(
		'keys'    => $keys,
		'dynamic' => $dynamic,
		'partial' => $partial,
	);
}

/**
 * Collect the top-level keys of the string dictionary in trait-ifi-i18n.php.
 *
 * Rather than keying off a variable name (which invites a silent break the next time the
 * map is renamed), this walks to the `strings()` declaration and takes the first array
 * literal assigned inside it. Only depth-1 string literals followed by "=>" count, so
 * values, nested arrays and translator comments are ignored.
 *
 * @param string $file Absolute path to the i18n trait.
 * @return array<string, int> Key => line number where declared.
 */
function ifi_lint_declared_keys( string $file ): array {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$count  = count( $tokens );
	$keys   = array();

	// Find "function strings(".
	$fn_at = null;
	for ( $i = 0; $i < $count - 1; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				continue;
			}
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && 'strings' === $tokens[ $j ][1] ) {
				$fn_at = $i;
			}
			break;
		}
		if ( null !== $fn_at ) {
			break;
		}
	}

	if ( null === $fn_at ) {
		return $keys;
	}

	// From there, take the first "$var = array(" / "$var = [" assignment that is not a
	// `static $cache = array()` declaration — those come first and are empty.
	$start = null;
	for ( $i = $fn_at; $i < $count - 1; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_VARIABLE !== $tokens[ $i ][0] ) {
			continue;
		}

		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			$prev--;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_STATIC === $tokens[ $prev ][0] ) {
			continue;
		}

		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $count || '=' !== $tokens[ $j ] ) {
			continue;
		}
		$j++;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j < $count && is_array( $tokens[ $j ] ) && T_ARRAY === $tokens[ $j ][0] ) {
			$start = $j;
			break;
		}
		if ( $j < $count && '[' === $tokens[ $j ] ) {
			$start = $j;
			break;
		}
	}

	if ( null === $start ) {
		return $keys;
	}

	$depth = 0;
	for ( $i = $start; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( '(' === $tok || '[' === $tok || '{' === $tok ) {
			$depth++;
			continue;
		}
		if ( ')' === $tok || ']' === $tok || '}' === $tok ) {
			$depth--;
			if ( 0 === $depth ) {
				break;
			}
			continue;
		}
		if ( 1 !== $depth || ! is_array( $tok ) || T_CONSTANT_ENCAPSED_STRING !== $tok[0] ) {
			continue;
		}

		// Only a literal immediately followed by "=>" is a key.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $count || ! is_array( $tokens[ $j ] ) || T_DOUBLE_ARROW !== $tokens[ $j ][0] ) {
			continue;
		}
		$keys[ ifi_lint_unquote( $tok[1] ) ] = $tok[2];
	}

	ksort( $keys );
	return $keys;
}

// --- Collect sources -------------------------------------------------------

$source_files = array_merge(
	(array) glob( $root . '/includes/*.php' ),
	(array) glob( $root . '/*.php' )
);
$source_files = array_values(
	array_filter(
		$source_files,
		static function ( $path ) {
			return is_file( $path ) && 'uninstall.php' !== basename( $path );
		}
	)
);

$i18n_file = $root . '/includes/trait-ifi-i18n.php';
if ( ! is_file( $i18n_file ) ) {
	fwrite( STDERR, "Cannot find {$i18n_file}\n" );
	exit( 2 );
}

$declared = ifi_lint_declared_keys( $i18n_file );
$called   = ifi_lint_called_keys( $source_files );

$missing = array_diff_key( $called['keys'], $declared );
$unused  = array_diff_key( $declared, $called['keys'] );

// --- Report ---------------------------------------------------------------

$lines   = array();
$lines[] = 'Inquiry Form Inbox — string key lint';
$lines[] = sprintf( '  declared keys : %d', count( $declared ) );
$lines[] = sprintf( '  called keys   : %d', count( $called['keys'] ) );
$lines[] = sprintf( '  call sites    : %d', array_sum( array_map( 'count', $called['keys'] ) ) );
$lines[] = sprintf( '  dynamic calls : %d', count( $called['dynamic'] ) );
$lines[] = sprintf( '  select calls  : %d', count( $called['partial'] ) );
$lines[] = '';

if ( $missing ) {
	$lines[] = sprintf( 'MISSING (%d) — these render as an empty string:', count( $missing ) );
	foreach ( $missing as $key => $sites ) {
		$lines[] = sprintf( '  - "%s"  called at %s', $key, implode( ', ', $sites ) );
	}
	$lines[] = '';
}

if ( $unused ) {
	$lines[] = sprintf( 'UNUSED (%d) — declared but never referenced:', count( $unused ) );
	foreach ( $unused as $key => $line ) {
		$lines[] = sprintf( '  - "%s"  declared at trait-ifi-i18n.php:%d', $key, $line );
	}
	$lines[] = '';
}

if ( $called['dynamic'] ) {
	$lines[] = sprintf( 'DYNAMIC (%d) — key comes from a variable, not checkable:', count( $called['dynamic'] ) );
	foreach ( $called['dynamic'] as $where ) {
		$lines[] = '  - ' . $where;
	}
	$lines[] = '';
}

if ( $called['partial'] ) {
	$lines[] = sprintf( 'SELECT (%d) — literal keys registered, but a variable also feeds the key:', count( $called['partial'] ) );
	foreach ( $called['partial'] as $where ) {
		$lines[] = '  - ' . $where;
	}
	$lines[] = '';
}

$status = 'OK';
if ( $missing ) {
	$status = 'FAIL';
} elseif ( $strict_unused && $unused ) {
	$status = 'FAIL (unused)';
}
$lines[] = 'Result: ' . $status;

$report = implode( "\n", $lines ) . "\n";
echo $report;

if ( '' !== $json_out ) {
	$dir = dirname( $json_out );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents(
		$json_out,
		json_encode(
			array(
				'declared' => array_keys( $declared ),
				'called'   => array_keys( $called['keys'] ),
				'missing'  => array_keys( $missing ),
				'unused'   => array_keys( $unused ),
				'dynamic'  => $called['dynamic'],
				'partial'  => $called['partial'],
				'call_sites' => array_sum( array_map( 'count', $called['keys'] ) ),
				'status'   => $status,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}

exit( $missing || ( $strict_unused && $unused ) ? 1 : 0 );
