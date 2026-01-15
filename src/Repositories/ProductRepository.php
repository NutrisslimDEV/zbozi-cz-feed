<?php
namespace ZboziCZ\Repositories;

class ProductRepository {
    /**
     * Fetch and parse Google Sheet CSV.
     * Returns array keyed by product ID with row data.
     */
    private function fetch_sheet_rows(): array {
        $cached = get_transient( ZBOZI_CZ_SHEET_TRANSIENT_KEY );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $opts = get_option( \ZboziCZ\Controllers\AdminController::OPTION_KEY, [] );
        $url  = trim( (string) ( $opts['sheet_csv_url'] ?? '' ) );
        
        if ( empty( $url ) ) {
            return [];
        }

        // Add cache buster to mitigate Google Sheets publish lag
        $url_busted = add_query_arg( '_cb', (string) time(), $url );
        
        $response = wp_remote_get( $url_busted, [
            'timeout' => 20,
            'headers' => [
                'Cache-Control' => 'no-cache',
                'Pragma'        => 'no-cache',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $csv = wp_remote_retrieve_body( $response );
        if ( empty( $csv ) ) {
            return [];
        }

        $rows = $this->parse_csv_assoc( $csv );
        $out  = [];

        foreach ( $rows as $row ) {
            // Look for ID column (case-insensitive)
            $pid_raw = '';
            foreach ( [ 'ID', 'id', 'Product ID', 'product id', 'PRODUCT ID' ] as $key ) {
                if ( isset( $row[ $key ] ) && trim( (string) $row[ $key ] ) !== '' ) {
                    $pid_raw = trim( (string) $row[ $key ] );
                    break;
                }
            }

            // Sanitize: strip tags/entities and keep digits only
            $pid_digits = preg_replace( '/\D+/', '', wp_strip_all_tags( (string) $pid_raw ) );
            if ( $pid_digits === '' || ! ctype_digit( $pid_digits ) ) {
                continue;
            }

            $pid = (string) (int) $pid_digits;
            if ( $pid === '0' ) {
                continue;
            }

            $out[ $pid ] = $row;
        }

        if ( ! empty( $out ) ) {
            set_transient( ZBOZI_CZ_SHEET_TRANSIENT_KEY, $out, ZBOZI_CZ_SHEET_TRANSIENT_TTL );
        }

        return $out;
    }

    /**
     * Parse CSV string into array of associative rows keyed by header names.
     */
    private function parse_csv_assoc( string $csv_string ): array {
        $temp_file = tmpfile();
        if ( $temp_file === false ) {
            return [];
        }

        fwrite( $temp_file, $csv_string );
        rewind( $temp_file );

        $headers = [];
        $out     = [];
        $row_num = 0;

        while ( ( $row = fgetcsv( $temp_file ) ) !== false ) {
            if ( $row_num === 0 ) {
                $headers = $row;
                $row_num++;
                continue;
            }

            // Skip completely empty rows
            if ( count( array_filter( $row, function( $val ) { return trim( $val ) !== ''; } ) ) === 0 ) {
                continue;
            }

            $assoc = [];
            foreach ( $headers as $idx => $key ) {
                $assoc[ $key ] = isset( $row[ $idx ] ) ? $row[ $idx ] : '';
            }
            $out[] = $assoc;
            $row_num++;
        }

        fclose( $temp_file );
        return $out;
    }

    /**
     * Case/space/underscore-insensitive column access.
     */
    private function row_get( array $row, array $candidates ): string {
        if ( empty( $row ) ) {
            return '';
        }

        $norm = function( $s ) {
            $s = strtolower( (string) $s );
            $s = str_replace( [ "\xC2\xA0", "\x00\xA0" ], ' ', $s ); // non-breaking spaces
            $s = preg_replace( '/\s+|_+/', ' ', $s );
            return trim( $s );
        };

        // Build map of normalized header -> original header
        $map = [];
        foreach ( $row as $k => $_ ) {
            $map[ $norm( $k ) ] = $k;
        }

        foreach ( $candidates as $want ) {
            $key = $norm( $want );
            if ( isset( $map[ $key ] ) ) {
                $val = $row[ $map[ $key ] ];
                return is_string( $val ) ? $val : (string) $val;
            }
        }

        return '';
    }

    /**
     * Generator for products ordered by Google Sheet row position.
     * Only includes products where "Show in feed" = yes.
     */
    public function all_products_generator(): \Generator {
        $sheet_rows = $this->fetch_sheet_rows();

        // If no sheet configured, return empty (or could fall back to old behavior)
        if ( empty( $sheet_rows ) ) {
            return;
        }

        // Iterate through sheet rows in order
        foreach ( $sheet_rows as $pid => $row ) {
            // Check "Show in feed" column (case-insensitive)
            $show_flag = $this->row_get( $row, [ 'Show in feed', 'show in feed', 'show_in_feed', 'Show in Feed' ] );
            $show_flag = strtolower( trim( (string) $show_flag ) );
            
            if ( $show_flag !== 'yes' ) {
                continue;
            }

            $product = wc_get_product( (int) $pid );
            if ( $product instanceof \WC_Product ) {
                // Yield both product and sheet row data
                yield [ 'product' => $product, 'sheet_row' => $row ];
            }
        }
    }
}
