<?php
/**
 * Plugin Name: Carroon FIPE DB
 * Description: Plugin para importar a tabela FIPE para o banco de dados local e fornecer endpoints
 *               REST para consultar marcas, modelos, anos, preços e metadados de produtos. Também
 *               oferece um endpoint para recuperar os metadados de um produto cadastrado para
 *               preenchimento automático de formulários de edição no front‑end.
 * Version:     1.2.0
 * Author:      Carroon
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Evita acesso direto
}

/**
 * Classe principal do plugin Carroon FIPE DB.
 */
final class Carroon_Fipe_DB {

    /**
     * Versão do plugin.
     *
     * @var string
     */
    const VERSION = '1.2.0';

    /**
     * Nome da tabela personalizada (sem prefixo).
     *
     * @var string
     */
    const TABLE = 'fipe';

    /**
     * Slug da página de administração.
     *
     * @var string
     */
    const PAGE_SLUG = 'carroon-fipe-db';

    /**
     * Option key para salvar o token da FIPE (v2).
     *
     * @var string
     */
    const OPT_TOKEN = 'carroon_fipe_token';

    /**
     * Endpoint base da FIPE v2 (Parallelum). Utilizado apenas quando for necessário
     * completar dados que não estejam no banco local.
     *
     * @var string
     */
    private $base_v2 = 'https://fipe.parallelum.com.br/api/v2';

    /**
     * Construtor. Registra ganchos de administração, endpoints REST e scripts.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_carroon_fipe_import', [ $this, 'handle_import' ] );
        add_action( 'rest_api_init', function() {
            $this->register_routes();
            $this->register_product_meta_route();
        } );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_js' ] );
    }

    /**
     * Define o nome da tabela com prefixo do WordPress.
     *
     * @return string Nome da tabela com prefixo (ex.: wp_fipe)
     */
    private function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Ativação do plugin: cria a tabela personalizada.
     */
    public static function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (\n"
             . "  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
             . "  type VARCHAR(20) NOT NULL,\n"
             . "  brand_code VARCHAR(32) NOT NULL,\n"
             . "  brand_name VARCHAR(255) NOT NULL,\n"
             . "  model_code VARCHAR(32) NOT NULL,\n"
             . "  model_name VARCHAR(255) NOT NULL,\n"
             . "  year_code VARCHAR(32) NOT NULL,\n"
             . "  year_name VARCHAR(255) NOT NULL,\n"
             . "  price VARCHAR(50) DEFAULT '',\n"
             . "  reference_month VARCHAR(50) DEFAULT '',\n"
             . "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
             . "  PRIMARY KEY (id),\n"
             . "  UNIQUE KEY uniq (type, brand_code, model_code, year_code),\n"
             . "  KEY idx_brand (type, brand_code),\n"
             . "  KEY idx_model (type, brand_code, model_code),\n"
             . "  KEY idx_updated (updated_at)\n"
             . ") {$charset};";
        dbDelta( $sql );
    }

