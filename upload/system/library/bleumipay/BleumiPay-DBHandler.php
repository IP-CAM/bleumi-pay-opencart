<?php

namespace BleumiPay\PaymentGateway;


/**
 * Copyright © 2020 Bleumi Pay. All rights reserved.
 */

class BleumiPay_DBHandler
{
    private $db;
    protected $helper;

    const cron_collision_safe_minutes = 0.1;

    const await_payment_minutes = 24 * 60;

    public function __construct($code, $config)
    {
        $this->helper = new BleumiPay_Helper($code, $config);
        $this->db = new \DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
    }

    /**
     * Get the (Pending/Awaiting confirmation/Multi Token Payment) order for the order_id.
     */

    public function getPendingOrder($order_id)
    {
        $result = null;
        $pending_status = $this->helper->getIDforOrderStatus('pending');
        $awaiting_confirmation_status = $this->helper->getIDforOrderStatus('awaiting_payment_confirmation');
        $multi_token_status = $this->helper->getIDforOrderStatus('multi_token');
        $sql = "SELECT 
                    order_id, 
                    order_status_id, 
                    total,  
                    UNIX_TIMESTAMP(date_modified) date_modified
                FROM 
                    `" . DB_PREFIX . "order` 
                WHERE 
                    `order_id` = " . (int) $order_id . " 
                    AND `order_status_id` IN ($pending_status, $awaiting_confirmation_status, $multi_token_status)";
        $data = $this->db->query($sql);
        if ($data->num_rows > 0) {
            $result = $data->row;
        }
        $this->helper->log("[Info]: getPendingOrder: order-id : " . $order_id . " Found : " . $data->num_rows . " pending/awaiting_confirmation/multi_token order(s)");
        return $result;
    }

    /**
     * Get all orders that are modified after last order-cron execution
     * Usage: The list of orders processed by Orders cron
     */

    public function getUpdatedOrders($updatedTime)
    {
        //5-Completed, 7-Canceled
        /* fetch all orders that match condition */
        $result = null;
        $complete_status = $this->helper->getIDforOrderStatus('complete');
        $canceled_status = $this->helper->getIDforOrderStatus('canceled');
        $sql = "SELECT  order_id, 
                        order_status_id, 
                        total,
                        UNIX_TIMESTAMP(date_modified) date_modified
                FROM 
                        `" . DB_PREFIX . "order` 
                WHERE 
                        `payment_code` = 'bleumipay' AND 
                        `order_status_id` IN ($complete_status, $canceled_status) AND 
                        (
                            (`bleumipay_processing_completed` = 'no') OR 
                            (`bleumipay_processing_completed` IS NULL)
                        ) AND 
                        UNIX_TIMESTAMP(`date_modified`) > " . $updatedTime . "
                ORDER BY `order_id` ASC";
        $data = $this->db->query($sql);
        if ($data->num_rows > 0) {
            $result = $data->rows;
        }
        return $result;

    }


    public function updateOrderStatus($order_id, $status_id)
    {
        $sql = "UPDATE `" . DB_PREFIX . "order` 
                SET `order_status_id` = " . (int) $status_id .
            " WHERE `order_id` =  " . (int) $order_id;
        $this->db->query($sql);
    }


    public function getMeta($order_id, $column_name)
    {
        try {
            $sql = "SELECT  `$column_name`  
                    FROM `" . DB_PREFIX . "order` 
                    WHERE `order_id` = " . (int) $order_id;
            $metadata = $this->db->query($sql);
        } catch (\Exception $e) {
            $this->helper->log('[Error]: getMeta: order_id : ' . $order_id . " for " . $column_name);
            return null;
        }
        return $metadata->row[$column_name];
    }

    public function deleteMetaData($order_id, $column_name)
    {
        return $this->updateStringData($order_id, $column_name);
    }

    public function get_order_for_id($order_id)
    {
        $result = null;
        $sql = "SELECT * 
                FROM `" . DB_PREFIX . "order` 
                WHERE `order_id` = " . (int) $order_id;
        $data = $this->db->query($sql);
        if ($data->num_rows > 0) {
            $result = $data->row;
        }
        return $result;
    }

    public function updateMetaData($order_id, $column_name, $column_value)
    {
        return $this->updateStringData($order_id, $column_name, $column_value);
    }

    /**
     * Helper function - creates and executes the UPDATE statement for a string columns of any table
     */

    public function updateStringData($order_id, $column_name, $column_value = null)
    {
        if (!empty($order_id)) {
            $set_clause =   "";
            if (!empty($column_value)) {
                $set_clause = " SET `" . $column_name . "` = '" . $column_value . "'";
            } else {
                $set_clause = " SET `" . $column_name . "` = null";
            }
            $sql = "UPDATE `" . DB_PREFIX . "order`"
                . $set_clause .
                " WHERE order_id = " . $order_id;
            $this->db->query($sql);
        }
    }

