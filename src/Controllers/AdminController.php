<?php
namespace ZboziCZ\Controllers;

use ZboziCZ\Services\FeedBuilder;

class AdminController {
    const OPTION_KEY = 'zbozi_cz_options';

    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_zbozi_cz_generate_now', [ $this, 'generate_now' ] );
    }

    public function register_menu() : void {
        add_submenu_page(
            'woocommerce',
            __( 'Zboží.cz XML Feed', 'zbozi-cz' ),
            __( 'Zboží.cz XML Feed', 'zbozi-cz' ),
            'manage_woocommerce',
            'zbozi-cz-feed',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() : void {
        register_setting( self::OPTION_KEY, self::OPTION_KEY, [ $this, 'sanitize' ] );

        add_settings_section( 'general', __( 'General', 'zbozi-cz' ), '__return_false', self::OPTION_KEY );
        add_settings_field( 'delivery_date', __( 'DELIVERY_DATE (days)', 'zbozi-cz' ),
            [ $this, 'field_number' ], self::OPTION_KEY, 'general', [ 'id' => 'delivery_date', 'placeholder' => '2' ] );
        add_settings_field( 'feed_filename', __( 'Feed filename (optional override)', 'zbozi-cz' ),
            [ $this, 'field_text' ], self::OPTION_KEY, 'general', [ 'id' => 'feed_filename', 'placeholder' => 'sklik_cz-datasource.xml' ] );
        add_settings_field( 'category_separator', __( 'CATEGORYTEXT separator', 'zbozi-cz' ),
            [ $this, 'field_text' ], self::OPTION_KEY, 'general', [ 'id' => 'category_separator', 'placeholder' => ' | ' ] );
        add_settings_field( 'decimal_rounding', __( 'PRICE_VAT rounding', 'zbozi-cz' ),
            [ $this, 'field_select' ], self::OPTION_KEY, 'general',
            [ 'id' => 'decimal_rounding', 'options' => [ 'round' => 'Round to integer (recommended)', 'ceil' => 'Ceil', 'floor' => 'Floor' ] ] );
        
        add_settings_section( 'sheet', __( 'Google Sheet', 'zbozi-cz' ), function() {
            echo '<p>' . esc_html__( 'Publish your Google Sheet tab as CSV (File → Share → Publish to web → CSV) and paste the URL here. The sheet must have columns: ID, SKU, and "Show in feed" (yes/no). Products will be ordered by their position in the sheet.', 'zbozi-cz' ) . '</p>';
        }, self::OPTION_KEY );
        add_settings_field( 'sheet_csv_url', __( 'Google Sheet CSV URL', 'zbozi-cz' ),
            [ $this, 'field_text' ], self::OPTION_KEY, 'sheet', [ 'id' => 'sheet_csv_url', 'placeholder' => 'https://docs.google.com/spreadsheets/d/…/pub?gid=…&single=true&output=csv' ] );
    }

    public function sanitize( $input ) {
        $out = get_option( self::OPTION_KEY, [] );
        if ( isset( $input['delivery_date'] ) ) { $out['delivery_date'] = (int) $input['delivery_date']; }
        if ( isset( $input['feed_filename'] ) ) { $out['feed_filename'] = sanitize_file_name( (string) $input['feed_filename'] ); }
        if ( isset( $input['category_separator'] ) ) { $out['category_separator'] = sanitize_text_field( (string) $input['category_separator'] ); }
        if ( isset( $input['decimal_rounding'] ) ) {
            $val = (string) $input['decimal_rounding'];
            $out['decimal_rounding'] = in_array( $val, [ 'round','ceil','floor' ], true ) ? $val : 'round';
        }
        if ( isset( $input['sheet_csv_url'] ) ) {
            $url = esc_url_raw( trim( (string) $input['sheet_csv_url'] ) );
            $out['sheet_csv_url'] = $url;
            // Clear cache when URL changes
            delete_transient( ZBOZI_CZ_SHEET_TRANSIENT_KEY );
        }
        return $out;
    }

    private function get_option( string $id, $default = '' ) {
        $opts = get_option( self::OPTION_KEY, [] );
        return $opts[ $id ] ?? $default;
    }

    private function feed_filename() : string {
        $manual = trim( (string) $this->get_option( 'feed_filename', '' ) );
        if ( $manual !== '' ) return sanitize_file_name( $manual );
        return 'sklik_cz-datasource.xml';
    }

    public function render_settings_page() : void {
        // Handle cache clear
        if ( isset( $_GET['clear_cache'] ) && $_GET['clear_cache'] === '1' && current_user_can( 'manage_woocommerce' ) ) {
            check_admin_referer( 'zbozi_cz_clear_cache' );
            delete_transient( ZBOZI_CZ_SHEET_TRANSIENT_KEY );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Cache cleared.', 'zbozi-cz' ) . '</p></div>';
        }

        $upload = wp_get_upload_dir();
        $filename  = $this->feed_filename();
        $file_path = trailingslashit( $upload['basedir'] ) . $filename;
        $file_url  = trailingslashit( $upload['baseurl'] ) . $filename;
        $exists    = file_exists( $file_path );
        $count     = (int) get_option( 'zbozi_cz_last_count', 0 );
        $updated   = get_option( 'zbozi_cz_last_generated', '-' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Zboží.cz XML Feed', 'zbozi-cz' ); ?></h1>
            <p><?php esc_html_e( 'Generates an XML feed compatible with Zboží.cz. Products are controlled via Google Sheet (ID, SKU, Show in feed columns).', 'zbozi-cz' ); ?></p>

            <table class="widefat striped" style="max-width:760px;margin-top:1em;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Feed File', 'zbozi-cz' ); ?></th>
                        <td>
                            <?php
                            echo $exists
                                ? '<a href="' . esc_url( $file_url ) . '" target="_blank">' . esc_html( $file_url ) . '</a>'
                                : esc_html__( 'Not generated yet', 'zbozi-cz' );
                            ?>
                        </td>
                    </tr>
                    <tr><th><?php esc_html_e( 'Last Generated', 'zbozi-cz' ); ?></th><td><?php echo esc_html( $updated ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Items in Last Feed', 'zbozi-cz' ); ?></th><td><?php echo esc_html( $count ); ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0;">
                <?php wp_nonce_field( 'zbozi_cz_generate' ); ?>
                <input type="hidden" name="action" value="zbozi_cz_generate_now">
                <button class="button button-primary"><?php esc_html_e( 'Generate feed now', 'zbozi-cz' ); ?></button>
            </form>

            <p>
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'clear_cache', '1' ), 'zbozi_cz_clear_cache' ) ); ?>" class="button">
                    <?php esc_html_e( 'Clear Sheet Cache', 'zbozi-cz' ); ?>
                </a>
            </p>

            <hr/>
            <form method="post" action="options.php" style="max-width:760px;">
                <?php
                    settings_fields( self::OPTION_KEY );
                    do_settings_sections( self::OPTION_KEY );
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function generate_now() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Unauthorized' ); }
        check_admin_referer( 'zbozi_cz_generate' );

        // Clear cache before generating to ensure fresh data
        delete_transient( ZBOZI_CZ_SHEET_TRANSIENT_KEY );

        $builder = new FeedBuilder();
        $count   = $builder->build_and_save(); // returns item count or WP_Error

        if ( is_wp_error( $count ) ) {
            wp_die( $count ); // show the error
        }

        update_option( 'zbozi_cz_last_count', (int) $count );
        update_option( 'zbozi_cz_last_generated', current_time( 'mysql' ) );

        wp_safe_redirect( admin_url( 'admin.php?page=zbozi-cz-feed' ) );
        exit;
    }

    // simple field renderers
    public function field_text( array $args ) : void {
        $id = esc_attr( $args['id'] );
        $val = esc_attr( $this->get_option( $id ) );
        $placeholder = isset( $args['placeholder'] ) ? esc_attr( $args['placeholder'] ) : '';
        echo '<input type="text" class="regular-text" name="' . self::OPTION_KEY . '[' . $id . ']" id="' . $id . '" value="' . $val . '" placeholder="' . $placeholder . '">';
    }
    public function field_number( array $args ) : void {
        $id = esc_attr( $args['id'] );
        $val = esc_attr( $this->get_option( $id ) );
        echo '<input type="number" class="small-text" name="' . self::OPTION_KEY . '[' . $id . ']" id="' . $id . '" value="' . $val . '">';
    }
    public function field_select( array $args ) : void {
        $id = esc_attr( $args['id'] );
        $val = $this->get_option( $id, array_key_first( $args['options'] ) );
        echo '<select name="' . self::OPTION_KEY . '[' . $id . ']" id="' . $id . '">';
        foreach ( $args['options'] as $k => $label ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
        }
        echo '</select>';
    }
}
