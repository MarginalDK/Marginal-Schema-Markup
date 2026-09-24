<?php
/**
 * Sikkerhed: statisk gennemgang af pluginnets kildekode.
 *
 * Fanger typiske bagdøre og farlige mønstre, hvis de nogensinde sniger sig ind i koden
 * (fx ved en fejl eller en kompromitteret pull request).
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @coversNothing
 */
class Test_Marginal_Schema_Security_Static extends Marginal_Schema_TestCase {

	/**
	 * Funktioner der aldrig må bruges i pluginnet.
	 */
	const FORBIDDEN_FUNCTIONS = array(
		// Kodeafvikling / kommandoer.
		'eval', 'assert', 'create_function', 'exec', 'shell_exec', 'system', 'passthru',
		'proc_open', 'popen', 'pcntl_exec', 'dl', 'call_user_func', 'call_user_func_array',
		// Typiske obfuskerings-funktioner i bagdøre.
		'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'convert_uudecode', 'hex2bin',
		// Objekt-injektion.
		'unserialize', 'maybe_unserialize',
		// Variabel-injektion.
		'extract', 'parse_str', 'import_request_variables',
		// Filsystem og netværk uden om WordPress' sikre API'er.
		'file_put_contents', 'fopen', 'fwrite', 'fputs', 'unlink', 'rmdir', 'mkdir', 'rename', 'copy',
		'move_uploaded_file', 'chmod', 'symlink', 'file_get_contents', 'readfile', 'fsockopen',
		'curl_init', 'curl_exec', 'wp_remote_get', 'wp_remote_post', 'wp_remote_request',
		// Brugere og rettigheder.
		'wp_set_current_user', 'wp_set_auth_cookie', 'wp_create_user', 'wp_insert_user', 'wp_update_user',
		'add_role', 'add_cap', 'grant_super_admin', 'set_role',
		// Upload.
		'wp_handle_upload', 'media_handle_upload',
		// Debug-output.
		'var_dump', 'print_r', 'var_export', 'error_log', 'phpinfo',
		// Opsætning.
		'ini_set', 'set_time_limit', 'putenv', 'header',
	);

	/**
	 * Eksterne værter der må optræde i koden.
	 */
	const ALLOWED_HOSTS = array(
		'github.com',
		'api.github.com',
		'raw.githubusercontent.com',
		'marginal.dk',
		'search.google.com',
		'schema.org',
		'www.gnu.org',
	);

	/**
	 * Alle PHP-filer som indgår i det udgivne plugin.
	 *
	 * @return string[]
	 */
	private function plugin_php_files() {
		$root  = MARGINAL_SCHEMA_TESTS_ROOT;
		$files = array_merge(
			glob( $root . '/*.php' ),
			glob( $root . '/includes/*.php' ),
			glob( $root . '/assets/*.php' )
		);
		$this->assertNotEmpty( $files );
		return $files;
	}

	/**
	 * Alle filer i pluginnet (PHP og JS).
	 *
	 * @return string[]
	 */
	private function plugin_files() {
		return array_merge( $this->plugin_php_files(), glob( MARGINAL_SCHEMA_TESTS_ROOT . '/assets/*.js' ) );
	}

	private function relative( $file ) {
		return str_replace( MARGINAL_SCHEMA_TESTS_ROOT . '/', '', $file );
	}

