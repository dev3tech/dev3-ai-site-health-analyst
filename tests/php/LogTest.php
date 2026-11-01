<?php
/**
 * Log parser, tail-with-offset, rotation awareness and grouping into issues.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Analyze\Fingerprint;
use Dev3\AiSiteHealth\Collect\Log_Parser;
use Dev3\AiSiteHealth\Collect\Php_Log;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Issues;

class LogTest extends Dev3sh_Base_Case {

	public function test_parser_levels_files_and_stack_traces() {
		$plugin = WP_PLUGIN_DIR . '/acme/acme.php';
		$text   = $this->line( "PHP Warning:  Undefined variable \$total in {$plugin} on line 41" )
			. $this->line( "PHP Fatal error:  Uncaught Error: Call to undefined function acme_missing() in {$plugin}:88" )
			. "Stack trace:\n#0 " . ABSPATH . "wp-includes/class-wp-hook.php(324): acme_boot()\n#1 {main}\n  thrown in {$plugin} on line 88\n"
			. $this->line( 'PHP Deprecated:  strlen(): Passing null to parameter #1 ($string) of type string is deprecated in ' . ABSPATH . 'wp-includes/formatting.php on line 9' )
			. $this->line( "WordPress database error Table 'wp.wp_acme' doesn't exist for query SELECT * FROM wp_acme WHERE id = 12 made by require('wp-blog-header.php'), acme_query" )
			. $this->line( 'PHP Parse error:  syntax error, unexpected token "}" in ' . get_theme_root() . '/shop/functions.php on line 3' )
			. "2026/10/25 10:11:12 [error] 123#0: *45 FastCGI sent in stderr: \"PHP message: PHP Notice:  Undefined index: foo in /srv/x.php on line 2\" while reading\n";
		$e      = Log_Parser::parse( $text );
		$this->assertCount( 6, $e );
		$this->assertSame( array( 'warning', 'fatal', 'deprecated', 'database', 'parse', 'notice' ), wp_list_pluck( $e, 'level' ) );
		$this->assertSame( $plugin, $e[0]['file'] );
		$this->assertSame( 41, $e[0]['line'] );
		$this->assertSame( 'Undefined variable $total', $e[0]['message'] );
		$this->assertSame( 'Uncaught Error: Call to undefined function acme_missing()', $e[1]['message'] );
		$this->assertSame( 88, $e[1]['line'] );
		$this->assertCount( 4, $e[1]['trace'] );
		$this->assertStringContainsString( "Table 'wp.wp_acme' doesn't exist for query SELECT * FROM wp_acme WHERE id = ?", $e[3]['message'] );
		$this->assertSame( 'plugin:acme', Fingerprint::component( $e[0]['file'] ) );
		$this->assertSame( 'theme:shop', Fingerprint::component( $e[4]['file'] ) );
		$this->assertSame( 'core', Fingerprint::component( $e[2]['file'] ) );
		$this->assertSame( 'Fatal error: Uncaught Error: Call to undefined function acme_missing()', Log_Parser::title( $e[1] ) );
		$this->assertGreaterThan( 0, $e[0]['time'] );
	}

	public function test_normalisation_groups_repeats() {
		$a = 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes)';
		$b = 'Allowed memory size of 268435456 bytes exhausted (tried to allocate 4096 bytes)';
		$this->assertSame( Fingerprint::make( 'php', 'fatal', $a, 'x.php', 3 ), Fingerprint::make( 'php', 'fatal', $b, 'x.php', 3 ) );
		$this->assertNotSame( Fingerprint::make( 'php', 'fatal', $a, 'x.php', 3 ), Fingerprint::make( 'php', 'fatal', $a, 'x.php', 4 ) );
	}

	public function test_three_warnings_and_one_fatal_make_two_issues_and_tail_reads_only_new_bytes() {
		$file = WP_PLUGIN_DIR . '/acme/acme.php';
		$warn = $this->line( "PHP Warning:  Undefined array key \"qty\" in {$file} on line 7" );
		$path = $this->log_file( $warn . $warn . $warn . $this->line( "PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes) in {$file} on line 90" ) );

		$r = Php_Log::collect();
		$this->assertSame( 4, $r['entries'] );
		$this->assertSame( 2, $r['issues'] );
		$list = Issues::query( array( 'source' => 'php' ) )['items'];
		$this->assertCount( 2, $list );
		$by = array_column( $list, 'count', 'kind' );
		$this->assertSame( 3, $by['warning'] );
		$this->assertSame( 1, $by['fatal'] );
		$fatal = wp_list_filter( $list, array( 'kind' => 'fatal' ) );
		$fatal = reset( $fatal );
		$this->assertSame( 'critical', $fatal['severity'] );
		$this->assertSame( 'plugin:acme', $fatal['component'] );
		$this->assertSame( 'wp-content/plugins/acme/acme.php', $fatal['file'] );

		// Nothing new: nothing read.
		$this->assertSame( 0, Php_Log::collect()['bytes'] );

		// Two more lines (one half written): only the complete one is consumed now.
		file_put_contents( $path, $warn . 'PHP Warning:  Undefined array key "qty" in', FILE_APPEND ); // phpcs:ignore
		$r = Php_Log::collect();
		$this->assertSame( strlen( $warn ), $r['bytes'] );
		file_put_contents( $path, " {$file} on line 7\n", FILE_APPEND ); // phpcs:ignore
		Php_Log::collect();
		$by = array_column( Issues::query( array( 'source' => 'php' ) )['items'], 'count', 'kind' );
		$this->assertSame( 5, $by['warning'], 'Each line is counted exactly once' );
	}

	public function test_rotation_and_truncation_are_detected_without_double_counting() {
		$file = WP_PLUGIN_DIR . '/acme/acme.php';
		$line = $this->line( "PHP Notice:  Undefined index: color in {$file} on line 5" );
		$path = $this->log_file( str_repeat( $line, 4 ) );
		Php_Log::collect();
		// The log keeps growing, then logrotate moves it to .1 and starts a new, shorter file.
		file_put_contents( $path, $line, FILE_APPEND ); // phpcs:ignore
		rename( $path, $path . '.1' ); // phpcs:ignore
		file_put_contents( $path, $line ); // phpcs:ignore
		$r = Php_Log::collect();
		$this->assertTrue( $r['rotated'] );
		$by = array_column( Issues::query( array( 'source' => 'php' ) )['items'], 'count', 'kind' );
		$this->assertSame( 6, $by['notice'], 'Unread rest of the rotated file + the new file, each line once' );

		// Truncated in place (emptied by a cleanup plugin).
		file_put_contents( $path, '' ); // phpcs:ignore
		Php_Log::collect();
		file_put_contents( $path, $line ); // phpcs:ignore
		Php_Log::collect();
		$by = array_column( Issues::query( array( 'source' => 'php' ) )['items'], 'count', 'kind' );
		$this->assertSame( 7, $by['notice'] );
		$this->assertNotEmpty( get_option( Php_Log::STATE )['rotated'] );
	}

	public function test_first_run_on_a_big_log_reads_only_the_tail() {
		$line = $this->line( 'PHP Notice:  Undefined index: a in ' . WP_PLUGIN_DIR . '/acme/a.php on line 1' );
		$this->log_file( str_repeat( $line, 2000 ) );
		$r = Php_Log::read_new( 65536 );
		$this->assertGreaterThan( 0, $r['skipped'] );
		$this->assertLessThanOrEqual( 65536, $r['bytes'] );
		$this->assertStringStartsWith( '[', $r['text'] );
	}

	public function test_tail_viewer_and_status_messages() {
		$this->log_file( $this->line( 'PHP Warning:  Division by zero in ' . WP_PLUGIN_DIR . '/acme/a.php on line 3' ) . $this->line( 'PHP Notice:  x in ' . WP_PLUGIN_DIR . '/acme/a.php on line 4' ) );
		Php_Log::collect();
		$tail = Php_Log::tail( 10, 'warning' );
		$this->assertCount( 1, $tail['entries'] );
		$this->assertSame( 'wp-content/plugins/acme/a.php', $tail['entries'][0]['file'] );
		$this->assertGreaterThan( 0, $tail['entries'][0]['issue'], 'Linked to its issue' );
		$this->assertTrue( $tail['info']['readable'] );

		remove_all_filters( 'dev3sh_log_path' );
		add_filter( 'dev3sh_log_path', '__return_empty_string' );
		$info = Php_Log::locate();
		$this->assertFalse( $info['enabled'] );
		$this->assertStringContainsString( 'WP_DEBUG_LOG', $info['message'] );
		$this->assertWPError( Php_Log::validate_path( '/etc/passwd' ) );
		$this->assertTrue( Php_Log::validate_path( '/var/log/php/error.log' ) );
	}

	public function test_notice_and_deprecation_switches() {
		Settings_Store::update(
			array(
				'log_notices'    => false,
				'log_deprecated' => false,
			)
		);
		$this->log_file( $this->line( 'PHP Notice:  x in /a.php on line 1' ) . $this->line( 'PHP Deprecated:  y in /a.php on line 2' ) . $this->line( 'PHP Warning:  z in /a.php on line 3' ) );
		Php_Log::collect();
		$this->assertSame( 1, Issues::query( array( 'source' => 'php' ) )['total'] );
	}
}
