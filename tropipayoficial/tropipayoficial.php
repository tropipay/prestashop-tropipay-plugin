<?php

if (! defined ( '_PS_VERSION_' )) {
	exit ();
}

if (! class_exists ( "TropipayAPI" )) {
	require_once ('src/TropipaySDK/apiTropipayFinal.php');
}
if (! defined ( '_CAN_LOAD_FILES_' ))
	exit ();

require_once('src/TropipaySDK/ILogger.php');
require_once('src/Infrastructure/Logger.php');
	
class Tropipayoficial extends PaymentModule 
{
	
	private $_html = '';
	private $html = '';
	private $_postErrors = array ();
	private $environments = [
		1 => 'https://www.tropipay.com',
		2 => 'https://tropipay-dev.herokuapp.com'
	];

	public ILogger $logger; 
	
	public function __construct() 
	{		
		$this->logger = new Logger();
		$this->name = 'tropipayoficial';
		$this->tab = 'payments_gateways';
		$this->version = '2.2.0';
		$this->author = 'TROPIPAY';

		$this->initializeProperties();
		$this->loadConfiguration();
		
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		
		$this->env = $this->environments[(int)$this->config ['TROPIPAY_URLTPV']] ?? null;
		parent::__construct ();
		
		// $this->page = basename ( __FILE__, '.php' );
		$this->displayName = $this->l ( 'Tropipay' );
		$this->description = $this->l ( 'Aceptar pagos con tarjeta mediante Tropipay' );
		
		// Mostrar aviso si faltan datos de config.
		if ($this->missingConfiguration()) {
            $this->warning = $this->l('Faltan datos por configurar en el módulo de Tropipay.');
        }
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
        $this->nombre = $this->config['TROPIPAY_CLIENTID'] ?? null;
        $this->codigo = $this->config['TROPIPAY_CLIENTSECRET'] ?? null;
        $this->error_pago = $this->config['TROPIPAY_ERROR_PAGO'] ?? null;
        $this->activar_log = $this->config['TROPIPAY_LOG'] ?? null;
        $this->idiomas_estado = $this->config['TROPIPAY_IDIOMAS_ESTADO'] ?? null;
        $this->estado_pedido = $this->config['TROPIPAY_ESTADO_PEDIDO'] ?? null;
    }

	private function missingConfiguration()
    {
        return !isset($this->urltpv, $this->nombre, $this->codigo, $this->error_pago, $this->activar_log, $this->idiomas_estado, $this->estado_pedido);
    }
	
	public function install()
    {
        if (!parent::install() || !$this->registerHooks() || !$this->initializeConfiguration()) {
            return false;
        }
        $this->tratarJSON();
        return true;
    }

	private function registerHooks()
    {
        return $this->registerHook('paymentReturn') &&
            (_PS_VERSION_ >= 1.7 ? $this->registerHook('paymentOptions') : $this->registerHook('payment')) &&
            (_PS_VERSION_ > '1.5' ? $this->registerHook('displayPaymentEU') : true);
    }

	private function initializeConfiguration()
    {
        return Configuration::updateValue('TROPIPAY_URLTPV', '0') &&
            Configuration::updateValue('TROPIPAY_CLIENTID', $this->l('Escriba el clientId de la API de Tropipay')) &&
            Configuration::updateValue('TROPIPAY_ERROR_PAGO', 'no') &&
            Configuration::updateValue('TROPIPAY_LOG', 'si') &&
            Configuration::updateValue('TROPIPAY_IDIOMAS_ESTADO', 'no') &&
            Configuration::updateValue('TROPIPAY_ESTADO_PEDIDO', '2');
    }
	
	/*
	 * Tratamos el JSON es_addons_modules.json para que addons 
	 * TPV Tropipay Pago tarjeta no pise nuestra versión 
	 */
	private function tratarJSON(){
		$fileName = "../app/cache/prod/es_addons_modules.json";
		if(file_exists($fileName) &&  _PS_VERSION_ >= 1.7){
			$data = file_get_contents($fileName);
			$jsonDecode = json_decode($data, true);
				
			if ( $jsonDecode[tropipay] != null && $jsonDecode[tropipay][name] != null){
				$jsonDecode[tropipay][name]="ps_tropipay";
				$newJsonString = json_encode($jsonDecode);
				file_put_contents($fileName, $newJsonString);
			}
		}
	}
	
