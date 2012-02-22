<?php
/**
 * Saferpay Ecommerce Magento Payment Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Saferpay Business to
 * newer versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @copyright Copyright (c) 2011 Openstream Internet Solutions (http://www.openstream.ch)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

abstract class Saferpay_Ecommerce_Model_Abstract extends Mage_Payment_Model_Method_Abstract
{
	/**
	 * Payment Method Code
	 *
	 * @var string
	 */
	protected $_code = 'abstract';

	protected $_formBlockType = 'saferpay/form';
	protected $_infoBlockType = 'saferpay/info';

	/*
	 * Availability options
	 */
	protected $_isGateway              = true;
	protected $_canAuthorize           = true;
	protected $_canCapture             = true;
	protected $_canCapturePartial      = false;
	protected $_canRefund              = true;
	protected $_canVoid                = false;
	protected $_canUseInternal         = false;
	protected $_canUseCheckout         = true;
	protected $_canUseForMultishipping = false;

	protected $_order;
	
	const STATE_AUTHORIZED = 'authorized';

	/**
	 * Get order model
	 *
	 * @return Mage_Sales_Model_Order
	 */
	public function getOrder()
	{
		if (!$this->_order) {
			try{
				$this->_order = $this->getInfoInstance()->getOrder();
				if (! $this->_order)
				{
					$orderId = $this->getSession()->getQuote()->getReservedOrderId();
					$order = Mage::getModel('sales/order');
					$order->loadByIncrementId($orderId);
					if ($order->getId())
					{
						$this->_order = $order;
					}
				}
			}catch(Exception $e){
				$id = $this->getSession()->getLastOrderId();
				$this->_order = Mage::getModel('sales/order')->load($id);
			}
		}
		return $this->_order;
	}

	/**
	 *
	 * @return Mage_Checkout_Model_Session
	 */
	public function getSession()
	{
		return Mage::getSingleton('checkout/session');
	}

	protected function _parseResponseXml($xml)
	{
		$data = array();
		if ($xml)
		{
			$xml = simplexml_load_string($xml);
			$data = (array) $xml->attributes();
			$data = $data['@attributes'];
		}
		return $data;
	}

	/**
	 * Return the payment provider id
	 *
	 * @return string
	 */
	public function getProviderId()
	{
		$id = str_replace(' ', '', (string) $this->getConfigData('provider_id'));
		return $id;
	}
	
	/**
	 * Return url for redirection after order placed
	 *
	 * @return string
	 */
	public function getOrderPlaceRedirectUrl()
	{
		$url = Mage::helper('saferpay')->process_url($this->getPayInitUrl(), $this->getPayInitFields());
		if(preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url)){
			return $url;
		}else{
			Mage::getSingleton('checkout/session')->addError(Mage::helper('saferpay')->__('An error occured while processing the payment failure, please contact the store owner for assistance.'));
			return Mage::getUrl('checkout/cart');
		}
	}

	/**
	 * Return the payment init base url
	 *
	 * @return string
	 */
	public function getPayInitUrl()
	{
		$url = Mage::helper('saferpay')->getSetting('payinit_base_url');
		return $url;
	}

	/**
	 * Capture payment through saferpay
	 *
	 * @param Varien_Object $payment
	 * @param decimal $amount
	 * @return Saferpay_Standard_Model_Abstract
	 */
	public function capture(Varien_Object $payment, $amount)
	{
		$payment->setStatus(self::STATUS_APPROVED)
			->setTransactionId($this->getTransactionId())
			->setIsTransactionClosed(0);

		return $this;
	}

	/**
	 * Cancel payment
	 *
	 * @param Varien_Object $payment
	 * @return Saferpay_Standard_Model_Abstract
	 */
	public function cancel(Varien_Object $payment)
	{
		$payment->setStatus(self::STATUS_DECLINED)
			->setTransactionId($this->getTransactionId())
			->setIsTransactionClosed(1);

		return $this;
	}

	/**
	 * Prepare params array to send it to gateway
	 *
	 * @return array
	 */
	public function getPayInitFields()
	{
		$orderId = $this->getOrder()->getRealOrderId();

		$params = array(
			'ACCOUNTID'             => Mage::helper('saferpay')->getSetting('saferpay_account_id'),
			'AMOUNT'                => intval(Mage::helper('saferpay')->round($this->getOrder()->getGrandTotal(), 2) * 100),
			'CURRENCY'              => $this->getOrder()->getOrderCurrencyCode(),
			'DESCRIPTION'           => $this->getOrder()->getStore()->getWebsite()->getName(),
			'CCCVC'                 => 'yes',
			'CCNAME'                => 'yes',
			'ORDERID'               => $orderId,
			'SUCCESSLINK'           => Mage::getUrl('saferpay/process/success', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
			'BACKLINK'              => Mage::getUrl('saferpay/process/back', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
			'FAILLINK'              => Mage::getUrl('saferpay/process/fail', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
			'NOTIFYURL'             => Mage::getUrl('saferpay/process/notify', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
			'AUTOCLOSE'             => 0,
			'PROVIDERSET'           => $this->getProviderId(),
			'LANGID'                => $this->getLangId(),
			'SHOWLANGUAGES'         => $this->getUseDefaultLangId() ? 'yes' : 'no',
            'DELIVERY'				=> 'no',
			'VTCONFIG'				=> Mage::helper('saferpay')->getSetting('vtconfig')
		);

		return $params;
	}

	/**
	 * Return the language to use in the saferpay terminal
	 *
	 * @param string
	 * @return string
	 */
	protected function getLangId($lang = null)
	{
		try
		{
			if (is_null($lang))
			{
				$lang = $this->_getOrderLang();
			}
			if ($lang)
			{
				if ($xml = $this->_getLangIdsXml())
				{
					$nodes = $xml->xpath("//LANGUAGE[@CODE='{$lang}']");
					foreach ($nodes as $node)
					{
						return (string) $node['LANGID'];
					}
				}
			}
		}
		catch (Exception $e)
		{
			Mage::logException($e);
		}

		$this->setUseDefaultLangId(true);
		return Mage::helper('saferpay')->getSetting('default_lang_id');
	}

	protected function _getOrderLang()
	{
		$orderLocale = $locale = Mage::getStoreConfig('general/locale/code', $this->getOrder()->getStoreId());
		$lang = strtolower(substr($orderLocale, 0, 2));
		return $lang;
	}

	/**
	 * Return the available language id's from the saferpay API
	 *
	 * @return SimpleXMLElement | false
	 */
	protected function _getLangIdsXml()
	{
		$langIds = $this->getData('lang_ids_xml');
		if (is_null($langIds))
		{
			$langIds = false;
			$url = Mage::helper('saferpay')->getSetting('language_ids_url');
			if ($langIds = new SimpleXMLElement(Mage::helper('saferpay')->process_url($url)))
			{
				$this->setLangIdsXml($langIds);
			}
		}
		return $langIds;
	}

	/**
	 * Get initialized flag status
	 *
	 * @return true
	 */
	public function isInitializeNeeded()
	{
		return true;
	}

	/**
	 * Instantiate state and set it to state onject
	 * 
	 * @param string
	 * @param Varien_Object
	 * @return Varien_Object
	 */
	public function initialize($paymentAction, $stateObject)
	{
		$stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
		$stateObject->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
		$stateObject->setIsNotified(false);
		$this->getSession()->setSaferpayPaymentMethod($this->getCode());
	}

	/**
	 * Throw an exception with a default error message if none is specified
	 *
	 * @param string $msg
	 * @param array $params
	 */
	protected function _throwException($msg = null, $params = null)
	{
		if (is_null($msg))
		{
			$msg = $this->getConfigData('generic_error_msg');
		}
		Mage::throwException(Mage::helper('saferpay')->__($msg, $params));
	}

	/**
	 * Seperate the result status and the xml in the response
	 *
	 * @param string $response
	 * @return array
	 */
	protected function _splitResponseData($response)
	{
		if (($pos = strpos($response, ':')) === false)
		{
			$status = $response;
			$xml = '';
		}
		else
		{
			$status = substr($response, 0, strpos($response, ':'));
			$xml = substr($response, strpos($response, ':')+1);
		}
		return array($status, $xml);
	}

	/**
	 * refund the amount with transaction id
	 *
	 * @access public
	 * @param string $payment Varien_Object object
	 * @return Mage_Payment_Model_Abstract
	 */
	public function refund(Varien_Object $payment, $amount) {

		$order = $payment->getOrder();

		$params = array(
			'ACCOUNTID' => Mage::getStoreConfig('saferpay/settings/saferpay_account_id'),
			'AMOUNT' => ($amount * 100),
			'CURRENCY' => $this->getOrder()->getOrderCurrencyCode(),
			'REFID' => $payment->getRefundTransactionId(),
			'DESCRIPTION' => 'Refunding order ' . $order->getIncrementId(),
			'REFOID' => $order->getIncrementId(),
			'ACTION' => 'Credit',
			'spPassword' => Mage::getStoreConfig('saferpay/settings/saferpay_password')
		);

		Mage::log('Refunding payment for order #'.$order->getIncrementId().': '. print_r($params, true), Zend_Log::DEBUG, 'saferpay_ecommerce.log');

		$url = Mage::getStoreConfig('saferpay/settings/execute_base_url');

		$response = Mage::helper('saferpay')->process_url($url, $params);

		Mage::log('Refunding response for order #'.$order->getIncrementId().': '. print_r($response, true), Zend_Log::DEBUG, 'saferpay_ecommerce.log');
		list($status, $xml) = $this->_splitResponseData($response);

		if ($status != 'OK') {
			$this->_throwException($xml);
		}

		$data = $this->_parseResponseXml($xml);

		$id = '';
		// check saferpay result code of authorization (0 = success)
		if ($data['RESULT'] == 0) {
			$id = $data['ID'];
			Mage::log('Refunded order '.$order->getIncrementId().': ' . print_r($data, true), Zend_Log::DEBUG, 'saferpay_ecommerce.log');
		} else {
			Mage::log('Refund for order '.$id.' failed (result code ' . $data['RESULT'] . ') : '. $response, Zend_Log::ERR, 'saferpay_ecommerce.log');
			$this->_throwException('Refund failed (result code ' . $data['RESULT'] . ')');
		}

		$payment->setLastTransId($id);
		$params = array(
			'ACCOUNTID' => Mage::getStoreConfig('saferpay/settings/saferpay_account_id'),
			'ID' => $id,
			'spPassword' => Mage::getStoreConfig('saferpay/settings/saferpay_password')
		);

		Mage::log('Finishing refunding #'.$order->getIncrementId().': ' . print_r($params, true), Zend_Log::DEBUG, 'saferpay_ecommerce.log');

		$url = Mage::getStoreConfig('saferpay/settings/paycomplete_base_url');

		$response = Mage::helper('saferpay')->process_url($url, $params);
		list($status, $data) = Mage::helper('saferpay')->_splitResponseData($response);

		if ($status != 'OK') {
			$this->_throwException($data);
		}
		$payment->setStatus(self::STATUS_SUCCESS);

		$amount = Mage::helper('core')->formatPrice(Mage::helper('saferpay')->round($amount, 2), false);
		$this->getOrder()->addStatusHistoryComment(
				Mage::helper('saferpay')->__('Refund for %s successfull (ID %s)', $amount, $id)
			)->save(); // save history model

		return $this;
	}
}