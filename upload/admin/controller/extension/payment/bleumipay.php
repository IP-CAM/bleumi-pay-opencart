<?php

// Used by OpenCart 2.1
class ControllerPaymentBleumipay extends ControllerExtensionPaymentBleumipay
{
}

class ControllerExtensionPaymentBleumipay extends Controller
{
    protected $error = array();
    protected $registry;
    protected $token = 'user_token';
    protected $paymentBreadcrumbLink = 'marketplace/extension';
    protected $paymentExtensionLink = 'extension/payment';
    protected $code = 'payment_bleumipay';
    protected $viewPath = 'extension/payment/bleumipay';

    public function __construct($registry)
    {
        parent::__construct($registry);

        if (!empty($missingRequirements = $this->missingRequirements())) {
            echo $missingRequirements;
            exit;
        }

        $this->registry = $registry;

        if (true === version_compare(VERSION, '2.3.0', '<')) {
            $this->token = 'token';
            $this->viewPath = 'payment/bleumipay.tpl';
            $this->paymentExtensionLink = 'payment';
            $this->paymentBreadcrumbLink = 'extension/payment';
            $this->code = 'bleumipay';
        } elseif (true === version_compare(VERSION, '3.0.0', '<')) {
            $this->token = 'token';
            $this->paymentBreadcrumbLink = 'extension/extension';
            $this->code = 'bleumipay';
        }

        $this->load->language($this->paymentExtensionLink . '/bleumipay');
    }

    public function __get($name)
    {
        return $this->registry->get($name);
    }

    // Logger function for debugging
    public function log($message)
    {
        if ($this->config->get($this->code . '_logging') != true) {
            return;
        }
        $log = new Log('bleumipay.log');
        $log->write($message);
    }