	public function uninstall()
    {
        return Configuration::deleteByName('TROPIPAY_URLTPV') &&
            Configuration::deleteByName('TROPIPAY_CLIENTID') &&
            Configuration::deleteByName('TROPIPAY_CLIENTSECRET') &&
            Configuration::deleteByName('TROPIPAY_ERROR_PAGO') &&
            Configuration::deleteByName('TROPIPAY_LOG') &&
            Configuration::deleteByName('TROPIPAY_IDIOMAS_ESTADO') &&
            Configuration::deleteByName('TROPIPAY_ESTADO_PEDIDO') &&
            parent::uninstall();
    }
	
	private function _postValidation() {
		// Si al enviar los datos del formulario de config. hay campos vacios, mostrar errores.
		if (Tools::isSubmit ( 'btnSubmit' )) {
			if (! Tools::getValue ( 'nombre' ) )
				$this->post_errors [] = $this->l ( 'Se requiere el clientId de la API de Tropipay.' );
			if (! Tools::getValue ( 'codigo' ) )
				$this->post_errors [] = $this->l ( 'Se requiere el clientSecret de la API de Tropipay.' );
			if (Tools::getValue('estado_pedido')==='-1')
				$this->post_errors[] = $this->l('Ha de indicar un estado de pedido válido.');
		}
	}
	
	private function _postProcess() {
		// Actualizar la config. en la BBDD
		if (Tools::isSubmit ( 'btnSubmit' )) {
			Configuration::updateValue ( 'TROPIPAY_URLTPV', Tools::getValue ( 'urltpv' ) );
			Configuration::updateValue ( 'TROPIPAY_CLIENTID', Tools::getValue ( 'nombre' ) );
			Configuration::updateValue ( 'TROPIPAY_CLIENTSECRET', Tools::getValue ( 'codigo' ) );
			Configuration::updateValue ( 'TROPIPAY_ERROR_PAGO', Tools::getValue ( 'error_pago' ) );
			Configuration::updateValue ( 'TROPIPAY_LOG', Tools::getValue ( 'activar_log' ) );
			Configuration::updateValue ( 'TROPIPAY_IDIOMAS_ESTADO', Tools::getValue ( 'idiomas_estado' ) );
			Configuration::updateValue ( 'TROPIPAY_ESTADO_PEDIDO', Tools::getValue ( 'estado_pedido' ) );
		}
		$this->html .= $this->displayConfirmation ( $this->l ( 'Configuración actualizada' ) );
	}
	
	
	private function _displayTropipay()
	{
		// lista de payments
		$this->html .= '<img src="../modules/tropipayoficial/views/img/tropipay.png" style="float:left; margin-right:15px;"><b><br />'
		.$this->l('Este módulo le permite aceptar pagos con tarjeta.').'</b><br />'
		.$this->l('Si el cliente elije este modo de pago, podrá pagar de forma automática.').'<br /><br /><br />';
	}
	
