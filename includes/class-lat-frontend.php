<?php
/**
 * Shortcode [lipovenesc_ateliere]: server-rendered calendar (good for SEO), enhanced by JS.
 *
 * @package Lipovenesc_Ateliere
 */

defined( 'ABSPATH' ) || exit;

class LAT_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'lipovenesc_ateliere', array( __CLASS__, 'shortcode' ) );
	}

	public static function register() {
		wp_register_style( 'lat-front', LAT_URL . 'assets/css/front.css', array(), LAT_VERSION );
		wp_register_script( 'lat-front', LAT_URL . 'assets/js/front.js', array(), LAT_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'categorie' => '',
				'limit'     => -1,
			),
			$atts,
			'lipovenesc_ateliere'
		);

		wp_enqueue_style( 'lat-front' );
		$accent = sanitize_hex_color( (string) LAT_Helpers::get( 'accent' ) );
		if ( $accent ) {
			wp_add_inline_style( 'lat-front', '.lat-app{--lat-accent:' . $accent . '}' );
		}
		wp_enqueue_script( 'lat-front' );

		$ids = LAT_Helpers::upcoming_ids( (int) $atts['limit'] );
		if ( $atts['categorie'] ) {
			$slug = sanitize_title( $atts['categorie'] );
			$ids  = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $slug ) {
						return has_term( $slug, LAT_Helpers::TAX, $id );
					}
				)
			);
		}

		$cards = array();
		$cats  = array();
		foreach ( $ids as $id ) {
			$terms = get_the_terms( $id, LAT_Helpers::TAX );
			$slugs = array();
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$slugs[]           = $t->slug;
				$cats[ $t->slug ] = $t->name;
			}
			$cards[] = array(
				'id'    => $id,
				'meta'  => LAT_Helpers::ws_meta( $id ),
				'slugs' => $slugs,
			);
		}

		$calendar = array();
		foreach ( $cards as $c ) {
			$calendar[] = array(
				'id'    => $c['id'],
				'date'  => $c['meta']['date'],
				'title' => get_the_title( $c['id'] ),
			);
		}

		ob_start();
		?>
		<div class="lat-app"
			data-api="<?php echo esc_url( add_query_arg( 'rest_route', '/' . LAT_Rest::NS, home_url( '/' ) ) ); ?>"
			data-calendar="<?php echo esc_attr( wp_json_encode( $calendar ) ); ?>"
			data-gdpr="<?php echo esc_attr( wp_kses_post( LAT_Helpers::get( 'gdpr_text' ) ) ); ?>"
			data-waitlist="<?php echo LAT_Helpers::get( 'waitlist' ) ? '1' : '0'; ?>">

			<?php if ( ! $cards ) : ?>
				<p class="lat-empty">Momentan nu sunt ateliere programate. Reveniți curând!</p>
			<?php else : ?>
				<div class="lat-top">
					<div class="lat-cal" aria-label="Calendar ateliere"></div>
					<?php if ( count( $cats ) > 1 && ! $atts['categorie'] ) : ?>
						<div class="lat-chips" role="group" aria-label="Filtrează după categorie">
							<button type="button" class="lat-chip is-on" data-cat="">Toate</button>
							<?php foreach ( $cats as $slug => $name ) : ?>
								<button type="button" class="lat-chip" data-cat="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="lat-list">
					<?php
					$current_month = '';
					foreach ( $cards as $c ) :
						$id    = $c['id'];
						$m     = $c['meta'];
						$month = substr( $m['date'], 0, 7 );
						if ( $month !== $current_month ) :
							$current_month = $month;
							?>
							<h3 class="lat-month" data-month="<?php echo esc_attr( $month ); ?>"><?php echo esc_html( ucfirst( LAT_Helpers::month( (int) substr( $month, 5, 2 ) ) ) . ' ' . substr( $month, 0, 4 ) ); ?></h3>
						<?php endif; ?>
						<?php self::card( $id, $m, $c['slugs'] ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function card( $id, $m, $slugs ) {
		$state  = LAT_Helpers::ws_state( $id );
		$left   = LAT_Helpers::places_left( $id );
		$ts     = strtotime( $m['date'] . ' 12:00' );
		$months = array( '', 'IAN', 'FEB', 'MAR', 'APR', 'MAI', 'IUN', 'IUL', 'AUG', 'SEP', 'OCT', 'NOI', 'DEC' );
		$post   = get_post( $id );
		$intro  = has_excerpt( $id ) ? get_the_excerpt( $id ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );
		$image  = get_the_post_thumbnail_url( $id, 'medium_large' );
		?>
		<article class="lat-card lat-card--<?php echo esc_attr( $state ); ?>" id="atelier-<?php echo (int) $id; ?>"
			data-id="<?php echo (int) $id; ?>"
			data-date="<?php echo esc_attr( $m['date'] ); ?>"
			data-cats="<?php echo esc_attr( implode( ' ', $slugs ) ); ?>"
			data-state="<?php echo esc_attr( $state ); ?>"
			data-left="<?php echo (int) $left; ?>"
			data-capacity="<?php echo (int) $m['capacity']; ?>"
			data-count="<?php echo esc_attr( $m['count'] ); ?>"
			data-max="<?php echo (int) $m['max_per']; ?>"
			data-price-adult="<?php echo esc_attr( $m['price_adult'] ); ?>"
			data-price-child="<?php echo esc_attr( $m['price_child'] ); ?>"
			data-title="<?php echo esc_attr( get_the_title( $id ) ); ?>">
			<div class="lat-badge-date" aria-hidden="true">
				<span class="lat-badge-day"><?php echo esc_html( gmdate( 'j', $ts ) ); ?></span>
				<span class="lat-badge-mon"><?php echo esc_html( $months[ (int) gmdate( 'n', $ts ) ] ); ?></span>
			</div>
			<?php if ( $image ) : ?>
				<img class="lat-img" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<div class="lat-body">
				<h4 class="lat-title"><?php echo esc_html( get_the_title( $id ) ); ?></h4>
				<p class="lat-when"><?php echo esc_html( LAT_Helpers::date_label( $m['date'] ) . ' · ' . $m['start'] . '–' . $m['end'] . ( $m['location'] ? ' · ' . $m['location'] : '' ) ); ?></p>
				<?php if ( $intro ) : ?>
					<p class="lat-intro"><?php echo esc_html( $intro ); ?></p>
				<?php endif; ?>
				<p class="lat-tags">
					<span class="lat-tag lat-tag--price"><?php echo esc_html( LAT_Helpers::price_label( $m ) ); ?></span>
					<?php if ( $m['ages'] ) : ?>
						<span class="lat-tag"><?php echo esc_html( $m['ages'] ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( trim( $post->post_content ) || $m['bring'] ) : ?>
					<details class="lat-details">
						<summary>Detalii</summary>
						<div class="lat-content"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div>
						<?php if ( $m['bring'] ) : ?>
							<p><strong>Ce să aduceți:</strong> <?php echo esc_html( $m['bring'] ); ?></p>
						<?php endif; ?>
					</details>
				<?php endif; ?>
				<div class="lat-foot">
					<div class="lat-places">
						<span class="lat-places-text"><?php echo esc_html( self::places_text( $state, $left ) ); ?></span>
						<span class="lat-bar"><span style="width:<?php echo (int) min( 100, round( ( (int) $m['capacity'] - $left ) / max( 1, (int) $m['capacity'] ) * 100 ) ); ?>%"></span></span>
					</div>
					<?php if ( 'open' === $state || ( 'full' === $state && LAT_Helpers::get( 'waitlist' ) ) ) : ?>
						<button type="button" class="lat-btn lat-open"><?php echo 'open' === $state ? 'Înscrie-te' : 'Listă de așteptare'; ?></button>
					<?php endif; ?>
				</div>
				<div class="lat-form-slot"></div>
			</div>
		</article>
		<?php
	}

	public static function places_text( $state, $left ) {
		if ( 'closed' === $state ) {
			return 'Înscrieri închise';
		}
		if ( 'full' === $state || $left < 1 ) {
			return 'Complet';
		}
		return 1 === $left ? 'Ultimul loc liber' : $left . ' locuri libere';
	}
}
