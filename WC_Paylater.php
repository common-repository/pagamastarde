<?php
/**
 * Plugin Name: Pagamastarde
 * Plugin URI: http://www.pagamastarde.com/
 * Description: Financiar con Pagamastarde
 * Version: 7.2.6
 * Author: Pagamastarde
 */

//namespace Gateways;


if (!defined('ABSPATH')) {
    exit;
}

class WcPaylater
{
    const GIT_HUB_URL = 'https://github.com/PagaMasTarde/woocommerce';
    const PMT_DOC_URL = 'https://docs.pagamastarde.com';
    const SUPPORT_EML = 'mailto:soporte@pagamastarde.com?Subject=woocommerce_plugin';
    /** Concurrency tablename */
    const LOGS_TABLE = 'pmt_logs';
    /** Config tablename */
    const CONFIG_TABLE = 'pmt_config';

    /** Concurrency tablename  */
    const CONCURRENCY_TABLE = 'pmt_concurrency';

    public $defaultConfigs = array('PMT_TITLE'=>'Instant Financing',
                            'PMT_SIMULATOR_DISPLAY_TYPE'=>'pmtSDK.simulator.types.SIMPLE',
                            'PMT_SIMULATOR_DISPLAY_SKIN'=>'pmtSDK.simulator.skins.BLUE',
                            'PMT_SIMULATOR_DISPLAY_POSITION'=>'hookDisplayProductButtons',
                            'PMT_SIMULATOR_START_INSTALLMENTS'=>3,
                            'PMT_SIMULATOR_MAX_INSTALLMENTS'=>12,
                            'PMT_SIMULATOR_CSS_POSITION_SELECTOR'=>'default',
                            'PMT_SIMULATOR_DISPLAY_CSS_POSITION'=>'pmtSDK.simulator.positions.INNER',
                            'PMT_SIMULATOR_CSS_PRICE_SELECTOR'=>'a:3:{i:0;s:48:"div.summary *:not(del)>.woocommerce-Price-amount";i:1;s:54:"div.entry-summary *:not(del)>.woocommerce-Price-amount";i:2;s:36:"*:not(del)>.woocommerce-Price-amount";}',
                            'PMT_SIMULATOR_CSS_QUANTITY_SELECTOR'=>'a:2:{i:0;s:22:"div.quantity input.qty";i:1;s:18:"div.quantity>input";}',
                            'PMT_FORM_DISPLAY_TYPE'=>0,
                            'PMT_DISPLAY_MIN_AMOUNT'=>1,
                            'PMT_URL_OK'=>'',
                            'PMT_URL_KO'=>'',
                            'PMT_TITLE_EXTRA' => 'Paga hasta en 12 cómodas cuotas con Paga+Tarde. Solicitud totalmente 
                            online y sin papeleos,¡y la respuesta es inmediata!'
    );

    /** @var Array $extraConfig */
    public $extraConfig;

