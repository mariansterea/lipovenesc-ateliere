<?php
/**
 * Panoul pluginului: bara de navigare comună pe toate ecranele Evenimente,
 * pagina „Panou” (cifre, creare rapidă, următoarele evenimente, de urmărit, înscrieri recente)
 * și curățenie în lista de evenimente.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Dashboard {

	const SLUG = 'lat-panou';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'order_menu' ), 999 );
		add_action( 'in_admin_header', array( __CLASS__, 'topbar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_lat_quick_create', array( __CLASS__, 'quick_create' ) );
		add_action( 'admin_post_lat_quick_copy', array( __CLASS__, 'quick_copy' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 99, 2 );
		foreach ( array( LAT_Helpers::CPT_WS, LAT_Helpers::CPT_REG ) as $pt ) {
			add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'clean_columns' ), 999 );
		}
		add_filter( 'manage_edit-' . LAT_Helpers::CPT_WS . '_columns', array( __CLASS__, 'clean_columns' ), 999 );
	}

	/* ---------- Meniu ---------- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LAT_Helpers::CPT_WS, 'Panou evenimente', 'Panou', 'edit_posts', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** „Panou” primul în submeniu: așa și clicul pe „Evenimente” din meniul mare duce la panou. */
	public static function order_menu() {
		global $submenu;
		$parent = 'edit.php?post_type=' . LAT_Helpers::CPT_WS;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}
		$panou = null;
		foreach ( $submenu[ $parent ] as $k => $item ) {
			if ( self::SLUG === $item[2] ) {
				$panou = $item;
				unset( $submenu[ $parent ][ $k ] );
			}
		}
		if ( $panou ) {
			array_unshift( $submenu[ $parent ], $panou );
		}
	}

	private static function on_plugin_screen() {
		$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $s ) {
			return false;
		}
		if ( in_array( $s->post_type, array( LAT_Helpers::CPT_WS, LAT_Helpers::CPT_REG ), true ) ) {
			return true;
		}
		return LAT_Helpers::TAX === $s->taxonomy || false !== strpos( (string) $s->id, 'lat-' );
	}

	public static function assets() {
		if ( self::on_plugin_screen() ) {
			wp_enqueue_style( 'lat-dash', LAT_URL . 'assets/css/dashboard.css', array(), LAT_VERSION );
			wp_enqueue_script( 'lat-dash', LAT_URL . 'assets/js/dashboard.js', array(), LAT_VERSION, true );
		}
	}

	private static function url( $page = '', $args = array() ) {
		$base = 'edit.php?post_type=' . LAT_Helpers::CPT_WS . ( $page ? '&page=' . $page : '' );
		return add_query_arg( $args, admin_url( $base ) );
	}

	/** Bara de sus, aceeași pe toate ecranele pluginului. */
	public static function topbar() {
		if ( ! self::on_plugin_screen() ) {
			return;
		}
		$s    = get_current_screen();
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'panou'  => array( 'Panou', self::url( self::SLUG ), self::SLUG === $page ),
			'ev'     => array( 'Evenimente', self::url(), LAT_Helpers::CPT_WS === $s->post_type && '' === $page && in_array( $s->base, array( 'edit', 'post' ), true ) ),
			'reg'    => array( 'Înscrieri', admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG ), LAT_Helpers::CPT_REG === $s->post_type ),
			'part'   => array( 'Participanți', self::url( 'lat-participants' ), 'lat-participants' === $page ),
			'check'  => array( 'Check-in', self::url( 'lat-checkin' ), 'lat-checkin' === $page ),
			'cat'    => array( 'Categorii', admin_url( 'edit-tags.php?taxonomy=' . LAT_Helpers::TAX . '&post_type=' . LAT_Helpers::CPT_WS ), LAT_Helpers::TAX === $s->taxonomy ),
			'set'    => array( 'Setări', self::url( 'lat-settings' ), 'lat-settings' === $page ),
		);
		echo '<div class="latd-top"><div class="latd-top-in">';
		echo '<a class="latd-logo" href="' . esc_url( self::url( self::SLUG ) ) . '"><span class="latd-logo-i" aria-hidden="true">' . self::ico( 'ticket' ) . '</span><span><b>Evenimente</b><small>Ferma Lipovenesc</small></span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<nav class="latd-tabs" aria-label="Evenimente">';
		foreach ( $tabs as $t ) {
			echo '<a href="' . esc_url( $t[1] ) . '"' . ( $t[2] ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $t[0] ) . '</a>';
		}
		echo '</nav>';
		echo '<a class="latd-btn latd-btn-new" href="' . esc_url( self::url( self::SLUG ) . '#creeaza' ) . '">' . self::ico( 'plus' ) . 'Eveniment nou</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div>';
	}

	public static function ico( $k ) {
		$p = array(
			'ticket'  => 'M4 7a2 2 0 0 0 2-2h12a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H6a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4zM14 5v14',
			'plus'    => 'M12 5v14M5 12h14',
			'cal'     => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
			'people'  => 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 20a6 6 0 0 1 12 0M17 11a2.5 2.5 0 1 0 0-5M16 14.5a5 5 0 0 1 5 5.5',
			'money'   => 'M3 7h18v10H3zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM6 12h.01M18 12h.01',
			'clock'   => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
			'alert'   => 'M12 3l10 18H2zM12 10v5M12 18v.5',
			'edit'    => 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
			'list'    => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
			'check'   => 'M4 12l5 5L20 6',
			'eye'     => 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
			'copy'    => 'M8 8h12v12H8zM4 16V4h12',
			'pin'     => 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
			'wait'    => 'M7 3h10M7 21h10M8 3c0 5 8 5 8 9s-8 4-8 9M16 3c0 5-8 5-8 9',
		);
		return '<svg class="latd-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="' . esc_attr( $p[ $k ] ?? $p['ticket'] ) . '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	/* ---------- Curățenie în listă ---------- */

	public static function post_states( $states, $post ) {
		if ( $post && in_array( $post->post_type, array( LAT_Helpers::CPT_WS, LAT_Helpers::CPT_REG ), true ) ) {
			foreach ( $states as $k => $v ) {
				if ( false !== stripos( wp_strip_all_tags( (string) $v ), 'editor' ) ) {
					unset( $states[ $k ] );
				}
			}
		}
		return $states;
	}

	/** Coloanele adăugate de alte pluginuri (SEO, cache) nu au sens la evenimente și înscrieri. */
	public static function clean_columns( $cols ) {
		foreach ( array_keys( $cols ) as $k ) {
			if ( preg_match( '/^(wpseo-|rocket|wpr|yoast|seopress|rank_math|aioseo)/i', $k ) ) {
				unset( $cols[ $k ] );
			}
		}
		return $cols;
	}

	/* ---------- Date pentru panou ---------- */

	private static function regs( $args = array() ) {
		return get_posts(
			array_merge(
				array(
					'post_type'   => LAT_Helpers::CPT_REG,
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
				),
				$args
			)
		);
	}

	/* ---------- Pagina Panou ---------- */

	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$up     = LAT_Helpers::upcoming_ids( -1 );
		$cats   = LAT_Helpers::categories();
		$places = 0;
		$cap    = 0;
		$money  = 0.0;
		$wait   = 0;
		$alerts = array();
		$now    = LAT_Helpers::now();
		foreach ( $up as $id ) {
			$m       = LAT_Helpers::ws_meta( $id );
			$taken   = LAT_Helpers::places_taken( $id );
			$places += $taken;
			$cap    += (int) $m['capacity'];
			$wait   += LAT_Helpers::waitlist_count( $id );
			foreach ( self::regs( array( 'meta_query' => array( array( 'key' => '_lat_workshop', 'value' => $id ), array( 'key' => '_lat_status', 'value' => 'confirmed' ) ) ) ) as $rid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$t = LAT_Helpers::price_total( $m, get_post_meta( $rid, '_lat_adults', true ), get_post_meta( $rid, '_lat_children', true ) );
				$money += (float) $t;
			}
			$days = (int) floor( ( strtotime( $m['date'] ) - strtotime( $now->format( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
			if ( $m['min'] > 0 && LAT_Helpers::people_confirmed( $id ) < $m['min'] && $days <= 21 ) {
				$alerts[] = array( $id, 'sub minim: ' . LAT_Helpers::people_confirmed( $id ) . ' din minim ' . (int) $m['min'] . ' participanți, în ' . $days . ( 1 === $days ? ' zi' : ' zile' ) );
			}
			if ( LAT_Helpers::price_missing( $m ) ) {
				$alerts[] = array( $id, 'lipsește prețul (pe site apare „Preț în curând”)' );
			}
			if ( empty( $m['open'] ) ) {
				$alerts[] = array( $id, 'înscrierile sunt oprite' );
			}
		}
		$drafts  = get_posts( array( 'post_type' => LAT_Helpers::CPT_WS, 'post_status' => 'draft', 'numberposts' => 10, 'fields' => 'ids' ) );
		$new30   = self::regs( array( 'date_query' => array( array( 'after' => '30 days ago' ) ) ) );
		$people30 = 0;
		foreach ( $new30 as $rid ) {
			$people30 += (int) get_post_meta( $rid, '_lat_adults', true ) + (int) get_post_meta( $rid, '_lat_children', true );
		}

		echo '<div class="wrap latd"><h1 class="screen-reader-text">Panou evenimente</h1>';
		$n = get_transient( 'lat_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'lat_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $n ) . '</p></div>';
		}

		echo '<header class="latd-hero"><div><p class="latd-kicker">' . esc_html( date_i18n( 'l, j F Y' ) ) . '</p><h2>Evenimente în curte</h2><p>Ce urmează, câte locuri s-au ocupat și ce trebuie urmărit.</p></div>';
		echo '<a class="latd-btn latd-btn-light" href="' . esc_url( home_url( '/hai-la-ferma/' ) ) . '" target="_blank" rel="noopener">' . self::ico( 'eye' ) . 'Vezi pagina publică</a></header>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Cifre.
		$fill = $cap ? round( 100 * $places / $cap ) : 0;
		$cards = array(
			array( 'cal', 'Evenimente viitoare', count( $up ), count( $drafts ) ? count( $drafts ) . ' ' . ( 1 === count( $drafts ) ? 'ciornă' : 'ciorne' ) . ' nepublicate' : 'toate publicate' ),
			array( 'people', 'Locuri ocupate', $places . ' / ' . $cap, $fill . '% din locurile viitoare' ),
			array( 'money', 'Încasări confirmate', LAT_Helpers::money( $money ), 'din înscrierile confirmate la evenimentele viitoare' ),
			array( 'list', 'Înscrieri noi', count( $new30 ), $people30 . ' persoane, în ultimele 30 de zile' ),
			array( 'wait', 'Listă de așteptare', $wait, $wait ? 'se mută automat când se eliberează locuri' : 'nimeni nu așteaptă' ),
			array( 'alert', 'De urmărit', count( $alerts ), count( $alerts ) ? 'vezi lista din dreapta' : 'totul e în regulă' ),
		);
		echo '<section class="latd-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="latd-card' . ( 'alert' === $c[0] && $c[2] ? ' is-warn' : '' ) . '"><p class="latd-card-l">' . self::ico( $c[0] ) . esc_html( $c[1] ) . '</p><p class="latd-card-n">' . esc_html( (string) $c[2] ) . '</p><p class="latd-card-s">' . esc_html( $c[3] ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</section>';

		echo '<div class="latd-grid">';

		// Coloana stângă: următoarele evenimente.
		echo '<section class="latd-panel latd-next"><div class="latd-panel-h"><h3>Următoarele evenimente</h3><a href="' . esc_url( self::url() ) . '">Toate evenimentele →</a></div>';
		if ( ! $up ) {
			echo '<div class="latd-empty"><p><strong>Niciun eveniment programat.</strong></p><p>Creează primul din formularul „Eveniment nou”.</p></div>';
		}
		foreach ( array_slice( $up, 0, 8 ) as $id ) {
			self::event_row( $id );
		}
		echo '</section>';

		// Coloana dreaptă: creare rapidă, de urmărit, înscrieri recente.
		echo '<div class="latd-side">';
		self::quick_form( $cats, array_merge( $up, LAT_Helpers::past_ids( 6 ) ) );

		echo '<section class="latd-panel"><div class="latd-panel-h"><h3>De urmărit</h3></div>';
		if ( ! $alerts && ! $drafts ) {
			echo '<p class="latd-ok">' . self::ico( 'check' ) . 'Nimic de rezolvat acum.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<ul class="latd-alerts">';
		foreach ( $alerts as $a ) {
			echo '<li><a href="' . esc_url( get_edit_post_link( $a[0] ) ) . '"><b>' . esc_html( get_the_title( $a[0] ) ) . '</b><span>' . esc_html( $a[1] ) . '</span></a></li>';
		}
		foreach ( $drafts as $d ) {
			$m = LAT_Helpers::ws_raw( $d );
			echo '<li class="is-draft"><a href="' . esc_url( get_edit_post_link( $d ) ) . '"><b>' . esc_html( get_the_title( $d ) ?: '(fără titlu)' ) . '</b><span>ciornă' . ( $m['date'] ? ', ' . esc_html( LAT_Helpers::date_label( $m['date'] ) ) : '' ) . ': completează și publică</span></a></li>';
		}
		echo '</ul></section>';

		$recent = get_posts( array( 'post_type' => LAT_Helpers::CPT_REG, 'post_status' => 'publish', 'numberposts' => 6 ) );
		echo '<section class="latd-panel"><div class="latd-panel-h"><h3>Înscrieri recente</h3><a href="' . esc_url( admin_url( 'edit.php?post_type=' . LAT_Helpers::CPT_REG ) ) . '">Toate →</a></div>';
		if ( ! $recent ) {
			echo '<p class="latd-muted latd-pad">Încă nu sunt înscrieri.</p>';
		}
		echo '<ul class="latd-regs">';
		$labels = array( 'confirmed' => 'confirmat', 'pending' => 'așteaptă plata', 'waitlist' => 'în așteptare', 'cancelled' => 'anulat' );
		foreach ( $recent as $p ) {
			$st = (string) get_post_meta( $p->ID, '_lat_status', true );
			$ws = (int) get_post_meta( $p->ID, '_lat_workshop', true );
			echo '<li><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '"><b>' . esc_html( get_post_meta( $p->ID, '_lat_name', true ) ?: $p->post_title ) . '</b><span>' . esc_html( LAT_Helpers::people_label( get_post_meta( $p->ID, '_lat_adults', true ), get_post_meta( $p->ID, '_lat_children', true ) ) . ' · ' . ( $ws ? get_the_title( $ws ) : '' ) ) . '</span></a><em class="latd-st latd-st-' . esc_attr( $st ) . '">' . esc_html( $labels[ $st ] ?? $st ) . '</em><time>' . esc_html( human_time_diff( get_post_time( 'U', true, $p ), time() ) ) . '</time></li>';
		}
		echo '</ul></section>';
		echo '</div></div></div>';
	}

	private static function event_row( $id ) {
		$m     = LAT_Helpers::ws_meta( $id );
		$taken = LAT_Helpers::places_taken( $id );
		$state = LAT_Helpers::ws_state( $id );
		$ts    = strtotime( $m['date'] . ' 12:00' );
		$pct   = $m['capacity'] ? min( 100, round( 100 * $taken / $m['capacity'] ) ) : 0;
		$minp  = $m['capacity'] && $m['min'] ? min( 100, round( 100 * $m['min'] / $m['capacity'] ) ) : 0;
		$people = LAT_Helpers::people_confirmed( $id );
		$labels = array( 'open' => 'înscrieri deschise', 'full' => 'complet', 'closed' => 'înscrieri închise', 'past' => 'a avut loc', 'cancelled' => 'anulat' );
		echo '<article class="latd-ev" style="--c:' . esc_attr( $m['cat_color'] ?: '#4f6246' ) . '">';
		echo '<div class="latd-date"><b>' . esc_html( date_i18n( 'j', $ts ) ) . '</b><span>' . esc_html( LAT_Helpers::month_short( (int) date_i18n( 'n', $ts ) ) ) . '</span><small>' . esc_html( date_i18n( 'D', $ts ) ) . '</small></div>';
		echo '<div class="latd-ev-main"><p class="latd-ev-cat">' . esc_html( $m['cat_name'] ?: 'Fără categorie' ) . '</p><h4><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h4>';
		echo '<p class="latd-ev-meta">' . self::ico( 'clock' ) . esc_html( $m['start'] . '–' . $m['end'] ) . ' <span>·</span> ' . esc_html( $m['free'] ? 'gratuit' : LAT_Helpers::price_label( $m ) ) . ' <span>·</span> ' . esc_html( 'buy' === $m['mode_eff'] ? 'bilet online' : 'rezervare' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="latd-fill"><div class="latd-fill-bar"><span style="width:' . (int) $pct . '%"></span>' . ( $minp ? '<i style="left:' . (int) $minp . '%" title="minim ' . (int) $m['min'] . '"></i>' : '' ) . '</div><p><b>' . (int) $taken . '</b> / ' . (int) $m['capacity'] . ' locuri' . ( $m['min'] ? ( $people >= $m['min'] ? ' <em class="ok">· minim atins</em>' : ' <em>· mai trebuie ' . (int) ( $m['min'] - $people ) . ' până la minim</em>' ) : '' ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="latd-ev-act"><span class="latd-state latd-state-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? $state ) . '</span><div>';
		echo '<a class="latd-ibtn" href="' . esc_url( get_edit_post_link( $id ) ) . '" title="Editează">' . self::ico( 'edit' ) . '<span>Editează</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<a class="latd-ibtn" href="' . esc_url( self::url( 'lat-participants', array( 'workshop' => $id ) ) ) . '" title="Participanți">' . self::ico( 'people' ) . '<span>Participanți</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<a class="latd-ibtn" href="' . esc_url( self::url( 'lat-checkin', array( 'workshop' => $id ) ) ) . '" title="Check-in">' . self::ico( 'check' ) . '<span>Check-in</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<a class="latd-ibtn" href="' . esc_url( LAT_Helpers::ws_url( $id ) ) . '" target="_blank" rel="noopener" title="Vezi pe site">' . self::ico( 'eye' ) . '<span>Vezi</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div></article>';
	}

	private static function quick_form( $cats, $existing ) {
		echo '<section class="latd-panel latd-create" id="creeaza"><div class="latd-panel-h"><h3>' . self::ico( 'plus' ) . 'Eveniment nou</h3></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="latd-tabs2" role="tablist"><button type="button" role="tab" aria-selected="true" data-t="nou">De la zero</button><button type="button" role="tab" aria-selected="false" data-t="copie">Copie după un eveniment</button></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="latd-form" data-p="nou">';
		wp_nonce_field( 'lat_quick', 'lat_quick_nonce' );
		echo '<input type="hidden" name="action" value="lat_quick_create">';
		echo '<label class="latd-f"><span>Numele evenimentului</span><input type="text" name="title" required placeholder="ex. Pomana porcului de Ignat"></label>';
		echo '<fieldset class="latd-f"><legend>Categoria</legend><div class="latd-catpick">';
		$first = true;
		foreach ( $cats as $t ) {
			$c = LAT_Helpers::cat_meta( $t->term_id );
			echo '<label style="--c:' . esc_attr( $c['color'] ) . '"><input type="radio" name="cat" value="' . (int) $t->term_id . '"' . ( $first ? ' checked' : '' ) . '><span><b>' . esc_html( $t->name ) . '</b><small>' . esc_html( ( $c['free'] ? 'gratuit' : LAT_Helpers::price_label( $c ) ) . ' · max ' . (int) $c['capacity'] . ' locuri' ) . '</small></span></label>';
			$first = false;
		}
		echo '</div><p class="latd-hint">Prețul, locurile și modul de rezervare se preiau din categorie. Le poți schimba după aceea.</p></fieldset>';
		echo '<div class="latd-row3"><label class="latd-f"><span>Data</span><input type="date" name="date" required min="' . esc_attr( LAT_Helpers::now()->format( 'Y-m-d' ) ) . '"></label><label class="latd-f"><span>De la</span><input type="time" name="start" value="12:00"></label><label class="latd-f"><span>Până la</span><input type="time" name="end" value="15:00"></label></div>';
		echo '<div class="latd-actions"><button class="latd-btn" name="go" value="edit">Creează și completează detaliile →</button></div>';
		echo '<p class="latd-hint">Se creează ca ciornă. În pasul următor adaugi descrierea și poza, apoi apeși „Publică”.</p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="latd-form" data-p="copie" hidden>';
		wp_nonce_field( 'lat_quick', 'lat_quick_nonce' );
		echo '<input type="hidden" name="action" value="lat_quick_copy">';
		echo '<label class="latd-f"><span>Eveniment de copiat</span><select name="source" required>';
		foreach ( array_unique( $existing ) as $id ) {
			$m = LAT_Helpers::ws_raw( $id );
			echo '<option value="' . (int) $id . '">' . esc_html( get_the_title( $id ) . ( $m['date'] ? ' · ' . LAT_Helpers::date_label( $m['date'], false ) : '' ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="latd-f"><span>Data nouă</span><input type="date" name="date" required min="' . esc_attr( LAT_Helpers::now()->format( 'Y-m-d' ) ) . '"></label>';
		echo '<div class="latd-actions"><button class="latd-btn">Creează copia →</button></div>';
		echo '<p class="latd-hint">Copia are aceeași descriere, poză, ore, prețuri și locuri. Se deschide ca ciornă, ca s-o verifici înainte de publicare.</p>';
		echo '</form></section>';
	}

	/* ---------- Acțiuni ---------- */

	private static function check() {
		if ( ! isset( $_POST['lat_quick_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lat_quick_nonce'] ) ), 'lat_quick' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Nu ai voie.', 403 );
		}
	}

	public static function quick_create() {
		self::check();
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$date  = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$cat   = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$tm    = function ( $k, $d ) {
			$v = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : $d;
		};
		if ( '' === $title || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_safe_redirect( self::url( self::SLUG ) );
			exit;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => LAT_Helpers::CPT_WS,
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		$meta          = LAT_Helpers::ws_defaults();
		$meta['date']  = $date;
		$meta['start'] = $tm( 'start', $meta['start'] );
		$meta['end']   = $tm( 'end', $meta['end'] );
		update_post_meta( $id, '_lat', $meta );
		update_post_meta( $id, '_lat_start', $date . ' ' . $meta['start'] );
		if ( $cat && term_exists( $cat, LAT_Helpers::TAX ) ) {
			wp_set_object_terms( $id, array( $cat ), LAT_Helpers::TAX );
		}
		set_transient( 'lat_notice_' . get_current_user_id(), 'Evenimentul „' . $title . '” e creat ca ciornă. Adaugă descrierea și poza, verifică detaliile, apoi apasă „Publică”.', 120 );
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
		exit;
	}

	public static function quick_copy() {
		self::check();
		$src  = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		if ( ! $src || LAT_Helpers::CPT_WS !== get_post_type( $src ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_safe_redirect( self::url( self::SLUG ) );
			exit;
		}
		$new = LAT_Workshops::copy( $src, $date );
		if ( ! $new ) {
			wp_die( 'Copia nu a putut fi creată.' );
		}
		set_transient( 'lat_notice_' . get_current_user_id(), 'Copia pentru ' . LAT_Helpers::date_label( $date ) . ' e creată ca ciornă. Verifică detaliile, apoi apasă „Publică”.', 120 );
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $new . '&action=edit' ) );
		exit;
	}
}
