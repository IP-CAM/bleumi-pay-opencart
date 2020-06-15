<?php

namespace BleumiPay\PaymentGateway;


/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

class BleumiPay_ExceptionHandler
{

    protected $store;
    protected $helper;

    public function __construct($code, $config)
    {
        $this->store = new BleumiPay_DBHandler($code, $config);
        $this->helper = new BleumiPay_Helper($code, $config);
    }

    public function logException($order_id, $retry_action, $code, $message)
    {
        if ($code == 400) {
            $this->logHardException($order_id, $retry_action, $code, $message);
        } else {
            $this->logTransientException($order_id, $retry_action, $code, $message);
        }
    }

    public function logTransientException($order_id, $retry_action, $code, $message)
    {
        $tries_count = 0;
        //Get previous transient errors for this order
        $prev_count = (int) $this->store->getMeta($order_id, 'bleumipay_transient_error_count');
        if (isset($prev_count) && !is_null($prev_count)) {
            $tries_count = $prev_count;
        }
        $prev_code = $this->store->getMeta($order_id, 'bleumipay_transient_error_code');
        $prev_action = $this->store->getMeta($order_id, 'bleumipay_retry_action');
        //If the same error occurs with same retry_action, then inc the retry count
        if (isset($prev_code) && isset($prev_action) && ($prev_code === $code) && ($prev_action === $retry_action)) {
            $tries_count++;
        } else {
            //Else restart count
            $tries_count = 0;
            $this->store->updateMetaData($order_id, 'bleumipay_transient_error', 'yes');
            $this->store->updateMetaData($order_id, 'bleumipay_transient_error_code', $code);
            $this->store->updateMetaData($order_id, 'bleumipay_transient_error_msg', $message);
            if (!is_null($retry_action)) {
                $this->store->updateMetaData($order_id, 'bleumipay_retry_action', $retry_action);
            }
        }
        $this->store->updateMetaData($order_id, 'bleumipay_transient_error_count', $tries_count);
    }

    public function logHardException($order_id, $retry_action, $code, $message)
    {
        $this->store->updateMetaData($order_id, 'bleumipay_hard_error',  'yes');
        $this->store->updateMetaData($order_id, 'bleumipay_hard_error_code', $code);
        $this->store->updateMetaData($order_id, 'bleumipay_hard_error_msg', $message);
        if (!is_null($retry_action)) {
            $this->store->updateMetaData($order_id, 'bleumipay_retry_action', $retry_action);
        }
    }

    public function clearTransientError($order_id)
    {
        $this->store->deleteMetaData($order_id, 'bleumipay_transient_error');
        $this->store->deleteMetaData($order_id, 'bleumipay_transient_error_code');
        $this->store->deleteMetaData($order_id, 'bleumipay_transient_error_msg');
        $this->store->deleteMetaData($order_id, 'bleumipay_transient_error_count');
        $this->store->deleteMetaData($order_id, 'bleumipay_retry_action');
    }

    public function checkRetryCount($order_id)
    {
        $retry_count = (int) $this->store->getMeta($order_id, 'bleumipay_transient_error_count');
        $action = $this->store->getMeta($order_id, 'bleumipay_retry_action');
        if ($retry_count > 3) {
            $code = 'E907';
            $msg = 'Retry count exceeded.';
            $this->logHardException($order_id, $action, $code, $msg);
        }
        return $retry_count;
    }
}
