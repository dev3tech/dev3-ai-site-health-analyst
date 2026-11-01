<?php
/**
 * Built-in, rule-based explanations (used when AI is off and as the instant first answer).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Analyze;

defined( 'ABSPATH' ) || exit;

/**
 * Pattern → plain-words explanation.
 */
class Knowledge_Base {

	/**
	 * Explains an issue.
	 *
	 * @param array $issue Issue (Issues::shape()).
	 * @return array { summary, cause, steps[], who, urgency, fix_action, fix_target, source:'built-in' }
	 */
	public static function explain( array $issue ) {
		$msg   = (string) $issue['message'];
		$who   = self::who( (string) $issue['component'] );
		$label = Fingerprint::component_label( (string) $issue['component'] );
		$label = '' !== $label ? $label : __( 'the code that wrote this message', 'dev3-ai-site-health-analyst' );
		$e     = null;

		switch ( $issue['source'] ) {
			case 'php':
				$e = self::php( $issue, $msg, $label );
				break;
			case 'security':
				$e = self::security( $issue );
				break;
			case 'updates':
				$e = array(
					'summary' => __( 'Software on your site has a newer version. Updates fix bugs and, very often, security holes that attackers already know about.', 'dev3-ai-site-health-analyst' ),
					'cause'   => __( 'The developer released a new version after you installed this one.', 'dev3-ai-site-health-analyst' ),
					'steps'   => array(
						__( 'Make a backup of files and database (or check that your host keeps daily backups).', 'dev3-ai-site-health-analyst' ),
						__( 'Open Dashboard → Updates and read the changelog for big version jumps.', 'dev3-ai-site-health-analyst' ),
						__( 'Update, then open the home page, a product/post page and the checkout or contact form to confirm everything works.', 'dev3-ai-site-health-analyst' ),
						__( 'Turn on automatic updates for plugins you trust so this does not pile up again.', 'dev3-ai-site-health-analyst' ),
					),
					'who'     => 'owner',
					'urgency' => 'high' === $issue['severity'] ? 'soon' : 'normal',
				);
				if ( 'inactive_plugins' === $issue['kind'] ) {
					$e['summary'] = __( 'Plugins you are not using are still on the server. Their code can still be attacked even while inactive.', 'dev3-ai-site-health-analyst' );
					$e['cause']   = __( 'Plugins were deactivated instead of deleted.', 'dev3-ai-site-health-analyst' );
					$e['steps']   = array(
						__( 'Go to Plugins → Inactive.', 'dev3-ai-site-health-analyst' ),
						__( 'Delete every plugin you do not plan to use again (settings of most plugins are kept in the database).', 'dev3-ai-site-health-analyst' ),
					);
				} elseif ( 'php_version' === $issue['kind'] ) {
					$e['summary'] = __( 'Your server runs an old PHP version that no longer gets security fixes and is slower than current versions.', 'dev3-ai-site-health-analyst' );
					$e['cause']   = __( 'The hosting account was set up years ago and never upgraded.', 'dev3-ai-site-health-analyst' );
					$e['steps']   = array(
						__( 'Check in Tools → Site Health → Info that all your plugins support PHP 8.1+.', 'dev3-ai-site-health-analyst' ),
						__( 'Switch the PHP version in your hosting panel (or ask your host) on a staging copy first.', 'dev3-ai-site-health-analyst' ),
						__( 'Watch this plugin\'s Error log for new warnings for a day after the switch.', 'dev3-ai-site-health-analyst' ),
					);
					$e['who']     = 'host';
				}
				break;
			case 'cron':
				$e = array(
					'summary' => __( 'WordPress runs scheduled tasks (publishing scheduled posts, sending emails, backups, update checks) with WP-Cron. Some of these tasks are late or will never run.', 'dev3-ai-site-health-analyst' ),
					'cause'   => __( 'WP-Cron only runs when someone visits the site, or it is disabled with DISABLE_WP_CRON and no server cron job replaces it. A blocked loopback request can also stop it.', 'dev3-ai-site-health-analyst' ),
					'steps'   => array(
						__( 'Check Tools → Site Health for "loopback request" or "scheduled event" problems.', 'dev3-ai-site-health-analyst' ),
						__( 'If DISABLE_WP_CRON is set, ask your host to add a server cron job that calls wp-cron.php every 5 minutes.', 'dev3-ai-site-health-analyst' ),
						__( 'Look at the System screen of this plugin to see which tasks are late and which plugin owns them.', 'dev3-ai-site-health-analyst' ),
					),
					'who'     => 'host',
					'urgency' => 'normal',
				);
				if ( 'orphan' === $issue['kind'] ) {
					$e['summary']    = __( 'A scheduled task is still registered, but no plugin handles it any more. It does nothing but keeps being rescheduled.', 'dev3-ai-site-health-analyst' );
					$e['cause']      = __( 'The plugin that created it was removed without cleaning up.', 'dev3-ai-site-health-analyst' );
					$e['steps']      = array(
						__( 'Check that you really removed the plugin that owned this task (the hook name usually starts with its name).', 'dev3-ai-site-health-analyst' ),
						__( 'Remove the task with the one-click fix (needs confirmation) or with the WP Crontrol plugin.', 'dev3-ai-site-health-analyst' ),
					);
					$e['who']        = 'owner';
					$e['fix_action'] = 'unschedule_cron';
					$e['fix_target'] = isset( $issue['context']['details']['hook'] ) ? (string) $issue['context']['details']['hook'] : '';
				}
				break;
			case 'query':
				$e = array(
					'summary' => __( 'A database query takes long enough to slow page loads. Repeated slow queries make the whole site feel sluggish and can overload the database server.', 'dev3-ai-site-health-analyst' ),
					'cause'   => __( 'Usually a missing index, a query over a large table (for example postmeta or options with many autoloaded rows), or a plugin running a heavy report on every page.', 'dev3-ai-site-health-analyst' ),
					'steps'   => array(
						/* translators: %s: component label. */
						sprintf( __( 'Check which page triggered it (sample) and which code ran it (%s).', 'dev3-ai-site-health-analyst' ), $label ),
						__( 'Update that plugin; check its settings for logging/statistics options you do not need.', 'dev3-ai-site-health-analyst' ),
						__( 'Use a persistent object cache (Redis/Memcached) if your host offers it.', 'dev3-ai-site-health-analyst' ),
						__( 'Send the query and timing to the plugin developer if it continues.', 'dev3-ai-site-health-analyst' ),
					),
					'who'     => 'developer',
					'urgency' => 'normal',
				);
				break;
			case 'integrity':
				$e = self::integrity( $issue );
				break;
			case 'change':
				$e = array(
					'summary' => __( 'Plugin or theme code changed without going through the WordPress admin (for example over FTP, SSH, a deployment tool or by an attacker).', 'dev3-ai-site-health-analyst' ),
					'cause'   => __( 'A developer or your host deployed files directly, or someone with server access changed them.', 'dev3-ai-site-health-analyst' ),
					'steps'   => array(
						__( 'Ask your developer or host whether they made this change.', 'dev3-ai-site-health-analyst' ),
						__( 'If nobody did, run the Integrity check and change all passwords (WordPress, hosting, FTP, database).', 'dev3-ai-site-health-analyst' ),
						__( 'Mark the issue as resolved once you have confirmed it.', 'dev3-ai-site-health-analyst' ),
					),
					'who'     => 'owner',
					'urgency' => 'soon',
				);
				break;
			case 'site_health':
				$details = isset( $issue['context']['details'] ) ? (array) $issue['context']['details'] : array();
				$steps   = array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $details['actions'] ?? '' ) ) ) ) );
				$e       = array(
					'summary' => '' !== $msg ? mb_substr( $msg, 0, 600 ) : (string) $issue['title'],
					'cause'   => __( 'Reported by the WordPress Site Health check.', 'dev3-ai-site-health-analyst' ),
					'steps'   => $steps ? array_slice( $steps, 0, 6 ) : array( __( 'Open Tools → Site Health for details and the recommended action.', 'dev3-ai-site-health-analyst' ) ),
					'who'     => 'owner',
					'urgency' => 'high' === $issue['severity'] ? 'soon' : 'normal',
				);
				break;
		}
		if ( ! $e ) {
			$e = array(
				'summary' => __( 'This message was written to the error log. It may or may not affect visitors.', 'dev3-ai-site-health-analyst' ),
				'cause'   => __( 'Not enough detail for a built-in explanation.', 'dev3-ai-site-health-analyst' ),
				'steps'   => array( __( 'Use "Explain with DEV3 AI", or send the message and the file it mentions to your developer.', 'dev3-ai-site-health-analyst' ) ),
				'who'     => $who,
				'urgency' => 'normal',
			);
		}
		return array_merge(
			array(
				'fix_action' => 'none',
				'fix_target' => '',
			),
			$e,
			array( 'source' => 'built-in' )
		);
	}

	/**
	 * PHP errors.
	 *
	 * @param array  $issue Issue.
	 * @param string $msg   Message.
	 * @param string $label Component label.
	 * @return array
	 */
	private static function php( array $issue, $msg, $label ) {
		$who        = self::who( (string) $issue['component'] );
		$where      = '' !== $issue['file'] ? $issue['file'] . ( $issue['line'] ? ':' . $issue['line'] : '' ) : '';
		$base       = array(
			'who'     => $who,
			'urgency' => 'critical' === $issue['severity'] ? 'now' : ( 'medium' === $issue['severity'] ? 'normal' : 'low' ),
		);
		$plugin_fix = array();
		if ( 0 === strpos( (string) $issue['component'], 'plugin:' ) && in_array( $issue['kind'], array( 'fatal', 'parse' ), true ) ) {
			$plugin_fix = array(
				'fix_action' => 'deactivate_plugin',
				'fix_target' => substr( (string) $issue['component'], 7 ),
			);
		}
		$rules = array(
			'/Allowed memory size of \S+ bytes exhausted/i' => array(
				__( 'PHP ran out of memory while building a page, so the request stopped (visitors may see a blank page or "critical error").', 'dev3-ai-site-health-analyst' ),
				__( 'The memory limit is too low for your plugins, or one plugin loads far too much data at once (big imports, reports, image processing).', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Raise WP_MEMORY_LIMIT in wp-config.php to 256M, or ask your host to raise memory_limit.', 'dev3-ai-site-health-analyst' ),
					/* translators: %s: component. */
					sprintf( __( 'If it keeps happening at the same place, update %s or contact its developer.', 'dev3-ai-site-health-analyst' ), $label ),
					__( 'Check whether the error happens on one specific page or action (see the samples).', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Maximum execution time of \d+ seconds exceeded/i' => array(
				__( 'A request took longer than the server allows and was stopped half way.', 'dev3-ai-site-health-analyst' ),
				__( 'A slow external service, a heavy import/export, or a slow database query.', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Find the action that triggers it (see the samples) and run it in smaller batches.', 'dev3-ai-site-health-analyst' ),
					__( 'Ask your host to raise max_execution_time for admin tasks, or to check server load.', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Call to undefined (function|method)|Class "?[^"]+"? not found|Interface "?[^"]+"? not found/i' => array(
				__( 'Code tried to use a function or class that does not exist, so the page crashed.', 'dev3-ai-site-health-analyst' ),
				__( 'Usually a plugin that needs another plugin (or a newer PHP/WordPress version) that is missing, or an incomplete update.', 'dev3-ai-site-health-analyst' ),
				array(
					/* translators: %s: component. */
					sprintf( __( 'Re-install or update %s.', 'dev3-ai-site-health-analyst' ), $label ),
					__( 'Check the plugin\'s requirements (other plugins, PHP version).', 'dev3-ai-site-health-analyst' ),
					__( 'If the site is down, deactivate the plugin (one-click fix or rename its folder over FTP).', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Cannot modify header information - headers already sent/i' => array(
				__( 'Some code printed output too early, so WordPress could not send redirects or cookies. Logins and redirects can break.', 'dev3-ai-site-health-analyst' ),
				__( 'Extra spaces or blank lines before "<?php" or after "?>" in a PHP file, or a plugin echoing output on the wrong hook.', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Look at the "output started at" file in the message: that file is the cause, not the one that failed.', 'dev3-ai-site-health-analyst' ),
					__( 'Remove whitespace before "<?php" / after a closing "?>" in that file (often functions.php or wp-config.php).', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Undefined (variable|array key|index|offset|property)|Trying to access array offset|Attempt to read property/i' => array(
				__( 'Code read a value that was not set. It usually keeps working, but it fills your log and can hide a real bug.', 'dev3-ai-site-health-analyst' ),
				__( 'A small coding mistake, often exposed by a newer PHP version.', 'dev3-ai-site-health-analyst' ),
				array(
					/* translators: %s: component. */
					sprintf( __( 'Update %s; most such warnings are fixed in later versions.', 'dev3-ai-site-health-analyst' ), $label ),
					__( 'If it is your own theme or custom code, ask your developer to check the value before using it (isset / empty).', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Deprecated|is deprecated|passing null to parameter/i' => array(
				__( 'Code uses something that newer PHP or WordPress versions will remove. Nothing is broken yet.', 'dev3-ai-site-health-analyst' ),
				__( 'The code was written for older versions.', 'dev3-ai-site-health-analyst' ),
				array(
					/* translators: %s: component. */
					sprintf( __( 'Update %s before upgrading PHP or WordPress.', 'dev3-ai-site-health-analyst' ), $label ),
					__( 'You can hide deprecations from collection in Settings if the log gets too noisy.', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/doesn\'t exist|Table .* doesn.t exist|Unknown column/i' => array(
				__( 'A database table or column that the code expects is missing. Features that use it fail.', 'dev3-ai-site-health-analyst' ),
				__( 'A plugin was not fully installed or upgraded, or a database was restored from an older backup.', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Deactivate and re-activate the plugin that owns the table so it can create it.', 'dev3-ai-site-health-analyst' ),
					__( 'Run the plugin\'s database update if it shows a notice.', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/Deadlock found|Lock wait timeout|MySQL server has gone away|Too many connections/i' => array(
				__( 'The database server was overloaded or dropped the connection for a moment.', 'dev3-ai-site-health-analyst' ),
				__( 'Traffic spikes, heavy background jobs or a small hosting plan.', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Check whether it lines up with imports, backups or traffic spikes.', 'dev3-ai-site-health-analyst' ),
					__( 'Ask your host about database limits and slow queries.', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/cURL error 28|Operation timed out|cURL error 6|Could not resolve host|SSL certificate problem/i' => array(
				__( 'The site could not reach an external service (update server, payment gateway, API) in time.', 'dev3-ai-site-health-analyst' ),
				__( 'The remote service was slow or down, or your server blocks outgoing connections / has outdated SSL certificates.', 'dev3-ai-site-health-analyst' ),
				array(
					__( 'Check whether it happens only now and then (remote service) or always (server configuration).', 'dev3-ai-site-health-analyst' ),
					__( 'Ask your host to check outgoing connections, DNS and the CA certificate bundle.', 'dev3-ai-site-health-analyst' ),
				),
			),
			'/syntax error|unexpected/i' => array(
				__( 'A PHP file contains a typo, so PHP cannot read it at all. Every page that loads this file fails.', 'dev3-ai-site-health-analyst' ),
				__( 'Usually a manual edit (theme editor, FTP) or a broken upload.', 'dev3-ai-site-health-analyst' ),
				array(
					/* translators: %s: file. */
					sprintf( __( 'Undo the last edit in %s, or restore the file from a backup.', 'dev3-ai-site-health-analyst' ), '' !== $where ? $where : __( 'the file', 'dev3-ai-site-health-analyst' ) ),
					__( 'If it is a plugin, re-install it from a fresh download.', 'dev3-ai-site-health-analyst' ),
				),
			),
		);
		foreach ( $rules as $re => $r ) {
			if ( preg_match( $re, $msg ) ) {
				return array_merge(
					$base,
					array(
						'summary' => $r[0],
						'cause'   => $r[1],
						'steps'   => $r[2],
					),
					$plugin_fix
				);
			}
		}
		$generic = array(
			'fatal'    => __( 'A fatal error stopped PHP. Visitors on that page saw an error or a blank page.', 'dev3-ai-site-health-analyst' ),
			'parse'    => __( 'A PHP file contains a syntax error and cannot be loaded.', 'dev3-ai-site-health-analyst' ),
			'database' => __( 'A database query failed. The feature that ran it may show missing or wrong data.', 'dev3-ai-site-health-analyst' ),
			'warning'  => __( 'PHP warned about a problem but kept running. The feature may behave oddly.', 'dev3-ai-site-health-analyst' ),
			'notice'   => __( 'A minor coding issue. It does not break the site but fills the log.', 'dev3-ai-site-health-analyst' ),
		);
		return array_merge(
			$base,
			array(
				'summary' => isset( $generic[ $issue['kind'] ] ) ? $generic[ $issue['kind'] ] : __( 'An entry in the PHP error log.', 'dev3-ai-site-health-analyst' ),
				/* translators: %s: component. */
				'cause'   => sprintf( __( 'Raised by %s.', 'dev3-ai-site-health-analyst' ), $label ) . ( '' !== $where ? ' (' . $where . ')' : '' ),
				'steps'   => array(
					/* translators: %s: component. */
					sprintf( __( 'Update %s to the latest version.', 'dev3-ai-site-health-analyst' ), $label ),
					__( 'If it continues, send the message, the file/line and the samples to its developer.', 'dev3-ai-site-health-analyst' ),
				),
			),
			$plugin_fix
		);
	}

	/**
	 * Security issues.
	 *
	 * @param array $issue Issue.
	 * @return array
	 */
	private static function security( array $issue ) {
		$kind = (string) $issue['kind'];
		if ( 'login_failed' === $kind || 'brute_force' === $kind ) {
			$e = array(
				'summary' => 'brute_force' === $kind
					? __( 'One address is trying many passwords on your login page. This is an automated attack that guesses weak passwords.', 'dev3-ai-site-health-analyst' )
					: __( 'People or bots tried to log in with wrong user names or passwords. A few are normal (typos); many are bots guessing passwords.', 'dev3-ai-site-health-analyst' ),
				'cause'   => __( 'Bots scan every WordPress site for wp-login.php and xmlrpc.php and try common user names like "admin".', 'dev3-ai-site-health-analyst' ),
				'steps'   => array(
					__( 'Make sure every administrator uses a long, unique password and turn on two-factor authentication.', 'dev3-ai-site-health-analyst' ),
					__( 'Do not use "admin" as a user name.', 'dev3-ai-site-health-analyst' ),
					__( 'Block the worst addresses on the Security screen (needs confirmation) or with your host/CDN firewall.', 'dev3-ai-site-health-analyst' ),
					__( 'If you do not use the mobile app or Jetpack, disable XML-RPC.', 'dev3-ai-site-health-analyst' ),
				),
				'who'     => 'owner',
				'urgency' => 'brute_force' === $kind ? 'soon' : 'low',
			);
			if ( 'brute_force' === $kind && ! empty( $issue['context']['details']['ip'] ) ) {
				$e['fix_action'] = 'block_ip';
				$e['fix_target'] = (string) $issue['context']['details']['ip'];
			}
			return $e;
		}
		$rule  = substr( $kind, strlen( 'suspicious_' ) );
		$texts = array(
			'traversal'      => __( 'Bots tried to read files outside the web folder with "../" in the address. WordPress blocks this, but it shows someone is scanning your site for holes.', 'dev3-ai-site-health-analyst' ),
			'system_file'    => __( 'Bots tried to read server system files such as /etc/passwd. This only works on badly configured servers, but it is a clear attack.', 'dev3-ai-site-health-analyst' ),
			'sqli'           => __( 'Requests contained SQL injection patterns ("union select", "sleep("). Attackers test whether a plugin passes input straight into database queries.', 'dev3-ai-site-health-analyst' ),
			'xss'            => __( 'Requests contained script code. Attackers test whether a form or page prints input back without escaping it.', 'dev3-ai-site-health-analyst' ),
			'sensitive_file' => __( 'Bots looked for files that should never be public: backups, .env files, .git folders, phpinfo pages.', 'dev3-ai-site-health-analyst' ),
			'author_enum'    => __( 'Someone listed user names through "?author=1". Knowing a user name makes password guessing easier.', 'dev3-ai-site-health-analyst' ),
			'rest_user_enum' => __( 'Someone listed user names through the REST API users endpoint.', 'dev3-ai-site-health-analyst' ),
			'not_found'      => __( 'One address requested many pages that do not exist in a short time. This is typical for vulnerability scanners.', 'dev3-ai-site-health-analyst' ),
		);
		return array(
			'summary' => isset( $texts[ $rule ] ) ? $texts[ $rule ] : __( 'Suspicious requests were seen.', 'dev3-ai-site-health-analyst' ),
			'cause'   => __( 'Automated scanners probe every site on the internet. Seeing them is normal; what matters is that nothing they look for exists or works.', 'dev3-ai-site-health-analyst' ),
			'steps'   => array(
				__( 'Keep WordPress, plugins and themes updated (see the Updates issues).', 'dev3-ai-site-health-analyst' ),
				__( 'Make sure no backups, .env or .sql files are stored inside the web folder.', 'dev3-ai-site-health-analyst' ),
				__( 'If one address keeps coming back, block it on the Security screen or in your host/CDN firewall.', 'dev3-ai-site-health-analyst' ),
			),
			'who'     => 'owner',
			'urgency' => in_array( $rule, array( 'sqli', 'system_file' ), true ) ? 'soon' : 'low',
		);
	}

	/**
	 * Integrity issues.
	 *
	 * @param array $issue Issue.
	 * @return array
	 */
	private static function integrity( array $issue ) {
		$details = isset( $issue['context']['details'] ) ? (array) $issue['context']['details'] : array();
		if ( 'core_modified' === $issue['kind'] ) {
			$first = ! empty( $details['modified'] ) ? (string) $details['modified'][0] : '';
			return array(
				'summary'    => __( 'Some WordPress core files are different from the official files on wordpress.org. Core files should never be edited, so this is a strong sign of a hack or a risky manual change.', 'dev3-ai-site-health-analyst' ),
				'cause'      => __( 'Malware injected into core files, an unsafe manual edit, or a failed update.', 'dev3-ai-site-health-analyst' ),
				'steps'      => array(
					__( 'Make a backup (keep the changed files for investigation).', 'dev3-ai-site-health-analyst' ),
					__( 'Restore each changed file from wordpress.org (one-click fix with confirmation, or Dashboard → Updates → Re-install).', 'dev3-ai-site-health-analyst' ),
					__( 'Change all passwords (WordPress admins, hosting, FTP/SFTP, database) and check for unknown admin users.', 'dev3-ai-site-health-analyst' ),
					__( 'Run the Integrity check again; if files change back, ask your host or a security service to clean the site.', 'dev3-ai-site-health-analyst' ),
				),
				'who'        => 'owner',
				'urgency'    => 'now',
				'fix_action' => '' !== $first ? 'restore_core_file' : 'none',
				'fix_target' => $first,
			);
		}
		if ( 'core_unknown' === $issue['kind'] ) {
			return array(
				'summary' => __( 'There are PHP files in wp-admin or wp-includes that are not part of WordPress. Attackers often hide backdoors there.', 'dev3-ai-site-health-analyst' ),
				'cause'   => __( 'A backdoor uploaded by an attacker, or leftovers of an old WordPress version or a misbehaving plugin.', 'dev3-ai-site-health-analyst' ),
				'steps'   => array(
					__( 'Look at each file with your host or developer (do not open it in a browser).', 'dev3-ai-site-health-analyst' ),
					__( 'Re-install WordPress from Dashboard → Updates, then delete the unknown files.', 'dev3-ai-site-health-analyst' ),
					__( 'Change all passwords and review administrator accounts.', 'dev3-ai-site-health-analyst' ),
				),
				'who'     => 'developer',
				'urgency' => 'now',
			);
		}
		return array(
			'summary' => __( 'Files of this plugin differ from its official wordpress.org release. It may have been customised, partly updated, or infected.', 'dev3-ai-site-health-analyst' ),
			'cause'   => __( 'Manual customisation, a failed update, or malware added to the plugin folder (added PHP files are the most suspicious).', 'dev3-ai-site-health-analyst' ),
			'steps'   => array(
				__( 'Ask your developer whether the plugin was customised on purpose.', 'dev3-ai-site-health-analyst' ),
				__( 'If not, delete the plugin and install a fresh copy from wordpress.org (settings are kept in the database).', 'dev3-ai-site-health-analyst' ),
				__( 'Run the Integrity check again.', 'dev3-ai-site-health-analyst' ),
			),
			'who'     => 'developer',
			'urgency' => ! empty( $details['added'] ) ? 'soon' : 'normal',
		);
	}

	/**
	 * Who should fix it, from the component.
	 *
	 * @param string $component Component.
	 * @return string owner|developer|host
	 */
	private static function who( $component ) {
		if ( 0 === strpos( $component, 'theme:' ) || 0 === strpos( $component, 'mu-plugin:' ) ) {
			return 'developer';
		}
		if ( 'core' === $component ) {
			return 'host';
		}
		return 0 === strpos( $component, 'plugin:' ) ? 'owner' : 'developer';
	}
}
