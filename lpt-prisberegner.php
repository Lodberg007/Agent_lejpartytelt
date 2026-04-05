<?php
/**
 * Plugin Name: LPT Prisberegner
 * Plugin URI:  https://www.lejpartytelt.dk
 * Description: Interaktiv prisberegner med WooCommerce-integration til Lejpartytelt.dk. Brug shortcode [prisberegner] på en side.
 * Version:     1.5.3
 * Author:      Lejpartytelt.dk
 * Text Domain: lpt-prisberegner
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LPT_VERSION', '1.5.3' );
define( 'LPT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LPT_URL',     plugin_dir_url( __FILE__ ) );

/* ─────────────────────────────────────────────
   MAIN CLASS
───────────────────────────────────────────── */
class LPT_Prisberegner {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Shortcode + assets
        add_shortcode( 'prisberegner', [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Admin settings
        add_action( 'admin_menu',  [ $this, 'admin_menu' ] );
        add_action( 'admin_init',  [ $this, 'admin_init' ] );

        // WooCommerce integration
        add_filter( 'woocommerce_add_cart_item_data',            [ $this, 'wc_add_cart_item_data' ],        10, 2 );
        add_filter( 'woocommerce_get_item_data',                 [ $this, 'wc_get_item_data' ],             10, 2 );
        add_action( 'woocommerce_before_calculate_totals',       [ $this, 'wc_set_cart_item_price' ],       10, 1 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'wc_save_order_meta' ],         10, 4 );

        // AJAX — kurv (gammel pakke-metode bevares for calculator-shortcode)
        add_action( 'wp_ajax_lpt_add_to_cart',        [ $this, 'ajax_add_to_cart' ] );
        add_action( 'wp_ajax_nopriv_lpt_add_to_cart', [ $this, 'ajax_add_to_cart' ] );

        // AJAX — individuelle kurv-varer (chat-agent)
        add_action( 'wp_ajax_lpt_add_items_to_cart',        [ $this, 'ajax_add_items_to_cart' ] );
        add_action( 'wp_ajax_nopriv_lpt_add_items_to_cart', [ $this, 'ajax_add_items_to_cart' ] );

        // AJAX — Rentman tilgængelighed
        add_action( 'wp_ajax_lpt_check_availability',        [ $this, 'ajax_check_availability' ] );
        add_action( 'wp_ajax_nopriv_lpt_check_availability', [ $this, 'ajax_check_availability' ] );

        // AJAX — Rentman API debug (kun admin)
        add_action( 'wp_ajax_lpt_rentman_debug', [ $this, 'ajax_rentman_debug' ] );

        // AJAX — Plugin self-update (kun admin)
        add_action( 'wp_ajax_lpt_plugin_update',       [ $this, 'ajax_plugin_update' ] );
        add_action( 'wp_ajax_lpt_plugin_check_update',  [ $this, 'ajax_plugin_check_update' ] );

        // Chat shortcodes + AJAX
        add_shortcode( 'lpt-chat',      [ $this, 'shortcode_chat' ] );
        add_shortcode( 'lpt-tilbud',    [ $this, 'shortcode_tilbud' ] );
        add_shortcode( 'lpt-produkter', [ $this, 'shortcode_produkter' ] );
        add_action( 'wp_ajax_lpt_chat_message',        [ $this, 'ajax_chat_message' ] );
        add_action( 'wp_ajax_nopriv_lpt_chat_message', [ $this, 'ajax_chat_message' ] );

        // Ryd pris-cache når et produkt opdateres
        add_action( 'woocommerce_update_product', [ $this, 'clear_price_cache' ] );
        add_action( 'woocommerce_new_product',    [ $this, 'clear_price_cache' ] );

        // Forudfyld checkout-bemærkninger med opsætningsønsker fra chat
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'wc_prefill_checkout_notes' ], 10, 2 );
    }

    /* ── CHECKOUT: forudfyld bemærkningsfelt med opsætningsønsker ── */
    public function wc_prefill_checkout_notes( $value, $input ) {
        if ( $input === 'order_comments' && ! $value && function_exists( 'WC' ) && WC()->session ) {
            $notes = WC()->session->get( 'lpt_setup_notes', '' );
            if ( $notes ) return sanitize_textarea_field( $notes );
        }
        return $value;
    }

    /* ── ASSETS ── */
    public function enqueue_assets() {
        wp_enqueue_style(
            'lpt-prisberegner',
            LPT_URL . 'assets/calculator.css',
            [], LPT_VERSION
        );
        wp_enqueue_script(
            'lpt-prisberegner',
            LPT_URL . 'assets/calculator.js',
            [ 'jquery' ], LPT_VERSION, true
        );

        // Chat assets
        wp_enqueue_style( 'lpt-chat', LPT_URL . 'assets/chat.css', [], LPT_VERSION );
        wp_enqueue_script( 'lpt-chat', LPT_URL . 'assets/chat.js', [ 'jquery' ], LPT_VERSION, true );
        wp_localize_script( 'lpt-chat', 'lptChatConfig', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'lpt_nonce' ),
            'cartUrl'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart',
            'productImages' => $this->get_product_data_map(),  // {name: {id, price, url, link, rentman_id}}
            'factorGroups'  => $this->get_rentman_factor_groups(), // {group_id: {name, factors:[{from,to,factor}]}}
        ] );

        $postcodes_raw = get_option( 'lpt_delivery_postcodes',
            '6700,6701,6705,6706,6710,6715,6720,6730,6740,6760,6771,6780,6800,6818,6823,6830,6840,6851,6852,6853,6854,6857,6862,6870,6880,6893'
        );
        $postcodes = array_map( 'trim', explode( ',', $postcodes_raw ) );

        wp_localize_script( 'lpt-prisberegner', 'lptConfig', [
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'lpt_nonce' ),
            'productId'         => (int) get_option( 'lpt_product_id', 0 ),
            'deliveryCost'      => (float) get_option( 'lpt_delivery_cost', 250 ),
            'deliveryPostcodes' => $postcodes,
            'cartUrl'           => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '/cart',
        ] );
    }

    /* ── SHORTCODE ── */
    public function shortcode( $atts ) {
        ob_start();
        ?>
        <div id="lpt-calculator" class="lpt-calculator" role="main">

            <!-- STEP 1: TELT -->
            <div class="lpt-step active" id="lpt-step-1">
                <div class="lpt-step-header">
                    <span class="lpt-step-num">1</span>
                    <h2>Vælg telt</h2>
                </div>
                <div class="lpt-step-body">
                    <label>Telttype / bredde</label>
                    <div class="lpt-btn-group" id="lpt-width-group">
                        <button type="button" class="lpt-btn" data-width="3">3 m brede</button>
                        <button type="button" class="lpt-btn" data-width="6">6 m brede</button>
                        <button type="button" class="lpt-btn" data-width="9">9 m brede</button>
                        <button type="button" class="lpt-btn" data-width="pavillon">Pavillon</button>
                    </div>

                    <div id="lpt-length-wrap" class="lpt-hidden">
                        <label for="lpt-length">Længde</label>
                        <select id="lpt-length" class="lpt-select">
                            <option value="">— vælg længde —</option>
                        </select>
                    </div>

                    <div id="lpt-tent-info" class="lpt-tent-info lpt-hidden"></div>
                </div>
                <div class="lpt-step-footer">
                    <button type="button" class="lpt-btn-primary lpt-hidden" id="lpt-step1-next">
                        Næste: Lejeperiode →
                    </button>
                </div>
            </div>

            <!-- STEP 2: LEJEPERIODE -->
            <div class="lpt-step" id="lpt-step-2">
                <div class="lpt-step-header">
                    <span class="lpt-step-num">2</span>
                    <h2>Lejeperiode</h2>
                </div>
                <div class="lpt-step-body">
                    <p class="lpt-help">Prisen er pr. dag du <em>bruger</em> udstyret.</p>
                    <div class="lpt-btn-group" id="lpt-days-group">
                        <button type="button" class="lpt-btn active" data-days="1">
                            1 dag<span class="lpt-multiplier">× 1,0</span>
                        </button>
                        <button type="button" class="lpt-btn" data-days="2">
                            2 dage<span class="lpt-multiplier">× 1,4</span>
                        </button>
                        <button type="button" class="lpt-btn" data-days="3">
                            3 dage<span class="lpt-multiplier">× 1,7</span>
                        </button>
                    </div>
                </div>
                <div class="lpt-step-footer">
                    <button type="button" class="lpt-btn-secondary" id="lpt-step2-back">← Tilbage</button>
                    <button type="button" class="lpt-btn-primary" id="lpt-step2-next">Næste: Tilbehør →</button>
                </div>
            </div>

            <!-- STEP 3: TILBEHØR -->
            <div class="lpt-step" id="lpt-step-3">
                <div class="lpt-step-header">
                    <span class="lpt-step-num">3</span>
                    <h2>Tilbehør <small>(valgfrit)</small></h2>
                </div>
                <div class="lpt-step-body">
                    <div class="lpt-acc-grid" id="lpt-accessories"></div>
                </div>
                <div class="lpt-step-footer">
                    <button type="button" class="lpt-btn-secondary" id="lpt-step3-back">← Tilbage</button>
                    <button type="button" class="lpt-btn-primary" id="lpt-step3-next">Næste: Levering →</button>
                </div>
            </div>

            <!-- STEP 4: LEVERING -->
            <div class="lpt-step" id="lpt-step-4">
                <div class="lpt-step-header">
                    <span class="lpt-step-num">4</span>
                    <h2>Levering</h2>
                </div>
                <div class="lpt-step-body">
                    <label for="lpt-postcode">Dit postnummer</label>
                    <div class="lpt-postcode-wrap">
                        <input type="text" id="lpt-postcode" class="lpt-input" maxlength="4" placeholder="f.eks. 6700" pattern="[0-9]{4}">
                        <div id="lpt-delivery-result" class="lpt-delivery-result"></div>
                    </div>
                    <p class="lpt-help">Levering og afhentning i Esbjerg og omegn: <strong>250 kr inkl. moms</strong>.<br>
                    Øvrige områder beregnes i kassen.</p>
                </div>
                <div class="lpt-step-footer">
                    <button type="button" class="lpt-btn-secondary" id="lpt-step4-back">← Tilbage</button>
                    <button type="button" class="lpt-btn-primary" id="lpt-step4-next">Se pris og bestil →</button>
                </div>
            </div>

            <!-- STEP 5: OVERSIGT + BESTIL -->
            <div class="lpt-step" id="lpt-step-5">
                <div class="lpt-step-header">
                    <span class="lpt-step-num">5</span>
                    <h2>Priser og bestilling</h2>
                </div>
                <div class="lpt-step-body">
                    <div id="lpt-summary" class="lpt-summary"></div>
                    <div id="lpt-total" class="lpt-total"></div>
                    <div class="lpt-note">
                        <strong>Ingen depositum.</strong> Efter booking sender vi en lejekontrakt, som du skal godkende, inden aftalen er bindende.
                    </div>
                </div>
                <div class="lpt-step-footer">
                    <button type="button" class="lpt-btn-secondary" id="lpt-step5-back">← Redigér</button>
                    <button type="button" class="lpt-btn-primary lpt-cta" id="lpt-add-to-cart">
                        🛒 Læg i kurv
                    </button>
                </div>
                <div id="lpt-cart-message" class="lpt-cart-message lpt-hidden"></div>
            </div>

            <!-- PROGRESS BAR -->
            <div class="lpt-progress" aria-hidden="true">
                <div class="lpt-progress-bar" id="lpt-progress-bar"></div>
            </div>

        </div><!-- #lpt-calculator -->
        <?php
        return ob_get_clean();
    }

    /* ── WOOCOMMERCE: gem data på cart item ── */
    public function wc_add_cart_item_data( $cart_item_data, $product_id ) {
        if ( empty( $_POST['lpt_package'] ) ) return $cart_item_data;

        $raw = sanitize_text_field( wp_unslash( $_POST['lpt_package'] ) );
        $package = json_decode( $raw, true );
        if ( ! is_array( $package ) ) return $cart_item_data;

        $cart_item_data['lpt_package'] = $package;
        $cart_item_data['unique_key']  = md5( microtime() . wp_rand() );
        return $cart_item_data;
    }

    /* ── WOOCOMMERCE: vis pakkeindhold i kurv/checkout ── */
    public function wc_get_item_data( $item_data, $cart_item ) {
        // Ny chat-agent metode: individuelle produkter med multiplikator
        if ( isset( $cart_item['lpt_unit_price'] ) ) {
            $days = $cart_item['lpt_days'] ?? 1;
            $mult = $cart_item['lpt_multiplier'] ?? 1.0;
            $item_data[] = [
                'name'  => 'Lejeperiode',
                'value' => $days . ' dag(e) × ' . number_format( $mult, 1, ',', '' ),
            ];
            if ( ! empty( $cart_item['lpt_start_date'] ) ) {
                $item_data[] = [ 'name' => 'Fra dato', 'value' => esc_html( $cart_item['lpt_start_date'] ) ];
            }
            if ( ! empty( $cart_item['lpt_end_date'] ) ) {
                $item_data[] = [ 'name' => 'Til dato',  'value' => esc_html( $cart_item['lpt_end_date'] ) ];
            }
            return $item_data;
        }

        // Gammel pakke-metode (calculator shortcode)
        if ( empty( $cart_item['lpt_package'] ) ) return $item_data;
        $pkg = $cart_item['lpt_package'];
        if ( ! empty( $pkg['tent'] ) )   $item_data[] = [ 'name' => 'Telt',       'value' => esc_html( $pkg['tent'] ) ];
        if ( ! empty( $pkg['days'] ) )   $item_data[] = [ 'name' => 'Lejeperiode','value' => (int) $pkg['days'] . ' dag(e)' ];
        if ( ! empty( $pkg['lines'] ) && is_array( $pkg['lines'] ) ) {
            foreach ( $pkg['lines'] as $line ) {
                $item_data[] = [ 'name' => esc_html( $line['name'] ), 'value' => esc_html( $line['detail'] ) ];
            }
        }
        if ( ! empty( $pkg['delivery_label'] ) ) {
            $item_data[] = [ 'name' => 'Levering', 'value' => esc_html( $pkg['delivery_label'] ) ];
        }
        return $item_data;
    }

    /* ── WOOCOMMERCE: sæt beregnede pris på cart item ── */
    public function wc_set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            // Ny metode: pris per enhed × multiplikator (qty håndteres af WC)
            if ( isset( $cart_item['lpt_unit_price'], $cart_item['lpt_multiplier'] ) ) {
                $price = (float) $cart_item['lpt_unit_price'] * (float) $cart_item['lpt_multiplier'];
                $cart_item['data']->set_price( $price );
            }
            // Gammel metode (calculator shortcode)
            elseif ( isset( $cart_item['lpt_package']['price_excl_delivery'] ) ) {
                $cart_item['data']->set_price(
                    (float) $cart_item['lpt_package']['price_excl_delivery']
                );
            }
        }
    }

    /* ── WOOCOMMERCE: gem meta på ordre ── */
    public function wc_save_order_meta( $item, $cart_item_key, $values, $order ) {
        // Ny metode
        if ( isset( $values['lpt_unit_price'] ) ) {
            $item->add_meta_data( 'Lejeperiode', ( $values['lpt_days'] ?? 1 ) . ' dag(e)' );
            $item->add_meta_data( 'Faktor',      'x' . number_format( $values['lpt_multiplier'] ?? 1, 1, ',', '' ) );
            if ( ! empty( $values['lpt_start_date'] ) ) $item->add_meta_data( 'Fra dato', $values['lpt_start_date'] );
            if ( ! empty( $values['lpt_end_date'] ) )   $item->add_meta_data( 'Til dato',  $values['lpt_end_date'] );
            return;
        }
        // Gammel metode
        if ( empty( $values['lpt_package'] ) ) return;
        $pkg = $values['lpt_package'];
        if ( ! empty( $pkg['tent'] ) )    $item->add_meta_data( 'Telt',        $pkg['tent'] );
        if ( ! empty( $pkg['days'] ) )    $item->add_meta_data( 'Lejeperiode', $pkg['days'] . ' dag(e)' );
        if ( ! empty( $pkg['summary'] ) ) $item->add_meta_data( 'Pakkeindhold', $pkg['summary'] );
    }

    /* ── AJAX: tilføj til kurv ── */
    public function ajax_add_to_cart() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );

        $product_id = (int) get_option( 'lpt_product_id', 0 );
        if ( ! $product_id ) {
            wp_send_json_error( [
                'message' => 'Produktet er ikke konfigureret endnu. Kontakt venligst butikken direkte.',
            ] );
        }

        $raw     = sanitize_text_field( wp_unslash( $_POST['lpt_package'] ?? '' ) );
        $package = json_decode( $raw, true );
        if ( ! is_array( $package ) ) {
            wp_send_json_error( [ 'message' => 'Ugyldig pakke-data. Prøv igen.' ] );
        }

        $_POST['lpt_package'] = $raw;

        WC()->cart->empty_cart();
        $key = WC()->cart->add_to_cart( $product_id, 1, 0, [], [ 'lpt_package' => $package ] );

        if ( $key ) {
            wp_send_json_success( [ 'cart_url' => wc_get_cart_url() ] );
        } else {
            wp_send_json_error( [ 'message' => 'Kunne ikke tilføje til kurv. Prøv igen eller kontakt os.' ] );
        }
    }

    /* ── RENTMAN: FAKTORGRUPPER ── */
    private function rentman_get( string $endpoint ) {
        $token = get_option( 'lpt_rentman_token', '' );
        if ( ! $token ) return null;

        $response = wp_remote_get( 'https://api.rentman.net/' . $endpoint, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'X-Api-Version' => '3',
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) return null;
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'] ?? $body ?? null;
    }

    public function get_rentman_factor_groups() {
        $cached = get_transient( 'lpt_rentman_factors' );
        if ( $cached !== false ) return $cached;

        $token = get_option( 'lpt_rentman_token', '' );
        if ( ! $token ) {
            set_transient( 'lpt_rentman_factors', [], HOUR_IN_SECONDS );
            return [];
        }

        // Korrekt endpoint bekræftet: factorgroups
        $list = $this->rentman_get( 'factorgroups' );

        if ( ! is_array( $list ) || empty( $list ) ) {
            set_transient( 'lpt_rentman_factors', [], HOUR_IN_SECONDS );
            return [];
        }

        $groups = [];
        foreach ( $list as $item ) {
            $id   = (string) ( $item['id'] ?? '' );
            $name = $item['displayname'] ?? $item['name'] ?? $id;
            if ( ! $id ) continue;

            // Bekræftet endpoint: factorgroups/{id}/factors
            // Felter: from_days, to_days, factor
            $rows    = $this->rentman_get( 'factorgroups/' . $id . '/factors' );
            $factors = $this->extract_factor_rows( is_array( $rows ) ? $rows : [] );

            $groups[ $id ] = [ 'name' => $name, 'factors' => $factors ];
        }

        set_transient( 'lpt_rentman_factors', $groups, DAY_IN_SECONDS );
        return $groups;
    }

    private function extract_factor_rows( array $rows ): array {
        // Bekræftede feltnavne fra Rentman API: from_days, to_days, factor
        $factors = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $from   = (int)   ( $row['from_days'] ?? $row['from'] ?? 1 );
            $to     = (int)   ( $row['to_days']   ?? $row['to']   ?? $from );
            $factor = (float) ( $row['factor']     ?? 0 );
            if ( $factor > 0 ) {
                $factors[] = [ 'from' => $from, 'to' => $to, 'factor' => $factor ];
            }
        }
        return $factors;
    }

    /* ── AJAX: Rentman API debug (admin) ── */
    public function ajax_rentman_debug() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Ikke tilladt' );

        $token = get_option( 'lpt_rentman_token', '' );
        if ( ! $token ) {
            wp_send_json_error( [ 'message' => 'Ingen API-token konfigureret.' ] );
        }

        $results = [];

        // Test 1: Kan vi overhovedet forbinde?
        $test = $this->rentman_get( 'equipment?limit=1' );
        $results['token_ok'] = is_array( $test );
        $results['equipment_sample'] = $test ? array_keys( $test[0] ?? [] ) : 'FEJL';

        // Test 2: Prøv alle faktor-endpoints
        $candidates = [
            'rentalperiodpricegroups',
            'rentalperiodprice',
            'periodicpricegroups',
            'pricefactorgroups',
            'rentalfactorgroups',
            'factorgroups',
            'pricegroups',
            'discountgroups',
        ];

        $results['factor_endpoints'] = [];
        foreach ( $candidates as $ep ) {
            $r = $this->rentman_get( $ep );
            if ( is_array( $r ) && ! empty( $r ) ) {
                $results['factor_endpoints'][ $ep ] = 'OK — ' . count( $r ) . ' poster. Første posts nøgler: ' . implode( ', ', array_keys( (array) $r[0] ) );
            } else {
                $results['factor_endpoints'][ $ep ] = is_array( $r ) ? 'TOM liste' : 'FEJL/404';
            }
        }

        // Vis fuld detail på første factorgroup + prøv sub-endpoints for faktorrækker
        $fg_list = $this->rentman_get( 'factorgroups' );
        if ( is_array( $fg_list ) && ! empty( $fg_list ) ) {
            $first_id = $fg_list[0]['id'] ?? null;
            $results['factorgroups_list'] = $fg_list;
            if ( $first_id ) {
                $results['factorgroup_detail'] = $this->rentman_get( 'factorgroups/' . $first_id );

                // Prøv sub-endpoints og filterbaserede endpoints for faktorrækker
                $sub_candidates = [
                    'factorgroups/' . $first_id . '/factors',
                    'factorgroups/' . $first_id . '/periods',
                    'factorgroups/' . $first_id . '/rows',
                    'factorgroups/' . $first_id . '/lines',
                    'factorrows?factorgroup=' . $first_id,
                    'factorrows?factorgroup=/factorgroups/' . $first_id,
                    'factorgroupperiods?factorgroup=' . $first_id,
                    'factorgroupperiods?factorgroup=/factorgroups/' . $first_id,
                    'factorgrouprows?factorgroup=' . $first_id,
                    'factorgrouplines?factorgroup=' . $first_id,
                    'periodfactors?group=' . $first_id,
                    'rentalfactors?group=/factorgroups/' . $first_id,
                ];
                $results['factor_sub_endpoints'] = [];
                foreach ( $sub_candidates as $sub ) {
                    $r = $this->rentman_get( $sub );
                    if ( is_array( $r ) && ! empty( $r ) ) {
                        $results['factor_sub_endpoints'][ $sub ] = 'OK — ' . count( $r ) . ' poster. Nøgler: ' . implode( ', ', array_keys( (array) $r[0] ) );
                    } else {
                        $results['factor_sub_endpoints'][ $sub ] = is_array( $r ) ? 'TOM' : 'FEJL/404';
                    }
                }
            }
        }

        wp_send_json_success( $results );
    }

    /* ── AJAX: Tjek om ny plugin-version er tilgængelig ── */
    public function ajax_plugin_check_update() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) wp_die( 'Ikke tilladt' );

        $url = get_option( 'lpt_update_url', '' );
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'Ingen update-URL konfigureret.' ] );
        }

        // Forvent en version.json ved samme base-URL eller eksplicit URL
        // Prøv at udlæse version fra URL'en selv (hvis den ender på version.json)
        // eller byg version-check URL ved at erstatte .zip med -version.json
        $version_url = preg_replace( '/\.zip$/i', '-version.json', $url );
        if ( $version_url === $url ) {
            // URL peger ikke på en zip — brug direkte som version.json
            $version_url = rtrim( $url, '/' ) . '/version.json';
        }

        $resp = wp_remote_get( $version_url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            // Kan ikke tjekke version — returner blot nuværende version
            wp_send_json_success( [
                'current'   => LPT_VERSION,
                'remote'    => null,
                'has_update' => false,
                'note'      => 'Kunne ikke tjekke remote version — klik Installer for at opdatere manuelt.',
            ] );
        }

        $data    = json_decode( wp_remote_retrieve_body( $resp ), true );
        $remote  = $data['version'] ?? null;
        $has_upd = $remote && version_compare( $remote, LPT_VERSION, '>' );

        wp_send_json_success( [
            'current'    => LPT_VERSION,
            'remote'     => $remote,
            'has_update' => $has_upd,
        ] );
    }

    /* ── AJAX: Installer plugin fra zip-URL ── */
    public function ajax_plugin_update() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );
        if ( ! current_user_can( 'update_plugins' ) ) wp_die( 'Ikke tilladt' );

        $url = esc_url_raw( get_option( 'lpt_update_url', '' ) );
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'Ingen update-URL sat under Indstillinger → LPT Prisberegner.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );

        // install() med overwrite_package overskriver eksisterende mappe
        $result = $upgrader->install( $url, [ 'overwrite_package' => true ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        if ( $result === false ) {
            $errors = $skin->get_errors();
            $msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Ukendt fejl under installation.';
            wp_send_json_error( [ 'message' => $msg ] );
        }

        // Ryd alle plugin-caches efter opdatering
        $this->clear_price_cache();

        wp_send_json_success( [ 'message' => 'Plugin opdateret til nyeste version! Genindlæser siden...' ] );
    }

    // Henter Rentman equipment → faktorgruppe-ID mapping
    private function get_rentman_equipment_factor_map() {
        $cached = get_transient( 'lpt_rentman_equip_factors' );
        if ( $cached !== false ) return $cached;

        // Korrekt felt bekræftet: factor_group
        $raw = $this->rentman_get( 'equipment?limit=500&fields=id,name,factor_group' );

        $map = []; // rentman_equipment_id → factor_group_id
        if ( is_array( $raw ) ) {
            foreach ( $raw as $item ) {
                $eq_id     = (string) ( $item['id'] ?? '' );
                $group_ref = $item['factor_group'] ?? null;
                // Rentman returnerer enten et ID eller {id: X} objekt
                $group_id  = is_array( $group_ref )
                    ? (string) ( $group_ref['id'] ?? '' )
                    : (string) ( $group_ref ?? '' );
                if ( $eq_id && $group_id ) {
                    $map[ $eq_id ] = $group_id;
                }
            }
        }

        set_transient( 'lpt_rentman_equip_factors', $map, DAY_IN_SECONDS );
        return $map;
    }

    // Beregner faktoren for et antal dage ud fra en faktorgruppe
    public static function calc_factor( array $group_factors, int $days ): float {
        if ( empty( $group_factors ) ) return 1.0;
        $best = 1.0;
        foreach ( $group_factors as $row ) {
            if ( $days >= $row['from'] && $days <= $row['to'] ) return (float) $row['factor'];
            // Gem højeste rækkes faktor som fallback
            if ( $days > $row['to'] ) $best = (float) $row['factor'];
        }
        return $best;
    }

    // Bygger prompt-tekst med alle faktorgrupper
    private function build_factor_groups_prompt(): string {
        $groups = $this->get_rentman_factor_groups();
        if ( empty( $groups ) ) {
            // Fallback: Standard-tabel hardcodet
            return "### Standard (bruges af alle produkter)\n"
                 . "| Fra | Til | Faktor |\n|-----|-----|--------|\n"
                 . "| 1 | 3 | 1,0 |\n| 4 | 4 | 1,4 |\n| 5 | 5 | 1,7 |\n"
                 . "| 6 | 6 | 2,0 |\n| 7 | 7 | 2,25 |\n| 8 | 8 | 2,5 |\n"
                 . "| 9 | 9 | 2,75 |\n| 10 | 10 | 3,0 |\n| 11+ | ... | +0,25/dag |";
        }

        $text = '';
        foreach ( $groups as $id => $group ) {
            $text .= '### ' . $group['name'] . "\n";
            $text .= "| Fra dage | Til dage | Faktor |\n|----------|----------|--------|\n";
            foreach ( $group['factors'] as $row ) {
                $text .= '| ' . $row['from'] . ' | ' . $row['to'] . ' | '
                      . number_format( $row['factor'], 2, ',', '' ) . " |\n";
            }
            $text .= "\n";
        }
        return $text;
    }

    // Bygger en tabel: produktnavn → faktorgruppe-navn (til system-prompten)
    private function build_product_factor_group_map_prompt(): string {
        $factor_groups   = $this->get_rentman_factor_groups();
        $equip_map       = $this->get_rentman_equipment_factor_map(); // rentman_id → group_id
        $product_map     = $this->get_product_data_map();             // name → {rentman_id, ...}

        if ( empty( $factor_groups ) || empty( $equip_map ) ) return '';

        // Grupper produktnavne per faktorgruppe
        $by_group = []; // group_name → [product names]
        foreach ( $product_map as $name => $data ) {
            $rentman_id = (string) ( $data['rentman_id'] ?? '' );
            if ( ! $rentman_id ) continue;
            $group_id   = $equip_map[ $rentman_id ] ?? '';
            $group_name = $factor_groups[ $group_id ]['name'] ?? 'Standard';
            $by_group[ $group_name ][] = $name;
        }

        if ( empty( $by_group ) ) return '';

        $text = "## FAKTORGRUPPE PER PRODUKT\n"
              . "Brug den korrekte faktorgruppe for HVERT produkt ved beregning af lineTotal:\n\n";
        foreach ( $by_group as $group_name => $names ) {
            $text .= '**' . $group_name . ':** ' . implode( ', ', array_slice( $names, 0, 20 ) ) . "\n";
        }
        return $text . "\n";
    }

    /* ── AJAX: individuelle produkter i kurv (chat-agent) ── */
    public function ajax_add_items_to_cart() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );

        $raw   = sanitize_textarea_field( wp_unslash( $_POST['offer'] ?? '' ) );
        $offer = json_decode( $raw, true );
        if ( ! is_array( $offer ) || empty( $offer['lines'] ) ) {
            wp_send_json_error( [ 'message' => 'Ugyldig offer-data.' ] );
        }

        $lines            = $offer['lines'];
        $days             = (int) ( $offer['days'] ?? 1 );
        $global_multiplier = (float) ( $offer['multiplier'] ?? 1.0 ); // Fallback hvis line ikke har egen
        $delivery         = (float) ( $offer['delivery'] ?? 0 );
        $start_date = sanitize_text_field( $offer['start_date'] ?? '' );
        $end_date   = sanitize_text_field( $offer['end_date'] ?? '' );
        $setup_notes = sanitize_textarea_field( $offer['setup_notes'] ?? '' );

        $product_map = $this->get_product_data_map();

        WC()->cart->empty_cart();

        $added = 0;
        $failed = [];

        foreach ( $lines as $line ) {
            $name           = sanitize_text_field( $line['name'] ?? '' );
            $qty            = max( 1, (int) ( $line['qty'] ?? 1 ) );
            $unit_price     = (float) ( $line['unitPrice'] ?? 0 );
            // Brug per-line multiplikator hvis tilgængelig, ellers global
            $multiplier     = isset( $line['multiplier'] ) ? (float) $line['multiplier'] : $global_multiplier;

            // Find produkt-ID (case-insensitivt match)
            $product_id = 0;
            $lower = mb_strtolower( $name, 'UTF-8' );
            foreach ( $product_map as $pname => $pdata ) {
                if ( mb_strtolower( $pname, 'UTF-8' ) === $lower ) {
                    $product_id = (int) $pdata['id'];
                    break;
                }
            }
            // Delvist match som fallback
            if ( ! $product_id ) {
                foreach ( $product_map as $pname => $pdata ) {
                    $plower = mb_strtolower( $pname, 'UTF-8' );
                    if ( str_contains( $plower, $lower ) || str_contains( $lower, $plower ) ) {
                        $product_id = (int) $pdata['id'];
                        break;
                    }
                }
            }

            if ( ! $product_id ) {
                $failed[] = $name;
                continue;
            }

            $item_meta = [
                'lpt_unit_price' => $unit_price,
                'lpt_multiplier' => $multiplier,
                'lpt_days'       => $days,
                'lpt_start_date' => $start_date,
                'lpt_end_date'   => $end_date,
                'unique_key'     => md5( $name . $multiplier . $start_date ),
            ];

            $key = WC()->cart->add_to_cart( $product_id, $qty, 0, [], $item_meta );
            if ( $key ) {
                $added++;
            } else {
                $failed[] = $name;
            }
        }

        // Tilføj levering som separat produkt hvis konfigureret
        if ( $delivery > 0 ) {
            $delivery_product_id = (int) get_option( 'lpt_delivery_product_id', 0 );
            if ( $delivery_product_id ) {
                WC()->cart->add_to_cart( $delivery_product_id, 1, 0, [], [
                    'lpt_unit_price' => $delivery,
                    'lpt_multiplier' => 1.0,
                    'lpt_days'       => 1,
                    'lpt_start_date' => $start_date,
                    'lpt_end_date'   => $end_date,
                    'unique_key'     => 'delivery_' . $start_date,
                ] );
            }
        }

        // Gem opsætningsønsker i WooCommerce sessionen — checkout-siden læser dem automatisk
        if ( $setup_notes && WC()->session ) {
            WC()->session->set( 'lpt_setup_notes', $setup_notes );
        }

        if ( $added === 0 ) {
            wp_send_json_error( [ 'message' => 'Kunne ikke finde produkterne i butikken. Prøv igen eller kontakt os.' ] );
        }

        $msg = $failed ? 'Bemærk: følgende produkter kunne ikke tilføjes: ' . implode( ', ', $failed ) . '.' : '';
        wp_send_json_success( [ 'cart_url' => wc_get_cart_url(), 'warning' => $msg ] );
    }

    /* ── AJAX: tjek Rentman tilgængelighed ── */
    public function ajax_check_availability() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );

        $lines      = json_decode( sanitize_textarea_field( wp_unslash( $_POST['lines'] ?? '[]' ) ), true );
        $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
        $end_date   = sanitize_text_field( $_POST['end_date'] ?? '' );

        if ( ! $start_date || ! $end_date || ! is_array( $lines ) ) {
            wp_send_json_success( [ 'ok' => true, 'skipped' => true ] ); // Manglende data — spring over
        }

        $product_map = $this->get_product_data_map();

        // Alternativer-map
        $alternatives = [
            'Telt 6×9m, hvid'         => 'Telt 9×6m, hvid',
            'Telt 9×6m, hvid'         => 'Telt 6×9m, hvid',
            'Telt 6×12m, hvid'        => 'Telt 9×9m, hvid',
            'Telt 9×9m, hvid'         => 'Telt 6×12m, hvid',
            'Stol, polstret sort'      => 'Stol, hvid m. sædehynde',
            'Stol, hvid m. sædehynde' => 'Stol, polstret sort',
            'Stol, hvid plastik'       => 'Stol, hvid m. sædehynde',
        ];

        // Byg item-liste med Rentman-IDs
        $items_to_check = [];
        foreach ( $lines as $line ) {
            $name  = sanitize_text_field( $line['name'] ?? '' );
            $lower = mb_strtolower( $name, 'UTF-8' );
            foreach ( $product_map as $pname => $pdata ) {
                if ( mb_strtolower( $pname, 'UTF-8' ) === $lower && ! empty( $pdata['rentman_id'] ) ) {
                    $items_to_check[] = [
                        'name'       => $name,
                        'qty'        => (int) ( $line['qty'] ?? 1 ),
                        'rentman_id' => $pdata['rentman_id'],
                    ];
                    break;
                }
            }
        }

        if ( empty( $items_to_check ) ) {
            wp_send_json_success( [ 'ok' => true, 'skipped' => true ] ); // Ingen Rentman-IDs — skip
        }

        $availability = $this->check_rentman_availability( $items_to_check, $start_date, $end_date );

        if ( isset( $availability['error'] ) ) {
            wp_send_json_success( [ 'ok' => true, 'skipped' => true, 'debug' => $availability['error'] ] );
        }

        // Analyser resultatet — find utilgængelige produkter
        $unavailable = [];
        $avail_data  = $availability['data'] ?? $availability; // Rentman returnerer typisk {data:[...]}
        if ( is_array( $avail_data ) ) {
            foreach ( $avail_data as $item ) {
                $rentman_id     = (string) ( $item['id'] ?? $item['equipment'] ?? '' );
                $avail_qty      = (int) ( $item['availableQuantity'] ?? $item['available_quantity'] ?? $item['quantity_available'] ?? 99 );
                // Find det matchende item i vores check-liste
                foreach ( $items_to_check as $checked ) {
                    if ( (string) $checked['rentman_id'] === $rentman_id && $avail_qty < $checked['qty'] ) {
                        $alt = $alternatives[ $checked['name'] ] ?? null;
                        $unavailable[] = [ 'name' => $checked['name'], 'alternative' => $alt ];
                    }
                }
            }
        }

        if ( empty( $unavailable ) ) {
            wp_send_json_success( [ 'ok' => true ] );
        } else {
            wp_send_json_success( [ 'ok' => false, 'unavailable' => $unavailable ] );
        }
    }

    /* ── ADMIN SETTINGS ── */
    public function admin_menu() {
        add_options_page(
            'LPT Prisberegner',
            'LPT Prisberegner',
            'manage_options',
            'lpt-prisberegner',
            [ $this, 'settings_page' ]
        );
    }

    public function admin_init() {
        register_setting( 'lpt_prisberegner_group', 'lpt_product_id',          [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_delivery_cost',       [ 'sanitize_callback' => 'floatval' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_delivery_postcodes',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_api_key',             [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_delivery_product_id', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_rentman_token',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_rentman_meta_key',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'lpt_prisberegner_group', 'lpt_update_url',          [ 'sanitize_callback' => 'esc_url_raw' ] );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>LPT Prisberegner — Indstillinger</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'lpt_prisberegner_group' ); ?>

                <h2>AI-chat + Rentman</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Claude API-nøgle</th>
                        <td>
                            <input type="text" name="lpt_api_key"
                                   value="<?php echo esc_attr( get_option( 'lpt_api_key', '' ) ); ?>"
                                   class="regular-text" placeholder="sk-ant-...">
                            <p class="description">API-nøgle fra <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a> — bruges til AI-chat-rådgiveren.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rentman API-token</th>
                        <td>
                            <input type="text" name="lpt_rentman_token"
                                   value="<?php echo esc_attr( get_option( 'lpt_rentman_token', '' ) ); ?>"
                                   class="regular-text" placeholder="Dit Rentman API-token">
                            <p class="description">Bruges til at tjekke produkttilgængelighed i Rentman under chat-samtalen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rentman meta-nøgle</th>
                        <td>
                            <input type="text" name="lpt_rentman_meta_key"
                                   value="<?php echo esc_attr( get_option( 'lpt_rentman_meta_key', '' ) ); ?>"
                                   class="regular-text" placeholder="Auto-detekteres (efterlad tom)">
                            <p class="description">Meta-nøglen som Rentman Advanced gemmer item-ID på WooCommerce-produkter. Efterlad tom for auto-detektion.
                            <?php
                            $detected = $this->get_rentman_meta_key();
                            if ( $detected ) echo '<br><strong>Auto-detekteret:</strong> <code>' . esc_html( $detected ) . '</code>';
                            else echo '<br><em>Ingen Rentman item-IDs fundet på produkterne endnu.</em>';
                            ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Leverings-produkt ID</th>
                        <td>
                            <input type="number" name="lpt_delivery_product_id"
                                   value="<?php echo esc_attr( get_option( 'lpt_delivery_product_id', '' ) ); ?>"
                                   class="small-text" placeholder="f.eks. 123">
                            <p class="description">WooCommerce produkt-ID for "Levering og afhentning" — tilføjes i kurven når kunden ønsker levering via chat-agenten.</p>
                        </td>
                    </tr>
                </table>

                <h2>Leveringsindstillinger</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Leveringspris (kr inkl. moms)</th>
                        <td>
                            <input type="number" name="lpt_delivery_cost"
                                   value="<?php echo esc_attr( get_option( 'lpt_delivery_cost', '250' ) ); ?>"
                                   class="small-text" step="1"> kr
                            <p class="description">Bruges i den klassiske prisberegner ([prisberegner]).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Postnumre med fast levering</th>
                        <td>
                            <textarea name="lpt_delivery_postcodes" rows="4" class="large-text"><?php
                                echo esc_textarea( get_option( 'lpt_delivery_postcodes',
                                    '6700,6701,6705,6706,6710,6715,6720,6730,6740,6760,6771,6780,6800,6818,6823,6830,6840,6851,6852,6853,6854,6857,6862,6870,6880,6893'
                                ) );
                            ?></textarea>
                            <p class="description">Kommaseparerede postnumre der er inkluderet i den faste leveringspris.</p>
                        </td>
                    </tr>
                </table>

                <h2>Klassisk prisberegner ([prisberegner])</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">WooCommerce Produkt ID (pakke)</th>
                        <td>
                            <input type="number" name="lpt_product_id"
                                   value="<?php echo esc_attr( get_option( 'lpt_product_id', '' ) ); ?>"
                                   class="regular-text" placeholder="f.eks. 36328">
                            <p class="description">Bruges KUN af den klassiske prisberegner. Chat-agenten tilføjer individuelle produkter direkte.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Gem indstillinger' ); ?>
            </form>

            <hr>
            <h2>Plugin-opdatering</h2>
            <p>Indsæt URL til nyeste zip-fil herunder, og klik <strong>Opdater</strong>.</p>
            <table class="form-table" style="max-width:700px">
                <tr>
                    <th scope="row">Download URL (zip)</th>
                    <td>
                        <form method="post" action="options.php" style="display:inline">
                            <?php settings_fields( 'lpt_prisberegner_group' ); ?>
                            <input type="url" name="lpt_update_url" id="lpt-update-url"
                                   value="<?php echo esc_attr( get_option( 'lpt_update_url', '' ) ); ?>"
                                   class="large-text" placeholder="https://...lpt-prisberegner-vX.X.X.zip">
                            <?php submit_button( 'Gem URL', 'secondary', 'submit', false ); ?>
                        </form>
                    </td>
                </tr>
            </table>
            <div style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button type="button" id="lpt-check-update" class="button button-secondary">🔍 Tjek version</button>
                <button type="button" id="lpt-do-update" class="button button-primary" <?php echo get_option('lpt_update_url','') ? '' : 'disabled'; ?>>⬆️ Installer opdatering</button>
                <span id="lpt-update-status" style="font-style:italic;color:#666">Nuværende version: <strong><?php echo LPT_VERSION; ?></strong></span>
            </div>
            <div id="lpt-update-log" style="display:none;background:#f6f7f7;border:1px solid #ccd0d4;padding:12px;border-radius:4px;margin-top:10px;font-family:monospace;font-size:0.85rem;max-width:700px"></div>

            <script>
            (function(){
                var nonce    = '<?php echo wp_create_nonce('lpt_nonce'); ?>';
                var $status  = document.getElementById('lpt-update-status');
                var $log     = document.getElementById('lpt-update-log');
                var $check   = document.getElementById('lpt-check-update');
                var $update  = document.getElementById('lpt-do-update');
                var urlInput = document.getElementById('lpt-update-url');

                function post(action, cb) {
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type':'application/x-www-form-urlencoded'},
                        body: 'action=' + action + '&nonce=' + nonce
                    }).then(r=>r.json()).then(cb).catch(function(){ $status.textContent = 'Netværksfejl.'; });
                }

                $check.addEventListener('click', function(){
                    $check.disabled = true;
                    $status.innerHTML = 'Tjekker...';
                    post('lpt_plugin_check_update', function(res){
                        $check.disabled = false;
                        if (!res.success) { $status.textContent = '❌ ' + res.data.message; return; }
                        var d = res.data;
                        if (d.has_update) {
                            $status.innerHTML = '🟡 Ny version tilgængelig: <strong>' + d.remote + '</strong> (installeret: ' + d.current + ')';
                        } else if (d.remote) {
                            $status.innerHTML = '✅ Du har nyeste version <strong>' + d.current + '</strong>';
                        } else {
                            $status.innerHTML = '📌 Installeret: <strong>' + d.current + '</strong>' + (d.note ? ' — ' + d.note : '');
                        }
                    });
                });

                $update.addEventListener('click', function(){
                    if (!urlInput.value.trim()) { alert('Indsæt download-URL til zip-filen først og gem den.'); return; }
                    if (!confirm('Installer plugin fra:\n' + urlInput.value + '\n\nFortæt?')) return;
                    $update.disabled = true;
                    $check.disabled  = true;
                    $status.textContent = 'Installerer...';
                    $log.style.display = 'none';
                    post('lpt_plugin_update', function(res){
                        $update.disabled = false;
                        $check.disabled  = false;
                        if (res.success) {
                            $status.innerHTML = '✅ ' + res.data.message;
                            $log.style.display = 'none';
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            $status.innerHTML = '❌ Fejl';
                            $log.style.display = 'block';
                            $log.textContent = res.data.message || 'Ukendt fejl.';
                        }
                    });
                });
            })();
            </script>

            <hr>
            <h2>Tilgængelige shortcodes</h2>
            <table class="widefat striped" style="max-width:700px">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Beskrivelse</th>
                        <th>Kan stå alene?</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[lpt-chat]</code></td>
                        <td>AI-lejerådgiver — kunden skriver hvad de har brug for, og agenten sammensætter et tilbud</td>
                        <td>✅ Ja</td>
                    </tr>
                    <tr>
                        <td><code>[lpt-tilbud]</code></td>
                        <td>Live prisoversigt — opdateres automatisk når chatten præsenterer et tilbud</td>
                        <td>⚠️ Kræver <code>[lpt-chat]</code> på samme side</td>
                    </tr>
                    <tr>
                        <td><code>[lpt-produkter]</code></td>
                        <td>Produktbilleder — viser billeder af de valgte produkter fra tilbuddet</td>
                        <td>⚠️ Kræver <code>[lpt-chat]</code> på samme side</td>
                    </tr>
                    <tr>
                        <td><code>[prisberegner]</code></td>
                        <td>Klassisk trin-for-trin prisberegner (uden AI)</td>
                        <td>✅ Ja</td>
                    </tr>
                </tbody>
            </table>
            <br>
            <div class="notice notice-success inline" style="max-width:700px">
                <p><strong>Eksempel — tre kolonner i Divi:</strong><br>
                Kolonne 1: <code>[lpt-chat]</code> &nbsp;|&nbsp;
                Kolonne 2: <code>[lpt-tilbud]</code> &nbsp;|&nbsp;
                Kolonne 3: <code>[lpt-produkter]</code>
                </p>
            </div>
            <p style="color:#888;font-size:0.85rem;margin-top:12px">Plugin version <?php echo LPT_VERSION; ?></p>

            <?php if ( get_option( 'lpt_rentman_token', '' ) ) : ?>
            <hr>
            <h2>Rentman — Faktorgrupper (fra API)</h2>
            <p>
                <button type="button" id="lpt-rentman-test" class="button button-secondary">🔍 Test Rentman API-forbindelse</button>
                <span id="lpt-rentman-test-status" style="margin-left:12px;font-style:italic;color:#666"></span>
            </p>
            <div id="lpt-rentman-test-output" style="display:none;background:#1e1e1e;color:#d4d4d4;padding:14px;border-radius:6px;font-family:monospace;font-size:0.82rem;white-space:pre-wrap;max-width:800px;margin-top:10px;overflow-x:auto"></div>
            <script>
            document.getElementById('lpt-rentman-test').addEventListener('click', function() {
                var btn = this;
                var status = document.getElementById('lpt-rentman-test-status');
                var output = document.getElementById('lpt-rentman-test-output');
                btn.disabled = true;
                status.textContent = 'Tester...';
                output.style.display = 'none';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=lpt_rentman_debug&nonce=<?php echo wp_create_nonce('lpt_nonce'); ?>'
                }).then(r=>r.json()).then(function(res) {
                    btn.disabled = false;
                    if (res.success) {
                        status.textContent = res.data.token_ok ? '✅ Token OK' : '❌ Token fejl';
                        output.style.display = 'block';
                        output.textContent = JSON.stringify(res.data, null, 2);
                    } else {
                        status.textContent = '❌ ' + (res.data.message || 'Fejl');
                    }
                }).catch(function(e){ btn.disabled=false; status.textContent='Netværksfejl'; });
            });
            </script>
            <?php
            $groups = $this->get_rentman_factor_groups();
            if ( empty( $groups ) ) :
            ?>
                <div class="notice notice-warning inline" style="max-width:700px">
                    <p><strong>Ingen faktorgrupper fundet.</strong> Tjek at API-token er korrekt og at Rentman-kontoen har adgang til <code>/rentalperiodpricegroups</code>. Ryd cache og prøv igen: <a href="<?php echo admin_url('options.php?page=lpt-prisberegner&lpt_clear_cache=1'); ?>">Ryd cache</a></p>
                </div>
            <?php else : ?>
                <p style="color:#666">Hentet fra Rentman og cachet i 24 timer. <a href="<?php echo esc_url( add_query_arg( 'lpt_clear_cache', '1' ) ); ?>">Tving genindlæsning</a></p>
                <table class="widefat striped" style="max-width:800px">
                    <thead><tr><th>Gruppe</th><th>Faktorer (dage → faktor)</th></tr></thead>
                    <tbody>
                    <?php foreach ( $groups as $id => $group ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $group['name'] ); ?></strong></td>
                            <td style="font-size:0.85rem">
                            <?php foreach ( $group['factors'] as $row ) :
                                echo esc_html( $row['from'] ) . '–' . esc_html( $row['to'] ) . ' dage → ×' . number_format( $row['factor'], 2, ',', '' ) . ' &nbsp; ';
                            endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php endif; ?>

            <?php
            // Ryd cache hvis ?lpt_clear_cache=1
            if ( isset( $_GET['lpt_clear_cache'] ) && current_user_can( 'manage_options' ) ) {
                $this->clear_price_cache();
                echo '<div class="notice notice-success inline" style="max-width:700px"><p>Cache ryddet. Genindlæser siden...</p></div>';
                echo '<script>setTimeout(function(){ window.location.href = window.location.href.replace(/[?&]lpt_clear_cache=1/,""); }, 1200);</script>';
            }
            ?>
        </div>
        <?php
    }

    /* ── SHORTCODE: CHAT ── */
    public function shortcode_chat( $atts ) {
        ob_start();
        ?>
        <div id="lpt-chat" class="lpt-chat-wrap">
            <div class="lpt-chat-header">💬 Lejerådgiver</div>
            <div class="lpt-chat-messages" id="lpt-chat-messages" role="log" aria-live="polite">
                <div class="lpt-msg lpt-msg-agent">
                    <div class="lpt-msg-bubble">
                        Hej! Jeg er din lejerådgiver fra Lejpartytelt.dk 👋<br><br>
                        Fortæl mig hvad du skal bruge — fx <em>"Jeg skal holde konfirmation for 50 personer lørdag"</em> — så sammensætter jeg et tilbud til dig.
                    </div>
                </div>
            </div>
            <div class="lpt-chat-input-wrap">
                <textarea id="lpt-chat-input" class="lpt-chat-input" rows="2"
                    placeholder="Beskriv din fest eller dit arrangement..."></textarea>
                <button type="button" id="lpt-chat-send" class="lpt-chat-send">Send</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── SHORTCODE: TILBUDSOVERSIGT ── */
    public function shortcode_tilbud( $atts ) {
        ob_start();
        ?>
        <div class="lpt-live-summary" id="lpt-live-summary">
            <div class="lpt-live-summary-header">📋 Dit tilbud</div>
            <div id="lpt-live-summary-body" class="lpt-live-summary-empty">
                <p>Dit tilbud bygges op her, efterhånden som du chatter med rådgiveren.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── SHORTCODE: PRODUKTBILLEDER ── */
    public function shortcode_produkter( $atts ) {
        ob_start();
        ?>
        <div class="lpt-visual-panel" id="lpt-visual-panel">
            <div class="lpt-visual-header">🖼 Produkter</div>
            <div id="lpt-visual-body" class="lpt-visual-empty">
                <p>Billeder af de valgte produkter vises her.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── AJAX: chat besked ── */
    public function ajax_chat_message() {
        check_ajax_referer( 'lpt_nonce', 'nonce' );

        $api_key = get_option( 'lpt_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( [ 'message' => 'AI-rådgiveren er ikke konfigureret endnu. Kontakt os direkte.' ] );
        }

        $user_message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        if ( ! $user_message ) {
            wp_send_json_error( [ 'message' => 'Tom besked.' ] );
        }

        $history_raw = wp_unslash( $_POST['history'] ?? '[]' );
        $history     = json_decode( $history_raw, true );
        if ( ! is_array( $history ) ) $history = [];

        // Sanitize history
        $history = array_map( function( $msg ) {
            return [
                'role'    => in_array( $msg['role'] ?? '', [ 'user', 'assistant' ] ) ? $msg['role'] : 'user',
                'content' => sanitize_textarea_field( $msg['content'] ?? '' ),
            ];
        }, array_slice( $history, -20 ) ); // Maks 20 beskeder historik

        $history[] = [ 'role' => 'user', 'content' => $user_message ];

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 45,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 1500,
                'system'     => $this->get_system_prompt(),
                'messages'   => $history,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Netværksfejl: ' . $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( [ 'message' => 'API-fejl (' . $code . '). Prøv igen.' ] );
        }

        $assistant_reply = $body['content'][0]['text'] ?? '';
        $history[]       = [ 'role' => 'assistant', 'content' => $assistant_reply ];

        wp_send_json_success( [
            'message' => $assistant_reply,
            'history' => $history,
        ] );
    }

    /* ── SYSTEM PROMPT (med live WooCommerce-priser + Rentman faktorer) ── */
    private function get_system_prompt() {
        $delivery_cost   = (float) get_option( 'lpt_delivery_cost', 250 );
        $price_table     = $this->get_live_price_table();
        $factor_prompt   = $this->build_factor_groups_prompt();
        $product_factors = $this->build_product_factor_group_map_prompt();

        return <<<PROMPT
Du er en venlig og professionel lejerådgiver for Lejpartytelt.dk — en dansk udlejningsvirksomhed i Esbjerg-området, der udlejer telte, møbler, lyd, lys og festudstyr.

Dit mål er at hjælpe kunden med at sammensætte det BEDST passende tilbud — ikke det dyreste. Stil opklarende spørgsmål hvis du mangler oplysninger (antal gæster, antal dage, leveringspostnummer, om de vil have opstilling). Svar altid på dansk.

## PRISREGLER

### Lejedage — VIGTIGT
Prisen beregnes ud fra hvor mange dage KUNDEN BRUGER udstyret — IKKE hvor mange dage teltet er opsat.
Eksempel: En kunde holder fest lørdag. Teltet opsættes torsdag og nedtages mandag. Kunden betaler KUN for 1 dag (lørdag), fordi det kun er lørdag de bruger det.
Spørg ALTID kunden: "Hvilken dag/dage skal du bruge teltet?" — ikke "Hvor mange dage er teltet opsat?".

Typiske scenarier:
- Lørdarrangement: teltet op torsdag/fredag, ned søndag/mandag → kunden betaler for 1 dag
- Weekend: brug fredag + lørdag → 2 dage
- Festival/firmaevent over flere dage: antal brugsdage opgøres konkret

### Prisfaktorer per gruppe (fra Rentman)
Hvert produkt har sin egen faktorgruppe — brug den korrekte faktor for HVERT produkt.

{$factor_prompt}
{$product_factors}
### Øvrige regler
- Levering og afhentning i Esbjerg og omegn: {$delivery_cost} kr inkl. moms
- Ingen depositum. Vi sender lejekontrakt efter booking, som kunden skal godkende inden aftalen er bindende.

## AKTUELLE PRISER FRA HJEMMESIDEN (pr. dag inkl. moms)
{$price_table}

## TELT-RÅDGIVNING — STIL ALTID DISSE SPØRGSMÅL FØR DU ANBEFALER STØRRELSE

Når en kunde spørger om telt til X personer, stil uddybende spørgsmål:
1. **Bordtype** — aflange borde (75×180 cm) eller runde borde (Ø160 cm)?
2. **Bar/buffet** — skal der være en bar, buffetbord eller serveringsstation?
3. **Ståborde** — ønskes ståborde (kræver mere areal pr. person end siddepladser)?
4. **Dansegulv** — skal der være et dansegulv? Ca. 1 m² pr. 2 gæster.
5. **Scene/DJ** — musikanlæg, DJ-bord eller scene?
6. **Opstilling** — ønskes hjælp til opstilling og nedtagning?
7. **Leveringspostnummer** — for at beregne leveringspris

Stil gerne 2-3 spørgsmål ad gangen — ikke alle på én gang.

## PLADSBEHOV PR. ELEMENT (brug dette til at beregne teltareal)

**Siddepladser ved borde:**
- Aflangt bord 75×180 cm: 6-8 gæster — regn **1 m² pr. siddende gæst** inkl. stol og gangplads
- Rundt bord Ø160 cm: 8-10 gæster — regn **1,5 m² pr. siddende gæst** inkl. stol og gangplads

**Øvrige elementer:**
- Bar (mobilbar/fadølsbar): 6-8 m²
- Buffet/serveringsbord: 4-6 m²
- Ståborde (pr. stk.): 3-4 m² (gæster samles rundt om)
- Dansegulv: ~1 m² pr. 2 gæster (fx 25 m² til 50 gæster)
- DJ-bord/mixer: 3-4 m²
- Scene (sceneplader): afhænger af størrelse, min. 6-8 m²
- Toiletvogn: placeres uden for teltet

**Beregning af samlet teltareal:**
Grundareal (gæster × pladsfaktor) + ekstra elementer = nødvendigt areal
Vælg derefter det næststørste tilgængelige telt så der er luft.

**Eksempler:**
- 50 gæster, aflange borde, ingen dans: 50 × 1 = 50 m² → Telt 6×9m (54 m²) passer fint
- 50 gæster, aflange borde + dansegulv (25 m²): 50 + 25 = 75 m² → Telt 6×12m (72 m²) eller 9×9m (81 m²)
- 50 gæster, runde borde, ingen dans: 50 × 1,5 = 75 m² → Telt 6×12m (72 m²) eller 9×9m (81 m²)
- 50 gæster, runde borde + dansegulv (25 m²) + bar (6 m²): 75 + 25 + 6 = 106 m² → Telt 9×12m (108 m²)

Anbefal ALTID en størrelse der giver lidt ekstra plads — det er bedre at have for meget end for lidt. Nævn det næststørste alternativ som "komfortabel" og det mindste som "tight".

## TOMMELFINGERREGLER
- 1 aflangt bord (75×180) passer til 6-8 gæster
- 1 rundt bord (Ø160) passer til 8-10 gæster
- Runde borde giver et mere festligt udtryk men kræver mere plads end aflange
- Gulv (grå klinke): anbefal hvis det er på græs/jord — koster 20-24 kr/m²
- Anbefal opstilling/nedtagning medmindre kunden eksplicit fravælger det

## DATO OG OPSÆTNING — VIGTIGT
Du SKAL have disse oplysninger fra kunden inden du præsenterer et endeligt tilbud:
1. **Konkret dato** — hvilken dato/hvilke datoer skal teltet bruges? (fx "lørdag den 21. juni 2025")
   Beregn ud fra brugsdagene: start_date = første brugsdag, end_date = sidste brugsdag (ISO 8601: YYYY-MM-DD)
2. **Opstilling/nedtagning** — ønsker kunden hjælp hertil? Hvornår? Særlig adresse/adgangsforhold?
   (Opsætnings- og nedtagningsønsker skrives i ordrebemærkninger — spørg til det men lad kunden udfylde detaljer ved udtjekning)

Stil gerne dato-spørgsmålet tidligt i samtalen — fx: "Hvornår skal I bruge teltet? Angiv gerne den konkrete dato."

## MERYDELSER — FORESLÅ PROAKTIVT NÅR DET ER RELEVANT
Foreslå disse produkter når konteksten passer — men pres ikke:
- **Fadølsanlæg** → ved fest med bar, voksne selskaber, firmafest, bryllup
- **Barvogn** → ved byfester, større firmafester — vi har en flot barvogn som er ideel
- **Funfood-maskiner** (popcorn, slushice m.fl.) → ved børneselskaber, sommerfester, festivaler
- **All-inclusive barløsning** → ikke tilgængelig online, direkter kunden:
  *"For en komplet all-inclusive barløsning bedes du kontakte os direkte: tlf. 72 40 67 10 eller kontakt@lejpartytelt.dk"*

## PRODUKTALTERNATIVER (bruges hvis et produkt ikke er ledigt)
- Telt 6×9m, hvid ↔ Telt 9×6m, hvid (samme areal, anderledes form)
- Telt 6×12m, hvid ↔ Telt 9×9m, hvid
- Stol, polstret sort ↔ Stol, hvid m. sædehynde
- Stol, hvid plastik → Stol, hvid m. sædehynde (lidt mere komfort)

## TILBUD-FORMAT
Når du har nok information (inkl. dato) til at præsentere et konkret tilbud, afslut dit svar med denne blok (INGEN linjeskift inde i JSON):

[TILBUD_START]
{"tent":"Telt 6×9m, hvid","days":2,"multiplier":1.4,"start_date":"2025-06-21","end_date":"2025-06-22","lines":[{"name":"Telt 6×9m, hvid","qty":1,"unitPrice":2400,"multiplier":1.0,"lineTotal":2400},{"name":"Stol, hvid plastik","qty":50,"unitPrice":7.20,"multiplier":1.4,"lineTotal":504},{"name":"Barvogn","qty":1,"unitPrice":800,"multiplier":1.5,"lineTotal":1200}],"delivery":250,"total":4354,"summary":"Telt + 50 stole + barvogn + levering, 2 dage","setup_notes":""}
[TILBUD_SLUT]

Felter:
- start_date / end_date: ISO 8601 (YYYY-MM-DD) — SKAL udfyldes
- Hvert line-element har sin EGEN multiplier baseret på produktets faktorgruppe
- lineTotal = qty × unitPrice × line.multiplier (undtagen opstilling/nedtagning og levering som IKKE ganges med dage)
- Det globale "multiplier"-felt er til display (brug telt-/primær produktets faktor)
- total = sum af alle lineTotals + delivery
- setup_notes: sammenfat hvad kunden har sagt om opsætning/nedtagning (kan være tom streng)
- Brug kun [TILBUD_START]/[TILBUD_SLUT] når tilbuddet er komplet — vent med tilbuddet hvis dato mangler
PROMPT;
    }

    /* ── CACHE RYDNING ── */
    public function clear_price_cache() {
        delete_transient( 'lpt_price_table' );
        delete_transient( 'lpt_product_map' );
        delete_transient( 'lpt_rentman_meta_key' );
        delete_transient( 'lpt_rentman_factors' );
        delete_transient( 'lpt_rentman_equip_factors' );
    }

    /* ── SAMLET PRODUKT-DATA MAP (navn → {id, price, url, link, rentman_id}) ── */
    private function get_product_data_map() {
        $cached = get_transient( 'lpt_product_map' );
        if ( $cached !== false ) return $cached;

        if ( ! function_exists( 'wc_get_products' ) ) return [];

        $products    = wc_get_products( [ 'status' => 'publish', 'limit' => 500 ] );
        $rentman_key = $this->get_rentman_meta_key();
        $map         = [];

        foreach ( $products as $product ) {
            $id = $product->get_id();

            // Pris inkl. moms
            $price = function_exists( 'wc_get_price_including_tax' )
                ? (float) wc_get_price_including_tax( $product )
                : (float) $product->get_price();

            // Billede: featured image → galleribillede
            $image_id = $product->get_image_id();
            if ( ! $image_id ) {
                $gallery = $product->get_gallery_image_ids();
                if ( ! empty( $gallery ) ) $image_id = $gallery[0];
            }
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

            $permalink   = get_permalink( $id );
            $rentman_id  = $rentman_key ? (string) get_post_meta( $id, $rentman_key, true ) : '';

            if ( $permalink ) {
                $map[ $product->get_name() ] = [
                    'id'         => $id,
                    'price'      => $price,
                    'url'        => $image_url ?: '',
                    'link'       => $permalink,
                    'rentman_id' => $rentman_id,
                ];
            }
        }

        set_transient( 'lpt_product_map', $map, HOUR_IN_SECONDS );
        return $map;
    }

    /* ── AUTO-DETECT RENTMAN META-NØGLE ── */
    private function get_rentman_meta_key() {
        // Tjek indstilling (kan overskrives manuelt)
        $manual = get_option( 'lpt_rentman_meta_key', '' );
        if ( $manual ) return $manual;

        $cached = get_transient( 'lpt_rentman_meta_key' );
        if ( $cached !== false ) return $cached;

        // Kendte meta-nøgler for Rentman Advanced / AppSys ICT Group og lignende plugins
        $candidates = [ 'rentman_id', '_rentman_id', 'rentman_item_id', '_rentman_item_id',
                        'appsys_rentman_id', '_appsys_rentman_id', 'rmid', '_rmid' ];

        // Find et hvilket som helst udgivet produkt for at teste
        $test_products = wc_get_products( [ 'status' => 'publish', 'limit' => 10 ] );
        foreach ( $test_products as $product ) {
            $all_meta = get_post_meta( $product->get_id() );
            foreach ( $candidates as $key ) {
                if ( isset( $all_meta[ $key ] ) && ! empty( $all_meta[ $key ][0] ) ) {
                    set_transient( 'lpt_rentman_meta_key', $key, DAY_IN_SECONDS );
                    return $key;
                }
            }
        }

        // Ikke fundet — gem tom streng i 1 time og prøv igen næste gang
        set_transient( 'lpt_rentman_meta_key', '', HOUR_IN_SECONDS );
        return '';
    }

    /* ── RENTMAN TILGÆNGELIGHED ── */
    private function check_rentman_availability( array $items, string $start_date, string $end_date ) {
        $token = get_option( 'lpt_rentman_token', '' );
        if ( ! $token ) return [ 'error' => 'Ingen Rentman API-token konfigureret.' ];

        // Byg query for hvert item
        $equipment_params = '';
        foreach ( $items as $item ) {
            if ( ! empty( $item['rentman_id'] ) ) {
                $equipment_params .= '&equipment[]=' . rawurlencode( $item['rentman_id'] );
            }
        }

        if ( ! $equipment_params ) return [ 'error' => 'Ingen Rentman-IDs fundet for de valgte produkter.' ];

        $url = 'https://api.rentman.net/availability?from=' . rawurlencode( $start_date )
             . '&till=' . rawurlencode( $end_date ) . $equipment_params;

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization'  => 'Bearer ' . $token,
                'X-Api-Version'  => '3',
                'Accept'         => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return [ 'error' => 'Rentman API fejl (' . $code . ')' ];
        }

        return $body; // Array med tilgængeligheds-data
    }

    private function get_live_price_table() {
        $cached = get_transient( 'lpt_price_table' );
        if ( $cached !== false ) return $cached;

        if ( ! function_exists( 'wc_get_products' ) ) {
            return '(WooCommerce ikke tilgængeligt — brug backup-priser)';
        }

        // Hent alle udgivne produkter med pris > 0
        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => 500,
            'orderby'  => 'title',
            'order'    => 'ASC',
        ] );

        $tents      = [];
        $chairs     = [];
        $tables     = [];
        $floor      = [];
        $heat       = [];
        $light      = [];
        $setup      = [];
        $other      = [];

        foreach ( $products as $product ) {
            $price = function_exists( 'wc_get_price_including_tax' )
                ? (float) wc_get_price_including_tax( $product )
                : (float) $product->get_price();
            if ( $price <= 0 ) continue;

            $name  = $product->get_name();
            $lower = mb_strtolower( $name, 'UTF-8' );
            $line  = $name . ' = ' . number_format( $price, 2, ',', '.' ) . ' kr inkl. moms';

            if ( preg_match( '/^telt\s/iu', $name ) || preg_match( '/^pavillon/iu', $name ) || preg_match( '/^scenetelt/iu', $name ) ) {
                $tents[] = $line;
            } elseif ( preg_match( '/^stol/iu', $name ) ) {
                $chairs[] = $line;
            } elseif ( preg_match( '/^(bord|ståbord|rundt bord|bord\/bænk)/iu', $name ) ) {
                $tables[] = $line;
            } elseif ( preg_match( '/^gulv/iu', $name ) ) {
                $floor[] = $line;
            } elseif ( preg_match( '/^(varme|gasflaske)/iu', $name ) ) {
                $heat[] = $line;
            } elseif ( preg_match( '/^(lysguirlande|uplight|lysekrone|nødbelysning|paniklys)/iu', $name ) ) {
                $light[] = $line;
            } elseif ( preg_match( '/^(opstilling|op\/nedtagning|lejpartytelt\.dk op)/iu', $name ) ) {
                $setup[] = $line;
            } else {
                $other[] = $line;
            }
        }

        $table  = "### Telte\n" . implode( "\n", $tents ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Stole\n" . implode( "\n", $chairs ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Borde\n" . implode( "\n", $tables ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Gulv\n" . implode( "\n", $floor ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Varme\n" . implode( "\n", $heat ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Lys\n" . implode( "\n", $light ?: [ '(ingen fundet)' ] ) . "\n\n";
        $table .= "### Opstilling og nedtagning (engangspris — IKKE ganges med dage)\n" . implode( "\n", $setup ?: [ '(ingen fundet)' ] ) . "\n\n";
        if ( ! empty( $other ) ) {
            $table .= "### Øvrigt udstyr\n" . implode( "\n", array_slice( $other, 0, 50 ) ) . "\n";
        }

        // Cache i 1 time
        set_transient( 'lpt_price_table', $table, HOUR_IN_SECONDS );

        return $table;
    }
}

LPT_Prisberegner::get_instance();
