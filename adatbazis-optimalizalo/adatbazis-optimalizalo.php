<?php
/**
 * Plugin Name: Adatbázis Optimalizáló
 * Description: Megmutatja, mi foglalja az adatbázis tárhelyét (táblák, autoload opciók, pluginok), felesleges adatokat detektál és törli, táblákat optimalizál, opcionálisan hetente automatikusan takarít.
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 * Text Domain: adatbazis-optimalizalo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADBOPT {

	const CAP       = 'manage_options';
	const OPT       = 'adbopt_settings';
	const CRON_HOOK = 'adbopt_cron';
	const SLUG      = 'adatbazis-optimalizalo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_adbopt_clean', array( __CLASS__, 'handle_clean' ) );
		add_action( 'admin_post_adbopt_optimize', array( __CLASS__, 'handle_optimize' ) );
		add_action( 'admin_post_adbopt_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_adbopt_noautoload', array( __CLASS__, 'handle_noautoload' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
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
	/* Admin oldal                                                         */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_management_page( 'Adatbázis Optimalizáló', 'Adatbázis Optimalizáló', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function post_form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nincs jogosultságod.' );
		}
		global $wpdb;

		echo '<div class="wrap"><h1>Adatbázis Optimalizáló</h1>';
		self::show_notice();

		$tables   = self::table_stats();
		$total    = 0;
		$total_ov = 0;
		foreach ( $tables as $t ) {
			$total    += $t->data_len + $t->index_len;
			$total_ov += (int) $t->free_len;
		}

		echo '<div class="notice notice-info inline"><p><strong>Teljes adatbázisméret (' . esc_html( DB_NAME ) . '): ' . esc_html( size_format( $total, 2 ) ) . '</strong>';
		echo ' &ndash; ebből felszabadítható töredezettség (overhead): ' . esc_html( size_format( $total_ov, 2 ) ) . '.<br>';
		echo 'A tárhelyszolgáltató az adatbázis <em>összes</em> táblájának méretét számolja, a más előtaggal rendelkező (pl. másik WP-telepítés vagy törölt plugin maradék) táblákat is.</p></div>';

		/* 1. Táblák */
		echo '<h2>1. Táblák mérete (legnagyobb elöl)</h2>';
		echo '<p>A sorszám becsült érték (InnoDB). A "Forrás" oszlop a tábla nevéből következtet arra, melyik plugin vagy WordPress-rész hozta létre.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Tábla</th><th>Forrás</th><th>Sorok (~)</th><th>Adat</th><th>Index</th><th>Összesen</th><th>Overhead</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $tables as $t ) {
			if ( $shown++ >= 40 ) {
				break;
			}
			$sum   = $t->data_len + $t->index_len;
			$style = ( $total > 0 && $sum / $total > 0.25 ) ? ' style="background:#fff3cd"' : '';
			echo '<tr' . $style . '><td><code>' . esc_html( $t->name ) . '</code></td><td>' . esc_html( self::table_owner( $t->name ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $t->row_count ) ) . '</td>';
			echo '<td>' . esc_html( size_format( $t->data_len, 2 ) ) . '</td><td>' . esc_html( size_format( $t->index_len, 2 ) ) . '</td>';
			echo '<td><strong>' . esc_html( size_format( $sum, 2 ) ) . '</strong></td><td>' . esc_html( size_format( (int) $t->free_len, 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $tables ) > 40 ) {
			echo '<p>(Csak a 40 legnagyobb tábla látszik; összesen ' . (int) count( $tables ) . ' van.)</p>';
		}

		/* Plugin szerinti összesítés */
		$by_owner = array();
		foreach ( $tables as $t ) {
			$o = self::table_owner( $t->name );
			if ( ! isset( $by_owner[ $o ] ) ) {
				$by_owner[ $o ] = 0;
			}
			$by_owner[ $o ] += $t->data_len + $t->index_len;
		}
		arsort( $by_owner );
		echo '<h2>2. Méret plugin / forrás szerint</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>Forrás</th><th>Tábla-méret</th><th>Arány</th></tr></thead><tbody>';
		foreach ( array_slice( $by_owner, 0, 15, true ) as $o => $sz ) {
			echo '<tr><td>' . esc_html( $o ) . '</td><td>' . esc_html( size_format( $sz, 2 ) ) . '</td><td>' . esc_html( $total ? round( 100 * $sz / $total, 1 ) : 0 ) . '%</td></tr>';
		}
		echo '</tbody></table>';

		/* 3. Autoload */
		$in  = self::autoload_sql_list();
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, SUM(LENGTH(option_value)) AS s FROM {$wpdb->options} WHERE autoload IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		echo '<h2>3. Legnagyobb opciók (wp_options)</h2>';
		echo '<p>Autoload opciók: <strong>' . esc_html( number_format_i18n( (int) $row->c ) ) . ' db, ' . esc_html( size_format( (int) $row->s, 2 ) ) . '</strong>. ';
		echo 'Ha ez 1&ndash;2 MB fölött van, minden oldalbetöltést lassít.</p>';
		$opts = $wpdb->get_results( "SELECT option_name, autoload, LENGTH(option_value) AS len FROM {$wpdb->options} ORDER BY len DESC LIMIT 25" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$core_protected = array( 'siteurl', 'home', 'blogname', 'active_plugins', 'template', 'stylesheet', 'rewrite_rules', 'cron', 'permalink_structure', 'users_can_register', 'default_role', 'WPLANG' );
		echo '<table class="widefat striped"><thead><tr><th>Opció neve</th><th>Valószínű forrás</th><th>Méret</th><th>Autoload</th><th></th></tr></thead><tbody>';
		foreach ( $opts as $o ) {
			$is_auto = in_array( $o->autoload, self::autoload_values(), true );
			echo '<tr><td><code>' . esc_html( mb_strimwidth( $o->option_name, 0, 70, '…' ) ) . '</code></td><td>' . esc_html( self::option_owner( $o->option_name ) ) . '</td>';
			echo '<td><strong>' . esc_html( size_format( (int) $o->len, 2 ) ) . '</strong></td><td>' . ( $is_auto ? 'igen' : 'nem' ) . '</td><td>';
			if ( $is_auto && ! in_array( $o->option_name, $core_protected, true ) ) {
				self::post_form_open( 'adbopt_noautoload' );
				echo '<input type="hidden" name="option" value="' . esc_attr( $o->option_name ) . '">';
				echo '<button class="button button-small" onclick="return confirm(\'Kikapcsolod az autoload-ot ennél az opciónál? Az opció megmarad, csak nem töltődik be minden oldalnál.\')">Autoload ki</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		/* 4. Takarítás */
		echo '<h2>4. Felesleges adatok törlése</h2>';
		echo '<p><strong>Törlés előtt készíts adatbázis-mentést</strong> (pl. UpdraftPlus vagy a tárhely phpMyAdmin exportja). A törlés nem visszavonható.</p>';
		self::post_form_open( 'adbopt_clean' );
		echo '<table class="widefat striped"><thead><tr><th style="width:30px"></th><th>Mit</th><th>Találat</th><th>Leírás</th></tr></thead><tbody>';
		foreach ( self::tasks() as $id => $t ) {
			$count = self::task_count( $t );
			echo '<tr><td><input type="checkbox" name="tasks[]" value="' . esc_attr( $id ) . '"' . ( ( $count > 0 && $t['safe'] ) ? ' checked' : '' ) . '></td>';
			echo '<td><strong>' . esc_html( $t['label'] ) . '</strong></td><td>' . esc_html( number_format_i18n( $count ) ) . ' db</td><td>' . esc_html( $t['desc'] ) . '</td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary" onclick="return confirm(\'Biztosan törlöd a kijelölt elemeket? Van mentésed?\')">Kijelöltek törlése</button></p></form>';
		echo '<p class="description">Az előre bejelölt elemek biztonságosnak számítanak. A revíziók, a kuka és az összes tranziens törlése tudatos döntés, ezeket magadnak jelöld be.</p>';

		/* 5. Optimalizálás */
		echo '<h2>5. Táblák optimalizálása (OPTIMIZE TABLE)</h2>';
		echo '<p>Törlés után ez adja vissza ténylegesen a helyet a lemeznek (InnoDB-nél a tábla újraépül). Nagy táblánál eltarthat egy ideig.</p>';
		self::post_form_open( 'adbopt_optimize' );
		echo '<p><button class="button" name="scope" value="overhead">Csak a töredezett táblák</button> ';
		echo '<button class="button" name="scope" value="all">Minden tábla</button></p></form>';

		/* 6. Automatikus */
		$s = self::settings();
		echo '<h2>6. Automatikus heti takarítás</h2>';
		self::post_form_open( 'adbopt_settings' );
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], 1, false ) . '> Heti automatikus takarítás bekapcsolása (WP-Cron)</label></p>';
		echo '<p>Csak az alábbi, biztonságosnak jelölt elemeket törli:</p><ul>';
		foreach ( self::tasks() as $id => $t ) {
			if ( ! $t['safe'] ) {
				continue;
			}
			echo '<li><label><input type="checkbox" name="auto_tasks[]" value="' . esc_attr( $id ) . '"' . ( in_array( $id, $s['tasks'], true ) ? ' checked' : '' ) . '> ' . esc_html( $t['label'] ) . '</label></li>';
		}
		echo '</ul><p><label><input type="checkbox" name="auto_optimize" value="1"' . checked( $s['optimize'], 1, false ) . '> Utána a töredezett táblák optimalizálása</label></p>';
		echo '<p><button class="button button-primary">Beállítások mentése</button></p></form>';
		if ( $s['last_run'] ) {
			echo '<p>Utolsó automatikus futás: ' . esc_html( wp_date( 'Y.m.d H:i', $s['last_run'] ) ) . ' &ndash; ' . esc_html( $s['last_result'] ) . '</p>';
		}

		/* 7. Tippek */
		echo '<h2>7. Megelőzés</h2><ul style="list-style:disc;margin-left:20px">';
		echo '<li>A <code>wp-config.php</code>-ba: <code>define( \'WP_POST_REVISIONS\', 5 );</code> &ndash; legfeljebb 5 revíziót tart meg bejegyzésenként.</li>';
		echo '<li>Töröld a már nem használt pluginok maradék tábláit (a fenti táblalista "Forrás" oszlopa segít). Ha a tábla olyan plugin nevét viseli, ami már nincs telepítve, phpMyAdmin-ban biztonsággal eldobható (mentés után).</li>';
		echo '<li>Naplózó/statisztika pluginoknál (Wordfence, WP Statistics, Activity Log) állítsd rövidre a megőrzési időt, vagy kapcsold ki a naplózást.</li>';
		echo '</ul></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Műveletek                                                           */
	/* ------------------------------------------------------------------ */

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Nincs jogosultságod.', 403 );
		}
		check_admin_referer( $action );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
	}

	private static function back( $msg ) {
		set_transient( 'adbopt_notice_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	private static function show_notice() {
		$key = 'adbopt_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
		}
	}

	public static function handle_clean() {
		self::guard( 'adbopt_clean' );
		$sel   = isset( $_POST['tasks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['tasks'] ) ) : array();
		$out   = self::run_selected( $sel, 25 );
		$msg   = $out ? implode( '<br>', $out ) : 'Nem jelöltél ki semmit.';
		$msg  .= '<br>Ha még maradt találat, futtasd újra a törlést (időkorlát miatt részletekben dolgozik). Végül futtasd az optimalizálást is.';
		wp_cache_flush();
		self::back( $msg );
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
				$out[] = esc_html( $t['label'] ) . ': kimaradt az időkorlát miatt, futtasd újra.';
				continue;
			}
			$out[] = esc_html( $t['label'] ) . ': <strong>' . number_format_i18n( self::run_task( $t, $deadline ) ) . '</strong> db törölve';
		}
		return $out;
	}

	private static function optimize_tables( $scope, $seconds ) {
		global $wpdb;
		$deadline = time() + $seconds;
		$done     = 0;
		foreach ( self::table_stats() as $t ) {
			if ( 'overhead' === $scope && (int) $t->free_len < 1048576 ) {
				continue;
			}
			if ( time() >= $deadline ) {
				break;
			}
			// A név az information_schema-ból jön, és visszaquote-oljuk.
			$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $t->name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL
			$done++;
		}
		return $done;
	}

	public static function handle_optimize() {
		self::guard( 'adbopt_optimize' );
		$scope = ( isset( $_POST['scope'] ) && 'all' === $_POST['scope'] ) ? 'all' : 'overhead';
		$n     = self::optimize_tables( $scope, 60 );
		self::back( 'Optimalizált táblák száma: <strong>' . (int) $n . '</strong>. Az új méretet lent láthatod.' );
	}

	public static function handle_noautoload() {
		self::guard( 'adbopt_noautoload' );
		global $wpdb;
		$name = isset( $_POST['option'] ) ? wp_unslash( $_POST['option'] ) : ''; // phpcs:ignore
		if ( '' === $name ) {
			self::back( 'Hiányzó opciónév.' );
		}
		$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ) );
		wp_cache_delete( 'alloptions', 'options' );
		self::back( 'Autoload kikapcsolva: <code>' . esc_html( $name ) . '</code>' );
	}

	/* ------------------------------------------------------------------ */
	/* Beállítások + cron                                                  */
	/* ------------------------------------------------------------------ */

	public static function settings() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'enabled'     => 0,
				'tasks'       => array(),
				'optimize'    => 0,
				'last_run'    => 0,
				'last_result' => '',
			)
		);
	}

	public static function handle_settings() {
		self::guard( 'adbopt_settings' );
		$s             = self::settings();
		$safe          = array_keys( array_filter( self::tasks(), function ( $t ) {
			return $t['safe'];
		} ) );
		$s['enabled']  = empty( $_POST['enabled'] ) ? 0 : 1;
		$s['optimize'] = empty( $_POST['auto_optimize'] ) ? 0 : 1;
		$chosen        = isset( $_POST['auto_tasks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['auto_tasks'] ) ) : array();
		$s['tasks']    = array_values( array_intersect( $chosen, $safe ) );
		update_option( self::OPT, $s, false );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $s['enabled'] ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
		self::back( 'Beállítások elmentve.' );
	}

	public static function run_cron() {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		@set_time_limit( 300 ); // phpcs:ignore
		$out = self::run_selected( $s['tasks'], 120 );
		if ( $s['optimize'] ) {
			$out[] = 'Optimalizált táblák: ' . self::optimize_tables( 'overhead', 120 );
		}
		$s['last_run']    = time();
		$s['last_result'] = wp_strip_all_tags( implode( '; ', $out ) );
		update_option( self::OPT, $s, false );
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
