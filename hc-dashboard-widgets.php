<?php
/**
 * Plugin Name: HC Dashboard Widgets
 * Description: Προσθέτει custom dashboard widgets: KPIs, γράφημα 14 ημερών, γρήγορα links και ανακοινώσεις προσωπικού. WooCommerce-aware.
 * Version:     1.0.0
 * Author:      Δημήτρης Ηλιόπουλος
 * License:     GPLv2 or later
 * Text Domain: hc-dashboard-widgets
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class HC_Dashboard_Widgets {
    const OPT_LINKS = 'hcdw_quick_links';       // array of [label=>url]
    const OPT_NOTES = 'hcdw_staff_notes';       // string (HTML allowed, sanitized)
    const TRANSIENT_STATS = 'hcdw_wc_stats_14d';// cached stats

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widgets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Καθάρισε cache όταν ενημερώνεται παραγγελία
        add_action( 'woocommerce_update_order', [ $this, 'flush_stats_cache' ] );
        add_action( 'save_post_shop_order', [ $this, 'flush_stats_cache' ] );
    }

    /* ================= Settings ================= */

    public function add_settings_page() {
        add_options_page(
            'Dashboard Widgets',
            'Dashboard Widgets',
            'manage_options',
            'hcdw-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'hcdw_settings_group', self::OPT_LINKS, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_links' ],
            'default' => [
                [ 'label' => 'Παραγγελίες', 'url' => admin_url( 'admin.php?page=wc-orders' ) ],
                [ 'label' => 'Προϊόντα',   'url' => admin_url( 'edit.php?post_type=product' ) ],
                [ 'label' => 'Πελάτες',    'url' => admin_url( 'admin.php?page=wc-admin&path=/customers' ) ],
                [ 'label' => 'Webmail',    'url' => 'https://webmail.homoceramics.gr' ],
            ],
        ] );

        register_setting( 'hcdw_settings_group', self::OPT_NOTES, [
            'type' => 'string',
            'sanitize_callback' => [ $this, 'sanitize_notes' ],
            'default' => '• Παρακαλώ ελέγξτε τις παραγγελίες “Σε εκκρεμότητα” πριν το κλείσιμο βάρδιας.',
        ] );
    }

    public function sanitize_links( $value ) {
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $row ) {
            $label = isset( $row['label'] ) ? wp_strip_all_tags( $row['label'] ) : '';
            $url   = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
            if ( $label !== '' && $url !== '' ) {
                $out[] = [ 'label' => $label, 'url' => $url ];
            }
        }
        return $out;
    }

    public function sanitize_notes( $value ) {
        // Επιτρέπουμε basic markup
        return wp_kses_post( $value );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $links = get_option( self::OPT_LINKS, [] );
        $notes = get_option( self::OPT_NOTES, '' );
        ?>
        <div class="wrap">
            <h1>HC Dashboard Widgets</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'hcdw_settings_group' ); ?>

                <h2>Γρήγορα Links</h2>
                <p>Πρόσθεσε/αφαίρεσε γραμμές. Συμπλήρωσε <em>Ετικέτα</em> και <em>URL</em>.</p>
                <table class="widefat" id="hcdw-links-table">
                    <thead><tr><th>Ετικέτα</th><th>URL</th><th style="width:60px;">&nbsp;</th></tr></thead>
                    <tbody>
                    <?php if ( ! empty( $links ) ) : foreach ( $links as $i => $row ) : ?>
                        <tr>
                            <td><input type="text" name="<?php echo esc_attr( self::OPT_LINKS ); ?>[<?php echo (int)$i; ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" class="regular-text"></td>
                            <td><input type="url" name="<?php echo esc_attr( self::OPT_LINKS ); ?>[<?php echo (int)$i; ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text code"></td>
                            <td><button class="button hcdw-row-remove" type="button">Διαγραφή</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <p><button class="button" id="hcdw-row-add" type="button">+ Προσθήκη γραμμής</button></p>

                <h2 style="margin-top:24px;">Ανακοινώσεις Προσωπικού</h2>
                <?php
                wp_editor( $notes, self::OPT_NOTES, [
                    'textarea_name' => self::OPT_NOTES,
                    'textarea_rows' => 6,
                    'media_buttons' => false,
                ] );
                ?>

                <?php submit_button( 'Αποθήκευση Ρυθμίσεων' ); ?>
            </form>
        </div>
        <script>
        (function(){
            const table = document.getElementById('hcdw-links-table').getElementsByTagName('tbody')[0];
            document.getElementById('hcdw-row-add').addEventListener('click', () => {
                const idx = table.rows.length;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td><input type="text" name="<?php echo esc_attr( self::OPT_LINKS ); ?>[${idx}][label]" value="" class="regular-text"></td>
                  <td><input type="url" name="<?php echo esc_attr( self::OPT_LINKS ); ?>[${idx}][url]" value="" class="regular-text code"></td>
                  <td><button class="button hcdw-row-remove" type="button">Διαγραφή</button></td>
                `;
                table.appendChild(tr);
            });
            table.addEventListener('click', (e) => {
                if (e.target && e.target.classList.contains('hcdw-row-remove')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>
        <?php
    }

    /* ================= Dashboard Widgets ================= */

    public function register_dashboard_widgets() {
        // KPIs + chart (WooCommerce)
        wp_add_dashboard_widget( 'hcdw_wc_kpis', 'Πωλήσεις (WooCommerce)', [ $this, 'render_wc_kpis_widget' ] );
        wp_add_dashboard_widget( 'hcdw_wc_chart', 'Τζίρος 14 Ημερών', [ $this, 'render_wc_chart_widget' ] );

        // Quick links & staff notes (γενικά)
        wp_add_dashboard_widget( 'hcdw_quick_links', 'Γρήγορα Links', [ $this, 'render_quick_links_widget' ] );
        wp_add_dashboard_widget( 'hcdw_staff_notes', 'Ανακοινώσεις Προσωπικού', [ $this, 'render_staff_notes_widget' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( 'index.php' !== $hook ) return; // Μόνο στο Dashboard
        // Μικρό CSS για κάρτες KPIs
        $css = "
        .hcdw-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:6px}
        .hcdw-card{border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff}
        .hcdw-card h4{margin:0 0 6px;font-size:13px;color:#64748b}
        .hcdw-metric{font-size:22px;font-weight:600;margin:0}
        .hcdw-sub{font-size:12px;color:#6b7280;margin-top:4px}
        .hcdw-links a{display:inline-block;margin:0 8px 8px 0}
        .hcdw-chart-wrap{height:140px}
        canvas#hcdw-spark{width:100%;height:140px}
        ";
        wp_register_style( 'hcdw-css', false );
        wp_enqueue_style( 'hcdw-css' );
        wp_add_inline_style( 'hcdw-css', $css );
    }

    /* ================= Data ================= */

    private function has_wc(): bool {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order_types' );
    }

    public function flush_stats_cache() {
        delete_transient( self::TRANSIENT_STATS );
    }

    private function get_wc_stats_14d(): array {
        $cached = get_transient( self::TRANSIENT_STATS );
        if ( is_array( $cached ) ) return $cached;

        if ( ! $this->has_wc() ) {
            $data = [
                'today_orders' => 0,
                'today_revenue' => 0.0,
                'w7_orders' => 0,
                'w7_revenue' => 0.0,
                'days' => [], // [ ['date'=>'Y-m-d','total'=>float], ... ]
                'currency' => get_woocommerce_currency_symbol( get_option('woocommerce_currency','EUR') ),
            ];
            set_transient( self::TRANSIENT_STATS, $data, 5 * MINUTE_IN_SECONDS );
            return $data;
        }

        // Build day buckets for last 14 days including today
        $tz = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $start14 = $now->sub( new DateInterval( 'P13D' ) )->setTime(0,0,0);
        $end = $now->setTime(23,59,59);

        $days = [];
        for ( $i = 0; $i < 14; $i++ ) {
            $d = $start14->add( new DateInterval( 'P' . $i . 'D' ) );
            $days[ $d->format('Y-m-d') ] = 0.0;
        }

        // Query paid/processing/completed orders in range
        $statuses = apply_filters( 'hcdw_wc_count_statuses', [ 'wc-processing', 'wc-completed' ] );

        $query = new WC_Order_Query( [
            'status'       => $statuses,
            'type'         => wc_get_order_types( 'view-orders' ),
            'date_created' => $start14->format('Y-m-d H:i:s') . '...' . $end->format('Y-m-d H:i:s'),
            'return'       => 'ids',
            'limit'        => -1,
        ] );
        $order_ids = $query->get_orders();

        $today_key = $now->format('Y-m-d');
        $w7_start = $now->sub( new DateInterval('P6D') )->setTime(0,0,0);

        $today_orders = 0;
        $today_rev = 0.0;
        $w7_orders = 0;
        $w7_rev = 0.0;

        if ( ! empty( $order_ids ) ) {
            foreach ( $order_ids as $oid ) {
                $o = wc_get_order( $oid );
                if ( ! $o ) continue;
                $created = $o->get_date_created();
                if ( ! $created ) continue;
                $created_local = $created->date_i18n( 'Y-m-d H:i:s' );
                $dt = new DateTimeImmutable( $created_local, $tz );
                $key = $dt->format('Y-m-d');
                $total = (float) $o->get_total();

                if ( isset( $days[ $key ] ) ) {
                    $days[ $key ] += $total;
                }

                if ( $key === $today_key ) {
                    $today_orders++;
                    $today_rev += $total;
                }
                if ( $dt >= $w7_start ) {
                    $w7_orders++;
                    $w7_rev += $total;
                }
            }
        }

        $out_days = [];
        foreach ( $days as $k => $v ) {
            $out_days[] = [ 'date' => $k, 'total' => round( $v, 2 ) ];
        }

        $data = [
            'today_orders'  => $today_orders,
            'today_revenue' => round( $today_rev, 2 ),
            'w7_orders'     => $w7_orders,
            'w7_revenue'    => round( $w7_rev, 2 ),
            'days'          => $out_days,
            'currency'      => get_woocommerce_currency_symbol( get_option('woocommerce_currency','EUR') ),
        ];

        set_transient( self::TRANSIENT_STATS, $data, 5 * MINUTE_IN_SECONDS );
        return $data;
    }

    /* ================= Renders ================= */

    public function render_wc_kpis_widget() {
        if ( ! $this->has_wc() ) {
            echo '<p>Το WooCommerce δεν είναι ενεργό. Τα KPIs θα εμφανιστούν όταν ενεργοποιηθεί.</p>';
            return;
        }
        $s = $this->get_wc_stats_14d();
        ?>
        <div class="hcdw-kpis">
            <div class="hcdw-card">
                <h4>Σήμερα - Παραγγελίες</h4>
                <p class="hcdw-metric"><?php echo (int) $s['today_orders']; ?></p>
                <p class="hcdw-sub">Επεξεργασία & Ολοκληρωμένες</p>
            </div>
            <div class="hcdw-card">
                <h4>Σήμερα - Τζίρος</h4>
                <p class="hcdw-metric"><?php echo esc_html( $s['currency'] . ' ' . number_format_i18n( $s['today_revenue'], 2 ) ); ?></p>
                <p class="hcdw-sub">Σύνολο παραγγελιών</p>
            </div>
            <div class="hcdw-card">
                <h4>7 Ημέρες - Παραγγελίες</h4>
                <p class="hcdw-metric"><?php echo (int) $s['w7_orders']; ?></p>
                <p class="hcdw-sub">Τελευταίες 7 ημέρες</p>
            </div>
            <div class="hcdw-card">
                <h4>7 Ημέρες - Τζίρος</h4>
                <p class="hcdw-metric"><?php echo esc_html( $s['currency'] . ' ' . number_format_i18n( $s['w7_revenue'], 2 ) ); ?></p>
                <p class="hcdw-sub">Τελευταίες 7 ημέρες</p>
            </div>
        </div>
        <?php
    }

    public function render_wc_chart_widget() {
        if ( ! $this->has_wc() ) {
            echo '<p>Το WooCommerce δεν είναι ενεργό. Το γράφημα θα εμφανιστεί όταν ενεργοποιηθεί.</p>';
            return;
        }
        $s = $this->get_wc_stats_14d();
        $labels = array_map( function( $d ){ return esc_js( date_i18n('d/m', strtotime( $d['date'] ) ) ); }, $s['days'] );
        $values = array_map( function( $d ){ return (float) $d['total']; }, $s['days'] );
        ?>
        <div class="hcdw-chart-wrap">
            <canvas id="hcdw-spark"></canvas>
        </div>
        <p class="hcdw-sub">Τζίρος ανά ημέρα (τελευταίες 14 ημέρες)</p>
        <script>
        (function(){
            const labels = <?php echo wp_json_encode( $labels ); ?>;
            const values = <?php echo wp_json_encode( $values ); ?>;
            const canvas = document.getElementById('hcdw-spark');
            const ctx = canvas.getContext('2d');

            // Resize to container
            function fit() {
                const parent = canvas.parentElement.getBoundingClientRect();
                canvas.width = Math.floor(parent.width);
                canvas.height = Math.floor(parent.height);
            }
            fit(); window.addEventListener('resize', fit);

            // Draw sparkline
            function draw(){
                const w = canvas.width, h = canvas.height;
                ctx.clearRect(0,0,w,h);
                const pad = 16;
                const min = Math.min.apply(null, values.concat([0]));
                const max = Math.max.apply(null, values.concat([1]));
                const range = (max - min) || 1;
                const stepX = (w - pad*2) / (values.length - 1);

                // Grid (light)
                ctx.globalAlpha = 0.1;
                ctx.beginPath();
                for (let i=0;i<5;i++){
                    const y = pad + (i*(h-pad*2)/4);
                    ctx.moveTo(pad,y); ctx.lineTo(w-pad,y);
                }
                ctx.strokeStyle = '#000'; ctx.stroke();
                ctx.globalAlpha = 1;

                // Line
                ctx.beginPath();
                values.forEach((v, i) => {
                    const x = pad + stepX * i;
                    const y = h - pad - ((v - min) / range) * (h - pad*2);
                    if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
                });
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#2271b1';
                ctx.stroke();

                // Last point dot
                const lastIdx = values.length - 1;
                const lx = pad + stepX * lastIdx;
                const ly = h - pad - ((values[lastIdx] - min) / range) * (h - pad*2);
                ctx.beginPath(); ctx.arc(lx, ly, 3, 0, Math.PI*2);
                ctx.fillStyle = '#2271b1'; ctx.fill();
            }
            draw();
        })();
        </script>
        <?php
    }

    public function render_quick_links_widget() {
        $links = get_option( self::OPT_LINKS, [] );
        if ( empty( $links ) ) { echo '<p>Δεν έχουν οριστεί links. Ρύθμισε από <a href="'.esc_url( admin_url('options-general.php?page=hcdw-settings') ).'">Settings → Dashboard Widgets</a>.</p>'; return; }
        echo '<div class="hcdw-links">';
        foreach ( $links as $row ) {
            printf(
                '<a class="button button-secondary" href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url( $row['url'] ),
                esc_html( $row['label'] )
            );
        }
        echo '</div>';
    }

    public function render_staff_notes_widget() {
        $notes = get_option( self::OPT_NOTES, '' );
        if ( ! $notes ) { echo '<p>Καμία ανακοίνωση. Πρόσθεσε από <a href="'.esc_url( admin_url('options-general.php?page=hcdw-settings') ).'">Settings → Dashboard Widgets</a>.</p>'; return; }
        echo wp_kses_post( wpautop( $notes ) );
    }
}

new HC_Dashboard_Widgets();
