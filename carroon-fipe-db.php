<?php
/**
 * Plugin Name: Carroon FIPE API Connector
 * Description: Conecta o formulário diretamente à API FIPE v2 com token embutido. Utiliza cache para otimizar a performance.
 * Version: 2.1.0
 * Author: Carroon
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Carroon_Fipe_Connector {
    const VERSION = '2.1.0';
    private $base_v2 = 'https://fipe.parallelum.com.br/api/v2';
    private $api_token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiI4MWMxYmEzZC05NDQ4LTRlYTgtOWUzOS0wZmEyYWNhNzAzM2MiLCJlbWFpbCI6ImFpcnRvbm1haWFtdEBnbWFpbC5jb20iLCJzdHJpcGVTdWJzY3JpcHRpb25JZCI6InN1Yl8xUnRicEpDU3ZJczA4dElFQmdHcUJnYzYiLCJpYXQiOjE3NTQ2MDM5MDd9.NcgNRBiC6TfxAgKb5oI2f3ngDMYSOqRFWGxjbC6a5MU';

    public function __construct() {
        add_action('rest_api_init', [ $this, 'register_routes' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_js' ]);
        add_action('template_redirect', [ $this, 'check_edit_page_permissions' ]);
    }

    /** ---------- Funções Auxiliares (Helpers) ---------- */

    /**
     * Converte o tipo de veículo para o slug esperado pela API v2.
     * @param string $type Tipo de veículo (ex: 'carros').
     * @return string Slug para a API (ex: 'cars').
     */
    private function v2slug($type){
        return [
            'carros'    => 'cars',
            'motos'     => 'motorcycles',
            'caminhoes' => 'trucks',
        ][$type] ?? 'cars';
    }

    /**
     * Realiza uma requisição GET para a API externa e decodifica o JSON.
     * @param string $url URL da API a ser consultada.
     * @return array Dados da resposta ou array vazio em caso de falha.
     */
    private function http_json($url){
        $headers = [
            'Accept'               => 'application/json',
            'User-Agent'           => 'CarroonFIPE-Connector/'.self::VERSION.' (+https://carroon.com.br)',
            'X-Subscription-Token' => $this->api_token
        ];

        $resp = wp_remote_get($url, ['timeout' => 60, 'headers' => $headers, 'redirection' => 2]);
        
        if ( is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200 ) {
            // Opcional: Logar o erro para depuração
            // error_log('FIPE API Error: ' . print_r($resp, true));
            return [];
        }
        
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : [];
    }
    
    /** ---------- Endpoints da API REST (100% via API externa + Cache) ---------- */

    public function register_routes(){
        $ns = 'carroon-fipe/v1';

        // Endpoint para buscar as marcas
        register_rest_route($ns, '/marcas', [
            'methods'  => 'GET',
            'permission_callback' => '__return_true',
            'args'     => ['type' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']],
            'callback' => function($req){
                $type = sanitize_text_field($req['type']);
                $cache_key = "fipe_marcas_{$type}_" . self::VERSION;
                
                if (false !== ($cached = get_transient($cache_key))) return $cached;

                $slug = $this->v2slug($type);
                $list = $this->http_json("{$this->base_v2}/{$slug}/brands");
                $rows = array_map(fn($x) => ['codigo' => (string)($x['code'] ?? ''), 'nome' => (string)($x['name'] ?? '')], $list ?: []);
                
                if (!empty($rows)) set_transient($cache_key, $rows, 12 * HOUR_IN_SECONDS);
                
                return $rows;
            }
        ]);

        // Endpoint para buscar os modelos
        register_rest_route($ns, '/modelos', [
            'methods'  => 'GET',
            'permission_callback' => '__return_true',
            'args'     => [
                'type'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'brand' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ],
            'callback' => function($req){
                $type = sanitize_text_field($req['type']); 
                $brand = sanitize_text_field($req['brand']);
                $cache_key = "fipe_modelos_{$type}_{$brand}_" . self::VERSION;

                if (false !== ($cached = get_transient($cache_key))) return $cached;

                $slug = $this->v2slug($type);
                $list = $this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models");
                $rows = array_map(fn($x) => ['codigo' => (string)($x['code'] ?? ''), 'nome' => (string)($x['name'] ?? '')], $list ?: []);

                if (!empty($rows)) set_transient($cache_key, $rows, 12 * HOUR_IN_SECONDS);
                
                return $rows;
            }
        ]);

        // Endpoint para buscar os anos
        register_rest_route($ns, '/anos', [
            'methods'  => 'GET',
            'permission_callback' => '__return_true',
            'args'     => [
                'type'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'brand' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'model' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ],
            'callback' => function($req){
                $type = sanitize_text_field($req['type']); 
                $brand = sanitize_text_field($req['brand']); 
                $model = sanitize_text_field($req['model']);
                $cache_key = "fipe_anos_{$type}_{$brand}_{$model}_" . self::VERSION;

                if (false !== ($cached = get_transient($cache_key))) return $cached;

                $slug = $this->v2slug($type);
                $list = $this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models/{$model}/years");
                $rows = array_map(function($x) {
                    $code = (string)($x['code'] ?? '');
                    $name = (string)($x['name'] ?? '');
                    preg_match('/^\d{4}/', $name, $matches); // Extrai apenas o ano (ex: "2015 Gasolina" -> "2015")
                    return ['codigo' => $code, 'nome' => $matches[0] ?? $name];
                }, $list ?: []);

                if (!empty($rows)) set_transient($cache_key, $rows, 12 * HOUR_IN_SECONDS);

                return $rows;
            }
        ]);

        // Endpoint para buscar o preço
        register_rest_route($ns, '/preco', [
            'methods'  => 'GET',
            'permission_callback' => '__return_true',
            'args'     => [
                'type'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'brand' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'model' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'year'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
            ],
            'callback' => function($req){
                $type = sanitize_text_field($req['type']); 
                $brand = sanitize_text_field($req['brand']); 
                $model = sanitize_text_field($req['model']); 
                $year = sanitize_text_field($req['year']);
                
                $cache_key = 'fipe_preco_' . md5($type.'|'.$brand.'|'.$model.'|'.$year);
                
                if (false !== ($cached = get_transient($cache_key))) return $cached;

                $slug = $this->v2slug($type);
                $det  = $this->http_json("{$this->base_v2}/{$slug}/brands/{$brand}/models/{$model}/years/{$year}");
                
                $result = [
                    'valor' => ($det['price'] ?? ''), 
                    'mes'   => ($det['referenceMonth'] ?? '')
                ];

                if (!empty($result['valor'])) set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

                return $result;
            }
        ]);
    }

    /** ---------- Carregamento do JS e Segurança ---------- */

    public function enqueue_js() {
        // Carrega o JS apenas se a página for a correta ou se o formulário estiver presente
        if ( ! is_page('editar-produto') && ! is_page('adicionar-produto') /* Adicione outras páginas se necessário */) {
            // Poderíamos adicionar uma verificação mais robusta aqui se o formulário pode aparecer em muitos lugares
        }

        wp_enqueue_script(
            'carroon-fipe-connector-js',
            plugin_dir_url(__FILE__).'fipe-localdb.js',
            ['jquery'],
            self::VERSION,
            true
        );

        $fields_config = [
            'tipo'           => 'tipo_categoria_veiculo',
            'marca'          => '__marca',
            'marca_nome'     => 'rotulo_marca',
            'modelo'         => '__modelo',
            'modelo_nome'    => 'model_value',
            'ano'            => '__ano_modelo',
            'ano_rotulo'     => '__ano_modelo_rotulo',
            'preco_medio'    => '__valor_fipe',
            'mes_referencia' => 'month',
        ];

        $saved_data = [];
        if ( is_page('editar-produto') && isset($_GET['pid']) ) {
            $post_id = absint($_GET['pid']);
            if ( $post_id > 0 && ($post = get_post($post_id)) ) {
                $saved_data = [
                    'brand' => get_post_meta($post_id, $fields_config['marca'], true),
                    'model' => get_post_meta($post_id, $fields_config['modelo'], true),
                    'year'  => get_post_meta($post_id, $fields_config['ano'], true),
                    'type'  => get_post_meta($post_id, $fields_config['tipo'], true),
                ];
            }
        }
        
        wp_localize_script('carroon-fipe-connector-js', 'CARROON_FIPE_LOCAL', [
            'base'   => rest_url('carroon-fipe/v1'),
            'fields' => $fields_config,
            'saved'  => $saved_data,
        ]);
    }

    public function check_edit_page_permissions(){
        if ( ! is_page('editar-produto') ) return;
        
        if ( ! is_user_logged_in() ) {
            wp_redirect(wp_login_url(get_permalink()));
            exit;
        }

        $pid = isset($_GET['pid']) ? absint($_GET['pid']) : 0;
        if ( ! $pid || !($post = get_post($pid)) ) {
            wp_die('Produto inválido.', 403);
        }

        if ( 'product' !== $post->post_type ) {
            wp_die('Item inválido.', 403);
        }

        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can('manage_woocommerce') ) {
            wp_die('Você não tem permissão para editar este produto.', 403);
        }
    }
}

new Carroon_Fipe_Connector();
