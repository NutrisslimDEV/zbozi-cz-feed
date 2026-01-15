<?php
namespace ZboziCZ\Repositories;

class ProductRepository {
    /**
     * Simple generator for all published products (incl. variations).
     * For very large catalogs, replace with a paginated WP_Query.
     */
    public function all_products_generator(): \Generator {
        $per_page = 250; // paginate if you like
        $page     = 1;

        while ( true ) {
            $args = [
                'status'      => 'publish',
                'limit'       => $per_page,
                'page'        => $page,
                'return'      => 'ids', // keep ids
                'type'        => [ 'simple', 'nutrisslim', 'bundle' ],
                'meta_query'  => [
                    [
                        'key'     => 'show_in_feed',
                        'value'   => '1',
                        'compare' => '=',
                    ],
                ],
            ];
            $ids = wc_get_products( $args );
            if ( empty( $ids ) ) break;

            foreach ( $ids as $id ) {
                $p = wc_get_product( $id );
                if ( $p instanceof \WC_Product ) {
                    yield $p; // always yield WC_Product
                }
            }

            if ( count( $ids ) < $per_page ) break; // last page
            $page++;
        }
    }
}
