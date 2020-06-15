<?php
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-APIHandler.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-Helper.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-DBHandler.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-PaymentCron.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-OrderCron.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-ExceptionHandler.php';
require_once DIR_SYSTEM . 'library/bleumipay/BleumiPay-RetryCron.php';


// Used by OpenCart 2.1
class ControllerPaymentBleumipay extends ControllerExtensionPaymentBleumipay
{
}

class ControllerExtensionPaymentBleumipay extends Controller
{
    protected $code = 'payment_bleumipay';
    protected $confirmPath = 'extension/payment';
    protected $viewPath = 'extension/payment/bleumipay';
    protected $languagePath = 'extension/payment/bleumipay';

    protected $api;
    protected $helper;
    protected $paymentCron;
    protected $orderCron;
    protected $retryCron;
    protected $exception_handler;

    public function __construct($registry)
    {
        parent::__construct($registry);

        if (true === version_compare(VERSION, '3.0.0', '<')) {
            $this->code = 'bleumipay';
        }
        if (true === version_compare(VERSION, '2.3.0', '<')) {
            $this->viewPath = 'payment/bleumipay.tpl';
            $this->confirmPath = 'payment';
            $this->languagePath = 'payment/bleumipay';
        }
        if (true === version_compare(VERSION, '2.2.0', '<')) {
            $this->viewPath = 'default/template/payment/bleumipay.tpl';
        }

        $this->api = new \BleumiPay\PaymentGateway\BleumiPay_APIHandler($this->code, $this->config);
        $this->helper = new \BleumiPay\PaymentGateway\BleumiPay_Helper($this->code, $this->config);
        $this->paymentCron = new \BleumiPay\PaymentGateway\BleumiPay_PaymentCron($this->code, $this->config);
        $this->orderCron = new \BleumiPay\PaymentGateway\BleumiPay_OrderCron($this->code, $this->config);
        $this->retryCron = new \BleumiPay\PaymentGateway\BleumiPay_RetryCron($this->code, $this->config);
        $this->exception_handler = new \BleumiPay\PaymentGateway\BleumiPay_ExceptionHandler($this->code, $this->config);

        $this->load->language($this->languagePath);
    }

    public function index()
    {
        $data['text_title'] = $this->language->get('text_title');
        $data['url_redirect'] = $this->url->link($this->confirmPath . '/bleumipay/confirm', $this->config->get('config_secure'));
        $data['button_confirm'] = $this->language->get('button_confirm');

        if (isset($this->session->data['error_bleumipay'])) {
            $data['error_bleumipay'] = $this->session->data['error_bleumipay'];
            unset($this->session->data['error_bleumipay']);
        }

        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/bleumipay')) {
            return $this->load->view($this->config->get('config_template') . '/template/' . $this->viewPath, $data);
        }
        return $this->load->view($this->viewPath, $data);
    }

    // Create payment invoice and redirect to BleumiPay
    public function confirm()
    {
        $this->load->model('checkout/order');
        if (!isset($this->session->data['order_id'])) {
            $this->response->redirect($this->url->link('checkout/cart'));
            return;
        }

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if (false === $order_info) {
            $this->response->redirect($this->url->link('checkout/cart'));
            return;
        }
        $id = $this->session->data['order_id'];
        $urls = array(
            "success" => $this->url->link('extension/payment/bleumipay/success', '', true),
            "cancel" => $this->url->link('extension/payment/bleumipay/cancel', '', true),
        );

        $createCheckout = $this->api->create($order_info, $urls);

        if (!empty($createCheckout) && !empty($createCheckout['url'])) {
            $pending_status = $this->helper->getIDforOrderStatus('pending');
            $this->model_checkout_order->addOrderHistory($id, $pending_status);
            $this->response->redirect($createCheckout['url']);
        } else {
            return array(
                'result' => 'fail',
            );
        }
    }

    // Redirect Handler

    /* Order Success Redirect Method*/

    public function success()
    {

        $this->load->model('checkout/order');
        $this->load->model('extension/payment/bleumipay');

        $this->validateUrl();

        $order_id = $this->session->data['order_id'];

        if (is_null($order_id)) {
            $this->response->redirect($this->url->link('common/home', '', true));
            return;
        } else {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        }
    }

    /* Order Cancel Redirect Method*/

    public function cancel()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/bleumipay');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        if ($order) {
            $canceled_status = $this->helper->getIDforOrderStatus('canceled');
            $this->model_checkout_order->addOrderHistory($order['order_id'], $canceled_status); // 7-Canceled
        }
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    /* Payment Validation */

    public function validateUrl()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/bleumipay');
        $order_id = $_GET['id'];
        $params = array(
            "hmac_alg" => $_GET["hmac_alg"],
            "hmac_input" => $_GET["hmac_input"],
            "hmac_keyId" => $_GET["hmac_keyId"],
            "hmac_value" => $_GET["hmac_value"],
        );
        $isValid = $this->api->validateUrl($params);
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $pending_status = $this->helper->getIDforOrderStatus('pending');
        if ($isValid && (int) $order_info["order_status_id"] == $pending_status) {
            $awaiting_status = $this->helper->getIDforOrderStatus('awaiting_payment_confirmation');
            $this->model_checkout_order->addOrderHistory($order_id, $awaiting_status);
        } else {
            $this->helper->log('[Error] :payment validation failed hence status not changed '. $isValid .' '. $order_info["order_status_id"] .' '. $pending_status);
        }
    }

    /* Cron Jobs Start*/

    public function orderCron()
    {
        $this->load->model('checkout/order');
        $this->orderCron->execute();
    }

    public function paymentCron()
    {
        $this->load->model('checkout/order');
        $this->paymentCron->execute();
    }

    public function retryCron()
    {
        $this->load->model('checkout/order');
        $this->retryCron->execute();
    }


}
