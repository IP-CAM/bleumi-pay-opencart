<?php

namespace BleumiPay\PaymentGateway;


/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

/*****************************************
 *
 * Bleumi Pay Retry CRON ("Retry failed transient actions") functions 
 *
 * Finds all the orders that failed during data synchronization
 * and re-performs them
 *
 ******************************************/

class BleumiPay_RetryCron
{
	protected $api;
	protected $paymentCron;
	protected $orderCron;
	protected $store;
	protected $helper;

	public function __construct($code, $config)

	{
		$this->api = new BleumiPay_APIHandler($code, $config);
		$this->paymentCron = new BleumiPay_PaymentCron($code, $config);
		$this->orderCron = new BleumiPay_OrderCron($code, $config);
		$this->store = new BleumiPay_DBHandler($code, $config);
		$this->helper = new BleumiPay_Helper($code, $config);

		$this->error_handler = new BleumiPay_ExceptionHandler($code, $config);
	}

	/**
	 *
	 * Retry cron
	 * 
	 */

	public function execute()
	{
		$data_source = 'retry-cron';
		$this->helper->log('[Info] : ' . $data_source . ' : looking for orders with transient errors');
		$retry_orders = $this->store->getTransientErrorOrders();
		foreach ($retry_orders as $order) {
			$order_id = $order['order_id'];
			$action = $this->store->getMeta($order_id, 'bleumipay_retry_action');
			$this->error_handler->checkRetryCount($order_id);

			$bp_hard_error = $this->store->getMeta($order_id, 'bleumipay_hard_error');
			if ($bp_hard_error === 'yes') {
				$this->helper->log('[Info] : '. $data_source . ': order-id :' . $order_id . ' skipping, hard error found');
			} else {
				$this->helper->log('[Info] : '. $data_source . ': order-id :' . $order_id . ' performing retry action : ' .  $action);
				switch ($action) {
					case "syncOrder":
						$this->orderCron->syncOrder($order, $data_source);
						break;
					case "syncPayment":
						$this->paymentCron->syncPayment(null, $order_id, $data_source);
						break;
					case "settle":
						$result = $this->api->getPaymentTokenBalance(null, $order_id);
						if (is_null($result[0]['code'])) {
							$this->orderCron->settleOrder($order, $result[1], $data_source);
						}
						break;
					case "refund":
						$result = $this->api->getPaymentTokenBalance(null, $order_id);
						if (is_null($result[0]['code'])) {
							$this->orderCron->refundOrder($order, $result[1], $data_source);
						}
						break;
					default:
						break;
				}
			}
		}
	}
}