    /**
     * WC_Paylater constructor.
     */
    public function __construct()
    {
        require_once(plugin_dir_path(__FILE__).'/vendor/autoload.php');

        $this->template_path = plugin_dir_path(__FILE__).'/templates/';

        $this->paylaterActivation();

        load_plugin_textdomain('paylater', false, basename(dirname(__FILE__)).'/languages');
        add_filter('woocommerce_payment_gateways', array($this, 'addPaylaterGateway'));
        add_filter('woocommerce_available_payment_gateways', array($this, 'paylaterFilterGateways'), 9999);
        add_filter('plugin_row_meta', array($this, 'paylaterRowMeta'), 10, 2);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'paylaterActionLinks'));
        add_action('woocommerce_after_add_to_cart_form', array($this, 'paylaterAddProductSimulator'));
        add_action('wp_enqueue_scripts', 'add_widget_js');
        add_action('rest_api_init', array($this, 'paylaterRegisterEndpoint')); //Endpoint
    }

    /**
     * Sql table
     */
    public function paylaterActivation()
    {
        global $wpdb;

        $tableName = $wpdb->prefix.self::CONCURRENCY_TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $tableName ( order_id int NOT NULL,  
                    createdAt timestamp DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY id (order_id)) $charset_collate";
            require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }


        $tableName = $wpdb->prefix.self::CONFIG_TABLE;

        //Check if table exists
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName;
        if ($tableExists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS $tableName (
                                id int NOT NULL AUTO_INCREMENT, 
                                config varchar(60) NOT NULL, 
                                value varchar(1000) NOT NULL, 
                                UNIQUE KEY id(id)) $charset_collate";

            require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        $dbConfigs = $wpdb->get_results("select * from $tableName", ARRAY_A);

        // Convert a multimple dimension array for SQL insert statements into a simple key/value
        $simpleDbConfigs = array();
        foreach ($dbConfigs as $config) {
            $simpleDbConfigs[$config['config']] = $config['value'];
        }
        $newConfigs = array_diff_key($this->defaultConfigs, $simpleDbConfigs);
        if (!empty($newConfigs)) {
            foreach ($newConfigs as $key => $value) {
                $wpdb->insert($tableName, array('config' => $key, 'value'  => $value), array('%s', '%s'));
            }
        }

        foreach (array_merge($this->defaultConfigs, $simpleDbConfigs) as $key => $value) {
            $this->extraConfig[$key] = $value;
        }

        //Current plugin config: pmt_public_key => New field --- public_key => Old field
        $settings = get_option('woocommerce_paylater_settings');

        if (!isset($settings['pmt_public_key']) && $settings['public_key']) {
            $settings['pmt_public_key'] = $settings['public_key'];
            unset($settings['public_key']);
        }

        if (!isset($settings['pmt_private_key']) && $settings['secret_key']) {
            $settings['pmt_private_key'] = $settings['secret_key'];
            unset($settings['secret_key']);
        }

        update_option('woocommerce_paylater_settings', $settings);
    }

    /**
     * Product simulator
     */
    public function paylaterAddProductSimulator()
    {
        global $product;

        $cfg = get_option('woocommerce_paylater_settings');
        if ($cfg['enabled'] !== 'yes' || $cfg['pmt_public_key'] == '' || $cfg['pmt_private_key'] == '' ||
            $cfg['simulator'] !== 'yes' || $product->price<$this->extraConfig['PMT_DISPLAY_MIN_AMOUNT']) {
            return;
        }

        $template_fields = array(
            'total'    => is_numeric($product->price) ? $product->price : 0,
            'public_key' => $cfg['pmt_public_key'],
            'simulator_type' => $this->extraConfig['PMT_SIMULATOR_DISPLAY_TYPE'],
            'positionSelector' => $this->extraConfig['PMT_SIMULATOR_CSS_POSITION_SELECTOR'],
            'quantitySelector' => unserialize($this->extraConfig['PMT_SIMULATOR_CSS_QUANTITY_SELECTOR']),
            'priceSelector' => unserialize($this->extraConfig['PMT_SIMULATOR_CSS_PRICE_SELECTOR']),
            'totalAmount' => is_numeric($product->price) ? $product->price : 0
        );
        wc_get_template('product_simulator.php', $template_fields, '', $this->template_path);
    }

    /**
     * Add Paylater to payments list.
     *
     * @param $methods
     *
     * @return array
     */
    public function addPaylaterGateway($methods)
    {
        if (! class_exists('WC_Payment_Gateway')) {
            return $methods;
        }

        include_once('controllers/paymentController.php');
        $methods[] = 'WcPaylaterGateway';

        return $methods;
    }

    /**
     * Initialize WC_Paylater class
     *
     * @param $methods
     *
     * @return mixed
     */
    public function paylaterFilterGateways($methods)
    {
        $paylater = new WcPaylaterGateway();
        if ($paylater->is_available()) {
            $methods['paylater'] = $paylater;
        }
        return $methods;
    }

    /**
     * Add links to Plugin description
     *
     * @param $links
     *
     * @return mixed
     */
    public function paylaterActionLinks($links)
    {
        $params_array = array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'paylater');
        $setting_url  = esc_url(add_query_arg($params_array, admin_url('admin.php?')));
        $setting_link = '<a href="'.$setting_url.'">'.__('Settings', 'paylater').'</a>';

        array_unshift($links, $setting_link);

        return $links;
    }

    /**
     * Add links to Plugin options
     *
     * @param $links
     * @param $file
     *
     * @return array
     */
    public function paylaterRowMeta($links, $file)
    {
        if ($file == plugin_basename(__FILE__)) {
            $links[] = '<a href="'.WcPaylater::GIT_HUB_URL.'" target="_blank">'.__('Documentation', 'paylater').'</a>';
            $links[] = '<a href="'.WcPaylater::PMT_DOC_URL.'" target="_blank">'.
            __('API documentation', 'paylater').'</a>';
            $links[] = '<a href="'.WcPaylater::SUPPORT_EML.'">'.__('Support', 'paylater').'</a>';

            return $links;
        }

        return $links;
    }

    /**
     * Read logs
     */
    public function readLogs($data)
    {
        global $wpdb;
        $filters   = ($data->get_params());
        $response  = array();
        $secretKey = $filters['secret'];
        $from = $filters['from'];
        $to   = $filters['to'];
        $cfg  = get_option('woocommerce_paylater_settings');
        $privateKey = isset($cfg['pmt_private_key']) ? $cfg['pmt_private_key'] : null;
        $tableName = $wpdb->prefix.self::LOGS_TABLE;
        $query = "select * from $tableName where createdAt>$from and createdAt<$to order by createdAt desc";
        $results = $wpdb->get_results($query);
        if (isset($results) && $privateKey == $secretKey) {
            foreach ($results as $key => $result) {
                $response[$key]['timestamp'] = $result->createdAt;
                $response[$key]['log']       = json_decode($result->log);
            }
        } else {
            $response['result'] = 'Error';
        }
        $response = json_encode($response);
        header("HTTP/1.1 200", true, 200);
        header('Content-Type: application/json', true);
        header('Content-Length: '.strlen($response));
        echo($response);
        exit();
    }

    /**
     * Update extra config
     */
    public function updateExtraConfig($data)
    {
        global $wpdb;
        $tableName = $wpdb->prefix.self::CONFIG_TABLE;
        $response = array('status'=>null);

        $filters   = ($data->get_params());
        $secretKey = $filters['secret'];
        $cfg  = get_option('woocommerce_paylater_settings');
        $privateKey = isset($cfg['pmt_private_key']) ? $cfg['pmt_private_key'] : null;
        if ($privateKey != $secretKey) {
            $response['status'] = 401;
            $response['result'] = 'Unauthorized';
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (count($_POST)) {
                foreach ($_POST as $config => $value) {
                    if (isset($this->defaultConfigs[$config]) && $response['status']==null) {
                        $wpdb->update(
                            $tableName,
                            array('value' => $value),
                            array('config' => $config),
                            array('%s'),
                            array('%s')
                        );
                    } else {
                        $response['status'] = 400;
                        $response['result'] = 'Bad request';
                    }
                }
            } else {
                $response['status'] = 422;
                $response['result'] = 'Empty data';
            }
        }

        if ($response['status']==null) {
            $tableName = $wpdb->prefix.self::CONFIG_TABLE;
            $dbResult = $wpdb->get_results("select config, value from $tableName", ARRAY_A);
            foreach ($dbResult as $value) {
                $formattedResult[$value['config']] = $value['value'];
            }
            $response['result'] = $formattedResult;
        }

        $result = json_encode($response['result']);
        header("HTTP/1.1 ".$response['status'], true, $response['status']);
        header('Content-Type: application/json', true);
        header('Content-Length: '.strlen($result));
        echo($result);
        exit();
    }

    /**
     * ENDPOINT - Read logs -> Hook: rest_api_init
     * @return mixed
     */
    public function paylaterRegisterEndpoint()
    {
        register_rest_route(
            'paylater/v1',
            '/logs/(?P<secret>\w+)/(?P<from>\d+)/(?P<to>\d+)',
            array(
            'methods'  => 'GET',
            'callback' => array(
                $this,
                'readLogs')
            ),
            true
        );

        register_rest_route(
            'paylater/v1',
            '/configController/(?P<secret>\w+)',
            array(
                'methods'  => 'GET, POST',
                'callback' => array(
                    $this,
                    'updateExtraConfig')
            ),
            true
        );
    }
}

/**
 * Add widget Js
 **/
function add_widget_js()
{
    wp_enqueue_script('pmtSdk', 'https://cdn.pagamastarde.com/js/pmt-v2/sdk.js', '', '', true);
}

$WcPaylater = new WcPaylater();
