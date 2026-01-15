<?php
namespace ZboziCZ\Services;

use ZboziCZ\Repositories\ProductRepository;
use ZboziCZ\Utils\XmlHelper;

class FeedBuilder {
    private array $opts;

    public function __construct() {
        $this->opts = get_option( \ZboziCZ\Controllers\AdminController::OPTION_KEY, [] );
    }

    private function feed_filename(): string {
        $manual = trim( (string) ( $this->opts['feed_filename'] ?? '' ) );
        if ( $manual !== '' ) return sanitize_file_name( $manual );
        return 'sklik_cz-datasource.xml';
    }

    public function build_and_save() {
        $upload_dir = wp_get_upload_dir();
        $file = trailingslashit( $upload_dir['basedir'] ) . $this->feed_filename();

        $x = new \XMLWriter();
        if ( ! $x->openURI( $file ) ) {
            return new \WP_Error( 'file_open_failed', 'Failed to open XML file for writing.' );
        }
        $x->startDocument( '1.0', 'UTF-8' );
        $x->setIndent( true );
        $x->setIndentString( '  ' );

        // Root element with Zboží.cz namespace
        $x->startElement( 'SHOP' );
        $x->writeAttribute( 'xmlns', 'http://www.zbozi.cz/ns/offer/1.0' );

        $helper = new XmlHelper( $x );

        $repo = new ProductRepository();
        $count = 0;

        foreach ( $repo->all_products_generator() as $p ) {
            if ( $this->write_item( $x, $helper, $p ) ) { $count++; }
        }

        $x->endElement(); // SHOP
        $x->endDocument();
        $x->flush();

        if ( ! file_exists( $file ) ) {
            return new \WP_Error( 'file_write_failed', 'Failed to write XML file.' );
        }
        return $count;
    }

    private function round_mode( float $value ): int {
        $mode = (string) ( $this->opts['decimal_rounding'] ?? 'round' );
        if ( $mode === 'ceil' )  return (int) ceil( $value );
        if ( $mode === 'floor' ) return (int) floor( $value );
        return (int) round( $value ); // default
    }

    private function price_vat_integer( \WC_Product $p ): int {
        // Base price = sale price if present, else regular
        $raw = $p->get_sale_price() !== '' ? (float) $p->get_sale_price() : (float) $p->get_regular_price();

        // If not taxable or empty, just return rounded raw
        if ( $raw <= 0 || 'taxable' !== $p->get_tax_status() ) {
            return $this->round_mode( $raw );
        }

        // Default to Czechia for Sklik feed (configurable)
        $country   = strtoupper( (string) ( $this->opts['feed_country'] ?? 'CZ' ) );
        $tax_class = $p->get_tax_class() ?: '';

        // 1) Normalize to price EXCLUDING tax regardless of store tax mode
        $price_excl = wc_get_price_excluding_tax( $p, [ 'qty' => 1, 'price' => $raw ] );

        // 2) Get CZ tax rates for this product’s tax class (stable API)
        $rates = \WC_Tax::find_rates( [
            'country'   => $country,
            'state'     => '',
            'postcode'  => '',
            'city'      => '',
            'tax_class' => $tax_class,
        ] );

        // 3) Calculate incl. VAT; if no rates found, fall back to WC’s own include-tax calc
        if ( ! empty( $rates ) ) {
            $taxes = \WC_Tax::calc_tax( $price_excl, $rates, false ); // price_excl does NOT include tax
            $incl  = $price_excl + array_sum( $taxes );
        } else {
            $incl = wc_get_price_including_tax( $p, [ 'qty' => 1, 'price' => $raw ] );
        }

        // 4) Integer rounding for Sklik PRICE_VAT
        return $this->round_mode( $incl );
    }


    private function category_text(\WC_Product $p): string {
        $sep = (string) ( $this->opts['category_separator'] ?? ' | ' );

        $terms = get_the_terms( $p->get_id(), 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) return '';

        // Prefer deepest category chain
        $deepest = null; $depth = -1;
        foreach ( $terms as $t ) {
            $d = count( get_ancestors( $t->term_id, 'product_cat' ) );
            if ( $d > $depth ) { $depth = $d; $deepest = $t; }
        }
        if ( ! $deepest ) return '';

        $chain_ids = array_merge( get_ancestors( $deepest->term_id, 'product_cat' ), [ $deepest->term_id ] );
        $names = [];
        foreach ( $chain_ids as $cid ) {
            $term = get_term( $cid, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $names[] = $term->name;
            }
        }
        return implode( $sep, $names );
    }

    private function main_image(\WC_Product $p): string {
        $img_id = $p->get_image_id();
        if ( ! $img_id && $p->is_type('variation') ) {
            $parent = wc_get_product( $p->get_parent_id() );
            if ( $parent ) $img_id = $parent->get_image_id();
        }
        if ( $img_id ) {
            $url = wp_get_attachment_image_url( $img_id, 'full' );
            return $url ?: '';
        }
        return '';
    }

    private function item_id(\WC_Product $p): string {
        $sku = $p->get_sku();
        if ( $p->is_type('variation') ) {
            return $sku ? ($sku . '-' . $p->get_id()) : ('VAR-' . $p->get_id());
        }
        return $sku ?: (string) $p->get_id();
    }

    private function description(\WC_Product $p): string {
        $pid = $p->get_id();
        // Prefer SEO field if present (ACF), then short description, then excerpt/content
        $seo = function_exists('get_field') ? ( get_field('seo_description', $pid) ?: '' ) : '';
        $src = $seo !== '' ? $seo : ( $p->get_short_description() ?: get_post_field( 'post_excerpt', $pid, 'raw' ) ?: $p->get_description() );
        return trim( wp_strip_all_tags( html_entity_decode( (string) $src ) ) );
    }

    private function write_item(\XMLWriter $x, XmlHelper $h, \WC_Product $p): bool {
        // Required fields per your sample
        $name   = $p->get_name();
        $url    = get_permalink( $p->get_id() );
        $price  = $this->price_vat_integer( $p );  // PRICE_VAT (CZK, incl VAT) as integer
        $img    = $this->main_image( $p );
        $itemId = $this->item_id( $p );
        $catTxt = $this->category_text( $p );

        if ( ! $name || ! $url || ! $img || ! $itemId ) {
            return false; // skip invalid
        }

        $delivery_days = (int) ( $this->opts['delivery_date'] ?? 2 );

        $x->startElement( 'SHOPITEM' );
            $h->element_text( 'PRODUCTNAME', $name );
            $h->element_text( 'DESCRIPTION', $this->description( $p ) );
            $h->element_text( 'URL', $url );
            $h->element_text( 'PRICE_VAT', (string) $price );
            $h->element_text( 'DELIVERY_DATE', (string) $delivery_days );
            $h->element_text( 'IMGURL', $img );
            $h->element_text( 'ITEM_ID', $itemId );
            $h->element_text( 'CATEGORYTEXT', $catTxt );
        $x->endElement(); // SHOPITEM

        return true;
    }
}
