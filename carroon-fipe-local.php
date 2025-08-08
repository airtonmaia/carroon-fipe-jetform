<?php
/**
 * Plugin Name: Carroon FIPE DB
 * Description: Importa CSV para a tabela (wp_)fipe e mantém a tabela atualizada sob demanda via API v2 (com token). Expõe REST para o formulário (marcas, modelos, anos, preço) e confere mudanças mensalmente.
 * Version: 1.1.0
 * Author: Carroon
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Carroon_Fipe_DB {
    const VERSION   = '1.1.0';
    const TABLE     = 'fipe'; // usa prefixo do WP: wp_fipe
    const PAGE_SLUG = 'carroon-fipe-db';
    const OPT_TOKEN = 'carroon_fipe_token';

    private $base_v2 = 'https://fipe.parallelum.com.br/api/v2';

    public function __construct() {
        add_action('admin_menu', [ $this, 'admin_menu' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_action('admin_post_carroon_fipe_import', [ $this, 'handle_import' ]);

        add_action('rest_api_init', [ $this, 'register_routes' ]);

        // (Opcional) carrega o JS que popula o formulário JetFormBuilder
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_js' ]);

        register_activation_hook(__FILE__, [ __CLASS__, 'activate' ]);
    }

    /** ---------- helpers ---------- */
    private function table() {
        global $wpdb;
        // Se quiser SEM prefixo, troque para: return self::TABLE;
        return $wpdb->prefix . self::TABLE;
    }
    private function v2slug($type){
        return [
            'carros'    => 'cars',
            'motos'     => 'motorcycles',
            'caminhoes' => 'trucks',
        ][$type] ?? 'cars';
    }
    private function http_json($url, $use_token = true){
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => 'CarroonFIPE-DB/'.self::VERSION.' (+https://carroon.com.br)',
        ];
        $token = get_option(self::OPT_TOKEN, '');
        if ($use_token && $token) $headers['X-Subscription-Token'] = $token;

        $resp = wp_remote_get($url, ['timeout'=>60,'headers'=>$headers,'redirection'=>2]);
        if ( is_wp_error($resp) ) return [];
        if ( (int) wp_remote_retrieve_response_code($resp) !== 200 ) return [];
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : [];
    }
    // “Ficou velho?” => se último update é anterior ao 1º dia do mês atual
    private function is_stale($updated_at){
        if (!$updated_at) return true;
        $startOfMonth = strtotime(date('Y-m-01 00:00:00'));
        return strtotime($updated_at) < $startOfMonth;
    }
    // Normaliza preço para comparação (remove tudo que não é dígito)
    private function normalize_price($s){
        return preg_replace('/\D+/', '', (string) $s);
    }
    // Chave mensal para throttling
    private function month_key(){
        return date('Y-m');
    }

    /** ---------- ativação ---------- */
    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,          -- carros|motos|caminhoes
            brand_code VARCHAR(32) NOT NULL,
            brand_name VARCHAR(255) NOT NULL,
            model_code VARCHAR(32) NOT NULL,
            model_name VARCHAR(255) NOT NULL,
            year_code VARCHAR(32) NOT NULL,
            year_name VARCHAR(255) NOT NULL,
            price VARCHAR(50) DEFAULT '',
            reference_month VARCHAR(50) DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq (type,brand_code,model_code,year_code),
            KEY idx_brand (type,brand_code),
            KEY idx_model (type,brand_code,model_code),
            KEY idx_updated (updated_at)
        ) {$charset};";
        dbDelta($sql);
    }

    /** ---------- admin ---------- */
    public function register_settings(){
        register_setting('carroon_fipe_db', self::OPT_TOKEN, ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    }
    public function admin_menu(){
        add_menu_page('FIPE DB','FIPE DB','manage_options',self::PAGE_SLUG,[ $this,'admin_page' ],'dashicons-database',57);
    }
    public function admin_page(){
        if ( ! current_user_can('manage_options') ) return;

        $tok = get_option(self::OPT_TOKEN,'');
        ?>
        <div class="wrap">
            <h1>FIPE DB</h1>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab nav-tab-active" href="#">Importar CSV & Configuração</a>
            </h2>

            <h2>Importar CSV</h2>
            <p>CSV com cabeçalho no formato abaixo (aceitamos ambos):</p>
            <ul style="margin-left:18px">
                <li><code>Type, Brand Code, Brand Value, Model Code, Model Value, Year Code, Year Value, Fipe Code, Fuel Letter, Fuel Type, Price, Month</code> (CSV PRO)</li>
                <li><code>type,brand_code,brand_name,model_code,model_name,year_code,year_name,price,reference_month</code> (PT)</li>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('carroon_fipe_import'); ?>
                <input type="hidden" name="action" value="carroon_fipe_import">
                <input type="file" name="csv" accept=".csv" required>
                <p><label><input type="checkbox" name="truncate" value="1"> Limpar a tabela antes (TRUNCATE)</label></p>
                <p><button class="button button-primary">Importar</button></p>
            </form>

            <hr>

            <h2>Configurações</h2>
            <form method="post" action="options.php">
                <?php settings_fields('carroon_fipe_db'); ?>
                <table class="form-table">
                    <tr>
                        <th>Token FIPE v2 (X-Subscription-Token)</th>
                        <td>
                            <input type="password" class="regular-text" name="<?php echo esc_attr(self::OPT_TOKEN); ?>" value="<?php echo esc_attr($tok); ?>">
                            <p class="description">Usado apenas no servidor para atualizar itens quando o mês vira ou quando o registro não existir.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** ---------- import CSV ---------- */
    public function handle_import(){
        if ( ! current_user_can('manage_options') ) wp_die('No perms');
        check_admin_referer('carroon_fipe_import');
        if ( empty($_FILES['csv']['tmp_name']) ) wp_die('Arquivo ausente');

        $fh = fopen($_FILES['csv']['tmp_name'],'r');
        if (!$fh) wp_die('Falha ao abrir CSV');

        $truncate = !empty($_POST['truncate']);

        // detecta header
        $header = fgetcsv($fh, 0, ',');
        $cols = array_map(fn($s)=>strtolower(trim($s)), $header ?: []);
        $pt = ['type','brand_code','brand_name','model_code','model_name','year_code','year_name','price','reference_month'];
        $en = ['type','brand code','brand value','model code','model value','year code','year value','fipe code','fuel letter','fuel type','price','month'];

        $mode = null;
        if ($cols === $pt) $mode='pt';
        elseif ($cols === $en) $mode='en';
        else { fclose($fh); wp_die('Cabeçalho não reconhecido.'); }

        global $wpdb; $t = $this->table();
        if ($truncate) $wpdb->query("TRUNCATE TABLE {$t}");

        $stmt = "REPLACE INTO {$t}
                 (type,brand_code,brand_name,model_code,model_name,year_code,year_name,price,reference_month)
                 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)";
        $type_map = ['CAR'=>'carros','MOTORCYCLE'=>'motos','TRUCK'=>'caminhoes'];

        if ( function_exists('set_time_limit') ) @set_time_limit(0);
        $count=0;
        while( ($row = fgetcsv($fh, 0, ',')) !== false ){
            if ($mode==='pt'){
                if (count($row) < 9) continue;
                [$type,$bc,$bn,$mc,$mn,$yc,$yn,$price,$month] = array_map('trim',$row);
            } else {
                if (count($row) < 12) continue;
                $r = array_combine($cols, array_map('trim',$row));
                $type  = $type_map[$r['type']] ?? strtolower($r['type']);
                $bc    = $r['brand code'];
                $bn    = $r['brand value'];
                $mc    = $r['model code'];
                $mn    = $r['model value'];
                $yc    = $r['year code'];
                $yn    = $r['year value'];
                $price = $r['price'];
                $month = $r['month'];
            }
            $wpdb->query( $wpdb->prepare($stmt, $type,$bc,$bn,$mc,$mn,$yc,$yn,$price,$month) );
            $count++;
        }
        fclose($fh);

        wp_redirect( admin_url('admin.php?page='.self::PAGE_SLUG.'&imported='.$count) );
        exit;
    }

    /** ---------- REST (usa DB; atualiza sob demanda/mensal) ---------- */
    public function register_routes(){
        $ns = 'carroon-fipe/v1';

        register_rest_route($ns,'/marcas',[
            'methods'=>'GET','permission_callback'=>'__return_true',
            'args'=>['type'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field']],
            'callback'=>function($req){
                global $wpdb; $t=$this->table();
                $type=sanitize_text_field($req['type']);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT brand_code AS codigo, brand_name AS nome FROM {$t} WHERE type=%s GROUP BY brand_code,brand_name ORDER BY brand_name",$type), ARRAY_A);
                if ($rows && count($rows)) return $rows;

                // fallback (não grava)
                $slug=$this->v2slug($type);
                $list=$this->http_json("{$this->base_v2}/{$slug}/brands", true);
                return array_map(fn($x)=>['codigo'=>(string)($x['code']??''),'nome'=>(string)($x['name']??'')], $list?:[]);
            }
        ]);

        register_rest_route($ns,'/modelos',[
            'methods'=>'GET','permission_callback'=>'__return_true',
            'args'=>[
                'type'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'brand'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
            ],
            'callback'=>function($req){
                global $wpdb; $t=$this->table();
                $type=sanitize_text_field($req['type']); $brand=sanitize_text_field($req['brand']);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT model_code AS codigo, model_name AS nome FROM {$t} WHERE type=%s AND brand_code=%s GROUP BY model_code,model_name ORDER BY model_name",$type,$brand), ARRAY_A);
                if ($rows && count($rows)) return $rows;

                // fallback (não grava)
                $slug=$this->v2slug($type);
                $list=$this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models", true);
                return array_map(fn($x)=>['codigo'=>(string)($x['code']??''),'nome'=>(string)($x['name']??'')], $list?:[]);
            }
        ]);

        register_rest_route($ns,'/anos',[
            'methods'=>'GET','permission_callback'=>'__return_true',
            'args'=>[
                'type'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'brand'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'model'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
            ],
            'callback'=>function($req){
                global $wpdb; $t=$this->table();
                $type=sanitize_text_field($req['type']); $brand=sanitize_text_field($req['brand']); $model=sanitize_text_field($req['model']);
                $rows = $wpdb->get_results($wpdb->prepare("SELECT year_code AS codigo, year_name AS nome FROM {$t} WHERE type=%s AND brand_code=%s AND model_code=%s GROUP BY year_code,year_name ORDER BY year_name",$type,$brand,$model), ARRAY_A);
                if ($rows && count($rows)) return $rows;

                // fallback (não grava)
                $slug=$this->v2slug($type);
                $list=$this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models/{$model}/years", true);
                return array_map(fn($x)=>['codigo'=>(string)($x['code']??''),'nome'=>(string)($x['name']??'')], $list?:[]);
            }
        ]);

        // >>>>> CHECAGEM MENSAL E UPSERT NO PREÇO <<<<<
        register_rest_route($ns,'/preco',[
            'methods'=>'GET','permission_callback'=>'__return_true',
            'args'=>[
                'type'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'brand'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'model'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                'year'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
            ],
            'callback'=>function($req){
                global $wpdb; $t=$this->table();

                $type  = sanitize_text_field($req['type']);
                $brand = sanitize_text_field($req['brand']);
                $model = sanitize_text_field($req['model']);
                $year  = sanitize_text_field($req['year']);

                // 1) lê do DB
                $row = $wpdb->get_row(
                    $wpdb->prepare("SELECT price,reference_month,updated_at,brand_name,model_name,year_name
                                    FROM {$t} WHERE type=%s AND brand_code=%s AND model_code=%s AND year_code=%s LIMIT 1",
                                   $type,$brand,$model,$year), ARRAY_A
                );

                $needs_api = false;
                if ( ! $row || $this->is_stale($row['updated_at'] ?? null) ) {
                    $needs_api = true;
                }

                // Throttle: evita bater toda hora no mesmo item no mesmo mês
                $throttle_key = 'fipe_chk_' . md5($type.'|'.$brand.'|'.$model.'|'.$year.'|'.$this->month_key());
                if ( $needs_api && get_transient($throttle_key) ) {
                    $needs_api = false;
                }

                if ( $needs_api ) {
                    $slug = $this->v2slug($type);
                    $det  = $this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models/{$model}/years/{$year}", true);

                    if ( $det && is_array($det) ) {
                        $brand_name = (string)($det['brand'] ?? ($row['brand_name'] ?? ''));
                        $model_name = (string)($det['model'] ?? ($row['model_name'] ?? ''));
                        $year_name  = (string)($det['year']  ?? ($row['year_name']  ?? ''));
                        $price_new  = (string)($det['price'] ?? '');
                        $month_new  = (string)($det['referenceMonth'] ?? '');

                        $changed = false;
                        if ( ! $row ) {
                            $changed = true;
                        } else {
                            $changed = (
                                $this->normalize_price($row['price']) !== $this->normalize_price($price_new)
                                || trim(mb_strtolower($row['reference_month'])) !== trim(mb_strtolower($month_new))
                            );
                        }

                        if ( $changed ) {
                            // UPSERT
                            $wpdb->query( $wpdb->prepare(
                                "REPLACE INTO {$t}
                                 (type,brand_code,brand_name,model_code,model_name,year_code,year_name,price,reference_month)
                                 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                                $type,$brand,$brand_name,$model,$model_name,$year,$year_name,$price_new,$month_new
                            ) );
                            $row = ['price'=>$price_new,'reference_month'=>$month_new];
                        } else {
                            // Só renova o updated_at
                            $wpdb->query( $wpdb->prepare(
                                "UPDATE {$t} SET updated_at = NOW() WHERE type=%s AND brand_code=%s AND model_code=%s AND year_code=%s",
                                $type,$brand,$model,$year
                            ) );
                            $row = ['price'=>$row['price'],'reference_month'=>$row['reference_month']];
                        }

                        // Não repetimos a checagem por 6h
                        set_transient($throttle_key, 1, 6 * HOUR_IN_SECONDS);
                    } else {
                        // API falhou: segura por 3h pra não insistir
                        set_transient($throttle_key, 1, 3 * HOUR_IN_SECONDS);
                    }
                }

                // Saída final
                $out_price = $row['price'] ?? '';
                $out_month = $row['reference_month'] ?? '';

                return ['valor'=>$out_price, 'mes'=>$out_month];
            }
        ]);
    }




    /** ---------- JS do formulário (JetFormBuilder) ---------- */
    public function enqueue_js() {
        if ( ! is_user_logged_in() ) return; // ajuste se quiser para visitantes também
        wp_enqueue_script(
            'carroon-fipe-localdb',
            plugin_dir_url(__FILE__).'fipe-localdb.js',
            ['jquery'],
            self::VERSION,
            true
        );
        wp_localize_script('carroon-fipe-localdb','CARROON_FIPE_LOCAL',[
            'base' => rest_url('carroon-fipe/v1'),
            'fields' => [
                'tipo'           => 'tipo_veiculo', // rádio: carros|motos|caminhoes
                'marca'          => '__marca',
                'marca_nome'     => 'rotulo_marca',
                'modelo'         => '__modelo',
                'modelo_nome'    => 'model_value',
                'ano'            => '__ano_modelo',
                'preco_medio'    => '__valor_fipe',
                'mes_referencia' => 'month',
                // 'regular_price'  => '_regular_price',
            ]
        ]);
    }
}

new Carroon_Fipe_DB();

add_action( 'template_redirect', function(){
    if ( ! is_user_logged_in() ) return;
    if ( ! is_page('editar-produto') ) return; // ajuste o slug da página que exibe o form
    $pid = absint( $_GET['pid'] ?? 0 );
    if ( ! $pid ) return;
    $post = get_post( $pid );
    if ( ! $post || $post->post_type !== 'product' ) {
        wp_die('Produto inválido', 403);
    }
    if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can('manage_woocommerce') ) {
        wp_die('Você não pode editar este produto.', 403);
    }
});



