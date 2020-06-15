<?php

namespace BleumiPay\PaymentGateway;

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

class BleumiPay_Helper
{
    private $code;
    private $config;

    public function __construct($code, $config)
    {
        $this->code = $code;
        $this->config = $config;
    }

    // Logger function for debugging
    public function log($message)
    {
        if ($this->config->get($this->code . '_logging') != true) {
            return;
        }
        $log = new \Log('bleumipay.log');
        $log->write($message);
    }

    /**
     * BleumiPay_Helper function - Returns the difference in minutes between 2 datetimes
     */

    public function getMinutesDiff($dateTime1, $dateTime2)
    {
        $minutes = abs($dateTime1 - $dateTime2) / 60;
        return $minutes;
    }

    /**
     * Returns the transaction link for the txhash in the given chain
     */

    public static function getTransactionLink($txHash, $chain = null)
    {
        switch ($chain) {
            case 'alg_mainnet':
                return 'https://algoexplorer.io/tx/' . $txHash;
            case 'alg_testnet':
                return 'https://algoexplorer.io/tx/' . $txHash;
            case 'rsk':
                return 'https://explorer.rsk.co/tx/' . $txHash;
            case 'rsk_testnet':
                return 'https://explorer.testnet.rsk.co/tx/' . $txHash;
            case 'mainnet':
            case 'xdai':
                return 'https://etherscan.io/tx/' . $txHash;
            case 'goerli':
            case 'xdai_testnet':
                return 'https://goerli.etherscan.io/tx/' . $txHash;
            default:
                return '';
        }
    }

    public function getIDforOrderStatus($status)
    {
        $bp_order_status = NULL;
        switch ($status) {
            case 'pending':
                $bp_order_status = 'payment_bleumipay_pending_status';
                break;
            case 'awaiting_payment_confirmation':
                $bp_order_status = 'payment_bleumipay_awaiting_payment_status';
                break;
            case 'processing':
                $bp_order_status = 'payment_bleumipay_processing_status';
                break;
            case 'complete':
                $bp_order_status = 'payment_bleumipay_completed_status';
                break;
            case 'canceled':
                $bp_order_status = 'payment_bleumipay_canceled_status';
                break;
            case 'multi_token':
                $bp_order_status = 'payment_bleumipay_multi_token_status';
                break;
            case 'failed':
                $bp_order_status = 'payment_bleumipay_failed_status';
                break;
            default:
                $bp_order_status = NULL;
                $this->log('[Error] :getIDforOrderStatus - unknown status ' . $status);
        }
        return $this->config->get($bp_order_status);
    }

    public function getOrderStatusforID($status_id)
    {
        $pending_status = $this->getIDforOrderStatus('pending');
        $awaiting_status = $this->getIDforOrderStatus('awaiting_payment_confirmation');
        $processing_status = $this->getIDforOrderStatus('processing');
        $complete_status = $this->getIDforOrderStatus('complete');
        $canceled_status = $this->getIDforOrderStatus('canceled');
        $multi_token_status = $this->getIDforOrderStatus('multi_token');
        $failed_status = $this->getIDforOrderStatus('failed');

        $order_status = NULL;
        switch ($status_id) {
            case $pending_status:
                $order_status = 'pending';
            case $processing_status:
                $order_status = 'processing';
            case $multi_token_status:
                $order_status = 'multi_token';
            case $awaiting_status:
                $order_status = 'awaiting_payment_confirmation';
            case $canceled_status:
                $order_status = 'canceled';
            case $complete_status:
                $order_status = 'complete';
            case $failed_status:
                $order_status = 'failed';
            default:
                $order_status = null;
                $this->log('[Error] :getOrderStatusforID - unknown status_id ' . $status_id);
        }
        return $order_status;
    }
}
