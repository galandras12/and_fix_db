<?php
/**
 * Plugin Name: And DB fix
 * Plugin URI: https://github.com/galandras12/and_fix_db
 * Description: Megmutatja, mi foglalja az adatbázis tárhelyét (táblák, autoload opciók, pluginok), felesleges adatokat detektál és törli, táblákat optimalizál, opcionálisan hetente automatikusan takarít.
 * Version: 1.2
 * Author: galandras12, Claude Code
 * Author URI: https://github.com/galandras12/and_fix_db
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 * Text Domain: and-db-fix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


final class ADBOPT {

	const VER       = '1.2';
	const CAP       = 'manage_options';
	const OPT       = 'adbopt_settings';
	const SNAP      = 'adbopt_snapshots';
	const CRON_HOOK = 'adbopt_cron';
	const HOURLY    = 'adbopt_hourly';
	const SLUG      = 'and-db-fix';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_adbopt_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'wp_ajax_adbopt', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( self::HOURLY, array( __CLASS__, 'run_hourly' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( ADBOPT::HOURLY ) ) {
				wp_schedule_event( time() + 300, 'hourly', ADBOPT::HOURLY );
			}
		} );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::HOURLY );
	}

	/* ------------------------------------------------------------------ */
	/* Takarítási feladatok                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * 'safe' => true: az automatikus (hetente futó) takarításban is felajánlott, nem veszélyes elemek.
	 * 'from' + 'pk' + 'table': egységes "SELECT pk FROM ..." alapú, kötegelt törlés.
	 * 'run' + 'count': egyedi törlés.
	 */
	public static function tasks() {
		global $wpdb;
		$now   = time();
		$tasks = array();

		$tasks['revisions'] = array(
			'label' => 'Bejegyzés-revíziók',
			'desc'  => 'A szerkesztések régi változatai. Gyakran ez a legnagyobb szemét. Az aktuális tartalmat nem érinti.',
			'safe'  => false,
			'table' => $wpdb->posts,
			'pk'    => 'ID',
			'sel'   => 'ID',
			'from'  => "FROM {$wpdb->posts} WHERE post_type = 'revision'",
		);
		$tasks['autodrafts'] = array(
			'label' => 'Automatikus piszkozatok (7 napnál régebbi)',
			'desc'  => 'A WordPress által magától létrehozott üres "auto-draft" bejegyzések.',
			'safe'  => true,
			'table' => $wpdb->posts,
			'pk'    => 'ID',
			'sel'   => 'ID',
			'from'  => $wpdb->prepare( "FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < %s", gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ) ),
		);
		$tasks['trash_posts'] = array(
			'label' => 'Kukában lévő bejegyzések / oldalak / média',
			'desc'  => 'Véglegesen törli a kuka tartalmát (a hozzá tartozó metaadatokkal és fájlokkal együtt).',
			'safe'  => false,
			'count' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'",
			'run'   => array( __CLASS__, 'run_trash_posts' ),
		);
		$tasks['spam_comments'] = array(
			'label' => 'Spam és kukába tett hozzászólások',
			'desc'  => 'Véglegesen törli őket.',
			'safe'  => true,
			'table' => $wpdb->comments,
			'pk'    => 'comment_ID',
			'sel'   => 'comment_ID',
			'from'  => "FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')",
		);
		$tasks['expired_transients'] = array(
			'label' => 'Lejárt tranziensek (átmeneti gyorsítótár)',
			'desc'  => 'Lejárt, ideiglenes opciók a wp_options táblában. Teljesen biztonságos.',
			'safe'  => true,
			'count' => $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			),
			'run'   => array( __CLASS__, 'run_expired_transients' ),
		);
		$tasks['all_transients'] = array(
			'label' => 'ÖSSZES tranziens (lejárt és élő is)',
			'desc'  => 'Minden átmeneti gyorsítótár törlése. A pluginok újragenerálják, ezért átmenetileg lassabb lehet az oldal.',
			'safe'  => false,
			'count' => $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			),
			'run'   => array( __CLASS__, 'run_all_transients' ),
		);
		$tasks['oembed'] = array(
			'label' => 'oEmbed gyorsítótár (postmeta)',
			'desc'  => 'Beágyazott videók/linkek gyorsítótára. Szükség esetén újra létrejön.',
			'safe'  => true,
			'table' => $wpdb->postmeta,
			'pk'    => 'meta_id',
			'sel'   => 'meta_id',
			'from'  => $wpdb->prepare( "FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_oembed_' ) . '%' ),
		);
		$tasks['orphan_postmeta'] = array(
			'label' => 'Árva postmeta (bejegyzés nélküli metaadatok)',
			'desc'  => 'Már törölt bejegyzésekhez tartozó metaadatok.',
			'safe'  => true,
			'table' => $wpdb->postmeta,
			'pk'    => 'meta_id',
			'sel'   => 'pm.meta_id',
			'from'  => "FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
		);
		$tasks['orphan_commentmeta'] = array(
			'label' => 'Árva commentmeta',
			'desc'  => 'Törölt hozzászólásokhoz tartozó metaadatok.',
			'safe'  => true,
			'table' => $wpdb->commentmeta,
			'pk'    => 'meta_id',
			'sel'   => 'cm.meta_id',
			'from'  => "FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
		);
		$tasks['orphan_termmeta'] = array(
			'label' => 'Árva termmeta',
			'desc'  => 'Törölt kategóriákhoz/címkékhez tartozó metaadatok.',
			'safe'  => true,
			'table' => $wpdb->termmeta,
			'pk'    => 'meta_id',
			'sel'   => 'tm.meta_id',
			'from'  => "FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL",
		);

		// Action Scheduler (WooCommerce és sok más plugin használja) – gyakori több száz MB-os bűnös.
		$as_actions = $wpdb->prefix . 'actionscheduler_actions';
		$as_logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( self::table_exists( $as_actions ) ) {
			$tasks['as_actions'] = array(
				'label' => 'Action Scheduler: lefutott / megszakadt / hibás feladatok (1 napnál régebbi)',
				'desc'  => 'A WooCommerce és más pluginok ütemezett feladatainak naplója. Gyakran több százezer sor.',
				'safe'  => true,
				'table' => $as_actions,
				'pk'    => 'action_id',
				'sel'   => 'action_id',
				'from'  => $wpdb->prepare( "FROM {$as_actions} WHERE status IN ('complete','canceled','failed') AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ),
			);
			if ( self::table_exists( $as_logs ) ) {
				$tasks['as_logs'] = array(
					'label' => 'Action Scheduler: árva naplóbejegyzések',
					'desc'  => 'Törölt feladatokhoz tartozó naplósorok.',
					'safe'  => true,
					'table' => $as_logs,
					'pk'    => 'log_id',
					'sel'   => 'l.log_id',
					'from'  => "FROM {$as_logs} l LEFT JOIN {$as_actions} a ON a.action_id = l.action_id WHERE a.action_id IS NULL",
				);
			}
		}

		$wc_sessions = $wpdb->prefix . 'woocommerce_sessions';
		if ( self::table_exists( $wc_sessions ) ) {
			$tasks['wc_sessions'] = array(
				'label' => 'WooCommerce lejárt munkamenetek',
				'desc'  => 'Lejárt vásárlói kosár-munkamenetek.',
				'safe'  => true,
				'table' => $wc_sessions,
				'pk'    => 'session_id',
				'sel'   => 'session_id',
				'from'  => $wpdb->prepare( "FROM {$wc_sessions} WHERE session_expiry < %d", $now ),
			);
		}

		return $tasks;
	}

	public static function task_count( $t ) {
		global $wpdb;
		$sql = isset( $t['count'] ) ? $t['count'] : 'SELECT COUNT(*) ' . $t['from'];
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function run_task( $t, $deadline ) {
		if ( isset( $t['run'] ) ) {
			return (int) call_user_func( $t['run'], $deadline );
		}
		return self::batch_delete( $t['sel'], $t['from'], $t['table'], $t['pk'], $deadline );
	}

	private static function batch_delete( $sel, $from, $table, $pk, $deadline ) {
		global $wpdb;
		$total = 0;
		do {
			$ids = $wpdb->get_col( "SELECT {$sel} {$from} LIMIT 2000" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( empty( $ids ) ) {
				break;
			}
			$ids = array_map( 'intval', $ids );
			$wpdb->query( "DELETE FROM {$table} WHERE {$pk} IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $wpdb->last_error ) {
				break;
			}
			$total += count( $ids );
		} while ( time() < $deadline );
		return $total;
	}

	public static function run_trash_posts( $deadline ) {
		global $wpdb;
		$total = 0;
		do {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 100" );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				$total++;
			}
		} while ( time() < $deadline );
		return $total;
	}

	public static function run_expired_transients( $deadline ) {
		global $wpdb;
		$total = 0;
		$now   = time();
		do {
			$timeouts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d LIMIT 500",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
			if ( empty( $timeouts ) ) {
				break;
			}
			$names = array();
			foreach ( $timeouts as $name ) {
				$names[] = $name;
				$names[] = str_replace( '_timeout_', '_', $name );
			}
			self::delete_options( $names );
			$total += count( $timeouts );
		} while ( time() < $deadline );
		return $total;
	}

	public static function run_all_transients( $deadline ) {
		global $wpdb;
		$total = 0;
		do {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1000",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_' ) . '%'
				)
			);
			if ( empty( $names ) ) {
				break;
			}
			self::delete_options( $names );
			$total += count( $names );
		} while ( time() < $deadline );
		wp_cache_flush();
		return $total;
	}

	private static function delete_options( $names ) {
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN ($ph)", $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Elemzés                                                             */
	/* ------------------------------------------------------------------ */

	public static function table_stats() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count, DATA_LENGTH AS data_len, INDEX_LENGTH AS index_len, DATA_FREE AS free_len
				 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
				DB_NAME
			)
		);
	}

	/** Tábla → melyik plugin/rész hozta létre (előtag alapján becslés). */
	public static function table_owner( $name ) {
		global $wpdb;
		$short = ( 0 === strpos( $name, $wpdb->prefix ) ) ? substr( $name, strlen( $wpdb->prefix ) ) : null;
		if ( null === $short ) {
			return 'Nem ehhez a WP-telepítéshez tartozik (másik előtag)';
		}
		$short = preg_replace( '/^\d+_/', '', $short ); // multisite: 2_posts
		$core  = array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'users', 'usermeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' );
		if ( in_array( $short, $core, true ) ) {
			return 'WordPress mag';
		}
		$map = array(
			'actionscheduler_' => 'Action Scheduler (WooCommerce / más pluginok)',
			'woocommerce_'     => 'WooCommerce',
			'wc_'              => 'WooCommerce',
			'yoast_'           => 'Yoast SEO',
			'rank_math'        => 'Rank Math SEO',
			'aioseo_'          => 'All in One SEO',
			'wpml'             => 'WPML',
			'icl_'             => 'WPML',
			'redirection_'     => 'Redirection',
			'wf'               => 'Wordfence',
			'itsec_'           => 'iThemes / Solid Security',
			'aiowps_'          => 'All In One WP Security',
			'wpforms_'         => 'WPForms',
			'frm_'             => 'Formidable Forms',
			'gf_'              => 'Gravity Forms',
			'rg_'              => 'Gravity Forms',
			'e_'               => 'Elementor',
			'statistics_'      => 'WP Statistics',
			'slim_'            => 'SlimStat',
			'wpmailsmtp_'      => 'WP Mail SMTP',
			'litespeed_'       => 'LiteSpeed Cache',
			'relevanssi'       => 'Relevanssi',
			'revslider'        => 'Slider Revolution',
			'rs_'              => 'Slider Revolution',
			'et_'              => 'Divi / Elegant Themes',
			'cli_'             => 'CookieYes',
			'cookielawinfo'    => 'CookieYes',
			'ngg_'             => 'NextGEN Gallery',
			'smush'            => 'Smush',
			'ewwwio_'          => 'EWWW Image Optimizer',
			'bwg_'             => 'Photo Gallery (10Web)',
			'duplicator'       => 'Duplicator',
			'learnpress_'      => 'LearnPress',
			'ihc_'             => 'Indeed Ultimate Membership Pro',
			'mailpoet_'        => 'MailPoet',
			'newsletter'       => 'Newsletter',
			'tm_'              => 'ThemeMakers',
			'wsal_'            => 'WP Activity Log',
			'simple_history'   => 'Simple History',
			'um_'              => 'Ultimate Member',
			'bp_'              => 'BuddyPress',
			'cf7'              => 'Contact Form 7 (kiegészítők)',
			'db7_'             => 'Contact Form CFDB7',
			'jetpack'          => 'Jetpack',
			'ppress_'          => 'ProfilePress',
			'fluentform'       => 'Fluent Forms',
			'ninja_'           => 'Ninja Forms / Tables',
			'nf3_'             => 'Ninja Forms',
			'tablepress'       => 'TablePress',
			'wpr_'             => 'WP Rocket / Royal Addons',
			'wpfm_'            => 'File Manager',
			'sg_'              => 'SiteGround',
			'mo_'              => 'miniOrange',
			'wpgmza'           => 'WP Google Maps',
			'ahm_'             => 'Download Manager',
			'wpdm_'            => 'WordPress Download Manager',
			'term_'            => 'Taxonomia-kiegészítő plugin',
			'hmapi_'           => 'HM API',
		);
		foreach ( $map as $prefix => $owner ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return $owner;
			}
		}
		return 'Ismeretlen plugin/téma (név: ' . $short . ')';
	}

	public static function option_owner( $name ) {
		$map = array(
			'_transient_'      => 'Tranziens (átmeneti gyorsítótár)',
			'_site_transient_' => 'Tranziens (átmeneti gyorsítótár)',
			'wpseo'            => 'Yoast SEO',
			'rank_math'        => 'Rank Math SEO',
			'aioseo'           => 'All in One SEO',
			'elementor'        => 'Elementor',
			'_elementor'       => 'Elementor',
			'woocommerce_'     => 'WooCommerce',
			'wc_'              => 'WooCommerce',
			'action_scheduler' => 'Action Scheduler',
			'jetpack'          => 'Jetpack',
			'wordfence'        => 'Wordfence',
			'wf'               => 'Wordfence',
			'itsec'            => 'iThemes / Solid Security',
			'litespeed'        => 'LiteSpeed Cache',
			'wpr_'             => 'WP Rocket',
			'wp_rocket'        => 'WP Rocket',
			'et_'              => 'Divi / Elegant Themes',
			'fusion'           => 'Avada / Fusion',
			'avada'            => 'Avada',
			'updraft'          => 'UpdraftPlus',
			'duplicator'       => 'Duplicator',
			'wpforms'          => 'WPForms',
			'widget_'          => 'Widgetek',
			'theme_mods_'      => 'Téma beállítások',
			'cron'             => 'WordPress ütemező (cron)',
			'rewrite_rules'    => 'WordPress mag (rewrite)',
			'active_plugins'   => 'WordPress mag',
			'recently_activated' => 'WordPress mag',
			'fs_'              => 'Freemius SDK (plugin licenc)',
			'ewww'             => 'EWWW Image Optimizer',
			'wp_smush'         => 'Smush',
			'wp-smush'         => 'Smush',
			'redux'            => 'Redux Framework',
			'wp_installer'     => 'WPML',
			'icl_'             => 'WPML',
			'revslider'        => 'Slider Revolution',
			'_wc_'             => 'WooCommerce',
			'sbi_'             => 'Smash Balloon',
			'cff_'             => 'Smash Balloon',
			'mailpoet'         => 'MailPoet',
			'wpmdb'            => 'WP Migrate DB',
			'ai1wm'            => 'All-in-One WP Migration',
		);
		foreach ( $map as $prefix => $owner ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return $owner;
			}
		}
		$pos = strpos( $name, '_' );
		return ( $pos > 1 ) ? 'Valószínűleg: "' . substr( $name, 0, $pos ) . '" plugin/téma' : 'Ismeretlen';
	}

	public static function autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}
		return array( 'yes' );
	}

	public static function autoload_sql_list() {
		global $wpdb;
		$vals = self::autoload_values();
		$ph   = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
		return $wpdb->prepare( $ph, $vals ); // phpcs:ignore WordPress.DB.PreparedSQL
	}


	/* ------------------------------------------------------------------ */
	/* Méret, limit, státusz                                               */
	/* ------------------------------------------------------------------ */

	public static function db_size() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', DB_NAME ) );
	}

	public static function cached_size() {
		$v = get_transient( 'adbopt_size' );
		if ( false === $v ) {
			$v = self::db_size();
			set_transient( 'adbopt_size', $v, 10 * MINUTE_IN_SECONDS );
		}
		return (int) $v;
	}

	/**
	 * level: 0 zöld (<70%), 1 citromsárga (70-80%), 2 narancs (80-95%), 3 piros (95%+); -1 nincs limit.
	 */
	public static function status( $size, $limit_mb ) {
		$limit_mb = (int) $limit_mb;
		if ( $limit_mb <= 0 ) {
			return array( 'level' => -1, 'pct' => null, 'limit' => 0, 'message' => 'Nincs beállított limit.' );
		}
		$limit = $limit_mb * 1048576;
		$pct   = $size / $limit * 100;
		if ( $pct >= 95 ) {
			$level = 3;
			$msg   = 'FIGYELEM! Az adatbázis elérte vagy meghaladta a limit 95%-át. Azonnal tisztítsd az adatbázist, különben a tárhely leállíthatja az oldalt!';
		} elseif ( $pct >= 80 ) {
			$level = 2;
			$msg   = 'Az adatbázis a limit 80–95%-án áll. Érdemes takarítani.';
		} elseif ( $pct >= 70 ) {
			$level = 1;
			$msg   = 'Az adatbázis a limit 70–80%-án áll.';
		} else {
			$level = 0;
			$msg   = 'Az adatbázis mérete rendben van.';
		}
		return array( 'level' => $level, 'pct' => round( $pct, 1 ), 'limit' => $limit, 'message' => $msg );
	}

	public static function level_colors( $level ) {
		$c = array(
			-1 => array( '#6b7280', '#fff' ),
			0  => array( '#2e9e4f', '#fff' ),
			1  => array( '#f2d600', '#1d2327' ),
			2  => array( '#f08a00', '#fff' ),
			3  => array( '#d63638', '#fff' ),
		);
		return $c[ $level ];
	}

	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = self::settings();
		$size = self::cached_size();
		$st   = self::status( $size, $s['limit_mb'] );
		list( $bg, $fg ) = self::level_colors( $st['level'] );
		$txt = 'DB ' . size_format( $size, 1 ) . ( $st['limit'] ? ' / ' . $s['limit_mb'] . ' MB (' . $st['pct'] . '%)' : '' );
		$bar->add_node( array(
			'id'    => 'adbopt',
			'title' => '<span style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';padding:2px 8px;border-radius:3px;font-weight:600">' . esc_html( $txt ) . '</span>',
			'href'  => admin_url( 'tools.php?page=' . self::SLUG ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin oldal + AJAX                                                  */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_management_page( 'And DB fix', 'And DB fix', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'adbopt', $url . 'assets/admin.css', array(), self::VER );
		wp_enqueue_script( 'adbopt', $url . 'assets/admin.js', array(), self::VER, true );
		wp_localize_script( 'adbopt', 'ADBOPT_CFG', array(
			'ajax'        => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'adbopt' ),
			'backupUrl'   => admin_url( 'admin-post.php' ),
			'backupNonce' => wp_create_nonce( 'adbopt_backup' ),
			'version'     => self::VER,
			'siteName'    => get_bloginfo( 'name' ),
		) );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nincs jogosultságod.' );
		}
		echo '<div class="wrap"><div id="adbopt-app" class="adbopt"><p>Betöltés…</p></div>';
		echo '<noscript><p>Az And DB fix működéséhez JavaScript szükséges.</p></noscript></div>';
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nincs jogosultságod.', 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg ) {
		wp_die( wp_kses_post( $msg ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Vissza</a>' );
	}

	private static function payload() {
		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore
		$p   = json_decode( $raw, true );
		return is_array( $p ) ? $p : array();
	}

	public static function ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Nincs jogosultságod.' ), 403 );
		}
		check_ajax_referer( 'adbopt', 'nonce' );
		@set_time_limit( 120 ); // phpcs:ignore
		global $wpdb;
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$p  = self::payload();

		switch ( $op ) {
			case 'overview':
				wp_send_json_success( self::op_overview() );
				break;

			case 'tasks':
				$list = array();
				foreach ( self::tasks() as $id => $t ) {
					$list[] = array(
						'id'    => $id,
						'label' => $t['label'],
						'desc'  => $t['desc'],
						'safe'  => $t['safe'],
						'count' => self::task_count( $t ),
					);
				}
				wp_send_json_success( array( 'tasks' => $list ) );
				break;

			case 'clean':
				$sel      = isset( $p['tasks'] ) ? array_map( 'sanitize_key', (array) $p['tasks'] ) : array();
				$deadline = time() + 20;
				$deleted  = array();
				$remain   = array();
				foreach ( self::tasks() as $id => $t ) {
					if ( ! in_array( $id, $sel, true ) ) {
						continue;
					}
					$deleted[ $id ] = ( time() < $deadline ) ? self::run_task( $t, $deadline ) : 0;
					$remain[ $id ]  = self::task_count( $t );
				}
				wp_cache_flush();
				delete_transient( 'adbopt_size' );
				wp_send_json_success( array( 'deleted' => $deleted, 'remaining' => $remain ) );
				break;

			case 'optimize_one':
				$name  = isset( $p['table'] ) ? (string) $p['table'] : '';
				$known = wp_list_pluck( self::table_stats(), 'name' );
				if ( ! in_array( $name, $known, true ) ) {
					wp_send_json_error( array( 'message' => 'Ismeretlen tábla.' ) );
				}
				$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
				delete_transient( 'adbopt_size' );
				wp_send_json_success( array( 'table' => $name ) );
				break;

			case 'droptable':
				$name  = isset( $p['table'] ) ? (string) $p['table'] : '';
				$known = wp_list_pluck( self::table_stats(), 'name' );
				if ( ! in_array( $name, $known, true ) || ! self::can_drop( $name ) ) {
					wp_send_json_error( array( 'message' => 'Ez a tábla nem törölhető innen.' ) );
				}
				$wpdb->query( 'DROP TABLE `' . str_replace( '`', '``', $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $wpdb->last_error ) {
					wp_send_json_error( array( 'message' => $wpdb->last_error ) );
				}
				delete_transient( 'adbopt_size' );
				wp_send_json_success( array( 'table' => $name ) );
				break;

			case 'noautoload':
				$name = isset( $p['option'] ) ? (string) $p['option'] : '';
				if ( '' === $name ) {
					wp_send_json_error( array( 'message' => 'Hiányzó opciónév.' ) );
				}
				$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ) );
				wp_cache_delete( 'alloptions', 'options' );
				wp_send_json_success( array( 'option' => $name ) );
				break;

			case 'save_settings':
				wp_send_json_success( array( 'settings' => self::save_settings( $p ) ) );
				break;

			case 'send_test':
				$cfg = self::settings();
				$to  = self::parse_emails( $cfg['emails'] );
				if ( ! $to ) {
					wp_send_json_error( array( 'message' => 'Nincs érvényes email cím elmentve. Előbb mentsd a beállításokat.' ) );
				}
				$size = self::db_size();
				$st   = self::status( $size, $cfg['limit_mb'] );
				$ok   = self::send_mail( $to, 'Teszt üzenet', self::mail_wrap( 'Teszt üzenet', '<p>Ez egy teszt email az And DB fix pluginból. Ha ezt olvasod, a levélküldés működik.</p>' . self::status_html( $size, $st ) ) );
				if ( ! $ok ) {
					wp_send_json_error( array( 'message' => 'A levél elküldése nem sikerült (a tárhely levélküldés-beállítása?).' ) );
				}
				wp_send_json_success( array( 'sent_to' => $to ) );
				break;
		}
		wp_send_json_error( array( 'message' => 'Ismeretlen művelet.' ) );
	}

	private static function op_overview() {
		global $wpdb;
		$s      = self::settings();
		$tables = array();
		$total  = 0;
		foreach ( self::table_stats() as $t ) {
			$sum      = (int) $t->data_len + (int) $t->index_len;
			$total   += $sum;
			$tables[] = array(
				'name'   => $t->name,
				'owner'  => self::table_owner( $t->name ),
				'engine' => (string) $t->engine,
				'rows'   => (int) $t->row_count,
				'data'   => (int) $t->data_len,
				'index'  => (int) $t->index_len,
				'total'  => $sum,
				'free'   => (int) $t->free_len,
				'drop'   => self::can_drop( $t->name ),
			);
		}
		set_transient( 'adbopt_size', $total, 10 * MINUTE_IN_SECONDS );

		$in        = self::autoload_sql_list();
		$row       = $wpdb->get_row( "SELECT COUNT(*) AS c, SUM(LENGTH(option_value)) AS s FROM {$wpdb->options} WHERE autoload IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$protected = array( 'siteurl', 'home', 'blogname', 'active_plugins', 'template', 'stylesheet', 'rewrite_rules', 'cron', 'permalink_structure', 'users_can_register', 'default_role', 'WPLANG' );
		$opts      = array();
		foreach ( $wpdb->get_results( "SELECT option_name, autoload, LENGTH(option_value) AS len FROM {$wpdb->options} ORDER BY len DESC LIMIT 30" ) as $o ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$auto   = in_array( $o->autoload, self::autoload_values(), true );
			$opts[] = array(
				'name'    => $o->option_name,
				'owner'   => self::option_owner( $o->option_name ),
				'len'     => (int) $o->len,
				'auto'    => $auto,
				'can_off' => $auto && ! in_array( $o->option_name, $protected, true ),
			);
		}
		$next = wp_next_scheduled( self::CRON_HOOK );

		return array(
			'db_name'    => DB_NAME,
			'prefix'     => $wpdb->prefix,
			'total'      => $total,
			'status'     => self::status( $total, $s['limit_mb'] ),
			'tables'     => $tables,
			'autoload'   => array( 'count' => (int) $row->c, 'size' => (int) $row->s ),
			'options'    => $opts,
			'settings'   => $s,
			'gzip'       => function_exists( 'deflate_init' ),
			'next_clean' => $next ? wp_date( 'Y.m.d H:i', $next ) : '',
			'last_run'   => $s['last_run'] ? wp_date( 'Y.m.d H:i', $s['last_run'] ) : '',
			'safe_tasks' => array_keys( array_filter( self::tasks(), function ( $t ) {
				return $t['safe'];
			} ) ),
		);
	}

	/** @return string[] */
	private static function run_selected( $selected, $seconds ) {
		$deadline = time() + $seconds;
		$out      = array();
		foreach ( self::tasks() as $id => $t ) {
			if ( ! in_array( $id, $selected, true ) ) {
				continue;
			}
			if ( time() >= $deadline ) {
				$out[] = $t['label'] . ': kimaradt az időkorlát miatt';
				continue;
			}
			$out[] = $t['label'] . ': ' . self::run_task( $t, $deadline ) . ' db törölve';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Beállítások                                                         */
	/* ------------------------------------------------------------------ */

	public static function settings() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'enabled'       => 0,
				'tasks'         => array(),
				'optimize'      => 0,
				'last_run'      => 0,
				'last_result'   => '',
				'limit_mb'      => 100,
				'emails'        => '',
				'mail_monthly'  => 0,
				'mail_70'       => 0,
				'mail_80'       => 0,
				'mail_95'       => 0,
				'mail_95_hours' => 24,
				'last_level'    => 0,
				'last_95_sent'  => 0,
				'last_monthly'  => '',
			)
		);
	}

	public static function parse_emails( $str ) {
		$ok = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $str, -1, PREG_SPLIT_NO_EMPTY ) as $x ) {
			$e = sanitize_email( $x );
			if ( $e && is_email( $e ) ) {
				$ok[] = $e;
			}
		}
		return array_values( array_unique( $ok ) );
	}

	private static function save_settings( $p ) {
		$s    = self::settings();
		$safe = array_keys( array_filter( self::tasks(), function ( $t ) {
			return $t['safe'];
		} ) );

		$s['limit_mb']      = max( 0, (int) ( isset( $p['limit_mb'] ) ? $p['limit_mb'] : 100 ) );
		$s['enabled']       = empty( $p['enabled'] ) ? 0 : 1;
		$s['optimize']      = empty( $p['optimize'] ) ? 0 : 1;
		$chosen             = isset( $p['tasks'] ) ? array_map( 'sanitize_key', (array) $p['tasks'] ) : array();
		$s['tasks']         = array_values( array_intersect( $chosen, $safe ) );
		$s['emails']        = implode( ', ', self::parse_emails( isset( $p['emails'] ) ? $p['emails'] : '' ) );
		$s['mail_monthly']  = empty( $p['mail_monthly'] ) ? 0 : 1;
		$s['mail_70']       = empty( $p['mail_70'] ) ? 0 : 1;
		$s['mail_80']       = empty( $p['mail_80'] ) ? 0 : 1;
		$s['mail_95']       = empty( $p['mail_95'] ) ? 0 : 1;
		$s['mail_95_hours'] = max( 1, min( 720, (int) ( isset( $p['mail_95_hours'] ) ? $p['mail_95_hours'] : 24 ) ) );
		if ( $s['mail_monthly'] && '' === $s['last_monthly'] ) {
			$s['last_monthly'] = wp_date( 'Y-m' ); // az első jelentés a következő hónap elején megy
		}
		update_option( self::OPT, $s, false );
		delete_transient( 'adbopt_size' );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $s['enabled'] ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
		return $s;
	}

	/* ------------------------------------------------------------------ */
	/* Cron: heti takarítás, óránkénti ellenőrzés, napi pillanatkép        */
	/* ------------------------------------------------------------------ */

	public static function run_cron() {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		@set_time_limit( 300 ); // phpcs:ignore
		$out = self::run_selected( $s['tasks'], 120 );
		if ( $s['optimize'] ) {
			$n = 0;
			global $wpdb;
			foreach ( self::table_stats() as $t ) {
				if ( (int) $t->free_len >= 1048576 ) {
					$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $t->name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
					$n++;
				}
			}
			$out[] = 'Optimalizált táblák: ' . $n;
		}
		delete_transient( 'adbopt_size' );
		$s                = self::settings();
		$s['last_run']    = time();
		$s['last_result'] = wp_strip_all_tags( implode( '; ', $out ) );
		update_option( self::OPT, $s, false );
	}

	public static function run_hourly() {
		@set_time_limit( 120 ); // phpcs:ignore
		self::take_snapshot();
		$s = self::settings();
		if ( ! self::parse_emails( $s['emails'] ) ) {
			return;
		}
		self::check_alerts();
		$s = self::settings();
		if ( $s['mail_monthly'] && wp_date( 'Y-m' ) !== $s['last_monthly'] ) {
			if ( self::send_monthly() ) {
				$s                 = self::settings();
				$s['last_monthly'] = wp_date( 'Y-m' );
				update_option( self::OPT, $s, false );
			}
		}
	}

	/** Napi pillanatkép: teljes méret + forrásonkénti méret. */
	public static function take_snapshot() {
		$snaps = get_option( self::SNAP, array() );
		$today = wp_date( 'Y-m-d' );
		if ( isset( $snaps[ $today ] ) ) {
			return;
		}
		$owners = array();
		$total  = 0;
		foreach ( self::table_stats() as $t ) {
			$sum          = (int) $t->data_len + (int) $t->index_len;
			$o            = self::table_owner( $t->name );
			$owners[ $o ] = ( isset( $owners[ $o ] ) ? $owners[ $o ] : 0 ) + $sum;
			$total       += $sum;
		}
		$snaps[ $today ] = array( 'total' => $total, 'owners' => $owners );
		ksort( $snaps );
		$cut = gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS );
		foreach ( array_keys( $snaps ) as $d ) {
			if ( $d < $cut ) {
				unset( $snaps[ $d ] );
			}
		}
		update_option( self::SNAP, $snaps, false );
	}

	public static function check_alerts() {
		$s     = self::settings();
		$to    = self::parse_emails( $s['emails'] );
		$size  = self::db_size();
		$st    = self::status( $size, $s['limit_mb'] );
		$level = max( 0, $st['level'] );
		$last  = (int) $s['last_level'];
		$send  = false;

		if ( $level > $last ) {
			$send = ( 1 === $level && $s['mail_70'] ) || ( 2 === $level && $s['mail_80'] ) || ( 3 === $level && $s['mail_95'] );
		} elseif ( 3 === $level && $s['mail_95'] && time() - (int) $s['last_95_sent'] >= (int) $s['mail_95_hours'] * HOUR_IN_SECONDS ) {
			$send = true;
		}

		if ( $send && $to ) {
			$title = ( 3 === $level ? 'KRITIKUS: ' : 'Figyelmeztetés: ' ) . 'az adatbázis a limit ' . $st['pct'] . '%-án áll';
			self::send_mail( $to, $title, self::mail_wrap( $title, '<p>' . esc_html( $st['message'] ) . '</p>' . self::status_html( $size, $st ) . self::owners_table_html( 6 ) ) );
			if ( 3 === $level ) {
				$s['last_95_sent'] = time();
			}
		}
		$s['last_level'] = $level;
		update_option( self::OPT, $s, false );
	}

	/* ------------------------------------------------------------------ */
	/* Levelek                                                             */
	/* ------------------------------------------------------------------ */

	private static function send_mail( $to, $subject, $html ) {
		$subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $subject;
		return wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private static function mail_wrap( $title, $body ) {
		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1d2327;max-width:640px">'
			. '<h2 style="margin:0 0 12px">' . esc_html( $title ) . '</h2>'
			. '<p style="color:#555">Oldal: <a href="' . esc_url( home_url() ) . '">' . esc_html( home_url() ) . '</a></p>'
			. $body
			. '<p style="color:#777;font-size:12px;margin-top:20px">Küldte: And DB fix ' . esc_html( self::VER ) . ' &middot; <a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">megnyitás</a></p></div>';
	}

	private static function status_html( $size, $st ) {
		list( $bg, $fg ) = self::level_colors( $st['level'] );
		$txt = size_format( $size, 2 ) . ( $st['limit'] ? ' / ' . size_format( $st['limit'], 0 ) . ' (' . $st['pct'] . '%)' : ' (nincs limit)' );
		return '<p><span style="display:inline-block;background:' . $bg . ';color:' . $fg . ';padding:6px 12px;border-radius:4px;font-weight:bold;font-size:16px">' . esc_html( $txt ) . '</span></p>';
	}

	/** Jelenlegi méret forrásonként (legnagyobb elöl). */
	private static function owners_table_html( $limit ) {
		$o = array();
		foreach ( self::table_stats() as $t ) {
			$k       = self::table_owner( $t->name );
			$o[ $k ] = ( isset( $o[ $k ] ) ? $o[ $k ] : 0 ) + $t->data_len + $t->index_len;
		}
		arsort( $o );
		$h = '<h3>Legnagyobb helyfoglalók</h3><table cellpadding="6" style="border-collapse:collapse;border:1px solid #ddd"><tr style="background:#f3f4f6"><th align="left">Forrás</th><th align="right">Méret</th></tr>';
		foreach ( array_slice( $o, 0, $limit, true ) as $name => $sz ) {
			$h .= '<tr><td style="border-top:1px solid #eee">' . esc_html( $name ) . '</td><td align="right" style="border-top:1px solid #eee">' . esc_html( size_format( $sz, 2 ) ) . '</td></tr>';
		}
		return $h . '</table>';
	}

	public static function send_monthly() {
		$s  = self::settings();
		$to = self::parse_emails( $s['emails'] );
		if ( ! $to ) {
			return false;
		}
		self::take_snapshot();
		$size  = self::db_size();
		$st    = self::status( $size, $s['limit_mb'] );
		$snaps = get_option( self::SNAP, array() );
		ksort( $snaps );

		$cur_month  = wp_date( 'Y-m' );
		$prev_month = gmdate( 'Y-m', strtotime( $cur_month . '-15 -1 month' ) );
		$prev_first = $prev_month . '-01';

		$base      = null;
		$base_date = '';
		foreach ( $snaps as $d => $v ) {
			if ( $d < $prev_first ) {
				$base      = $v;
				$base_date = $d;
			}
		}
		$partial = false;
		if ( null === $base ) { // nincs előzmény a hónap előttről: a hónap első pillanatképe
			foreach ( $snaps as $d => $v ) {
				if ( $d >= $prev_first ) {
					$base      = $v;
					$base_date = $d;
					$partial   = true;
					break;
				}
			}
		}
		$end = end( $snaps );

		$body  = '<p>' . esc_html( $st['message'] ) . '</p>' . self::status_html( $size, $st );
		$body .= '<h3>' . esc_html( $prev_month ) . ' &ndash; mennyit írtak az adatbázisba?</h3>';
		if ( $base && $end && $base_date !== wp_date( 'Y-m-d' ) ) {
			$delta = array();
			foreach ( array_unique( array_merge( array_keys( $base['owners'] ), array_keys( $end['owners'] ) ) ) as $o ) {
				$b = isset( $base['owners'][ $o ] ) ? $base['owners'][ $o ] : 0;
				$e = isset( $end['owners'][ $o ] ) ? $end['owners'][ $o ] : 0;
				if ( $e !== $b ) {
					$delta[ $o ] = array( $e - $b, $e );
				}
			}
			uasort( $delta, function ( $a, $b ) {
				return $b[0] <=> $a[0];
			} );
			$body .= '<p>Teljes változás: <strong>' . esc_html( ( $end['total'] >= $base['total'] ? '+' : '-' ) . size_format( abs( $end['total'] - $base['total'] ), 2 ) ) . '</strong>. A számok nettó változások: a törlések levonódnak.</p>';
			if ( $partial ) {
				$body .= '<p style="color:#b45309">Megjegyzés: a mérés ' . esc_html( $base_date ) . ' napján indult, ezért a hónap eleje hiányos.</p>';
			}
			if ( $delta ) {
				$body .= '<table cellpadding="6" style="border-collapse:collapse;border:1px solid #ddd"><tr style="background:#f3f4f6"><th align="left">Szolgáltatás / plugin</th><th align="right">Változás</th><th align="right">Jelenlegi méret</th></tr>';
				foreach ( array_slice( $delta, 0, 15, true ) as $o => $v ) {
					$body .= '<tr><td style="border-top:1px solid #eee">' . esc_html( $o ) . '</td><td align="right" style="border-top:1px solid #eee">' . esc_html( ( $v[0] >= 0 ? '+' : '-' ) . size_format( abs( $v[0] ), 2 ) ) . '</td><td align="right" style="border-top:1px solid #eee">' . esc_html( size_format( $v[1], 2 ) ) . '</td></tr>';
				}
				$body .= '</table>';
			} else {
				$body .= '<p>Nem volt méretváltozás.</p>';
			}
		} else {
			$body .= '<p>Még nincs elég mért előzmény (a napi mérés most indult); a következő jelentésben már lesz hónapos összehasonlítás.</p>' . self::owners_table_html( 10 );
		}
		return self::send_mail( $to, 'Havi adatbázis-jelentés (' . $prev_month . ')', self::mail_wrap( 'Havi adatbázis-jelentés', $body ) );
	}
	/* ------------------------------------------------------------------ */
	/* Biztonsági mentés (SQL dump, streamelve)                            */
	/* ------------------------------------------------------------------ */

	private static function backup_filename( $gz ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$site = sanitize_file_name( $host . ( $path ? '-' . str_replace( '/', '-', $path ) : '' ) );
		return $site . '_' . wp_date( 'Y-m-d_H-i-s' ) . '.sql' . ( $gz ? '.gz' : '' );
	}

	public static function handle_backup() {
		self::guard( 'adbopt_backup' );
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^a-z0-9]/i', '', wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore
		if ( '' !== $token ) {
			setcookie( 'adbopt_dl', $token, time() + 600, '/' );
		}
		global $wpdb;
		$db = $wpdb->dbh;
		if ( ! ( $db instanceof mysqli ) ) {
			self::back( 'A mentés mysqli adatbáziskapcsolatot igényel, ez a környezet nem ilyen.' );
		}
		$gz = ! empty( $_POST['gz'] ) && function_exists( 'deflate_init' );
		@set_time_limit( 0 ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $gz ? 'application/gzip' : 'application/sql; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . self::backup_filename( $gz ) . '"' );
		header( 'X-Accel-Buffering: no' );

		$ctx = $gz ? deflate_init( ZLIB_ENCODING_GZIP ) : null;
		$out = function ( $chunk ) use ( $ctx ) {
			echo $ctx ? deflate_add( $ctx, $chunk, ZLIB_NO_FLUSH ) : $chunk; // phpcs:ignore
			if ( ! $ctx || mt_rand( 1, 20 ) === 1 ) {
				flush();
			}
		};

		$out( "-- And DB fix biztonsági mentés\n-- Oldal: " . home_url() . "\n-- Adatbázis: " . DB_NAME . "\n-- Dátum: " . wp_date( 'Y-m-d H:i:s' ) . "\n\n" );
		$out( "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" );

		foreach ( self::table_stats() as $t ) {
			if ( 0 !== strpos( $t->name, $wpdb->prefix ) ) {
				continue;
			}
			$q   = '`' . str_replace( '`', '``', $t->name ) . '`';
			$row = $db->query( "SHOW CREATE TABLE {$q}" )->fetch_row();
			if ( empty( $row[1] ) ) {
				continue; // nézet vagy elérhetetlen
			}
			$out( "DROP TABLE IF EXISTS {$q};\n{$row[1]};\n\n" );

			$res = $db->query( "SELECT * FROM {$q}", MYSQLI_USE_RESULT );
			if ( ! $res ) {
				continue;
			}
			$fields = $res->fetch_fields();
			$hexcol = array();
			foreach ( $fields as $i => $f ) {
				$hexcol[ $i ] = ( 63 === $f->charsetnr && in_array( $f->type, array( 16, 249, 250, 251, 252, 253, 254 ), true ) );
			}
			$cols  = '`' . implode( '`,`', array_map( function ( $f ) {
				return str_replace( '`', '``', $f->name );
			}, $fields ) ) . '`';
			$buf   = '';
			$count = 0;
			while ( $r = $res->fetch_row() ) {
				$vals = array();
				foreach ( $r as $i => $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( $hexcol[ $i ] ) {
						$vals[] = '' === $v ? "''" : '0x' . bin2hex( $v );
					} else {
						$vals[] = "'" . $db->real_escape_string( $v ) . "'";
					}
				}
				$buf .= ( '' === $buf ? "INSERT INTO {$q} ({$cols}) VALUES\n(" : ",\n(" ) . implode( ',', $vals ) . ')';
				$count++;
				if ( strlen( $buf ) > 500000 ) {
					$out( $buf . ";\n" );
					$buf = '';
				}
			}
			$res->free();
			if ( '' !== $buf ) {
				$out( $buf . ";\n" );
			}
			$out( "\n" );
		}
		$out( "SET FOREIGN_KEY_CHECKS=1;\n-- Vége\n" );
		if ( $ctx ) {
			echo deflate_add( $ctx, '', ZLIB_FINISH ); // phpcs:ignore
		}
		exit;
	}

	private static function core_table_names() {
		global $wpdb;
		$names = array();
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'users', 'usermeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' ) as $c ) {
			$names[] = $wpdb->prefix . $c;
			$names[] = $wpdb->base_prefix . $c;
		}
		foreach ( array( 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions' ) as $c ) {
			$names[] = $wpdb->base_prefix . $c;
		}
		return $names;
	}

	/** Törölhető-e a tábla a felületről: ugyanazon előtag, nem WP-mag. */
	public static function can_drop( $name ) {
		global $wpdb;
		if ( 0 !== strpos( $name, $wpdb->prefix ) || in_array( $name, self::core_table_names(), true ) ) {
			return false;
		}
		return 'WordPress mag' !== self::table_owner( $name );
	}

}

// A heti ütemezés a WordPress beépített "weekly" intervalluma (6.1-től része a magnak); régebbi verzión pótoljuk.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Hetente egyszer',
		);
	}
	return $schedules;
} );

register_deactivation_hook( __FILE__, array( 'ADBOPT', 'deactivate' ) );
ADBOPT::init();
