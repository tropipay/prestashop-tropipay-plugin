<?php

if (! defined('_PS_VERSION_')) {
	exit();
}

if (! class_exists("TropipayAPI")) {
	require_once('src/TropipaySDK/apiTropipayFinal.php');
}
if (! defined('_CAN_LOAD_FILES_'))
	exit();

require_once('src/TropipaySDK/ILogger.php');
require_once('src/Infrastructure/Logger.php');
require_once('src/Enums/Environment.php');

class TropipayOfficial extends PaymentModule
{
	private string $clientId;
	private string $clientSecret;
	private string $retryPayment;
	private string $activateLogs;
	private string $statusesLanguage;
	private string $orderStatus;
	private string $_html = '';
	private string $html = '';
	private array $_postErrors = array();
	private array $environments = [
		Environment::PRODUCTION => 'https://www.tropipay.com',
		Environment::SANDBOX => 'https://tropipay-dev.herokuapp.com'
	];
	private array $config;
	private string $env;
	private string $urltpv;
	public string $urltpvd;
	public ILogger $logger;
	private int $is_eu_compatible;
	public array $post_errors;

	private const DEFAULT_PAYMENT_STATUS = '2';
	private const YES = 'si';
	private const NO = 'no';

	public function __construct()
	{
		$this->logger = new Logger();
		$this->name = 'tropipayofficial';
		$this->tab = 'payments_gateways';
		$this->version = '2.2.0';
		$this->author = 'TROPIPAY';
		$this->need_instance = 1;

		// TODO: declare the min and max versions supported
		// $this->ps_versions_compliancy = [
		// 	'min' => '1.6.0',
		// 	'max' => '1.7.9'
		// ];

		$this->initializeProperties();
		$this->loadConfiguration();
		$this->env = $this->config['urltpv'] ?? Environment::PRODUCTION;
		$this->urltpv = $this->environments[$this->env];
		
		parent::__construct();

		$this->displayName = $this->translate('Tropipay');
        $this->description = $this->translate('Aceptar pagos con tarjeta mediante Tropipay.');

		$this->confirmUninstall = $this->translate('Esta seguro que desea desintalar?');

		if (!Configuration::get('TROPIPAYOFFICIAL_NAME')) {
			$this->warning = $this->translate('El nombre no ha sido especificado.');
		}

		// Mostrar aviso si faltan datos de config.
		if ($this->missingConfiguration()) {
			$this->warning = $this->l('Faltan datos por configurar en el módulo de Tropipay.');
		}
	}
	
	private function translate(string $text): string
	{
		//TODO: create the right i18n file
		if (_PS_VERSION_ >= 8.0)
			return $this->trans($text, [], 'Modules.tropipayofficial.Admin');

		return $this->l($text);
	}

	private function initializeProperties()
	{
		if (_PS_VERSION_ >= 1.6) {
			$this->is_eu_compatible = 1;
			$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
			$this->bootstrap = true;
		}

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
	}

	private function loadConfiguration()
	{
		$this->config = Configuration::getMultiple(array(
			'TROPIPAY_URLTPV',
			'TROPIPAY_CLIENTID',
			'TROPIPAY_CLIENTSECRET',
			'TROPIPAY_ERROR_PAGO',
			'TROPIPAY_LOG',
			'TROPIPAY_IDIOMAS_ESTADO',
			'TROPIPAY_ESTADO_PEDIDO'
		));

		$this->setConfigurationProperties();
	}

	private function setConfigurationProperties()
	{
		$this->clientId = $this->config['TROPIPAY_CLIENTID'] ? $this->config['TROPIPAY_CLIENTID'] : '';
		$this->clientSecret = $this->config['TROPIPAY_CLIENTSECRET'] ? $this->config['TROPIPAY_CLIENTSECRET'] : '';
		$this->retryPayment = $this->config['TROPIPAY_ERROR_PAGO'] ? $this->config['TROPIPAY_ERROR_PAGO'] : self::NO;
		$this->activateLogs = $this->config['TROPIPAY_LOG'] ? $this->config['TROPIPAY_LOG'] : self::YES;
		$this->statusesLanguage = $this->config['TROPIPAY_IDIOMAS_ESTADO'] ? $this->config['TROPIPAY_IDIOMAS_ESTADO'] : '';
		$this->orderStatus = $this->config['TROPIPAY_ESTADO_PEDIDO'] ? $this->config['TROPIPAY_ESTADO_PEDIDO'] : self::DEFAULT_PAYMENT_STATUS;
	}
	