	private function _displayForm()
	{
	
		// Opciones para el comportamiento en error en el pago
		$error_pago = Tools::getValue('error_pago', $this->error_pago);
		$error_pago_si = ($error_pago == 'si') ? ' checked="checked" ' : '';
		$error_pago_no = ($error_pago == 'no') ? ' checked="checked" ' : '';
	
		// Opciones para el comportamiento del log
		$activar_log = Tools::getValue('activar_log');
		$activar_log_si = ($activar_log == 'si') ? ' checked="checked" ' : '';
		$activar_log_no = ($activar_log == 'no') ? ' checked="checked" ' : '';
	
		// Opciones para activar los idiomas
		$idiomas_estado = Tools::getValue('idiomas_estado', $this->idiomas_estado);
		$idiomas_estado_si = ($idiomas_estado == 'si') ? ' checked="checked" ' : '';
		$idiomas_estado_no = ($idiomas_estado == 'no') ? ' checked="checked" ' : '';
		
		//Opciones para estado de pedido
		$id_estado_pedido = Tools::getValue('estado_pedido', $this->estado_pedido);
	

		// Opciones entorno
		if (!Tools::getValue('urltpv'))
			$entorno = Tools::getValue('env', $this->env);
		else
			$entorno = Tools::getValue('urltpv');
			$entorno_real = ($entorno == 1) ? ' selected="selected" ' : '';
			$entorno_t = ($entorno == 2) ? ' selected="selected" ' : '';

			
			// Mostar formulario
			$this->html .= '<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
			<fieldset>
			<legend><img src="../img/admin/contact.gif" />'.$this->l('Configuración del TPV').'</legend>
				<table border="0" width="680" cellpadding="0" cellspacing="0" id="form">
					<tr><td colspan="2">'.$this->l('Por favor completa los datos de configuración del comercio').'.<br /><br /></td></tr>
					<tr><td width="255" style="height: 35px;">'.$this->l('Entorno de Tropipay').'</td><td><select name="urltpv"><option value="1"'.$entorno_real.'>'.$this->l('Real').'</option><option value="2"'.$entorno_t.'>'.$this->l('Sandbox').'</option></select></td></tr>
					<tr><td width="255" style="height: 35px;">'.$this->l('clientId').'</td><td><input type="text" name="nombre" value="'.htmlentities(Tools::getValue('nombre', $this->nombre), ENT_COMPAT, 'UTF-8').'" style="width: 200px;" /></td></tr>
					<tr><td width="255" style="height: 35px;">'.$this->l('clientSecret').'</td><td><input type="password" name="codigo" value="'.Tools::getValue('codigo', $this->codigo).'" style="width: 200px;" /></td></tr>
				</table>
			</fieldset>
			<br>
			<fieldset>
			<legend><img src="../img/admin/asterisk.gif" />'.$this->l('Personalización').'</legend>
			<table border="0" width="680" cellpadding="0" cellspacing="0" id="form">
		<tr>
		<td colspan="2">'.$this->l('Por favor completa los datos adicionales').'.<br /><br /></td>
		</tr>
		<tr>
			<td width="340" style="height: 35px;">'.$this->l('Estado del pedido tras verificar el pago').'</td>
			<td>
					<select name="estado_pedido" id="estado_pago_1">';
						$order_states = OrderState::getOrderStates($this->context->language->id);
						foreach ($order_states as $state) {
							if($state['unremovable'] == '1')
								$this->html.='<option value="'.$state['id_order_state'].'" '.(($id_estado_pedido==$state['id_order_state'])?'selected':'').'>'.$state['name'].'</option>';
						}
	$this->html.='	</select>
			</td>
		</tr>
		<tr>
		<td width="340" style="height: 35px;">'.$this->l('En caso de error, permitir repetir el pedido').'</td>
			<td>
			<input type="radio" name="error_pago" id="error_pago_1" value="si" '.$error_pago_si.'/>
			<img src="../img/admin/enabled.gif" alt="'.$this->l('Activado').'" title="'.$this->l('Activado').'" />
			<input type="radio" name="error_pago" id="error_pago_0" value="no" '.$error_pago_no.'/>
			<img src="../img/admin/disabled.gif" alt="'.$this->l('Desactivado').'" title="'.$this->l('Desactivado').'" />
			</td>
		</tr>
		<tr>
		<td width="340" style="height: 35px;">'.$this->l('Activar trazas de log').'</td>
			<td>
			<input type="radio" name="activar_log" id="activar_log_1" value="si" '.$activar_log_si.'/>
			<img src="../img/admin/enabled.gif" alt="'.$this->l('Activado').'" title="'.$this->l('Activado').'" />
			<input type="radio" name="activar_log" id="activar_log_0" value="no" '.$activar_log_no.'/>
			<img src="../img/admin/disabled.gif" alt="'.$this->l('Desactivado').'" title="'.$this->l('Desactivado').'" />
			</td>
		</tr>
		<!-- <tr>
		<td width="340" style="height: 35px;">'.$this->l('Activar los idiomas en el TPV').'</td>
			<td>
			<input type="radio" name="idiomas_estado" id="idiomas_estado_si" value="si" '.$idiomas_estado_si.'/>
			<img src="../img/admin/enabled.gif" alt="'.$this->l('Activado').'" title="'.$this->l('Activado').'" />
			<input type="radio" name="idiomas_estado" id="idiomas_estado_no" value="no" '.$idiomas_estado_no.'/>
			<img src="../img/admin/disabled.gif" alt="'.$this->l('Desactivado').'" title="'.$this->l('Desactivado').'" />
			</td>
		</tr> -->
		</table>
			</fieldset>
			<br>
		<input class="button" name="btnSubmit" value="'.$this->l('Guardar configuración').'" type="submit" />
		</form>';
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
				else
					foreach ($this->_postErrors as $err)
						$this->html .= $this->displayError($err);
		}
		else{
			$this->_html .= '<br />';
		}
		
