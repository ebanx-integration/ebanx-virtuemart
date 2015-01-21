<?php

/**
 * Copyright (c) 2013, EBANX Tecnologia da Informação Ltda.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this
 * list of conditions and the following disclaimer.
 *
 * Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * Neither the name of EBANX nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin'))
{
  require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentEbanx extends vmPSPlugin
{
  /**
   * The instance of class
   * @var object
   */
  public static $_this = false;

  /**
   * The plugin version
   * @var string
   */
  protected static $_pluginVersion = '1.0.0';

  /**
   * The currencies EBANX supports
   * @var array
   */
  protected $availableCurrencies = array('BRL', 'EUR', 'PEN', 'USD');

  /**
   * The constructor
   * @param type $subject
   * @param type $config
   */
  public function __construct(&$subject, $config)
  {

    parent::__construct($subject, $config);

    $this->_loggable   = true;
    $this->tableFields = array_keys($this->getTableSQLFields());
    $this->_tablepkey  = 'id';
    $this->_tableId    = 'id';
    $this->setConfigParameterable($this->_configTableFieldName, $this->getPushVars());

    // Include the library
    require_once 'ebanx-php/src/autoload.php';

    // Include required classes
    if (!class_exists('VirtueMartModelOrders'))
    {
      require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
    }

    if (!class_exists('VirtueMartModelCurrency'))
    {
      require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
    }

    if (!class_exists('TableVendors'))
    {
      require(JPATH_VM_ADMINISTRATOR . DS . 'tables' . DS . 'vendors.php');
    }

    if (!class_exists('VirtueMartCart'))
    {
      require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
    }
  }

  /**
   * Create table sql for EBANX Payment Plugin
   * @return string
   */
  public function getVmPluginCreateTableSQL()
  {
    return $this->createTableSQL('Payment EBANX Table');
  }

  /**
   * Table fields for EBANX Payment Plugin
   * @return Array
   */
  public function getTableSQLFields()
  {
    return array(
        'id'                  => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT'
      , 'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL'
      , 'order_number'        => 'char(32) DEFAULT NULL'
      , 'virtuemart_paymentmethod_id'  => 'mediumint(1) UNSIGNED DEFAULT NULL'
      , 'payment_name'         => 'char(255) NOT NULL DEFAULT \'\' '
      , 'payment_order_total'  => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' '
      , 'payment_currency'     => 'char(3)'
      , 'cost_per_transaction'       => 'decimal(10,2) DEFAULT NULL'
      , 'cost_percent_total'       => 'decimal(10,2) DEFAULT NULL'
      , 'tax_id'               => 'smallint(11) DEFAULT NULL'
      , 'reference'            => 'char(32) DEFAULT NULL'
      , 'ebanx_hash'           => "char(255) NOT NULL DEFAULT ''"
    );
  }

  /**
   * Plugin settings
   * @return array
   */
  protected function getPushVars()
  {
    return array(
        'integration_key'  => array('', 'string')
      , 'test_mode'        => array('', 'int')
      , 'status_pending'   => array('', 'char')
      , 'status_cancelled' => array('', 'char')
      , 'status_paid'      => array('', 'char')
    );
  }

  /**
   * Get the application instance
   * @return Application
   */
  protected function getApplication()
  {
    return JFactory::getApplication();
  }

  /**
   * Checks if the payment method should be displayed on the cart   *
   * @param object  $cart Cart object
   * @param integer $selected ID of the method selected
   * @return boolean
   */
  public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
  {
    $currentCurrency = $this->getIsoCurrencyCode($cart->paymentCurrency ?: $cart->pricesCurrency);

    if (in_array($currentCurrency, $this->availableCurrencies))
    {
      return $this->displayListFE($cart, $selected, $htmlIn);
    }

    return false;
  }

  /**
   * Check if the payment conditions are fulfilled for this payment method
   * @param $cart_prices: cart prices
   * @param $payment
   * @return boolean
   */
  protected function checkConditions($cart, $method, $cart_prices)
  {
    $countries = array();
    if (!empty($method->countries))
    {
      if (!is_array($method->countries))
      {
        $countries[0] = $method->countries;
      }
      else
      {
        $countries = $method->countries;
      }
    }

    // Probably did not gave his BT:ST address
    $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
    if (!is_array($address))
    {
      $address = array();
      $address['virtuemart_country_id'] = 0;
    }

    if (!isset($address['virtuemart_country_id']))
    {
      $address['virtuemart_country_id'] = 0;
    }

    if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0)
    {
      return true;
    }

    return false;
  }

  /**
   * Client confirms the order
   * @param $cart
   * @param $order
   * @return mixed
   */
  function plgVmConfirmedOrder($cart, $order)
  {
    // Another method was selected, do nothing
    $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
    if (!$method)
    {
      return null;
    }

    if (!$this->selectedThisElement($method->payment_element)) {
      return false;
    }

    $this->logInfo('EBANX plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

    // Vendor data
    $vendorModel = VmModel::getModel('Vendor');
    $vendorModel->setId(1);
    $vendor = $vendorModel->getVendor();
    $vendorModel->addImages($vendor, 1);

    // Get order data
    $this->getPaymentCurrency($method);
    $paymentCurrency        = CurrencyDisplay::getInstance($method->payment_currency);
    $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo(
                                      $method->payment_currency, $order['details']['BT']->order_total, FALSE), 2);
    $isoCurrencyCode        = $this->getIsoCurrencyCode($method->payment_currency);

    // Creating an entry for payment into EBANX payment table
    $this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
    $orderData['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
    $orderData['order_number']         = $order['details']['BT']->order_number;
    $orderData['payment_name']         = $this->renderPluginName($method);
    $orderData['payment_currency']     = $isoCurrencyCode;
    $orderData['payment_order_total']  = $totalInPaymentCurrency;
    $orderData['cost_per_transaction'] = (!empty($method->cost_per_transaction) ? $method->cost_per_transaction : 0);
    $orderData['cost_percent_total']   = (!empty($method->cost_percent_total) ? $method->cost_percent_total : 0);
    $orderData['tax_id']               = $method->tax_id;
    $orderData['reference']            = $order['details']['BT']->virtuemart_order_id;

    // Create EBANX payment
    try
    {
      $response = $this->createEbanxPayment($cart, $order, $method);
    }
    catch (Exception $e)
    {
      $this->getApplication()->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'), $e->getMessage());
    }

    if ($response && $response->status == 'SUCCESS')
    {
      // Store data order into plugin data table
      $orderData['ebanx_hash'] = $response->payment->hash;
      $this->storePSPluginInternalData($orderData);

      // Update order status
      $order['order_status']      = $method->status_pending;
      $order['customer_notified'] = 1;
      $order['comments']          = '';
      $order['paymentName']       = $orderData['payment_name'];

      $modelOrder = VmModel::getModel('orders');
      $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

      // Empty cart
      $cart = VirtueMartCart::getCart();
      $cart->emptyCart();

      // Redirect to EBANX checkout
      $this->getApplication()->redirect($response->redirect_url);
    }
    // Error processing the payment
    else
    {
      $this->getApplication()->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'), $response->status_message);
    }
  }


  /**
   * Identifies customer's country
   * @param  StdClass $plugin
   * @return string
   */
  protected function renderPluginName ($plugin)
  {
      $cart = VirtueMartCart::getCart();

      $country = $cart->BTaddress['fields']['virtuemart_country_id']['country_2_code'];
      $span_id = $cart->virtuemart_paymentmethod_id;

      if ($country == 'BR')
      {
          $plugin_name = 'EBANX - Boleto Bancário - TEF';
          $plugin_desc = 'Brazil Only';
      }
      if ($country == 'PE')
      {
          $plugin_name = 'PagoEfectivo, SafetyPay';
          $plugin_desc = 'Peru Only';
      }

      if ($country == '')
      {
          $plugin_name = 'EBANX - Boleto, TEF, PagoEfectivo, SafetyPay';
          $plugin_desc = 'Brazil and Peru Only';      
      }

      $return = '';

      $description = '';

      $logosFieldName = $this->_psType . '_logos';
      $logos = $plugin->$logosFieldName;

      if (!empty($logos))
      {
          $return = $this->displayLogos ($logos) . ' ';
      }

      if (!empty($plugin_name))
      {
          $description = '<span class="' . $this->_type . '_description">' . $plugin_desc . '</span>';
          $pluginName = $return . '<span class="' . $this->_type . '_name">' . $plugin_name . '</span>' . $description;
      }

      return $pluginName;
  }

  /**
   * Creates EBANX payment
   * @param  VirtueMartCart $cart
   * @param  array $order
   * @param  TablePaymentmethods $method
   * @return StdClass
   */
  protected function createEbanxPayment(VirtueMartCart $cart, Array $order, TablePaymentmethods $method)
  {
    $this->setupEbanx();

    // Append to order ID for test purposes
    $testOrderId = (intval($method->test_mode) == 1) ? time() : '';

    // Get order details
    $orderBilling = $order['details']['BT'];
    $orderId      = $orderBilling->virtuemart_order_id;
    $name    = $orderBilling->first_name . ' ' . $orderBilling->last_name;
    $email   = $orderBilling->email;
    $address       = $orderBilling->address_1 . ' ' . $orderBilling->address_2;
    $addressNumber = (preg_replace('/\D/', '', $orderBilling->address_1)) ?: '1';
    $countryCode   = $this->getColumnValue('virtuemart_countries', 'country_2_code', 'virtuemart_country_id', $orderBilling->virtuemart_country_id);
    $stateCode     = $this->getColumnValue('virtuemart_states', 'state_2_code', 'virtuemart_state_id', $orderBilling->virtuemart_state_id);

    // Prepare EBANX request
    $requestData = array(
        'currency_code'     => $this->getIsoCurrencyCode($method->payment_currency)
      , 'amount'            => $cart->pricesUnformatted['billTotal']
      , 'name'              => $name
      , 'email'             => $email
      , 'payment_type_code' => '_all'
      , 'address'           => $address
      , 'street_number'     => $addressNumber
      , 'city'              => $orderBilling->city
      , 'state'             => $stateCode
      , 'zipcode'           => $orderBilling->zip
      , 'country'           => $countryCode
      , 'phone_number'      => ($orderBilling->phone_1) ?: $orderBilling->phone_2
      , 'merchant_payment_code' => $orderId . $testOrderId
      , 'order_number'          => $orderId
    );

    $response = Ebanx\Ebanx::doRequest($requestData);

    return $response;
  }

  /**
   * Sets up EBANX library
   * @param  TablePaymentmethods $method
   * @return void
   */
  protected function setupEbanx()
  {
    $settings = $this->getEbanxSettings();

    Ebanx\Config::set(array(
        'integrationKey' => $settings['integration_key']
      , 'directMode'     => false
      , 'testMode'       => (intval($settings['test_mode']) == 1)
    ));
  }

  /**
   * Gets the ISO 4217 currency code for a currency
   * @param  int $currencyId
   * @return string
   */
  protected function getIsoCurrencyCode($currencyId)
  {
    return ShopFunctions::getCurrencyByID($currencyId, 'currency_code_3');
  }

  /**
   * Get value from Virtuemart database
   * @return mixed
   */
  private function getColumnValue($table, $select, $where, $value)
  {
    $db = JFactory::getDbo();
    $sql = "SELECT $select FROM #__$table WHERE $where=" . $value;
    $db->setQuery($sql);
    return $db->loadResult();
  }

  /**
   * Gets the EBANX plugin settings
   * @return array
   */
  protected function getEbanxSettings()
  {
    $settings = array();

    $db = JFactory::getDBO();
    $db->setQuery('SELECT `payment_params` FROM `#__virtuemart_paymentmethods` WHERE `payment_element`="ebanx" ');
    $params = explode('|', $db->loadResult());

    foreach ($params as $param)
    {
      if (isset($param) && trim($param) != '')
      {
        $tmp = explode('=', $param);
        $settings[$tmp[0]] = str_replace('"', '', $tmp[1]);
      }
    }

    return $settings;
  }

  /**
   * Handles a payment status notification
   * index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component
   * @return void
   */
  public function plgVmOnPaymentNotification()
  {
    $hashes = $_REQUEST['hash_codes'];

    if ($hashes)
    {
      $hashes = explode(',', $hashes);

      if (count($hashes) > 0)
      {
        // Setup EBANX settings
        $this->setupEbanx();

        foreach ($hashes as $hash)
        {
          $response = \Ebanx\Ebanx::doQuery(array('hash' => $hash));

          if (isset($response->status) && $response->status == 'SUCCESS')
          {
            $reference = (int) $response->payment->order_number;
            $status    = $response->payment->status;
            $newStatus = $this->getPaymentStatus($status);

            if ($status == 'CO')
            {
              $this->updateOrderStatus($reference, $newStatus, 'Changed to Paid by IPN.');
              echo "OK: Payment {$hash} was completed";
            }
            else if ($status == 'CA')
            {
              $this->updateOrderStatus($reference, $newStatus, 'Changed to Cancelled by IPN.');
              echo "OK: Payment {$hash} was cancelled via IPN";
            }
            else
            {
              echo "SKIP: Payment {$hash} is pending.";
            }
          }
        }

        exit;
      }
    }
  }

  /**
   * Handles EBANX return URL
   * @param String $html
   * @return boolean
   */
  public function plgVmOnPaymentResponseReceived(&$html)
  {
    $this->setupEbanx();

    $hash = $_REQUEST['hash'];
    $response = Ebanx\Ebanx::doQuery(array('hash' => $hash));

    if ($response->status == 'SUCCESS')
    {
      // Clear cart
      $cart = VirtueMartCart::getCart();
      $cart->emptyCart();

      // Find order number from ID/reference
      $order = VmModel::getModel('orders');
      $orderNumber = $order->getOrderNumber($response->payment->order_number);

      if ($orderNumber)
      {
        $this->getApplication()->redirect(JRoute::_('index.php/orders/number/' . $orderNumber));
      }
    }

    return false;
  }

  /**
   * Gets the Virtuemart payment status from the EBANX payment status
   * @param  string $ebanxStatus The ebanx status (CA, CO, PE, OP)
   * @return string
   */
  protected function getPaymentStatus($ebanxStatus)
  {
    $ebanxSettings = $this->getEbanxSettings();

    switch ($ebanxStatus)
    {
      case 'CA':
        $status = $ebanxSettings['status_cancelled'];
        break;
      case 'CO':
        $status = $ebanxSettings['status_paid'];
        break;
      case 'OP':
      case 'PE':
        $status = $ebanxSettings['status_pending'];
        break;
      default:
        break;
    }

    return $status;
  }

  /**
   * Update an order status
   * @param int    $reference The order reference
   * @param char   $newStatus The new status
   * @param string $comment The status comment
   * @return boolean
   */
    protected function updateOrderStatus($reference, $newStatus, $comment = '')
    {
      $model = VmModel::getModel('orders');

      $inputOrder = array(
          'order_status'      => $newStatus
        , 'customer_notified' => true
        , 'comments'          => $comment
      );

      return $model->updateStatusForOneOrder($reference, $inputOrder);
    }

  /**
   * displays the logos of a VirtueMart plugin
   *
   * @author Valerie Isaksen
   * @author Max Milbers
   * @param array $logo_list
   * @return html with logos
   */
  protected function displayLogos($logo_list)
  {
    $img = "";

    if (!(empty($logo_list))) {

      $url = JURI::root() . '/media/images/stories/virtuemart/' . $this->_psType . '/';
      if (!is_array($logo_list)) {
        $logo_list = (array) $logo_list;
      }
      foreach ($logo_list as $logo) {
        $alt_text = substr($logo, 0, strpos($logo, '.'));
        $img .= '<span class="vmCartPaymentLogo" ><img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /></span> ';
      }
    }
    return $img;
  }


  /**
   * Get payment currency
   * @param type $virtuemart_paymentmethod_id
   * @param type $paymentCurrencyId
   * @return null|boolean
   */
  function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
  {

    if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
      return NULL; // Another method was selected, do nothing
      }
    if (!$this->selectedThisElement($method->payment_element)) {
      return FALSE;
    }

    $this->getPaymentCurrency($method);
    $paymentCurrencyId = $method->payment_currency;
  }

  /**
   * Calcule final price to product
   *
   * @param VirtueMartCart $cart
   * @param $key
   * @return float
   */
  private function calculePrice(VirtueMartCart $cart, $key)
  {

    if (!class_exists('calculationHelper')) {
      require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'calculationh.php');
    }

    $calculator = calculationHelper::getInstance();
    $cart_prices = $calculator->getCheckoutPrices($cart);

    $sales_price = $cart->products[$key]->product_price;

    foreach ($cart_prices as $prices_key => $prices) {
      if ($key === $prices_key) {
        $sales_price = $prices["salesPrice"];
      }
    }

    return $sales_price;
  }

  /**
   * Create the table for this plugin if it does not yet exist.
   * This functions checks if the called plugin is active one.
   * When yes it is calling the standard method to create the tables
   *
   * @author Valérie Isaksen
   *
   */
  function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
  {
    return $this->onStoreInstallPluginTable($jplugin_id);
  }

  /**
   * This event is fired after the payment method has been selected. It can be used to store
   * additional payment info in the cart.
   *
   * @author Max Milbers
   * @author Valérie isaksen
   *
   * @param VirtueMartCart $cart: the actual cart
   * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
   *
   */
  public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
  {
    return $this->OnSelectCheck($cart);
  }

  /*
   * plgVmonSelectedCalculatePricePayment
   * Calculate the price (value, tax_id) of the selected method
   * It is called by the calculator
   * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
   * @author Valerie Isaksen
   * @cart: VirtueMartCart the current cart
   * @cart_prices: array the new cart prices
   * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
   * @param VirtueMartCart $cart
   * @param array          $cart_prices
   * @param                $cart_prices_name
   * @return bool|null
   */
  public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array & $cart_prices, &$cart_prices_name)
  {
    return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
  }

  /**
   * plgVmOnCheckAutomaticSelectedPayment
   * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
   * The plugin must check first if it is the correct type
   *
   * @author Valerie Isaksen
   * @param VirtueMartCart cart: the cart object
   * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
   *
   */
  public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
  {
    return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
  }

  /**
   * This method is fired when showing the order details in the frontend.
   * It displays the method-specific data.
   *
   * @param integer $order_id The order ID
   * @return mixed Null for methods that aren't active, text (HTML) otherwise
   * @author Max Milbers
   * @author Valerie Isaksen
   */
  public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
  {
    $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
  }

  /**
   * This method is fired when showing when priting an Order
   * It displays the the payment method-specific data.
   *
   * @param integer $_virtuemart_order_id The order ID
   * @param integer $method_id  method used for this order
   * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
   * @author Valerie Isaksen
   */
  public function plgVmonShowOrderPrintPayment($order_number, $method_id)
  {
    return $this->onShowOrderPrint($order_number, $method_id);
  }

  public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
  {
    return $this->declarePluginParams('payment', $name, $id, $data);
  }

  /**
   * Fired in payment method when click save into
   * payment method info view
   * @param String $name
   * @param Integer $id
   * @param String $table
   * @return bool
   */
  public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
  {
    return $this->setOnTablePluginParams($name, $id, $table);
  }
}