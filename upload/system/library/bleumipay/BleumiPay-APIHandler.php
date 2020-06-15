<?php

namespace BleumiPay\PaymentGateway;

/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

require_once DIR_SYSTEM . 'library/bleumipay/vendor/autoload.php';

class BleumiPay_APIHandler
{
    private $code;
    private $config;
    protected $payment_instance;
    protected $HC_instance;
    protected $apiKey;
    protected $helper;
    protected $store;
    protected $error_handler;

    public function __construct($code, $config)
    {
        $this->code = $code;
        $this->config = $config;
        $apiKey = $this->config->get($this->code . '_payment_api_key');
        $this->helper = new BleumiPay_Helper($this->code, $this->config);
        $this->store = new BleumiPay_DBHandler($code, $config);
        $this->error_handler = new BleumiPay_ExceptionHandler($this->code, $this->config);


        $bleumiConfig = \Bleumi\Pay\Configuration::getDefaultConfiguration()->setApiKey('x-api-key', $apiKey);
        $this->payment_instance = new \Bleumi\Pay\Api\PaymentsApi(new \GuzzleHttp\Client(), $bleumiConfig);
        $this->HC_instance = new \Bleumi\Pay\Api\HostedCheckoutsApi(new \GuzzleHttp\Client(), $bleumiConfig);
    }

    public function create($order, $urls)
    {
        $this->helper->log($order['order_id']);
        try {
            $createReq = new \Bleumi\Pay\Model\CreateCheckoutUrlRequest();
            $createReq->setId($order['order_id']);
            $createReq->setCurrency($order['currency_code']);
            $createReq->setAmount($order['total']);
            $createReq->setSuccessUrl($urls["success"]);
            $createReq->setCancelUrl($urls["cancel"]);
            $result = $this->HC_instance->createCheckoutUrl($createReq);
            $this->helper->log("[Info]: create: Payment created for order-id:" . $order['order_id']);
            return $result;
        } catch (\Exception $e) {
            $this->helper->log('[Error]: create: Payement request creation failed', ['exception' => $e->getMessage()]);
        }
    }