		$this->_html .= $this->_displayTropipay();
		$this->_html .=	$this->_displayForm();
		
		return $this->html;
	}

	public function createParameter($params){
		
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
	
		$orderId = $params ['cart']->id;
	
		$valorCookie=$this->getTropipayCookie($orderId);
		if (isset($valorCookie) && $valorCookie!=null) {
			$sec_pedido = $valorCookie;
		} else {
			$sec_pedido = - 1;
		}
		$this->logger->info( " - COOKIE: " . $valorCookie . "($orderId) - secPedido: $sec_pedido");
		if ($sec_pedido < 9) {
			$this->setTropipayCookie($orderId,++ $sec_pedido);
		}

		$numpedido = str_pad ( $orderId . "z" . $sec_pedido . time()%1000, 12, "0", STR_PAD_LEFT );

		$this->logger->info(" - NÚMERO PEDIDO: Número de pedido interno: '" . $orderId . "'. Número de pedido enviado a TROPIPAY: '" . $numpedido . "'");

		// Fuc
		$codigo = $this->codigo;
		// ISO Moneda
		$moneda = $currency->iso_code;

	
		// URL de Respuesta Online
		if (empty ( $_SERVER ['HTTPS'] )) {
			$protocolo = 'http://';
			$urltienda = $protocolo . $_SERVER ['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?fc=module&module=tropipayoficial&controller=validation';
		} else {
			$protocolo = 'https://';
			$urltienda = $protocolo . $_SERVER ['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?fc=module&module=tropipayoficial&controller=validation';
		}
		
		// Product Description
		$products = $params ['cart']->getProducts ();
		$productos = '';
		foreach ( $products as $product )
			$productos .= $product ['quantity'] . ' ' . Tools::truncate ( $product ['name'], 50 ) . ' ';
		
		$productos = str_replace ( "%", "&#37;", $productos );
	
		// Idiomas del TPV
		$idiomas_estado = $this->idiomas_estado;
		if ($idiomas_estado == 'si') {
			$idioma_web = Tools::substr ( $_SERVER ['HTTP_ACCEPT_LANGUAGE'], 0, 2 );
				
			switch ($idioma_web) {
				case 'es' :
					$idioma_tpv = '001';
					break;
				case 'en' :
					$idioma_tpv = '002';
					break;
				case 'ca' :
					$idioma_tpv = '003';
					break;
				case 'fr' :
					$idioma_tpv = '004';
					break;
				case 'de' :
					$idioma_tpv = '005';
					break;
				case 'nl' :
					$idioma_tpv = '006';
					break;
				case 'it' :
					$idioma_tpv = '007';
					break;
				case 'sv' :
					$idioma_tpv = '008';
					break;
				case 'pt' :
					$idioma_tpv = '009';
					break;
				case 'pl' :
					$idioma_tpv = '011';
					break;
				case 'gl' :
					$idioma_tpv = '012';
					break;
				case 'eu' :
					$idioma_tpv = '013';
					break;
				default :
					$idioma_tpv = '002';
			}
		} else
			$idioma_tpv = 'es';
				
			// Variable cliente
			$customer = new Customer ( $params ['cart']->id_customer );
			$id_cart = ( int ) $params ['cart']->id;
			$miObj = new TropipayAPI ();
			$miObj->setParameter ( "DS_MERCHANT_AMOUNT", $cantidad );
			$miObj->setParameter ( "DS_MERCHANT_ORDER", strval ( $numpedido ) );
			$miObj->setParameter ( "DS_MERCHANT_MERCHANTCODE", $codigo );
			$miObj->setParameter ( "DS_MERCHANT_CURRENCY", $moneda );
			
			
			$miObj->setParameter ( "DS_MERCHANT_MERCHANTURL", $urltienda );
			$miObj->setParameter ( "DS_MERCHANT_URLOK", $protocolo . $_SERVER ['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $id_cart . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder . '&key=' . $customer->secure_key );
			$miObj->setParameter ( "DS_MERCHANT_URLKO", $urltienda );
			$miObj->setParameter ( "Ds_Merchant_ConsumerLanguage", $idioma_tpv );
			$miObj->setParameter ( "Ds_Merchant_ProductDescription", $productos );
			// $miObj->setParameter("Ds_Merchant_Titular",$this->nombre);
			$miObj->setParameter ( "Ds_Merchant_Titular", $customer->firstname . " " . $customer->lastname );
			$miObj->setParameter ( "Ds_Merchant_MerchantData", sha1 ( $urltienda ) );
			$miObj->setParameter ( "Ds_Merchant_MerchantName", $this->nombre );
			// $miObj->setParameter("Ds_Merchant_MerchantName",$customer->firstname." ".$customer->lastname);
			
			$miObj->setParameter ( "Ds_Merchant_Module", "PR_tropipay_" . $this->version );

			$billingInfo = new Address($params ['cart']->id_address_invoice);
			
			$ds_merchant_usermail = $this->nombre;
			$ds_merchant_userpassword = $codigo;

			$data1 = array("email" => $ds_merchant_usermail, "password" => $ds_merchant_userpassword);
			$data_string = json_encode($data1);

			$tropipay_server=$this->urltpv;

			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => $tropipay_server . "/api/v2/access/token",
				//CURLOPT_HTTPHEADER => array ('Content-Type: application/json','Content-Length: ' . strlen($data_string)),
				CURLOPT_HTTPHEADER => array ('Content-Type: application/json'),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 10,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => '{"grant_type": "client_credentials","client_id":"' . $ds_merchant_usermail .'","client_secret":"' . $ds_merchant_userpassword . '","scope": "ALLOW_EXTERNAL_CHARGE ALLOW_PAYMENT_IN"}',
			));
			$response = curl_exec($curl);
			$err = curl_error($curl);

			curl_close($curl);

			if ($err) {
				//echo "cURL Error #:" . $err;
			} else {
				//echo $response;
				$character = json_decode($response);
				$tokent=$character->access_token;

				$this->logger->info(" - Llamada a la api login: '" .$ds_merchant_usermail . "'. Token: '" . $tokent . "'");
			
				$datetime = new DateTime('now');
				//echo $datetime->format('Y-m-d');

				
			
				//$customerprofiled=commerce_customer_profile_load($order->commerce_customer_billing['und'][0]['profile_id']);
				$country = new Country((int) $billingInfo->id_country);
				$billingstate = new State((int) $billingInfo->id_state);
				

				$arraycliente["name"]=$customer->firstname;
				$arraycliente["lastName"]=$customer->lastname;
				$arraycliente["address"]=$billingInfo->address1 . ", " . $billingInfo->city . ", " . $billingInfo->postcode;
				$arraycliente["phone"] = $billingInfo->phone ? $billingInfo->phone : '+34648454542';
				$arraycliente["email"]=$customer->email;
				$arraycliente["countryIso"] = $country->iso_code;
				$arraycliente["city"] = $billingInfo->city;
				$arraycliente["state"] = $billingstate->iso_code;
				$arraycliente["postCode"] = $billingInfo->postcode;
				$arraycliente["termsAndConditions"] = true;

			
				$datos=array(
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
				  "urlSuccess" => $protocolo . $_SERVER ['HTTP_HOST'] . __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $id_cart . '&id_module=' . $this->id . '&id_order=' . $this->currentOrder . '&key=' . $customer->secure_key,
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
				  $this->logger->info(" - Error a la llamada a la api paymentcards: '" .$err . "'");
				} else {
				  //echo $response;
				  $character = json_decode($response);
				  $shorturl=$character->shortUrl;

				  $this->logger->info(" - Respuesta paymentcards: '" .$response . "'");
			
				  $this->logger->info(" - Short url: '" .$shorturl . "'");
			
				  //$form['#action'] = $shorturl;
				  //dsm($form);
				 /* $form['submit'] = array(
					  '#type' => 'submit',
					  '#value' => t('Proceed to Tropipay'),
					);*/
			
				}
			}

			$this->urltpvd=$shorturl;

			$this->smarty->assign ( array (
					'urltpvd' => $this->urltpvd,
					'this_path' => $this->_path
			) );
				
	}
	
	public function hookDisplayPaymentEU($params){
		if ($this->hookPayment($params) == null) {
			return null;
		}
	
		return array(
				'cta_text' => "Pagar con tarjeta",
				'logo' => _MODULE_DIR_."tropipayoficial/views/img/tarjetas.png",
				'form' => $this->display(__FILE__, "views/templates/hook/payment_eu.tpl"),
		);
	}
	
	
	/*
	 * HOOK V1.6
	 */
	public function hookPayment($params) {
		
		if (! $this->active) {
			return;
		}
		
		if (! $this->checkCurrency ( $params ['cart'] )) {
			return;
		}
		
		
		return $this->display(__FILE__, 'payment.tpl');
	}
	
	/*
	 * HOOK V1.7
	 */
	public function hookPaymentOptions($params) {

		if (! $this->active) {
			return;
		}
		
		if (! $this->checkCurrency ( $params ['cart'] )) {
			return;
		}
		
	
		$newOption = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
		$newOption->setCallToActionText ($this->l('Pago con Tarjeta a través de Tropipay' ))
				->setAction ($this->context->link->getModuleLink($this->name, 'form', array(), true));

		$payment_options = [$newOption];

		return $payment_options;
	}
	
	public function hookPaymentReturn($params) {
		$totaltoPay = null;
		$idOrder = null;
		
		if( _PS_VERSION_ >= 1.7){
			$totaltoPay = Tools::displayPrice ( $params ['order']->getOrdersTotalPaid (), new Currency ( $params ['order']->id_currency ), false );
			$idOrder = $params ['order']->id;
		}else{
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
		
		return $this->display ( __FILE__, 'payment_return.tpl' );
	}
	
	
	public function checkCurrency($cart) {
		$currency_order = new Currency ( $cart->id_currency );
		$currencies_module = $this->getCurrency ( $cart->id_currency );
		
		if (is_array ( $currencies_module )) {
			foreach ( $currencies_module as $currency_module ) {
				if ($currency_order->id == $currency_module ['id_currency']) {
					return true;
				}
			}
		}
		return false;
	}
	
	public function getTropipayCookie($order){
		$key="tropipay".str_pad($order,12,"0",STR_PAD_LEFT);
	
		if(_PS_VERSION_ < 1.7){
			if(isset($_COOKIE[$key]))
				return $_COOKIE[$key];
		}
		else{
			if(Context::getContext()->cookie->__isset($key))
				return Context::getContext()->cookie->__get($key);
		}
	
		return null;
	}
	
	public function setTropipayCookie($order,$valor){
		$key="tropipay".str_pad($order,12,"0",STR_PAD_LEFT);
		
		if(_PS_VERSION_ < 1.7){
			setcookie ( $key, $valor, time () + (3600 * 24), __PS_BASE_URI__ );
		}
		else{
			Context::getContext()->cookie->__set($key, $valor);
		}
	}
}
