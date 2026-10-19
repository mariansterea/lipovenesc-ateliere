<?php
/**
 * Public side: shortcodes (list, single card, category tiles, next events), the event's own
 * page, and Event structured data. Server-rendered (good for SEO), enhanced by JS.
 *
 * Shortcodes:
 *  [lipovenesc_evenimente]                       list with calendar + filters
 *  [lipovenesc_evenimente categorie="ateliere"]  one category
 *  [lipovenesc_evenimente layout="urmatoarele" limit="3"]  compact "next events"
 *  [lipovenesc_evenimente layout="arhiva"]       past events
 *  [lipovenesc_eveniment id="123"]               one event card with its form
 *  [lipovenesc_categorii]                        the big category tiles
 *  [lipovenesc_ateliere]                         alias of the list (v1 compatibility)
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Frontend {

	private static $schema = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'lipovenesc_evenimente', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'lipovenesc_ateliere', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'lipovenesc_eveniment', array( __CLASS__, 'shortcode_single' ) );
		add_shortcode( 'lipovenesc_categorii', array( __CLASS__, 'shortcode_categories' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_content' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_schema' ), 5 );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'strip' ) );
	}

	public static function register() {
		wp_register_style( 'lat-front', LAT_URL . 'assets/css/front.css', array(), LAT_VERSION );
		wp_register_script( 'lat-front', LAT_URL . 'assets/js/front.js', array(), LAT_VERSION, true );
	}

	public static function strip( $v ) {
		return $v;
	}

	private static function enqueue() {
		wp_enqueue_style( 'lat-front' );
		$s   = LAT_Helpers::settings();
		$css = '.lat-app,.lat-tiles,.lat-single{--lat-accent:' . sanitize_hex_color( $s['accent'] ) . ';--lat-accent-rgb:' . LAT_Helpers::hex_rgb( $s['accent'] ) . ';--lat-paper:' . sanitize_hex_color( $s['paper'] ) . ';--lat-ink:' . sanitize_hex_color( $s['ink'] ) . ';' . ( $s['font_head'] ? '--lat-serif:' . wp_strip_all_tags( $s['font_head'] ) . ';' : '' ) . '}';
		wp_add_inline_style( 'lat-front', $css );
		wp_enqueue_script( 'lat-front' );
	}

	private static function app_attrs( $extra = array() ) {
		$attrs = array_merge(
			array(
				'data-api'      => add_query_arg( 'rest_route', '/' . LAT_Rest::NS, home_url( '/' ) ),
				'data-gdpr'     => wp_kses_post( LAT_Helpers::get( 'gdpr_text' ) ),
				'data-waitlist' => LAT_Helpers::get( 'waitlist' ) ? '1' : '0',
			),
			$extra
		);
		$out   = '';
		foreach ( $attrs as $k => $v ) {
			$out .= ' ' . $k . '="' . esc_attr( is_array( $v ) ? wp_json_encode( $v ) : $v ) . '"';
		}
		return $out;
	}

	/* ---------- Category tiles ---------- */

	public static function shortcode_categories( $atts ) {
		$atts = shortcode_atts(
			array(
				'link'  => '',
				'titlu' => '',
			),
			$atts,
			'lipovenesc_categorii'
		);
		self::enqueue();
		$cats = LAT_Helpers::categories();
		if ( ! $cats ) {
			return '';
		}
		ob_start();
		if ( $atts['titlu'] ) {
			echo '<h2 class="lat-tiles-title">' . esc_html( $atts['titlu'] ) . '</h2>';
		}
		echo '<div class="lat-tiles lat-tiles--' . (int) min( 4, count( $cats ) ) . '">';
		foreach ( $cats as $t ) {
			$c     = LAT_Helpers::cat_meta( $t->term_id );
			$img   = $c['image_id'] ? wp_get_attachment_image_url( (int) $c['image_id'], 'large' ) : '';
			$count = count( LAT_Helpers::upcoming_ids( -1, $t->slug ) );
			$base  = $atts['link'] ? $atts['link'] : ( LAT_Helpers::get( 'list_page' ) ? get_permalink( (int) LAT_Helpers::get( 'list_page' ) ) : '' );
			$href  = $base ? add_query_arg( 'categorie', $t->slug, $base ) . '#lat-lista' : '#lat-lista';
			?>
			<a class="lat-tile" href="<?php echo esc_url( $href ); ?>" style="--lat-c:<?php echo esc_attr( $c['color'] ); ?>">
				<?php if ( $img ) : ?>
					<img class="lat-tile-img" src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $t->name . ' la Ferma Lipovenesc' ); ?>" loading="lazy">
				<?php else : ?>
					<span class="lat-tile-img lat-tile-img--empty"></span>
				<?php endif; ?>
				<span class="lat-tile-body">
					<span class="lat-tile-kicker"><?php echo $count ? esc_html( $count . ( 1 === $count ? ' eveniment' : ' evenimente' ) ) : 'În pregătire'; ?></span>
					<span class="lat-tile-title"><?php echo esc_html( $t->name ); ?></span>
					<?php if ( $c['intro'] ) : ?>
						<span class="lat-tile-text"><?php echo esc_html( $c['intro'] ); ?></span>
					<?php endif; ?>
					<span class="lat-btn lat-btn--tile"><?php echo esc_html( $c['cta'] ? $c['cta'] : 'Vezi evenimentele' ); ?></span>
				</span>
			</a>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------- List ---------- */

	public static function shortcode_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'categorie' => '',
				'limit'     => -1,
				'layout'    => 'lista', // lista | urmatoarele | arhiva
				'calendar'  => 'da',
				'filtre'    => 'da',
				'titlu'     => '',
				'link'      => '',
			),
			$atts,
			'lipovenesc_evenimente'
		);
		// ?categorie=slug from the tiles narrows the list.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from_url = isset( $_GET['categorie'] ) ? sanitize_title( wp_unslash( $_GET['categorie'] ) ) : '';
		$category = $atts['categorie'] ? $atts['categorie'] : $from_url;

		self::enqueue();
		$archive = 'arhiva' === $atts['layout'];
		$ids     = $archive ? LAT_Helpers::past_ids( (int) $atts['limit'] > 0 ? (int) $atts['limit'] : 12, $category ) : LAT_Helpers::upcoming_ids( (int) $atts['limit'], $category );

		if ( 'urmatoarele' === $atts['layout'] ) {
			return self::render_next( $ids, $atts );
		}

		$cards = array();
		$cats  = array();
		foreach ( $ids as $id ) {
			$m       = LAT_Helpers::ws_meta( $id );
			$cards[] = array(
				'id'   => $id,
				'meta' => $m,
			);
			if ( $m['cat'] ) {
				$cats[ $m['cat_slug'] ] = $m['cat_name'];
			}
		}
		$calendar = array();
		foreach ( $cards as $c ) {
			$calendar[] = array(
				'id'    => $c['id'],
				'date'  => $c['meta']['date'],
				'title' => get_the_title( $c['id'] ),
			);
		}
		$term = $category ? get_term_by( 'slug', sanitize_title( $category ), LAT_Helpers::TAX ) : null;

		ob_start();
		?>
		<?php static $lat_list_anchor = false; $lat_anchor_attr = $lat_list_anchor ? '' : ' id="lat-lista"'; $lat_list_anchor = true; ?>
		<div class="lat-app"<?php echo $lat_anchor_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo self::app_attrs( array( 'data-calendar' => $calendar ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $atts['titlu'] ) : ?>
				<h2 class="lat-h"><?php echo esc_html( $atts['titlu'] ); ?></h2>
			<?php endif; ?>
			<?php if ( $term && ! $atts['titlu'] ) : ?>
				<div class="lat-cat-head" style="--lat-c:<?php echo esc_attr( LAT_Helpers::cat_meta( $term->term_id )['color'] ); ?>">
					<span class="lat-kicker"><?php echo $archive ? 'Arhivă' : 'Evenimente în curte'; ?></span>
					<h2 class="lat-h"><?php echo esc_html( $term->name ); ?></h2>
					<?php if ( LAT_Helpers::cat_meta( $term->term_id )['intro'] ) : ?>
						<p class="lat-lead"><?php echo esc_html( LAT_Helpers::cat_meta( $term->term_id )['intro'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $cards ) : ?>
				<p class="lat-empty"><?php echo $archive ? 'Nu există încă evenimente trecute.' : 'Momentan nu sunt evenimente programate' . ( $term ? ' la ' . esc_html( mb_strtolower( $term->name ) ) : '' ) . '. Reveniți curând sau urmăriți-ne pe Facebook.'; ?></p>
			<?php else : ?>
				<?php if ( ! $archive && ( 'da' === $atts['calendar'] || ( 'da' === $atts['filtre'] && count( $cats ) > 1 && ! $category ) ) ) : ?>
					<div class="lat-top">
						<?php if ( 'da' === $atts['calendar'] ) : ?>
							<div class="lat-cal" aria-label="Calendar evenimente"></div>
						<?php endif; ?>
						<?php if ( 'da' === $atts['filtre'] && count( $cats ) > 1 && ! $category ) : ?>
							<div class="lat-chips" role="group" aria-label="Filtrează după categorie">
								<button type="button" class="lat-chip is-on" data-cat="">Toate</button>
								<?php foreach ( LAT_Helpers::categories() as $t ) : ?>
									<?php if ( isset( $cats[ $t->slug ] ) ) : ?>
										<button type="button" class="lat-chip" data-cat="<?php echo esc_attr( $t->slug ); ?>" style="--lat-c:<?php echo esc_attr( LAT_Helpers::cat_meta( $t->term_id )['color'] ); ?>"><?php echo esc_html( $t->name ); ?></button>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="lat-list">
					<?php
					$current_month = '';
					foreach ( $cards as $c ) :
						$month = substr( $c['meta']['date'], 0, 7 );
						if ( $month !== $current_month ) :
							$current_month = $month;
							?>
							<h3 class="lat-month" data-month="<?php echo esc_attr( $month ); ?>"><?php echo esc_html( ucfirst( LAT_Helpers::month( (int) substr( $month, 5, 2 ) ) ) . ' ' . substr( $month, 0, 4 ) ); ?></h3>
						<?php endif; ?>
						<?php self::card( $c['id'], $c['meta'], $archive ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_next( $ids, $atts ) {
		if ( ! $ids ) {
			return '';
		}
		ob_start();
		echo '<div class="lat-app lat-app--next"' . self::app_attrs() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $atts['titlu'] ) {
			echo '<h2 class="lat-h">' . esc_html( $atts['titlu'] ) . '</h2>';
		}
		echo '<div class="lat-next">';
		foreach ( $ids as $id ) {
			$m     = LAT_Helpers::ws_meta( $id );
			$ts    = strtotime( $m['date'] . ' 12:00' );
			$state = LAT_Helpers::ws_state( $id );
			$url   = LAT_Helpers::ws_url( $id );
			self::collect_schema( $id, $m );
			?>
			<a class="lat-next-item" href="<?php echo esc_url( $url ); ?>" style="--lat-c:<?php echo esc_attr( $m['cat_color'] ); ?>">
				<span class="lat-badge-date"><span class="lat-badge-day"><?php echo esc_html( gmdate( 'j', $ts ) ); ?></span><span class="lat-badge-mon"><?php echo esc_html( LAT_Helpers::month_short( (int) gmdate( 'n', $ts ) ) ); ?></span></span>
				<span class="lat-next-body">
					<span class="lat-kicker"><?php echo esc_html( $m['cat_name'] ); ?></span>
					<span class="lat-next-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
					<span class="lat-next-meta"><?php echo esc_html( $m['start'] . ' · ' . self::places_text( $state, LAT_Helpers::places_left( $id ) ) . ' · ' . LAT_Helpers::price_label( $m ) ); ?></span>
				</span>
			</a>
			<?php
		}
		echo '</div>';
		if ( $atts['link'] ) {
			echo '<p class="lat-more"><a class="lat-btn lat-btn--ghost" href="' . esc_url( $atts['link'] ) . '">Toate evenimentele</a></p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------- Single card ---------- */

	public static function shortcode_single( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'lipovenesc_eveniment' );
		$id   = absint( $atts['id'] );
		if ( ! $id || LAT_Helpers::CPT_WS !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		self::enqueue();
		ob_start();
		echo '<div class="lat-app lat-app--single"' . self::app_attrs() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card( $id, LAT_Helpers::ws_meta( $id ), false, true );
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * The event's own page: the content is the description; we add the booking card under it.
	 */
	public static function single_content( $content ) {
		if ( ! is_singular( LAT_Helpers::CPT_WS ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = get_the_ID();
		self::enqueue();
		$m = LAT_Helpers::ws_meta( $id );
		ob_start();
		echo '<div class="lat-app lat-app--page"' . self::app_attrs() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card( $id, $m, false, true, true );
		echo '</div>';
		$card = ob_get_clean();
		// On the single page the card carries the full description, so avoid printing it twice.
		return $card;
	}

	public static function places_text( $state, $left ) {
		if ( 'cancelled' === $state ) {
			return 'Anulat';
		}
		if ( 'past' === $state ) {
			return 'A avut loc';
		}
		if ( 'closed' === $state ) {
			return 'Înscrieri închise';
		}
		if ( 'full' === $state || $left < 1 ) {
			return 'Complet';
		}
		if ( $left <= 3 ) {
			return 1 === $left ? 'Ultimul loc' : 'Ultimele ' . $left . ' locuri';
		}
		return $left . ' locuri libere';
	}

	public static function card( $id, $m, $archive = false, $expanded = false, $page = false ) {
		$state  = LAT_Helpers::ws_state( $id );
		$left   = LAT_Helpers::places_left( $id );
		$ts     = strtotime( $m['date'] . ' 12:00' );
		$post   = get_post( $id );
		// Pe card, rezumatul apare doar dacă e scris explicit (Excerpt); descrierea stă strânsă sub „Detalii”.
		$intro  = has_excerpt( $id ) ? get_the_excerpt( $id ) : '';
		$image  = get_the_post_thumbnail_url( $id, $page ? 'large' : 'medium_large' );
		$url    = LAT_Helpers::ws_url( $id );
		$buy    = 'buy' === $m['mode_eff'];
		$cta    = $m['cta'] ? $m['cta'] : ( $buy ? 'Cumpără bilet' : 'Rezervă' );
		$minmsg = $m['min'] > 0 && ! LAT_Helpers::min_reached( $id ) && in_array( $state, array( 'open', 'full' ), true ) ? 'Se confirmă la ' . (int) $m['min'] . ' participanți (' . (int) LAT_Helpers::people_confirmed( $id ) . ' înscriși)' : '';
		$can    = ! $archive && ( 'open' === $state || ( 'full' === $state && LAT_Helpers::get( 'waitlist' ) ) );
		$when   = LAT_Helpers::date_label( $m['date'] ) . ' · ' . $m['start'] . '–' . $m['end'] . ( $m['location'] ? ' · ' . $m['location'] : '' );
		$taken  = (int) $m['capacity'] - $left;
		$pct    = (int) min( 100, round( $taken / max( 1, (int) $m['capacity'] ) * 100 ) );
		self::collect_schema( $id, $m );
		?>
		<article class="lat-card lat-card--<?php echo esc_attr( $state ); ?><?php echo $expanded ? ' is-expanded' : ''; ?><?php echo $page ? ' lat-card--page' : ''; ?><?php echo $image ? ' has-img' : ''; ?>" id="eveniment-<?php echo (int) $id; ?>"
			style="--lat-c:<?php echo esc_attr( $m['cat_color'] ); ?>"
			data-id="<?php echo (int) $id; ?>"
			data-date="<?php echo esc_attr( $m['date'] ); ?>"
			data-cats="<?php echo esc_attr( $m['cat_slug'] ); ?>"
			data-state="<?php echo esc_attr( $state ); ?>"
			data-left="<?php echo (int) $left; ?>"
			data-capacity="<?php echo (int) $m['capacity']; ?>"
			data-count="<?php echo esc_attr( $m['count'] ); ?>"
			data-max="<?php echo (int) $m['max_per']; ?>"
			data-mode="<?php echo esc_attr( $m['mode_eff'] ); ?>"
			data-free="<?php echo (int) $m['free']; ?>"
			data-price-adult="<?php echo esc_attr( $m['price_adult'] ); ?>"
			data-price-child="<?php echo esc_attr( $m['price_child'] ); ?>"
			data-cta="<?php echo esc_attr( $cta ); ?>"
			data-title="<?php echo esc_attr( get_the_title( $id ) ); ?>"
			data-when="<?php echo esc_attr( $when ); ?>">
			<div class="lat-card-side">
				<div class="lat-badge-date" aria-hidden="true">
					<span class="lat-badge-day"><?php echo esc_html( gmdate( 'j', $ts ) ); ?></span>
					<span class="lat-badge-mon"><?php echo esc_html( LAT_Helpers::month_short( (int) gmdate( 'n', $ts ) ) ); ?></span>
					<span class="lat-badge-time"><?php echo esc_html( $m['start'] ); ?></span>
				</div>
			</div>
			<?php if ( $image ) : ?>
				<?php if ( $url && ! $page ) : ?><a class="lat-img-link" href="<?php echo esc_url( $url ); ?>"><?php endif; ?>
				<img class="lat-img" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( get_the_title( $id ) . ( $m['cat_name'] ? ' – ' . $m['cat_name'] : '' ) . ' la Ferma Lipovenesc' ); ?>" loading="lazy">
				<?php if ( $url && ! $page ) : ?></a><?php endif; ?>
			<?php endif; ?>
			<div class="lat-body">
				<div class="lat-head">
					<div>
						<?php if ( $m['cat_name'] ) : ?><span class="lat-kicker"><?php echo esc_html( $m['cat_name'] ); ?></span><?php endif; ?>
						<?php if ( $page ) : ?>
							<?php // The theme already prints the title as H1 on the event page. ?>
						<?php else : ?>
							<h4 class="lat-title"><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php endif; ?><?php echo esc_html( get_the_title( $id ) ); ?><?php if ( $url ) : ?></a><?php endif; ?></h4>
						<?php endif; ?>
					</div>
					<span class="lat-price"><?php echo esc_html( LAT_Helpers::price_label( $m ) ); ?></span>
				</div>
				<?php if ( $page ) : ?>
					<p class="lat-when"><?php echo esc_html( $when ); ?></p>
					<p class="lat-tags">
						<span class="lat-state lat-state--<?php echo esc_attr( $state ); ?>"><span class="lat-dot"></span><span class="lat-places-text"><?php echo esc_html( self::places_text( $state, $left ) ); ?></span></span>
						<?php if ( $m['ages'] ) : ?><span class="lat-tag"><?php echo esc_html( $m['ages'] ); ?></span><?php endif; ?>
						<?php if ( $minmsg ) : ?><span class="lat-tag lat-tag--min"><?php echo esc_html( $minmsg ); ?></span><?php endif; ?>
					</p>
					<?php if ( trim( $post->post_content ) ) : ?><div class="lat-content"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div><?php endif; ?>
					<?php if ( $m['bring'] ) : ?><p class="lat-bring"><strong>Ce să aduceți:</strong> <?php echo esc_html( $m['bring'] ); ?></p><?php endif; ?>
					<div class="lat-foot">
						<div class="lat-places">
							<span class="lat-bar"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
							<span class="lat-places-note"><?php echo esc_html( $taken . ' din ' . (int) $m['capacity'] . ' locuri ocupate' ); ?></span>
						</div>
						<?php if ( $can ) : ?>
							<button type="button" class="lat-btn lat-open"><?php echo esc_html( 'open' === $state ? $cta : 'Listă de așteptare' ); ?></button>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p class="lat-meta">
						<span class="lat-time"><?php echo esc_html( $m['start'] . '–' . $m['end'] ); ?></span>
						<span class="lat-state lat-state--<?php echo esc_attr( $state ); ?>"><span class="lat-dot"></span><span class="lat-places-text"><?php echo esc_html( self::places_text( $state, $left ) ); ?></span></span>
						<?php if ( $m['ages'] ) : ?><span class="lat-tag"><?php echo esc_html( $m['ages'] ); ?></span><?php endif; ?>
					</p>
					<?php if ( $intro ) : ?>
						<p class="lat-intro"><?php echo esc_html( $intro ); ?></p>
					<?php endif; ?>
					<div class="lat-foot">
						<button type="button" class="lat-more-toggle" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="lat-more-<?php echo (int) $id; ?>">Detalii</button>
						<?php if ( $can ) : ?>
							<button type="button" class="lat-btn lat-open"><?php echo esc_html( 'open' === $state ? $cta : 'Listă de așteptare' ); ?></button>
						<?php endif; ?>
					</div>
					<div class="lat-more" id="lat-more-<?php echo (int) $id; ?>"<?php echo $expanded ? '' : ' hidden'; ?>>
						<p class="lat-when"><?php echo esc_html( $when ); ?></p>
						<?php if ( trim( $post->post_content ) ) : ?><div class="lat-content"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div><?php endif; ?>
						<?php if ( $m['bring'] ) : ?><p class="lat-bring"><strong>Ce să aduceți:</strong> <?php echo esc_html( $m['bring'] ); ?></p><?php endif; ?>
						<?php if ( $minmsg ) : ?><p class="lat-min-note"><?php echo esc_html( $minmsg ); ?></p><?php endif; ?>
						<div class="lat-places">
							<span class="lat-bar"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
							<span class="lat-places-note"><?php echo esc_html( $taken . ' din ' . (int) $m['capacity'] . ' locuri ocupate' ); ?></span>
						</div>
						<?php if ( $url ) : ?><p class="lat-more-link"><a href="<?php echo esc_url( $url ); ?>">Pagina evenimentului →</a></p><?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="lat-form-slot"></div>
				<?php if ( $page ) : ?>
					<p class="lat-share"><span>Distribuie:</span>
						<a href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ); ?>" target="_blank" rel="noopener">Facebook</a>
						<a href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( get_the_title( $id ) . ' ' . $url ) ); ?>" target="_blank" rel="noopener">WhatsApp</a>
						<button type="button" class="lat-copy" data-url="<?php echo esc_attr( $url ); ?>">Copiază linkul</button>
					</p>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/* ---------- Structured data ---------- */

	private static function collect_schema( $id, $m ) {
		if ( isset( self::$schema[ $id ] ) || ! $m['date'] ) {
			return;
		}
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( $m['date'] . ' ' . $m['start'], $tz ) )->format( DATE_ATOM );
		$end   = ( new DateTimeImmutable( $m['date'] . ' ' . $m['end'], $tz ) )->format( DATE_ATOM );
		$state = LAT_Helpers::ws_state( $id );
		$url   = LAT_Helpers::ws_url( $id );
		$image = get_the_post_thumbnail_url( $id, 'large' );
		$pa    = LAT_Helpers::num( $m['price_adult'] );
		$pc    = LAT_Helpers::num( $m['price_child'] );
		$low   = min( array_filter( array( $pa, $pc ), 'is_numeric' ) ?: array( 0 ) );
		$event = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => get_the_title( $id ),
			'startDate'           => $start,
			'endDate'             => $end,
			'eventStatus'         => 'cancelled' === $state ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'description'         => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $id ) ), 60 ),
			'location'            => array(
				'@type'   => 'Place',
				'name'    => $m['location'] ? $m['location'] : 'Ferma Lipovenesc',
				'address' => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => 'Strada 1 Mai nr. 40',
					'addressLocality' => 'Jurilovca',
					'addressRegion'   => 'Tulcea',
					'postalCode'      => '827115',
					'addressCountry'  => 'RO',
				),
			),
			'organizer'           => array(
				'@type' => 'Organization',
				'name'  => 'Ferma Lipovenesc',
				'url'   => home_url( '/' ),
			),
			'offers'              => array(
				'@type'         => 'Offer',
				'price'         => (string) ( null === $low ? 0 : $low ),
				'priceCurrency' => 'RON',
				'availability'  => in_array( $state, array( 'full', 'closed', 'past', 'cancelled' ), true ) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
				'url'           => $url ? $url : home_url( '/' ),
				'validFrom'     => get_the_date( DATE_ATOM, $id ),
			),
		);
		if ( LAT_Helpers::price_missing( $m ) ) {
			unset( $event['offers'] ); // Preț neanunțat încă: fără ofertă cu 0 lei în date structurate.
		}
		if ( $url ) {
			$event['url'] = $url;
		}
		if ( $image ) {
			$event['image'] = array( $image );
		}
		if ( $m['cat_name'] ) {
			$event['keywords'] = $m['cat_name'];
		}
		self::$schema[ $id ] = $event;
	}

	public static function print_schema() {
		if ( ! self::$schema ) {
			return;
		}
		$data = count( self::$schema ) === 1 ? reset( self::$schema ) : array_values( self::$schema );
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
