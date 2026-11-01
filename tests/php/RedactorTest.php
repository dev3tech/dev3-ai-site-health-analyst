<?php
/**
 * Masking rules: nothing secret, no paths, emails, IPs or user names leave the site.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Analyze\Fingerprint;
use Dev3\AiSiteHealth\Analyze\Redactor;

class RedactorTest extends Dev3sh_Base_Case {

	public function test_masks_api_keys_tokens_and_jwts() {
		$in  = 'key AIzaSyA1234567890abcdefghijklmnopqrstu stripe sk_live_51HxYzAbCdEfGh openai sk-proj-abcdefghijklmnop1234 github ghp_abcdefghijklmnopqrstuvwxyz0123 slack xoxb-1234567890-abcdef aws AKIAIOSFODNN7EXAMPLE '
			. 'Authorization: Bearer abc.def-ghi_jkl012345 jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
		$out = Redactor::mask( $in );
		foreach ( array( 'AIzaSyA', 'sk_live_51', 'sk-proj-', 'ghp_', 'xoxb-', 'AKIAIOSFODNN7', 'abc.def-ghi', 'eyJhbGci' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $out, $secret );
		}
		$this->assertStringContainsString( '[api-key]', $out );
		$this->assertStringContainsString( '[jwt]', $out );
		$this->assertStringContainsString( 'Bearer [token]', $out );
	}

	public function test_masks_key_value_secrets_in_every_format() {
		$cases = array(
			'https://x.test/cb?token=abc123&page=2'                    => 'abc123',
			'password=hunter2 next'                                    => 'hunter2',
			"define( 'DB_PASSWORD', 'S3cr3t!' );"                      => 'S3cr3t!',
			"define( 'AUTH_KEY', 'put your unique phrase here 123' );" => 'unique phrase',
			'{"client_secret":"zzTopSecret","ok":1}'                   => 'zzTopSecret',
			"array( 'api_key' => 'live-123-xyz' )"                     => 'live-123-xyz',
			'X-Api-Key: 9f8e7d6c5b4a'                                  => '9f8e7d6c5b4a',
			'Cookie: wordpress_logged_in_abc=admin%7C1700000000%7Cxyz' => '1700000000',
			'PHPSESSID=deadbeefcafe; path=/'                           => 'deadbeefcafe',
		);
		foreach ( $cases as $in => $secret ) {
			$this->assertStringNotContainsString( $secret, Redactor::mask( $in ), $in );
		}
		$this->assertStringContainsString( 'page=2', Redactor::mask( 'https://x.test/cb?token=abc123&page=2' ), 'Non-secret parameters stay' );
		$this->assertSame( '/?author=1', Redactor::mask( '/?author=1' ), 'Probe paths stay readable' );
		$this->assertStringNotContainsString( 'admin@', Redactor::mask( 'mail to admin@' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' failed' ) );
	}

	public function test_masks_db_credentials_and_dsns() {
		$out = Redactor::mask( "Access denied for user 'shopdb_user'@'10.0.0.12' (using password: YES) mysql://root:pw@db.internal:3306/shop" );
		$this->assertStringNotContainsString( 'shopdb_user', $out );
		$this->assertStringNotContainsString( '10.0.0.12', $out );
		$this->assertStringNotContainsString( 'db.internal', $out );
		$this->assertStringContainsString( "'[db-user]'@'[db-host]'", $out );
	}

	public function test_masks_paths_but_keeps_wordpress_relative_paths() {
		$abs = rtrim( ABSPATH, '/' );
		$out = Redactor::mask( "PHP Warning: Undefined variable \$x in {$abs}/wp-content/plugins/acme/inc/cart.php on line 12; include(/home/client7/private/secrets.php) /var/www/vhosts/shop.example/httpdocs/x.php" );
		$this->assertStringNotContainsString( $abs, $out );
		$this->assertStringContainsString( 'wp-content/plugins/acme/inc/cart.php', $out );
		$this->assertStringNotContainsString( 'client7', $out );
		$this->assertStringNotContainsString( '/home/', $out );
		$this->assertStringNotContainsString( 'vhosts', $out );
		$this->assertStringContainsString( '[path]/secrets.php', $out );
		$this->assertSame( 'wp-includes/class-wpdb.php', Fingerprint::relative( ABSPATH . 'wp-includes/class-wpdb.php' ) );
	}

	public function test_masks_emails_ips_site_host_and_users() {
		Redactor::add_literals( array( 'jane_doe' ), '[user]' );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = Redactor::mask( "Login failed for jane_doe <jane@shop.test> from 203.0.113.42 and 2001:db8::7334 on https://{$host}/wp-login.php card 4111 1111 1111 1111" );
		$this->assertStringNotContainsString( 'jane_doe', $out );
		$this->assertStringNotContainsString( 'jane@shop.test', $out );
		$this->assertStringNotContainsString( '203.0.113.42', $out );
		$this->assertStringNotContainsString( '2001:db8', $out );
		$this->assertStringNotContainsString( $host, $out );
		$this->assertStringNotContainsString( '4111', $out );
		$this->assertStringContainsString( '[site]/wp-login.php', $out );
		$this->assertStringContainsString( '[user]', $out );
	}

	public function test_does_not_destroy_normal_messages() {
		$msg = 'Uncaught Error: Call to undefined function Feed::add() at 10:11:12 in wp-content/themes/shop/functions.php version 6.8.1';
		$out = Redactor::mask( $msg );
		$this->assertStringContainsString( 'Feed::add()', $out );
		$this->assertStringContainsString( '10:11:12', $out );
		$this->assertStringContainsString( 'wp-content/themes/shop/functions.php', $out );
		$this->assertStringContainsString( 'https://wordpress.org/support/', Redactor::mask( 'see https://wordpress.org/support/ for help' ) );
	}

	public function test_mask_deep_and_hashes() {
		$out = Redactor::mask_deep(
			array(
				'a' => array( 'b' => 'secret=1234' ),
				'n' => 5,
				'h' => 'nonce 0123456789abcdef0123456789abcdef',
			)
		);
		$this->assertSame( 'secret=[secret]', $out['a']['b'] );
		$this->assertSame( 5, $out['n'] );
		$this->assertStringContainsString( '[hash]', $out['h'] );
	}

	public function test_filter_and_ip_anonymizer() {
		add_filter(
			'dev3sh_redact',
			static function ( $t ) {
				return str_replace( 'ACME-CORP', '[company]', $t );
			}
		);
		$this->assertSame( '[company] down', Redactor::mask( 'ACME-CORP down' ) );
		$this->assertSame( '198.51.100.0', Redactor::anonymize_ip( '198.51.100.77' ) );
	}
}
