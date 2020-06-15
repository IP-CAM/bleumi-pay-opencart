<?php

namespace BleumiPay\PaymentGateway;


/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

class BleumiPay_PaymentCron
{
    protected $api;
    protected $store;
    protected $error_handler;
    protected $helper;

    public function __construct($code, $config)
    {
        $this->store = new BleumiPay_DBHandler($code, $config);
        $this->api = new BleumiPay_APIHandler($code, $config);
        $this->helper = new BleumiPay_Helper($code, $config);
        $this->error_handler = new BleumiPay_ExceptionHandler($code, $config);
    }

    public function execute()
    {
        $data_source = 'payments-cron';
        $start_at = $this->store->getCronTime('payment_updated_at');
        $this->helper->log('[Info] : ' . $data_source . ' : looking for payment modified after : ' . $start_at);
        $next_token = '';
        $updated_at = 0;
        do {
            $result = $this->api->get_payments($start_at, $next_token);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->helper->log('[Info] : ' . $data_source . ' : get_payments api request failed. ' . $result[0]['message'] . ' exiting payments-cron.');
                return $result[0];
            }
            $payments = $result[1]['results'];
            if (is_null($payments)) {
                $this->helper->log('[Info]: ' . $data_source . ' : unable to fetch payments to process');
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'no payments data found.', 'bleumipay',
                );
                return $errorStatus;
            }
            try {
                $next_token = $result[1]['next_token'];
            } catch (\Exception $e) {
                $this->helper->log('[Info] : ' . $data_source . ' : next_token no found ');
            }
            if (is_null($next_token)) {
                $next_token = '';
            }

            foreach ($payments as $payment) {
                $updated_at = $payment['updated_at'];
                $this->helper->log('[Info] : ' . $data_source . ' : processing order-id : ' . $payment['id'] . ' modified:' . date('Y-m-d H:i:s', $updated_at));
                $this->syncPayment($payment, $payment['id'], $data_source);
            }
        } while ($next_token !== '');

        if ($updated_at > 0) {
            $updated_at = $updated_at + 1;
            $this->store->updateCronTime('payment_updated_at', $updated_at);
            $this->helper->log('[Info] : ' . $data_source . ' : setting payment_updated_at: ' . date('Y-m-d H:i:s', $updated_at));
        }
    }


    public function syncPayment($payment, $payment_id, $data_source)
    {
        $order = $this->store->getPendingOrder($payment_id);
        $order_id = $order['order_id'];

        if (!empty($order_id) && !empty($order)) {
            $this->helper->log('syncPayment: ' . $data_source . ' :  order-id: ' . $order_id);

            // If there is a hard error (or) transient error action does not match, return
            $bp_hard_error = $this->store->getMeta($order_id, 'bleumipay_hard_error');
            $bp_transient_error = $this->store->getMeta($order_id, 'bleumipay_transient_error');
            $bp_retry_action = $this->store->getMeta($order_id, 'bleumipay_retry_action');
            if (($bp_hard_error == 'yes') || (($bp_transient_error == 'yes') && ($bp_retry_action != 'syncPayment'))) {
                $msg = 'syncPayment: ' . $data_source . ' order-id: ' . $order_id . ' : Skipping, hard error found (or) retry_action mismatch, order retry_action is : ' . $bp_retry_action;
                $this->helper->log($msg);
                return;
            }

            // If already processing completed, no need to sync
            $bp_processing_completed = $this->store->getMeta($order_id, 'bleumipay_processing_completed');
            if ($bp_processing_completed == 'yes') {
                $msg = 'Processing already completed for this order. No further changes possible.';
                $this->helper->log('[Info] : syncPayment: ' . $data_source . ' : order-id: ' . $order_id . ' ' . $msg);
                return;
            }

            // Exit payments_cron update if bp_payment_status indicated operations are in progress or completed
            $order_status = $order['order_status_id'];
            $bp_payment_status = $this->store->getMeta($order_id, 'bleumipay_payment_status');
            $invalid_bp_statuses = array('settle_in_progress', 'settled', 'settle_failed', 'refund_in_progress', 'refunded', 'refund_failed');
            if (in_array($bp_payment_status, $invalid_bp_statuses)) {
                $msg = 'syncPayment: ' . $data_source . ' : order-id: ' . $order_id . ' exiting .. bp_status:' . $bp_payment_status . ' order_status:' . $order_status;
                $this->helper->log("[Info] : " . $msg);
                return;
            }

            // skip payments_cron update if order was sync-ed by orders_cron in recently.
            $bp_data_source = $this->store->getMeta($order_id, 'bleumipay_data_source');
            $currentTime = strtotime(date("Y-m-d H:i:s")); //server unix time
            $date_modified = ($order['date_modified']);
            $minutes =  $this->helper->getMinutesDiff($currentTime, $date_modified);
            if ($minutes < $this->store::cron_collision_safe_minutes) {
                if (($data_source === 'payments-cron') && ($bp_data_source === 'orders-cron')) {
                    $msg = 'syncPayment: order-id: ' . $order_id . ', skipping payment processing at this time as orders-cron processed this order recently, will be processing again later';
                    $this->error_handler->logTransientException($order_id, 'syncPayment', 'E102', $msg);
                    return;
                }
            }

            if (!is_null($payment)) {
                $this->store->updateMetaData($order_id, 'bleumipay_addresses', json_encode($payment["addresses"]));
            }
            //Get token 
            $result = $this->api->getPaymentTokenBalance($payment, $order_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                if ($result[0]['code'] == -2) {
                    $success =  $this->store->markAsMultiTokenPayment($order_id, $order_status);
                    if ($success) {
                        $msg = $result[0]['message'];
                        $this->helper->log("[Info]: '. $data_source .' : syncPayment : order-id: " . $order_id . " " . $msg . "', order status changed to 'multi_token'");
                    }
                } else {
                    $this->helper->log("[Error]: " . $data_source . " : syncPayment : order-id: " . $order_id . 'get token balance error');
                }
                return;
            }
            $payment_info = $result[1];
            $amount = 0;
            try {
                $amount = (float) $payment_info['token_balances'][0]['balance'];
            } catch (\Exception $e) {
                $msg = 'syncPayment: failed response: ' . $e->getMessage();
                if ($e->getResponseBody() !== null) {
                    $msg = $msg . $e->getResponseBody();
                }
                $this->helper->log($msg);
            }

            $order_value = (float) $order['total'];
            $this->helper->log('syncPayment: after getPaymentTokenBalance: amount: ' . $amount . ' order_value:' .  $order_value);
            if (!empty($amount) && ($amount >= $order_value)) {
                $success = $this->store->markOrderAsProcessing($order_id, $order_status);
                if ($success) {
                    $this->store->updateMetaData($order_id, 'bleumipay_processing_completed', "no");
                    $this->store->updateMetaData($order_id, 'bleumipay_payment_status', "payment-received");
                    $this->store->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
                    $this->helper->log("[Info]: " . $data_source . " : syncPayment : order-id : " . $order_id . " set to 'processing'");
                }
            }
        }
    }
}