    /**
     * Get all orders with status = $status
     * Usage: Orders cron to get all orders that are in 'awaiting_confirmation' status to check if
     * they are still awaiting even after 24 hours.
     */

    public function getOrdersForStatus($status, $field)
    {
        $result = null;
        $where_clause_1 = null;
        if ($field === 'order_status_id') {
            $where_clause_1 =  "`order_status_id` = " . $status ." ";
        } else if ($field === 'bleumipay_payment_status') {
            $where_clause_1 = "`bleumipay_payment_status` = '" . $status ."' ";
        }

        $sql = "SELECT  order_id, 
                        order_status_id, 
                        total,
                        UNIX_TIMESTAMP(date_modified) date_modified 
                FROM 
                    `" . DB_PREFIX . "order` 
                WHERE 
                    " . $where_clause_1 . " 
                    AND (
                            (`bleumipay_processing_completed` = 'no') OR 
                            (`bleumipay_processing_completed` IS NULL)
                        )  
                    AND `payment_code` = 'bleumipay'  
                    ORDER BY date_modified ASC";
        $data = $this->db->query($sql);
        $this->helper->log("[Info] : getOrdersForStatus: Found : " . $data->num_rows . " " . $status . " order(s)");
        if ($data->num_rows > 0) {
            $result = $data->rows;
        }
        return $result;
    }


    /**
     * Get all orders with transient errors.
     * Used by Retry cron to reprocess such orders
     */

    public function getTransientErrorOrders()
    {
        $result = null;
        $sql = "SELECT * 
                FROM 
                    `" . DB_PREFIX . "order` 
                WHERE 
                    `bleumipay_transient_error` = 'yes' AND 
                    (
                        (`bleumipay_processing_completed` = 'no') OR 
                        (`bleumipay_processing_completed` IS NULL)
                    ) AND 
                    `payment_code` = 'bleumipay'  
        ORDER BY date_modified ASC";
        $data = $this->db->query($sql);
        $this->helper->log("[Info] : getTransientErrorOrders: Found : " . $data->num_rows . " transient error orders");
        if ($data->num_rows > 0) {
            $result = $data->rows;
        }
        return $result;
    }

    /**
     * Changes the order status to 'payment_failed'
     */

    public function failThisOrder($order_id, $order_status)
    {
        $awaiting_status = $this->helper->getIDforOrderStatus('awaiting_payment_confirmation');
        if ($order_status == $awaiting_status) {
            $failed_status = $this->helper->getIDforOrderStatus('failed');
            $this->updateOrderStatus($order_id, $failed_status); 
            return true;
        }
        return false;
    }

    /**
     * Changes the order status to 'multi_token'
     */

    public function markAsMultiTokenPayment($order_id, $order_status)
    {
        $multi_token_status = $this->helper->getIDforOrderStatus('multi_token');
        $pending_status = $this->helper->getIDforOrderStatus('pending');
        $awaiting_status = $this->helper->getIDforOrderStatus('awaiting_payment_confirmation');
        $valid_statuses = array($pending_status, $awaiting_status);
        if (in_array($order_status, $valid_statuses)) {
            $this->updateOrderStatus($order_id, $multi_token_status);
            return true;
        }
        return false;
    }


    /**
     * Changes the order status to 'processing'
     */

    public function markOrderAsProcessing($order_id, $order_status)
    {
        $processing_status = $this->helper->getIDforOrderStatus('processing');
        $pending_status = $this->helper->getIDforOrderStatus('pending');
        $awaiting_status = $this->helper->getIDforOrderStatus('awaiting_payment_confirmation');
        $valid_statuses = array($pending_status, $awaiting_status);
        if (in_array($order_status, $valid_statuses)) {
            $this->updateOrderStatus($order_id, $processing_status);
            return true;
        }
        return false;
    }

    public function get_col_field_value($id, $field, $table_name, $key)
    {
        $result = null;
        $dataSet = $this->db->query("SELECT " . $field . " FROM `" . DB_PREFIX . $table_name . "` WHERE $key = '" . $id . "';");
        if ($dataSet->num_rows > 0) {
            $result = $dataSet->row[$field];
        }
        return $result;
    }

    public function getCronTime($field_name)
    {
        $table_name = 'bleumi_pay_cron';
        $key = 'id';
        return $this->get_col_field_value(1, $field_name, $table_name, $key);
    }

    public function updateCronTime($field_name, $last_exec_time)
    {
        $field_name = 'payment_updated_at';
        $table_name = 'bleumi_pay_cron';
        $this->db->query("UPDATE `" .  DB_PREFIX . $table_name  . "` SET `" . $field_name . "` = '" . $last_exec_time . "'  WHERE id = 1 ;");
    }

}
