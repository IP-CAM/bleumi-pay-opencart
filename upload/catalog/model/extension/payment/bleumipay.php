<?php

/** Used by OC 2.1 */
class ModelPaymentBleumipay extends ModelExtensionPaymentBleumipay {}

/**
 * Class ModelExtensionPaymentBleumipay
 */
class ModelExtensionPaymentBleumipay extends Model
{
    /** @var string  */
    protected $languagePath = 'extension/payment/bleumipay';

    public function __construct($registry)
    {
        parent::__construct($registry);

        if (true === version_compare(VERSION, '2.3.0', '<')) {
            $this->languagePath = 'payment/bleumipay';
        }

        $this->load->language($this->languagePath);
    }

    public function getMethod()
    {
        $this->load->language('extension/payment/bleumipay');

        return array(
            'code' => 'bleumipay',
            'title' => $this->language->get('heading_title') . ' - ' . $this->language->get('text_title'),
            'terms' => $this->language->get('text_terms'),
            'sort_order' => '1'
        );
    }
}
