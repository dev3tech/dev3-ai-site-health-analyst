<?php
/**
 * File integrity vs (mocked) wordpress.org checksums.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Data\Issues;

class IntegrityTest extends Dev3sh_Base_Case {

	/**
	 * Fake core checksums: real md5s of a few files, one wrong, one missing file.
	 */
	private function fake_core() {
		$sums = array(
			'wp-load.php'                 => md5_file( ABSPATH . 'wp-load.php' ),
			'wp-includes/version.php'     => md5_file( ABSPATH . 'wp-includes/version.php' ),
			'wp-includes/formatting.php'  => 'ffffffffffffffffffffffffffffffff',
			'wp-includes/gone-file.php'   => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
			'licencia.txt'                => 'dddddddddddddddddddddddddddddddd',
			'wp-content/plugins/index.php' => 'cccccccccccccccccccccccccccccccc',
		);
		add_filter( 'dev3sh_core_integrity', array( $this, 'only_known_unknowns' ) );
		$this->routes['api.wordpress.org/core/checksums'] = array(
			200,
			wp_json_encode( array( 'checksums' => $sums ) ),
		);
		return $sums;
	}

	/**
	 * The fake checksum list has only a few files, so drop "unknown" noise except our planted file.
	 *
	 * @param array $out Result.
	 * @return array
	 */
	public function only_known_unknowns( $out ) {
		$out['unknown'] = array_values( array_filter( $out['unknown'], static fn( $f ) => 'wp-includes/dev3sh-backdoor.php' === $f ) );
		return $out;
	}

	public function tear_down() {
		remove_filter( 'dev3sh_core_integrity', array( $this, 'only_known_unknowns' ) );
		$planted = ABSPATH . 'wp-includes/dev3sh-backdoor.php';
		if ( file_exists( $planted ) ) {
			unlink( $planted ); // phpcs:ignore
		}
		parent::tear_down();
	}

	public function test_core_modified_missing_and_unknown_files() {
		$this->fake_core();
		file_put_contents( ABSPATH . 'wp-includes/dev3sh-backdoor.php', '<?php // test' ); // phpcs:ignore
		$core = Integrity::check_core();
		$this->assertSame( 'modified', $core['status'] );
		$this->assertSame( array( 'wp-includes/formatting.php' ), $core['modified'] );
		$this->assertSame( array( 'wp-includes/gone-file.php' ), $core['missing'], 'licencia.txt may be missing; wp-content is skipped' );
		$this->assertSame( array( 'wp-includes/dev3sh-backdoor.php' ), $core['unknown'] );
		$this->assertSame( 5, $core['checked'] );
	}

	public function test_plugins_on_and_off_wordpress_org() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$dir = WP_PLUGIN_DIR . '/dev3sh-fake-org';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/dev3sh-fake-org.php', "<?php\n/*\nPlugin Name: Fake Org\nVersion: 1.2.3\n*/\n" ); // phpcs:ignore
		file_put_contents( $dir . '/extra.php', '<?php // planted' ); // phpcs:ignore
		file_put_contents( $dir . '/readme.txt', 'changed' ); // phpcs:ignore
		wp_cache_delete( 'plugins', 'plugins' );
		$this->routes['plugin-checksums/dev3sh-fake-org/1.2.3.json'] = array(
			200,
			wp_json_encode(
				array(
					'files' => array(
						'dev3sh-fake-org.php' => array( 'md5' => md5_file( $dir . '/dev3sh-fake-org.php' ) ),
						'readme.txt'          => array( 'md5' => array( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) ),
						'inc/lost.php'        => array( 'md5' => 'cccccccccccccccccccccccccccccccc' ),
					),
				)
			),
		);
		$p = Integrity::check_plugin( 'dev3sh-fake-org/dev3sh-fake-org.php' );
		$this->assertSame( 'modified', $p['status'] );
		$this->assertSame( array( 'readme.txt' ), $p['modified'] );
		$this->assertSame( array( 'inc/lost.php' ), $p['missing'] );
		$this->assertSame( array( 'extra.php' ), $p['added'] );

		$own = Integrity::check_plugin( plugin_basename( DEV3SH_FILE ) );
		$this->assertSame( 'unverifiable', $own['status'], 'Premium/custom plugins are never reported as modified' );
		$this->assertSame( 'unverifiable', Integrity::check_plugin( 'hello.php' )['status'] );

		foreach ( array( 'dev3sh-fake-org.php', 'extra.php', 'readme.txt' ) as $f ) {
			unlink( $dir . '/' . $f ); // phpcs:ignore
		}
		rmdir( $dir ); // phpcs:ignore
	}

	public function test_job_runs_and_records_issues() {
		$this->fake_core();
		add_filter( 'dev3sh_jobs_use_action_scheduler', '__return_false' );
		$job = Integrity::start();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( $job['id'], Integrity::start()['id'], 'One job at a time' );
		$final = Jobs::run( $job['id'] );
		$this->assertSame( 'done', $final['status'] );
		$last = Integrity::last();
		$this->assertSame( 1, $last['summary']['core_modified'] );
		$this->assertSame( 1, $last['summary']['core_missing'] );
		$issue = Issues::query( array( 'source' => 'integrity' ) )['items'][0];
		$this->assertSame( 'critical', $issue['severity'] );
		$this->assertSame( 2, $issue['count'] );
		$this->assertContains( 'wp-includes/formatting.php', $issue['context']['details']['modified'] );
	}

	public function test_checksum_download_failure_is_reported_not_crashing() {
		$this->routes['api.wordpress.org/core/checksums'] = array( 500, '' );
		$core = Integrity::check_core();
		$this->assertSame( 'error', $core['status'] );
		$res = Integrity::save( $core, array() );
		$this->assertSame( 0, $res['summary']['core_modified'] );
		$this->assertSame( 0, Issues::query( array( 'source' => 'integrity' ) )['total'] );
	}
}