    // Plugin installer
    public function install()
    {
        $this->log('Installing');
        $this->load->model('localisation/order_status');
        $this->db->query("CREATE TABLE 
                            `" . DB_PREFIX . "bleumi_pay_cron` (
                            `id` INT(6) UNSIGNED NOT NULL,
                            `payment_updated_at` BIGINT(20) UNSIGNED,
                            `order_updated_at` BIGINT(20) UNSIGNED,
                            PRIMARY KEY (`id`)
                            )"
                        );

        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_status','0','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_payment_api_key','','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_notification_url','" . str_replace("admin/", "", $this->url->link($this->paymentExtensionLink . '/bleumipay/callback', $this->config->get('config_secure'))) . "','0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_logging','1','0');");

        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_pending_status',1,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_awaiting_payment_status',6,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_canceled_status',7,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_completed_status',5,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_multi_token_status',4,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_processing_status',2,'0');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` (`store_id`,`code`,`key`,`value`,`serialized`) VALUES ('0','payment_bleumipay','payment_bleumipay_failed_status',10,'0');");

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_status` (`order_status_id`,`language_id`,`name`) VALUES (6,1,'Awaiting Payment Confirmation');");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_status` (`order_status_id`,`language_id`,`name`) VALUES (4,1,'Multi Token Payment');");
        $currentTime = strtotime(date("Y-m-d H:i:s")); //server unix time
        $this->db->query("INSERT INTO `" . DB_PREFIX . "bleumi_pay_cron` (`id`, `payment_updated_at`, `order_updated_at`) VALUES (1, $currentTime, $currentTime);");

        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_addresses` TEXT");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_payment_status` VARCHAR(30)");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_txid` VARCHAR(30)");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_data_source` VARCHAR(30)");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_transient_error` VARCHAR(30) DEFAULT 'no'");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_transient_error_code` VARCHAR(30) ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_transient_error_msg` TEXT ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_retry_action` VARCHAR(30)");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_hard_error` VARCHAR(30) DEFAULT 'no'");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_hard_error_code` VARCHAR(30)");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_hard_error_msg` TEXT");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_processing_completed` VARCHAR(30) DEFAULT 'no' ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `bleumipay_transient_error_count` VARCHAR(30) DEFAULT '0' ");
    }

    // Plugin uninstaller
    public function uninstall()
    {
        $this->log('Uninstalling');
        $this->load->model('setting/setting');
        $this->db->query("DROP TABLE `" . DB_PREFIX . "bleumi_pay_cron`");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_addresses` ;");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_payment_status` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_txid` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_data_source` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_transient_error` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_transient_error_code` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_transient_error_msg` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_retry_action` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_hard_error` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_hard_error_code` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_hard_error_msg` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_processing_completed` ");
        $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` DROP COLUMN `bleumipay_transient_error_count` ");
        $this->db->query("DELETE FROM `" . DB_PREFIX . "order_status` WHERE `oc_order_status`.`order_status_id` = 6 AND `oc_order_status`.`language_id` = 1;");
        $this->db->query("DELETE FROM `" . DB_PREFIX . "order_status` WHERE `oc_order_status`.`order_status_id` = 4 AND `oc_order_status`.`language_id` = 1;");

        $this->model_setting_setting->deleteSetting($this->code);
    }

    // Setting Handler
    public function index()
    {
        // Activate array that passes data to twig template
        $data = array();

        // Saving settings
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->request->post['action'] === 'save') {
            $data = $this->validate();
            if (empty($data)) {
                $this->load->model('setting/setting');
                $this->model_setting_setting->editSetting($this->code, $this->request->post);
                $this->log('Settings Updated.');
                $this->session->data['success'] = $this->language->get('notification_success');
                $this->response->redirect($this->url->link($this->paymentExtensionLink . '/bleumipay', $this->token . '=' . $this->session->data[$this->token], true));
            }
        }

        $this->document->setTitle($this->language->get('bleumipay'));

        // System template
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $callbackUrl = $this->url->link('extension/payment/bleumipay/ipn', '', true);

        // Links
        $data['url_action'] = $this->url->link($this->paymentExtensionLink . '/bleumipay', $this->token . '=' . $this->session->data[$this->token], 'SSL');
        $data['url_reset'] = $this->url->link($this->paymentExtensionLink . '/bleumipay/reset', $this->token . '=' . $this->session->data[$this->token], 'SSL');
        $data['url_clear'] = $this->url->link($this->paymentExtensionLink . '/bleumipay/clear', $this->token . '=' . $this->session->data[$this->token], 'SSL');
        $data['cancel'] = $this->url->link($this->paymentExtensionLink, $this->token . '=' . $this->session->data[$this->token] . '&type=payment', 'SSL');
        $data['callback_url'] = str_replace('admin/', '', $callbackUrl);

        // Buttons
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['button_clear'] = $this->language->get('button_clear');

        // Breadcrumbs
        $data['breadcrumbs'] = array(
            array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $this->token . '=' . $this->session->data[$this->token], true),
            ),
            array(
                'text' => $this->language->get('text_payment'),
                'href' => $this->url->link($this->paymentBreadcrumbLink, $this->token . '=' . $this->session->data[$this->token] . '&type=payment', true),
            ),
            array(
                'text' => $this->language->get('bleumipay'),
                'href' => $this->url->link($this->paymentExtensionLink . '/bleumipay', $this->token . '=' . $this->session->data[$this->token], true),
            ),
        );

        // Tabs
        $data['tab_settings'] = $this->language->get('tab_settings');
        $data['tab_order_status'] = $this->language->get('tab_order_status');
        $data['tab_log'] = $this->language->get('tab_log');

        // Headings
        $data['heading_title'] = $this->language->get('bleumipay');

        // Labels
        $data['label_edit'] = $this->language->get('label_edit');
        $data['label_enabled'] = $this->language->get('label_enabled');
        $data['label_payment_api_key'] = $this->language->get('label_payment_api_key');
        $data['label_notification_url'] = $this->language->get('label_notification_url');
        $data['label_debugging'] = $this->language->get('label_debugging');

        $data['label_pending_status'] = $this->language->get('label_pending_status');
        $data['label_awaiting_payment_status'] = $this->language->get('label_awaiting_payment_status');
        $data['label_canceled_status'] = $this->language->get('label_canceled_status');
        $data['label_completed_status'] = $this->language->get('label_completed_status');
        $data['label_multi_token_status'] = $this->language->get('label_multi_token_status');
        $data['label_failed_status'] = $this->language->get('label_failed_status');
        $data['label_processing_status'] = $this->language->get('label_processing_status');

        // Text
        $data['text_payment'] = $this->language->get('text_payment');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');

        // Validation
        $data['success'] = '';
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        }

        // Load saved values
        $data['value_enabled'] = $this->config->get($this->code . '_status');
        $data['value_payment_api_key'] = $this->config->get($this->code . '_payment_api_key');
        $data['value_notification_url'] = $this->config->get($this->code . '_notification_url');
        $data['value_debugging'] = $this->config->get($this->code . '_logging');

        $data['value_pending_status'] = $this->config->get($this->code . '_pending_status');
        $data['value_awaiting_payment_status'] = $this->config->get($this->code . '_awaiting_payment_status');
        $data['value_canceled_status'] = $this->config->get($this->code . '_canceled_status');
        $data['value_completed_status'] = $this->config->get($this->code . '_completed_status');
        $data['value_multi_token_status'] = $this->config->get($this->code . '_multi_token_status');
        $data['value_failed_status'] = $this->config->get($this->code . '_failed_status');
        $data['value_processing_status'] = $this->config->get($this->code . '_processing_status');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Log data
        $data['log'] = '';
        $file = DIR_LOGS . 'bleumipay.log';
        if (file_exists($file)) {
            foreach (file($file, FILE_USE_INCLUDE_PATH, null) as $line) {
                $data['log'] .= $line . "<br/>\n";
            }
        }
        if (empty($data['log'])) {
            $data['log'] = '<i>No log data available. Is Debugging switched on?</i>';
        }

        // Send output to browser
        $this->response->setOutput($this->load->view($this->viewPath, $data));
    }

    // Clear the BleumiPay Log
    public function clear()
    {
        fclose(fopen(DIR_LOGS . 'bleumipay.log', 'w'));
        $this->session->data['success'] = $this->language->get('notification_log_success');
        $this->response->redirect($this->url->link($this->paymentExtensionLink . '/bleumipay', $this->token . '=' . $this->session->data[$this->token], 'SSL'));
    }

    // Authorization and Validation
    private function validate()
    {
        $data = array();
        // Ensure the user has the permission to modify the plugin
        if (!$this->user->hasPermission('modify', $this->paymentExtensionLink . '/bleumipay')) {
            $data['error_warning'] = $this->language->get('warning_permission');
        }

        // Ensure the plugin cannot be activated without authorization
        if ($this->request->post[$this->code . '_status'] == 1 && empty($this->request->post[$this->code . '_payment_api_key'])) {
            $data['error_payment_api_key'] = $this->language->get('notification_error_payment_api_key');
        }

        return $data;
    }

    // Check that the system meets the minimum requirements
    private function missingRequirements()
    {
        $errors = [];
        $contactYourWebAdmin = " in order to function. Please contact your web server administrator for assistance.";

        # PHP
        if (true === version_compare(PHP_VERSION, '5.5.0', '<')) {
            $errors[] = 'Your PHP version is too old. The Bleumi Pay plugin requires PHP 5.4 or higher'
                . $contactYourWebAdmin;
        }

        # JSON
        if (extension_loaded('json') === false) {
            $errors[] = 'The Bleumi Pay plugin requires the JSON extension for PHP' . $contactYourWebAdmin;
        }

        # Curl required
        if (false === extension_loaded('curl')) {
            $errors[] = 'The Bleumi Pay plugin requires the Curl extension for PHP' . $contactYourWebAdmin;
        }

        if (!empty($errors)) {
            return implode("<br>\n", $errors);
        }
    }
}