    public function validateUrl($params)
    {
        try {
            $validateReq = new \Bleumi\Pay\Model\ValidateCheckoutRequest();
            $validateReq->setHmacAlg($params["hmac_alg"]);
            $validateReq->setHmacInput($params["hmac_input"]);
            $validateReq->setHmacKeyId($params["hmac_keyId"]);
            $validateReq->setHmacValue($params["hmac_value"]);
            $result = $this->HC_instance->validateCheckoutPayment($validateReq);

            return $result['valid'];
        } catch (\Exception $e) {
            $this->helper->log('[Error]: validateUrl: payment validation failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Retrieves the payment details for the order_id from Bleumi Pay
     */
    public function get_payments($updated_after_time, $next_token)
    {
        $result = null;
        $errorStatus = array();
        $next_token = $next_token;
        $sort_by = "updatedAt";
        $sort_order = "ascending";
        $start_at = $updated_after_time;
        try {
            $result = $this->payment_instance->listPayments($next_token, $sort_by, $sort_order, $start_at);
        } catch (\Exception $e) {
            $msg = 'get_payments: failed, response: ' . $e->getMessage();
            if ($e->getResponseBody() !== null) {
                $msg = $msg . ' ' . $e->getResponseBody();
            }
            $this->helper->log('[Error] ' . $msg);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    public function getPaymentTokenBalance($payment = null, $order_id)
    {

        $chain = '';
        $addr = '';
        $token_balances = array();
        $payment_info = array();
        $errorStatus = array();
        //Call get_payment API to set $payment if found null.
        if (is_null($payment)) {
            $result = $this->get_payment($order_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $this->helper->log('[Error] getPaymentTokenBalance: order-id :' . $order_id . ' get_payment api failed : ' . $result[0]['message']);
                $errorStatus = array(
                    'code' => -1,
                    'message' => 'get payment details failed ',
                );
                return array($errorStatus, $payment_info);
            }
            $payment = $result[1];
        }

        // If still not payment data is found, return error
        if (is_null($payment)) {
            $errorStatus = array(
                'code' => -1,
                'message' => 'no payment details found ',
            );
            return array($errorStatus, $payment_info);
        }

        $payment_info['id'] = $payment['id'];
        $payment_info['addresses'] = $payment['addresses'];
        $payment_info['balances'] = $payment['balances'];
        $payment_info['created_at'] = $payment['created_at'];
        $payment_info['updated_at'] = $payment['updated_at'];

        if ($this->isMultiTokenPayment($payment)) {
            $msg = 'More than one token balance found';
            $errorStatus['code'] = -2;
            $errorStatus['message'] = $msg;
            return array($errorStatus, $payment_info);
        }

        $order = $this->store->get_order_for_id($order_id);
        $storeCurrency = $order['currency_code'];

        $result = $this->list_tokens($storeCurrency);

        if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
            $this->helper->log('[Error] getPaymentTokenBalance: order-id :' . $order_id . ' list_tokens api failed : ' . $result[0]['message']);
            return array($result[0], $payment_info);
        }
        $tokens = $result[1];
        if (count($tokens) > 0) {
            foreach ($tokens as $token) {
                $network = $token['network'];
                $chain = $token['chain'];
                $addr = $token['addr'];
                $token_balance = null;
                try {
                    if (!is_null($network) && !is_null($chain) && !is_null($addr)) {
                        $token_balance = $payment['balances'][$network][$chain][$addr];
                    }
                } catch (\Exception $e) {
                    continue;
                }
                /*{
                "balance": "0",
                "token_decimals": 6,
                "blockNum": "1896563",
                "token_balance": "0"
                }*/

                if (!is_null($token_balance['balance'])) {
                    $balance = (float) $token_balance['balance'];
                    if ($balance > 0) {
                        $item = array();
                        $item['network'] = $network;
                        $item['chain'] = $chain;
                        $item['addr'] = $addr;
                        $item['balance'] = $token_balance['balance'];
                        $item['token_decimals'] = $token_balance['token_decimals'];
                        $item['blockNum'] = $token_balance['blockNum'];
                        $item['token_balance'] = $token_balance['token_balance'];
                        array_push($token_balances, $item);
                    }
                }
            }
        }
        $ret_token_balances = $this->ignoreALGO($token_balances);
        $balance_count = count($ret_token_balances);

        if ($balance_count > 0) {
            $payment_info['token_balances'] = $ret_token_balances;
            if ($balance_count > 1) {
                $msg = 'More than one token balance found';
                $this->helper->log('[Error] ' . 'getPaymentTokenBalance: order-id :' . $order_id . ', balance_count: ' . $balance_count . ', ' . $msg);
                $errorStatus['code'] = -2;
                $errorStatus['message'] = $msg;
            }
        } else {
            $this->helper->log('[Info]: getPaymentTokenBalance: order-id :' . $order_id . ', no token balance found ');
        }

        return array($errorStatus, $payment_info);
    }

    /**
     * Retrieves the payment details for the order_id from Bleumi Pay
     */
    public function get_payment($order_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPayment($order_id);
        } catch (\Exception $e) {
            $msg = 'get_payment: failed order-id:' . $order_id;
            $this->helper->log('[Error] ' . 'bleumi_pay: ' . $msg);
            $errorStatus['code'] = -1;
            $errorStatus['message'] = $msg;
        }
        return array($errorStatus, $result);
    }

    /**
     * To check whether payment is made using multiple ERC-20 tokens
     * It is possible that user could have made payment to the wallet address using a different token
     * Returns false if balance>0 is found for more than 1 token when network='ethereum', chain=['mainnet', 'goerli']
     */

    public function isMultiTokenPayment($payment)
    {
        $networks = array('ethereum', 'algorand', 'rsk');
        $token_balances = array();
        $chain_token_balances = null;
        foreach ($networks as $network) {
            $chains = array();
            if ($network === 'ethereum') {
                $chains = array('mainnet', 'goerli', 'xdai_testnet', 'xdai');
            } else if ($network === 'algorand') {
                $chains = array('alg_mainnet', 'alg_testnet');
            } else if ($network === 'rsk') {
                $chains = array('rsk', 'rsk_testnet');
            }
            foreach ($chains as $chain) {
                try {
                    $chain_token_balances = $payment['balances'][$network][$chain];
                } catch (\Exception $e) {
                }
                if (!is_null($chain_token_balances)) {
                    foreach ($chain_token_balances as $addr => $token_balance) {
                        $balance = (float) $token_balance['balance'];
                        if ($balance > 0) {
                            $item = array();
                            $item['network'] = $network;
                            $item['chain'] = $chain;
                            $item['addr'] = $addr;
                            $item['balance'] = $token_balance['balance'];
                            $item['token_decimals'] = $token_balance['token_decimals'];
                            $item['blockNum'] = $token_balance['blockNum'];
                            $item['token_balance'] = $token_balance['token_balance'];
                            array_push($token_balances, $item);
                        }
                    }
                }
            }
        }
        $ret_token_balances = $this->ignoreALGO($token_balances);
        return (count($ret_token_balances) > 1);
    }

    /**
     * Retrieves the payment operation details for the payment_id, tx_id from Bleumi Pay
     */

    public function ignoreALGO($token_balances)
    {
        $algo_token_found = false;
        $ret_token_balances = array();
        foreach ($token_balances as $item) {
            if (($item['network'] === 'algorand') && ($item['addr'] !== 'ALGO')) {
                $algo_token_found = true;
            }
        }
        foreach ($token_balances as $item) {
            if ($item['network'] === 'algorand') {
                if ($algo_token_found && ($item['addr'] !== 'ALGO')) {
                    array_push($ret_token_balances, $item);
                }
            } else {
                array_push($ret_token_balances, $item);
            }
        }
        return $ret_token_balances;
    }

    /**
     * List of Payment Operations
     */
    public function list_payment_operations($id, $next_token = null)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->listPaymentOperations($id, $next_token);
        } catch (\Exception $e) {
            $msg = 'list_payment_operations: failed : ' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->helper->log('[Error]: ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * List Tokens
     */
    public function list_tokens($storeCurrency)
    {
        $result = array();
        $errorStatus = array();
        try {
            $tokens = $this->HC_instance->listTokens();
            foreach ($tokens as $item) {
                if ($item['currency'] === $storeCurrency) {
                    array_push($result, $item);
                }
            }
        } catch (\Exception $e) {
            $msg = 'list_tokens: failed, response: response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->helper->log('[Error]: ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * Verify Payment operation completion status.
     */

    public function verifyOperationCompletion($orders, $operation, $data_source)
    {

        $completion_status = '';
        $op_failed_status = '';
        if ($operation === 'settle') {
            $completion_status = 'settled';
            $op_failed_status = 'settle_failed';
        } else if ($operation === 'refund') {
            $completion_status = 'refunded';
            $op_failed_status = 'refund_failed';
        }

        foreach ($orders as $order) {
            $order_id = $order['order_id'];
            $tx_id = $this->store->getMeta($order_id, 'bleumipay_txid');
            $this->helper->log('[Info]: verifyOperationCompletion: tx_id :' . $tx_id);

            if (is_null($tx_id)) {
                $this->helper->log('[Info]: verifyOperationCompletion: order-id :' . $order_id . ' tx-id is not set.');
                continue;
            }
            //For such orders perform get operation & check whether status has become 'true'
            $result = $this->get_payment_operation($order_id, $tx_id);
            if (isset($result[0]['code']) && !is_null($result[0]['code'])) {
                $msg = $result[0]['message'];
                $this->helper->log('[Info]: verifyOperationCompletion: order-id :' . $order_id . ' get_payment_operation api request failed: ' . $msg);
                continue;
            }
            $this->helper->log('get_payment_operation: result :' . json_encode($result));

            $status = $result[1]['status'];
            $txHash = $result[1]['hash'];
            $chain = $result[1]['chain'];
            if (!is_null($status)) {
                if ($status == 'yes') {
                    // $note = 'Tx hash for Bleumi Pay transfer ' . $txHash . ' Transaction Link : ' . BleumiPay_Utils::getTransactionLink($txHash, $chain);
                    // BleumiPay_Utils::addOrderNote($order_id, $note, true);
                    $this->store->updateMetaData($order_id, 'bleumipay_payment_status', $completion_status);
                    if ($operation === 'settle') {
                        $this->store->updateMetaData($order_id, 'bleumipay_processing_completed', 'yes');
                    }
                } else {
                    $msg = 'payment operation failed';
                    $this->store->updateMetaData($order_id, 'bleumipay_payment_status', $op_failed_status);
                    if ($operation === 'settle') {
                        //Settle failure will be retried again & again
                        $this->error_handler->logTransientException($order_id, $operation, 'E908', $msg);
                    } else {
                        //Refund failure will not be processed again
                        $this->error_handler->logHardException($order_id, $operation, 'E909', $msg);
                    }
                    $this->helper->log('[Info]: verifyOperationCompletion: order-id :' . $order_id . ' ' . $operation . ' ' . $msg);
                }
                $this->store->updateMetaData($order_id, 'bleumipay_data_source', $data_source);
            }
        }
    }

    /**
     * Retrieves the payment operation details for the payment_id, tx_id from Bleumi Pay
     */
    public function get_payment_operation($id, $tx_id)
    {
        $result = null;
        $errorStatus = array();
        try {
            $result = $this->payment_instance->getPaymentOperation($id, $tx_id);
        } catch (\Exception $e) {
            $msg = 'get_payment_operation: failed : payment-id: ' . $id . ' tx_id: ' . json_encode($tx_id) . ' response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->helper->log('bleumi_pay: ' . $msg);
            $this->error_handler->logException($id, 'get_payment_operation', $e->getCode(), $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * Settle payment in Bleumi Pay for the given order_id
     */

    public function settle_payment($payment_info, $order)
    {
        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        $tokenBalance = $payment_info['token_balances'][0];
        $token = $tokenBalance['addr'];
        $paymentSettleRequest = new \Bleumi\Pay\Model\PaymentSettleRequest();
        $amount = (string) $order['total'];
        $paymentSettleRequest->setAmount($amount);
        $paymentSettleRequest->setToken($token);
        try {
            $result = $this->payment_instance->settlePayment($paymentSettleRequest, $id, $tokenBalance['chain']);
            $order_id = $order['order_id'];
            $this->error_handler->clearTransientError($order_id);
        } catch (\Exception $e) {
            $this->helper->log('settle_payment --Exception--' . $e->getMessage());
            $msg = 'settle_payment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->helper->log('[Error]:  ' . $msg);
        }
        return array($errorStatus, $result);
    }

    /**
     * Refund payment in Bleumi Pay for the given order_id
     */

    public function refund_payment($payment_info, $order_id)
    {
        $result = null;
        $errorStatus = array();
        $id = $payment_info['id'];
        try {
            $tokenBalance = $payment_info['token_balances'][0];
            $amount = (float) $tokenBalance['balance'];
            $token = $tokenBalance['addr'];
            if ($amount > 0) {
                $paymentRefundRequest = new \Bleumi\Pay\Model\PaymentRefundRequest();
                $paymentRefundRequest->setToken($token);
                $result = $this->payment_instance->refundPayment($paymentRefundRequest, $id, $tokenBalance['chain']);
            }
            $this->error_handler->clearTransientError($order_id);
        } catch (\Exception $e) {
            $msg = 'refund_payment: failed : order-id :' . $id . '; response: ' . $e->getMessage();
            $errorStatus['code'] = -1;
            if ($e->getResponseBody() !== null) {
                $msg = $msg . $e->getResponseBody();
            }
            $errorStatus['message'] = $msg;
            $this->helper->log('[Error]:  ' . $msg);
        }
        return array($errorStatus, $result);
    }
}