	private function missingConfiguration()
	{
		return !isset($this->urltpv, $this->nombre, $this->codigo, $this->error_pago, $this->activar_log, $this->idiomas_estado, $this->estado_pedido);
	}

	public function install(): bool
    {
        if(parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
			&& Configuration::updateValue('TROPIPAYOFFICIAL_NAME', 'tropipay official'))
		{
			$this->tratarJSON();
			return true;
		}

		return false;
    }

	
	/*
	 * Tratamos el JSON es_addons_modules.json para que addons 
	 * TPV Tropipay Pago tarjeta no pise nuestra versión 
	 */
	private function tratarJSON()
	{
		$fileName = "../app/cache/prod/es_addons_modules.json";
		if (file_exists($fileName) &&  _PS_VERSION_ >= 1.7) {
			$data = file_get_contents($fileName);
			$jsonDecode = json_decode($data, true);

			if ($jsonDecode[tropipay] != null && $jsonDecode[tropipay][name] != null) {
				$jsonDecode[tropipay][name] = "ps_tropipay";
				$newJsonString = json_encode($jsonDecode);
				file_put_contents($fileName, $newJsonString);
			}
		}
	}


	public function uninstall()
    {
		return Configuration::deleteByName('TROPIPAY_URLTPV') 
			&& Configuration::deleteByName('TROPIPAY_CLIENTID')
			&& Configuration::deleteByName('TROPIPAY_CLIENTSECRET')
			&& Configuration::deleteByName('TROPIPAY_ERROR_PAGO')
			&& Configuration::deleteByName('TROPIPAY_LOG')
			&& Configuration::deleteByName('TROPIPAY_IDIOMAS_ESTADO')
			&& Configuration::deleteByName('TROPIPAY_ESTADO_PEDIDO')
			&& Configuration::deleteByName('TROPIPAYOFFICIAL_NAME')
			&& parent::uninstall();
			
    }

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit')) {
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->html .= $this->displayError($err);
		} else {
			$this->_html .= '<br />';
		}

		$this->_html .= $this->_displayTropipay();
		$this->_html .=	$this->_displayForm();

		return $this->html;
	}

	private function _postValidation()
	{
		// Si al enviar los datos del formulario de config. hay campos vacios, mostrar errores.
		if (Tools::isSubmit('btnSubmit')) {
			if (! Tools::getValue('clientId'))
				$this->post_errors[] = $this->l('Se requiere el clientId de la API de Tropipay.');
			if (! Tools::getValue('clientSecret'))
				$this->post_errors[] = $this->l('Se requiere el clientSecret de la API de Tropipay.');
			if (Tools::getValue('orderStatus') === '-1')
				$this->post_errors[] = $this->l('Ha de indicar un estado de pedido válido.');
		}
	}

	private function _postProcess()
	{
		// Actualizar la config. en la BBDD
		if (Tools::isSubmit('btnSubmit')) {
			$this->urltpv = $this->environments[(int)Tools::getValue('urltpv')];
			Configuration::updateValue('TROPIPAY_URLTPV', $this->urltpv );
			Configuration::updateValue('TROPIPAY_CLIENTID', Tools::getValue('clientId'));
			Configuration::updateValue('TROPIPAY_CLIENTSECRET', Tools::getValue('clientSecret'));
			Configuration::updateValue('TROPIPAY_ERROR_PAGO', Tools::getValue('retryPayment'));
			Configuration::updateValue('TROPIPAY_LOG', Tools::getValue('activateLogs'));
			Configuration::updateValue('TROPIPAY_IDIOMAS_ESTADO', Tools::getValue('statusLanguages'));
			Configuration::updateValue('TROPIPAY_ESTADO_PEDIDO', Tools::getValue('orderStatus'));
		}
		$this->html .= $this->displayConfirmation($this->l('Configuración actualizada'));
	}

	private function _displayTropipay()
	{
		// lista de payments
		$this->html .= '<img src="../modules/tropipayofficial/views/img/tropipay.png" style="float:left; margin-right:15px;"><b><br />'
			. $this->l('Este módulo le permite aceptar pagos con tarjeta.') . '</b><br />'
			. $this->l('Si el cliente elije este modo de pago, podrá pagar de forma automática.') . '<br /><br /><br />';
	}

	private function _displayForm()
	{
		$this->html .= '<form action="' . $_SERVER['REQUEST_URI'] . '" method="post">';
		$this->html .= $this->getTpvConfigurationFieldset();
		$this->html .= $this->getCustomizationFieldset();
		$this->html .= '<br><input class="btn-toolbar toolbar-box toolbar-btn" class="button" name="btnSubmit" value="' . $this->l('Guardar configuración') . '" type="submit" />';
		$this->html .= '</form>';
	}

	private function getTpvConfigurationFieldset()
	{
		$entornoOptions = $this->getEntornoOptions();
		$clientIdInput = $this->getInputField('clientId', "Escribe el client id del API de Tropipay");
		$clientSecretInput = $this->getInputField('clientSecret', "Escribe el client secret", "password");

		return '
			<fieldset>
				<legend><img src="../img/admin/contact.gif" />' . $this->l('Configuración del TPV') . '</legend>
				<table border="0" width="680" cellpadding="0" cellspacing="0" id="form">
					<tr><td colspan="2">' . $this->l('Por favor completa los datos de configuración del comercio') . '.<br /><br /></td></tr>
					<tr><td width="255">' . $this->l('Entorno de Tropipay') . '</td><td width="425">' . $entornoOptions . '</td></tr>
					<tr><td width="255">' . $this->l('Client ID') . '</td><td width="425">' . $clientIdInput . '</td></tr>
					<tr><td width="255">' . $this->l('Client Secret') . '</td><td width="425">' . $clientSecretInput . '</td></tr>
				</table>
			</fieldset>
			<br>';
	}

	private function getCustomizationFieldset()
	{
		$orderStateOptions = $this->getOrderStateOptions();
		$paymentErrorOptions = $this->getRadioOptions('retryPayment', 'En caso de error, permitir repetir el pedido', $this->retryPayment);
		$activateLogOptions = $this->getRadioOptions('activateLogs', 'Activar trazas de log', $this->activateLogs);

		return '
			<fieldset>
				<legend><img src="../img/admin/asterisk.gif" />' . $this->l('Personalización') . '</legend>
				<table border="0" width="680" cellpadding="0" cellspacing="0" id="form">
					<tr ><td colspan="2">' . $this->l('Por favor completa los datos adicionales') . '.<br /><br /></td></tr>
					<tr ><td width="340" style="height: 35px;">' . $this->l('Estado del pedido tras verificar el pago') . '</td><td>' . $orderStateOptions . '</td></tr>
					<tr ><td width="340" style="height: 35px;">' . $this->l('En caso de error, permitir repetir el pedido') .'</td><td>' . $paymentErrorOptions . '</td></tr>
					<tr ><td width="340" style="height: 35px;">' . $this->l('Activar trazas de log') . '</td><td>' . $activateLogOptions . '</td></tr>
				</table>
			</fieldset>
			<br>';
	}

	private function getEntornoOptions()
	{
		$environment = Tools::getValue('urltpv', Tools::getValue('env', $this->env));
		$production = ($environment == 1) ? ' selected="selected" ' : '';
		$sandbox = ($environment == 2) ? ' selected="selected" ' : '';

		return '<select name="urltpv">
					<option value="1"' . $production . '>' . $this->l('Real') . '</option>
					<option value="2"' . $sandbox . '>' . $this->l('Sandbox') . '</option>
				</select>';
	}

	private function getInputField($name, $defaultValue, $type = "text")
	{	
		$valueOrPlaceHolder = Tools::getValue($name) ? 'value="'.Tools::getValue($name).'"' : 'placeholder="'.htmlentities($defaultValue).'"';
		return "<input type=\"{$type}\" name=\"{$name}\" " . $valueOrPlaceHolder ." autocomplete=\"new-password\" />";
	}

	private function getOrderStateOptions()
	{
		$orderStatusId = Tools::getValue('orderStatus', $this->orderStatus);
		$orderStates = OrderState::getOrderStates($this->context->language->id);
		$options = '';

		foreach ($orderStates as $state) {
			if ($state['unremovable'] == '1') {
				$selected = ($orderStatusId == $state['id_order_state']) ? 'selected' : '';
				$options .= '<option value="' . $state['id_order_state'] . '" ' . $selected . '>' . $this->l($state['name']) . '</option>';
			}
		}

		return '<select name="orderStatus" id="estado_pago_1">' . $options . '</select>';
	}

	private function getRadioOptions($name, $defaultValue = null)
	{
		$value = Tools::getValue($name, $this->$name);
		$checkedYes = ($value === self::YES) ? ' checked="checked" ' : '';
		$checkedNo = ($value === self::NO) ? ' checked="checked" ' : '';

		return '
			<input type="radio" name="' . $name . '" id="' . $name . '_1" value="si" ' . $checkedYes . '/>
			<img src="../img/admin/enabled.gif" alt="' . $this->l('Activado') . '" title="' . $this->l('Activado') . '" />
			<input type="radio" name="' . $name . '" id="' . $name . '_0" value="no" ' . $checkedNo . '/>
			<img src="../img/admin/disabled.gif" alt="' . $this->l('Desactivado') . '" title="' . $this->l('Desactivado') . '" />';
	}

	public function hookDisplayPaymentEU($params)
	{
		if ($this->hookPayment($params) == null) {
			return null;
		}

		return array(
			'cta_text' => "Pagar con tarjeta",
			'logo' => _MODULE_DIR_ . "tropipayofficial/views/img/tarjetas.png",
			'form' => $this->display(__FILE__, "views/templates/hook/payment_eu.tpl"),
		);
	}

	/*
	 * HOOK V1.6
	 */
	public function hookPayment($params)
	{

		if (! $this->active) {
			return;
		}

		if (! $this->checkCurrency($params['cart'])) {
			return;
		}


		return $this->display(__FILE__, 'payment.tpl');
	}

	/*
	 * HOOK V1.7
	 */
	public function hookPaymentOptions($params)
	{

		if (! $this->active) {
			return;
		}

		if (! $this->checkCurrency($params['cart'])) {
			return;
		}


		$newOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
		$newOption->setCallToActionText($this->l('Pago con Tarjeta a través de Tropipay'))
			->setAction($this->context->link->getModuleLink($this->name, 'form', array(), true));

		$payment_options = [$newOption];

		return $payment_options;
	}

	public function hookPaymentReturn($params)
	{
		$totaltoPay = null;
		$idOrder = null;

		if (_PS_VERSION_ >= 1.7) {
			$totaltoPay = Tools::displayPrice($params['order']->getOrdersTotalPaid(), new Currency($params['order']->id_currency), false);
			$idOrder = $params['order']->id;
		} else {
			$totaltoPay = Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false);
			$idOrder = $params['objOrder']->id;
		}

		if (! $this->active) {
			return;
		}

		$this->smarty->assign(array(
			'total_to_pay' => $totaltoPay,
			'status' => 'ok',
			'id_order' => $idOrder,
			'this_path' => $this->_path
		));

		return $this->display(__FILE__, 'payment_return.tpl');
	}

	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module)) {
			foreach ($currencies_module as $currency_module) {
				if ($currency_order->id == $currency_module['id_currency']) {
					return true;
				}
			}
		}
		return false;
	}

	public function getTropipayCookie($order)
	{
		$key = "tropipay" . str_pad($order, 12, "0", STR_PAD_LEFT);

		if (_PS_VERSION_ < 1.7) {
			if (isset($_COOKIE[$key]))
				return $_COOKIE[$key];
		} else {
			if (Context::getContext()->cookie->__isset($key))
				return Context::getContext()->cookie->__get($key);
		}

		return null;
	}

	public function setTropipayCookie($order, $valor)
	{
		$key = "tropipay" . str_pad($order, 12, "0", STR_PAD_LEFT);

		if (_PS_VERSION_ < 1.7) {
			setcookie($key, $valor, time() + (3600 * 24), __PS_BASE_URI__);
		} else {
			Context::getContext()->cookie->__set($key, $valor);
		}
	}

	public function createParameter($params)
	{

		// Valor de compra
		$currency = new Currency($params['cart']->id_currency);
		$currency_decimals = is_array($currency) ? (int) $currency['decimals'] : (int) $currency->decimals;
		$cart_details = $params['cart']->getSummaryDetails(null, true);
		$decimals = $currency_decimals * _PS_PRICE_DISPLAY_PRECISION_;

		$shipping = $cart_details['total_shipping_tax_exc'];
		$subtotal = $cart_details['total_price_without_tax'] - $cart_details['total_shipping_tax_exc'];
		$tax = $cart_details['total_tax'];

		$total_price = Tools::ps_round($shipping + $subtotal + $tax, $decimals);
		$cantidad = number_format($total_price, 2, '', '');
		$cantidad = (int)$cantidad;

		// El num. de pedido -> id_Carrito + el tiempo SS

		$orderId = $params['cart']->id;

		$valorCookie = $this->getTropipayCookie($orderId);
		if (isset($valorCookie) && $valorCookie != null) {
			$sec_pedido = $valorCookie;
		} else {
			$sec_pedido = -1;
		}
		$this->logger->info(" - COOKIE: " . $valorCookie . "($orderId) - secPedido: $sec_pedido");
		if ($sec_pedido < 9) {
			$this->setTropipayCookie($orderId, ++$sec_pedido);
		}

		$numpedido = str_pad($orderId . "z" . $sec_pedido . time() % 1000, 12, "0", STR_PAD_LEFT);

		$this->logger->info(" - NÚMERO PEDIDO: Número de pedido interno: '" . $orderId . "'. Número de pedido enviado a TROPIPAY: '" . $numpedido . "'");

		// Fuc
		$codigo = $this->clientSecret;
		// ISO Moneda
		$moneda = $currency->iso_code;


		// URL de Respuesta Online
		if (empty($_SERVER['HTTPS'])) {
			$protocolo = 'http://';
			$urltienda = $protocolo . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?fc=module&module=tropipayofficial&controller=validation';
		} else {
			$protocolo = 'https://';
			$urltienda = $protocolo . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?fc=module&module=tropipayofficial&controller=validation';
		}

		// Product Description
		$products = $params['cart']->getProducts();
		$productos = '';
		foreach ($products as $product)
			$productos .= $product['quantity'] . ' ' . Tools::truncate($product['name'], 50) . ' ';

		$productos = str_replace("%", "&#37;", $productos);

		// Idiomas del TPV
		$idiomas_estado = $this->statusesLanguage;
		if ($idiomas_estado == 'si') {
			$idioma_web = Tools::substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);

			switch ($idioma_web) {
				case 'es':
					$idioma_tpv = '001';
					break;
				case 'en':
					$idioma_tpv = '002';
					break;
				case 'ca':
					$idioma_tpv = '003';
					break;
				case 'fr':
					$idioma_tpv = '004';
					break;
				case 'de':
					$idioma_tpv = '005';
					break;
				case 'nl':
					$idioma_tpv = '006';
					break;
				case 'it':
					$idioma_tpv = '007';
					break;
				case 'sv':
					$idioma_tpv = '008';
					break;
				case 'pt':
					$idioma_tpv = '009';
					break;
				case 'pl':
					$idioma_tpv = '011';
					break;
				case 'gl':
					$idioma_tpv = '012';
					break;
				case 'eu':
					$idioma_tpv = '013';
					break;
				default:
					$idioma_tpv = '002';
			}
		} else
			$idioma_tpv = 'es';

		// Variable cliente
		$customer = new Customer($params['cart']->id_customer);
		$id_cart = (int) $params['cart']->id;
		$miObj = new TropipayAPI();
		$miObj->setParameter("DS_MERCHANT_AMOUNT", $cantidad);
		$miObj->setParameter("DS_MERCHANT_ORDER", strval($numpedido));
		$miObj->setParameter("DS_MERCHANT_MERCHANTCODE", $codigo);
		$miObj->setParameter("DS_MERCHANT_CURRENCY", $moneda);


		$miObj->setParameter("DS_MERCHANT_MERCHANTURL", $urltienda);
		$miObj->setParameter("DS_MERCHANT_URLOK", $protocolo . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $id_cart . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder . '&key=' . $customer->secure_key);
		$miObj->setParameter("DS_MERCHANT_URLKO", $urltienda);
		$miObj->setParameter("Ds_Merchant_ConsumerLanguage", $idioma_tpv);
		$miObj->setParameter("Ds_Merchant_ProductDescription", $productos);
		// $miObj->setParameter("Ds_Merchant_Titular",$this->nombre);
		$miObj->setParameter("Ds_Merchant_Titular", $customer->firstname . " " . $customer->lastname);
		$miObj->setParameter("Ds_Merchant_MerchantData", sha1($urltienda));
		$miObj->setParameter("Ds_Merchant_MerchantName", $this->clientId);
		// $miObj->setParameter("Ds_Merchant_MerchantName",$customer->firstname." ".$customer->lastname);

		$miObj->setParameter("Ds_Merchant_Module", "PR_tropipay_" . $this->version);

		$billingInfo = new Address($params['cart']->id_address_invoice);

		$ds_merchant_usermail = $this->clientId;
		$ds_merchant_userpassword = $codigo;
		$tropipay_server = Configuration::get('TROPIPAY_URLTPV');

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $tropipay_server . "/api/v2/access/token",
			CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => '{"grant_type": "client_credentials","client_id":"' . $ds_merchant_usermail . '","client_secret":"' . $ds_merchant_userpassword . '","scope": "ALLOW_EXTERNAL_CHARGE ALLOW_PAYMENT_IN"}',
		));
		$response = curl_exec($curl);
		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
			//echo "cURL Error #:" . $err;
		} else {
			//echo $response;
			$response = json_decode($response);
			if (property_exists($response, 'errors'))
			{	
				var_dump($response);
				$errors = $response->errors;
				$this->logger->error($errors[0]->title.": ".$errors[0]->detail);
				Tools::redirect('index.php?controller=order&step=1');
			}

			$tokent = $response->access_token;

			$this->logger->info(" - Llamada a la api login: '" . $ds_merchant_usermail . "'. Token: '" . $tokent . "'");

			$datetime = new DateTime('now');
			//echo $datetime->format('Y-m-d');



			//$customerprofiled=commerce_customer_profile_load($order->commerce_customer_billing['und'][0]['profile_id']);
			$country = new Country((int) $billingInfo->id_country);
			$billingstate = new State((int) $billingInfo->id_state);


			$arraycliente["name"] = $customer->firstname;
			$arraycliente["lastName"] = $customer->lastname;
			$arraycliente["address"] = $billingInfo->address1 . ", " . $billingInfo->city . ", " . $billingInfo->postcode;
			$arraycliente["phone"] = $billingInfo->phone ? $billingInfo->phone : '+34648454542';
			$arraycliente["email"] = $customer->email;
			$arraycliente["countryIso"] = $country->iso_code;
			$arraycliente["city"] = $billingInfo->city;
			$arraycliente["state"] = $billingstate->iso_code;
			$arraycliente["postCode"] = $billingInfo->postcode;
			$arraycliente["termsAndConditions"] = true;


			$datos = array(
				"reference" => $numpedido,
				"concept" => 'Order #: ' . $orderId,
				"favorite" => false,
				"description" => " ",
				"amount" => $cantidad,
				"currency" => $moneda,
				"singleUse" => true,
				"reasonId" => 4,
				"expirationDays" => 1,
				"lang" => "es",
				"urlSuccess" => $protocolo . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $id_cart . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder . '&key=' . $customer->secure_key,
				"urlFailed" => $urltienda,
				"urlNotification" => $urltienda,
				"serviceDate" => $datetime->format('Y-m-d'),
				"directPayment" => true,
				"client" => $arraycliente
			);

			$this->logger->info(" - Antes de la llamada a pago: '" . $moneda . "'");
			$data_string2 = json_encode($datos);

			$this->logger->info(" - Antes de la llamada a pago: '" . $data_string2 . "'");

			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => $tropipay_server . "/api/v2/paymentcards",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => $data_string2,
				CURLOPT_HTTPHEADER => array(
					"authorization: Bearer " . $tokent,
					"content-type: application/json"
				),
			));

			$response = curl_exec($curl);
			$err = curl_error($curl);

			curl_close($curl);

			if ($err) {
				//echo "cURL Error #:" . $err;
				$this->logger->info(" - Error a la llamada a la api paymentcards: '" . $err . "'");
			} else {
				//echo $response;
				$character = json_decode($response);
				$shorturl = $character->shortUrl;

				$this->logger->info(" - Respuesta paymentcards: '" . $response . "'");

				$this->logger->info(" - Short url: '" . $shorturl . "'");
			}
		}

		$this->urltpvd = $shorturl;

		$this->smarty->assign(array(
			'urltpvd' => $this->urltpv,
			'this_path' => $this->_path
		));
	}
}