	public function test_only_known_files_are_shipped() {
		$root     = MARGINAL_SCHEMA_TESTS_ROOT;
		$expected = array(
			'assets/admin.css',
			'assets/admin.js',
			'assets/index.php',
			'includes/class-marginal-schema-admin.php',
			'includes/class-marginal-schema-json.php',
			'includes/class-marginal-schema-logger.php',
			'includes/class-marginal-schema-metabox.php',
			'includes/class-marginal-schema-output.php',
			'includes/class-marginal-schema-updater.php',
			'includes/index.php',
		);
		$actual   = array_map( array( $this, 'relative' ), array_merge( glob( $root . '/includes/*' ), glob( $root . '/assets/*' ) ) );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'Nye filer i includes/ eller assets/ skal tilføjes her bevidst (og gennemgås for sikkerhed).' );
	}

	public function test_every_php_file_blocks_direct_access() {
		foreach ( $this->plugin_php_files() as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$name = basename( $file );

			if ( 'index.php' === $name ) {
				$this->assertMatchesRegularExpression( '#^<\?php\s*(//[^\n]*\s*)?$#', $code, $this->relative( $file ) . ' skal være tom.' );
				continue;
			}

			if ( 'uninstall.php' === $name ) {
				$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;", $code );
				continue;
			}

			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $code, $this->relative( $file ) . ' mangler beskyttelse mod direkte adgang.' );
			$guard = strpos( $code, "defined( 'ABSPATH' ) || exit;" );
			$first = $this->first_executable_token_position( $file );
			$this->assertSame( $first, $guard, $this->relative( $file ) . ': ABSPATH-tjekket skal være det første der køres.' );
		}
	}

	/**
	 * Position af første kørbare kode i filen (efter kommentarer).
	 *
	 * @param string $file Fil.
	 * @return int
	 */
	private function first_executable_token_position( $file ) {
		$code   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$offset = 0;
		foreach ( token_get_all( $code ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token ) && in_array( $token[0], array( T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				$offset += strlen( $text );
				continue;
			}
			return $offset;
		}
		return $offset;
	}

	public function test_no_forbidden_functions_are_called() {
		// Undtagelser: præcis hvor og hvorfor en ellers forbudt funktion er tilladt.
		$allowed = array(
			'includes/class-marginal-schema-json.php'    => array(),
			'includes/class-marginal-schema-updater.php' => array(),
		);

		foreach ( $this->plugin_php_files() as $file ) {
			$rel    = $this->relative( $file );
			$tokens = token_get_all( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] ) {
					continue;
				}
				$name = strtolower( $tokens[ $i ][1] );
				if ( ! in_array( $name, self::FORBIDDEN_FUNCTIONS, true ) ) {
					continue;
				}

				// Er det et funktionskald (efterfulgt af "(") og ikke en metode ($x->name / X::name)?
				$next = $i + 1;
				while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					++$next;
				}
				$prev = $i - 1;
				while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
					--$prev;
				}
				$is_call   = $next < $count && '(' === $tokens[ $next ];
				$is_method = $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true );

				if ( $is_call && ! $is_method && ! in_array( $name, isset( $allowed[ $rel ] ) ? $allowed[ $rel ] : array(), true ) ) {
					$this->fail( sprintf( 'Forbudt funktion %s() i %s linje %d.', $name, $rel, $tokens[ $i ][2] ) );
				}
			}
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_no_dangerous_language_constructs() {
		foreach ( $this->plugin_php_files() as $file ) {
			$rel    = $this->relative( $file );
			$tokens = token_get_all( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			foreach ( $tokens as $i => $token ) {
				if ( '`' === $token ) {
					$this->fail( "Backtick (shell-kommando) i $rel." );
				}
				if ( '$' === $token && isset( $tokens[ $i + 1 ] ) && ( is_array( $tokens[ $i + 1 ] ) && T_VARIABLE === $tokens[ $i + 1 ][0] || '{' === $tokens[ $i + 1 ] ) ) {
					$this->fail( "Variable variables (\$\$) i $rel." );
				}
				if ( ! is_array( $token ) ) {
					continue;
				}
				if ( T_EVAL === $token[0] ) {
					$this->fail( "eval() i $rel linje {$token[2]}." );
				}
				if ( T_VARIABLE === $token[0] && in_array( $token[1], array( '$_REQUEST', '$_COOKIE', '$_FILES', '$_ENV', '$GLOBALS' ), true ) ) {
					$this->fail( "Brug af {$token[1]} i $rel linje {$token[2]}." );
				}
				if ( T_INLINE_HTML === $token[0] && '' !== trim( $token[1] ) ) {
					$this->fail( "HTML uden for PHP-tags i $rel (kan give output før headers)." );
				}
			}
		}
		$this->addToAssertionCount( 1 );
	}

	public function test_files_have_no_closing_php_tag_or_bom() {
		foreach ( $this->plugin_php_files() as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertStringStartsWith( '<?php', $code, $this->relative( $file ) . ' skal starte med <?php (ingen BOM/mellemrum).' );
			$this->assertStringNotContainsString( '?>', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $code ), $this->relative( $file ) . ' må ikke have lukke-tag (risiko for "headers already sent").' );
		}
	}

	public function test_includes_only_use_constant_paths() {
		foreach ( $this->plugin_php_files() as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
					continue;
				}
				// Eneste tilladte: require_once MARGINAL_SCHEMA_DIR . $file (fra en fast liste i bootstrap).
				$line = '';
				for ( $j = $i; $j < count( $tokens ) && ';' !== $tokens[ $j ]; $j++ ) {
					$line .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				}
				$this->assertSame( 'require_once MARGINAL_SCHEMA_DIR . $file', trim( $line ), 'Uventet include i ' . $this->relative( $file ) );
			}
		}
	}

	public function test_only_allowed_external_hosts_are_referenced() {
		foreach ( $this->plugin_files() as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			preg_match_all( '#(?:https?:)?//([a-z0-9.-]+\.[a-z]{2,})#i', $code, $m );
			foreach ( array_unique( $m[1] ) as $host ) {
				$this->assertContains( strtolower( $host ), self::ALLOWED_HOSTS, 'Ukendt ekstern vært "' . $host . '" i ' . $this->relative( $file ) );
			}
		}
	}

	public function test_no_insecure_http_urls() {
		foreach ( $this->plugin_files() as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertDoesNotMatchRegularExpression( '#http://(?!www\.gnu\.org)#', $code, 'Usikker http:// i ' . $this->relative( $file ) );
		}
	}

	public function test_admin_post_handlers_check_capability_and_nonce() {
		$handlers = array();
		foreach ( $this->plugin_php_files() as $file ) {
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( preg_match_all( "#add_action\(\s*'(admin_post_[a-z_]+|wp_ajax_[a-z_]+)',\s*array\(\s*__CLASS__,\s*'([a-z_]+)'#", $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$handlers[] = array( $file, $match[1], $match[2] );
				}
			}
		}
		$this->assertNotEmpty( $handlers );

		foreach ( $handlers as list( $file, $hook, $method ) ) {
			$class = 'Marginal_Schema_' . ucfirst( str_replace( array( 'class-marginal-schema-', '.php' ), '', basename( $file ) ) );
			$ref   = new ReflectionMethod( $class, $method );
			$lines = file( $file );
			$body  = implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );

			$this->assertStringContainsString( 'current_user_can(', $body, "$hook mangler capability-tjek." );
			$this->assertMatchesRegularExpression( '#check_admin_referer\(|check_ajax_referer\(|wp_verify_nonce\(#', $body, "$hook mangler nonce-tjek." );
			$this->assertLessThan( strpos( $body, 'Marginal_Schema_' ), strpos( $body, 'check_admin_referer(' ), "$hook skal tjekke nonce før den gør noget." );
		}
	}

	public function test_superglobals_are_always_unslashed_and_sanitized() {
		foreach ( $this->plugin_php_files() as $file ) {
			$lines = file( $file );
			foreach ( $lines as $n => $line ) {
				if ( ! preg_match( '#\$_(POST|GET|SERVER)\[#', $line ) || preg_match( '#isset\(\s*\$_|empty\(\s*\$_#', $line ) && ! preg_match( '#\?\s*\S#', $line ) ) {
					continue;
				}
				$this->assertStringContainsString( 'wp_unslash(', $line, sprintf( '%s linje %d læser input uden wp_unslash().', $this->relative( $file ), $n + 1 ) );
			}
		}
	}

	public function test_javascript_has_no_dangerous_sinks() {
		$js = (string) file_get_contents( MARGINAL_SCHEMA_TESTS_ROOT . '/assets/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$js = preg_replace( '#/\*.*?\*/|^\s*//[^\n]*#ms', '', $js );
		foreach ( array( 'eval(', 'new Function', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'setTimeout( \'', 'setTimeout(\'', 'XMLHttpRequest', 'fetch(', 'jQuery', '$(' ) as $sink ) {
			$this->assertStringNotContainsString( $sink, $js, "admin.js må ikke bruge $sink." );
		}
	}

	public function test_all_output_uses_the_single_safe_builder() {
		// Den eneste kode der skriver <script type="application/ld+json"> er Marginal_Schema_Json::build_script_tag().
		foreach ( $this->plugin_php_files() as $file ) {
			$code  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$count = substr_count( $code, "'<script type=\"application/ld+json\"" );
			$rel   = $this->relative( $file );
			$this->assertSame( 'includes/class-marginal-schema-json.php' === $rel ? 1 : 0, $count, "Uventet <script>-output i $rel." );
		}

		$code = (string) file_get_contents( MARGINAL_SCHEMA_TESTS_ROOT . '/includes/class-marginal-schema-json.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringContainsString( 'JSON_HEX_TAG', $code, 'JSON_HEX_TAG er det der forhindrer </script>-udbrud.' );
	}
}