    /**
     * Registra configurações de administração (salva token FIPE).
     */
    public function register_settings() {
        register_setting( 'carroon_fipe_db', self::OPT_TOKEN, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    /**
     * Cria menu na área de administração.
     */
    public function admin_menu() {
        add_menu_page(
            'FIPE DB',
            'FIPE DB',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'admin_page' ],
            'dashicons-database',
            57
        );
    }

    /**
     * Exibe a página de administração com formulário de importação e configuração.
     */
    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $token  = get_option( self::OPT_TOKEN, '' );
        $imported = isset( $_GET['imported'] ) ? intval( $_GET['imported'] ) : 0;
        ?>
        <div class="wrap">
            <h1>FIPE DB</h1>
            <?php if ( $imported ) : ?>
                <div class="updated notice"><p><?php printf( esc_html__( 'Importado %d registros.', 'carroon-fipe-db' ), $imported ); ?></p></div>
            <?php endif; ?>
            <h2>Importar CSV</h2>
            <p>Envie um CSV com cabeçalho em um dos formatos aceitos:</p>
            <ul>
                <li><code>Type, Brand Code, Brand Value, Model Code, Model Value, Year Code, Year Value, Fipe Code, Fuel Letter, Fuel Type, Price, Month</code></li>
                <li><code>type,brand_code,brand_name,model_code,model_name,year_code,year_name,price,reference_month</code></li>
            </ul>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'carroon_fipe_import' ); ?>
                <input type="hidden" name="action" value="carroon_fipe_import" />
                <p><input type="file" name="csv" accept=".csv" required /></p>
                <p><label><input type="checkbox" name="truncate" value="1" /> <?php esc_html_e( 'Limpar a tabela antes de importar (TRUNCATE)', 'carroon-fipe-db' ); ?></label></p>
                <p><button class="button button-primary" type="submit"><?php esc_html_e( 'Importar', 'carroon-fipe-db' ); ?></button></p>
            </form>
            <hr />
            <h2>Configurações</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'carroon_fipe_db' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr( self::OPT_TOKEN ); ?>">Token FIPE (X‑Subscription‑Token)</label></th>
                        <td><input type="password" name="<?php echo esc_attr( self::OPT_TOKEN ); ?>" value="<?php echo esc_attr( $token ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Processa o upload de CSV e insere/atualiza dados na tabela local.
     */
    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sem permissão' );
        }
        check_admin_referer( 'carroon_fipe_import' );
        if ( empty( $_FILES['csv']['tmp_name'] ) ) {
            wp_die( 'Arquivo ausente' );
        }
        $file = $_FILES['csv']['tmp_name'];
        $fh   = fopen( $file, 'r' );
        if ( ! $fh ) {
            wp_die( 'Falha ao abrir CSV' );
        }
        $truncate = ! empty( $_POST['truncate'] );
        $header   = fgetcsv( $fh, 0, ',' );
        $cols     = array_map( function( $s ) {
            return strtolower( trim( $s ) );
        }, $header ?: [] );
        $pt = [ 'type', 'brand_code', 'brand_name', 'model_code', 'model_name', 'year_code', 'year_name', 'price', 'reference_month' ];
        $en = [ 'type', 'brand code', 'brand value', 'model code', 'model value', 'year code', 'year value', 'fipe code', 'fuel letter', 'fuel type', 'price', 'month' ];
        $mode = null;
        if ( $cols === $pt ) {
            $mode = 'pt';
        } elseif ( $cols === $en ) {
            $mode = 'en';
        } else {
            fclose( $fh );
            wp_die( 'Cabeçalho inválido' );
        }
        global $wpdb;
        $table = $this->table();
        if ( $truncate ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }
        $type_map = [ 'CAR' => 'carros', 'MOTORCYCLE' => 'motos', 'TRUCK' => 'caminhoes' ];
        $count    = 0;
        if ( function_exists( 'set_time_limit' ) ) {@set_time_limit( 0 );}
        while ( ( $row = fgetcsv( $fh, 0, ',' ) ) !== false ) {
            if ( $mode === 'pt' ) {
                if ( count( $row ) < 9 ) {
                    continue;
                }
                list( $type, $bc, $bn, $mc, $mn, $yc, $yn, $price, $month ) = array_map( 'trim', $row );
            } else {
                if ( count( $row ) < 12 ) {
                    continue;
                }
                $r     = array_combine( $cols, array_map( 'trim', $row ) );
                $type  = isset( $type_map[ $r['type'] ] ) ? $type_map[ $r['type'] ] : strtolower( $r['type'] );
                $bc    = $r['brand code'];
                $bn    = $r['brand value'];
                $mc    = $r['model code'];
                $mn    = $r['model value'];
                $yc    = $r['year code'];
                $yn    = $r['year value'];
                $price = $r['price'];
                $month = $r['month'];
            }
            $wpdb->query( $wpdb->prepare(
                "REPLACE INTO {$table} (type,brand_code,brand_name,model_code,model_name,year_code,year_name,price,reference_month)" .
                " VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                $type,
                $bc,
                $bn,
                $mc,
                $mn,
                $yc,
                $yn,
                $price,
                $month
            ) );
            $count++;
        }
        fclose( $fh );
        wp_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&imported=' . $count ) );
        exit;
    }

    /**
     * Registra endpoints REST para marcas, modelos, anos e preço.
     */
    public function register_routes() {
        $ns = 'carroon-fipe/v1';
        // Marcas
        register_rest_route( $ns, '/marcas', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'type' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
            'callback'            => function( $req ) {
                global $wpdb;
                $type = sanitize_text_field( $req['type'] );
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT brand_code AS codigo, brand_name AS nome FROM {$this->table()} WHERE type=%s GROUP BY brand_code,brand_name ORDER BY brand_name", $type ), ARRAY_A );
                return $rows ?: [];
            },
        ] );
        // Modelos
        register_rest_route( $ns, '/modelos', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'type'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'brand' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
            'callback'            => function( $req ) {
                global $wpdb;
                $type  = sanitize_text_field( $req['type'] );
                $brand = sanitize_text_field( $req['brand'] );
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT model_code AS codigo, model_name AS nome FROM {$this->table()} WHERE type=%s AND brand_code=%s GROUP BY model_code,model_name ORDER BY model_name", $type, $brand ), ARRAY_A );
                return $rows ?: [];
            },
        ] );
        // Anos
        register_rest_route( $ns, '/anos', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'type'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'brand' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'model' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
            'callback'            => function( $req ) {
                global $wpdb;
                $type  = sanitize_text_field( $req['type'] );
                $brand = sanitize_text_field( $req['brand'] );
                $model = sanitize_text_field( $req['model'] );
                $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT year_code AS codigo, year_name AS nome FROM {$this->table()} WHERE type=%s AND brand_code=%s AND model_code=%s GROUP BY year_code,year_name ORDER BY year_code DESC", $type, $brand, $model ), ARRAY_A );
                return $rows ?: [];
            },
        ] );
        // Preço
        register_rest_route( $ns, '/preco', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'type'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'brand' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'model' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'year'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
            'callback'            => function( $req ) {
                global $wpdb;
                $type  = sanitize_text_field( $req['type'] );
                $brand = sanitize_text_field( $req['brand'] );
                $model = sanitize_text_field( $req['model'] );
                $year  = sanitize_text_field( $req['year'] );
                $row   = $wpdb->get_row( $wpdb->prepare( "SELECT price AS valor, reference_month AS mes FROM {$this->table()} WHERE type=%s AND brand_code=%s AND model_code=%s AND year_code=%s LIMIT 1", $type, $brand, $model, $year ), ARRAY_A );
                return $row ?: [ 'valor' => '', 'mes' => '' ];
            },
        ] );
    }

    /**
     * Endpoint para retornar metadados de um produto (marca, modelo, ano, etc.).
     */
    public function register_product_meta_route() {
        register_rest_route( 'carroon-fipe/v1', '/productmeta/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'callback'            => function( $request ) {
                $post_id = intval( $request['post_id'] );
                if ( ! $post_id ) {
                    return new WP_Error( 'invalid_id', 'Post ID inválido', [ 'status' => 400 ] );
                }
                $fields = [
                    '__marca',
                    '__modelo',
                    '__ano_modelo',
                    'rotulo_marca',
                    'model_value',
                    '__valor_fipe',
                    'month',
                    '_regular_price',
                    '_quilometragem',
                ];
                $resp = [];
                foreach ( $fields as $key ) {
                    $resp[ $key ] = get_post_meta( $post_id, $key, true );
                }
                return $resp;
            },
        ] );
    }

    /**
     * Enfileira o arquivo JS responsável por preencher os campos dinâmicos. O JS é
     * localizado com as chaves necessárias para funcionar.
     */
    public function enqueue_js() {
        // Só carrega o script para usuários logados que estão criando ou editando produtos.
        if ( ! is_user_logged_in() ) {
            return;
        }
        // Enfileira script
        wp_enqueue_script(
            'carroon-fipe-localdb',
            plugin_dir_url( __FILE__ ) . 'fipe-localdb.js',
            [ 'jquery' ],
            self::VERSION,
            true
        );
        // Passa variáveis para o script
        wp_localize_script( 'carroon-fipe-localdb', 'CARROON_FIPE_LOCAL', [
            'base'   => rest_url( 'carroon-fipe/v1' ),
            'fields' => [
                'tipo'           => 'tipo_veiculo',
                'marca'          => '__marca',
                'marca_nome'     => 'rotulo_marca',
                'modelo'         => '__modelo',
                'modelo_nome'    => 'model_value',
                'ano'            => '__ano_modelo',
                'preco_medio'    => '__valor_fipe',
                'mes_referencia' => 'month',
                'regular_price'  => '_regular_price',
            ],
        ] );
    }
}

// Instancia a classe e registra a ativação
$carroon_fipe_db_instance = new Carroon_Fipe_DB();
register_activation_hook( __FILE__, [ 'Carroon_Fipe_DB', 'activate' ] );