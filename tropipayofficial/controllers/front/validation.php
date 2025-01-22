<?php

require_once(dirname(__DIR__).'/../src/Services/ProcessPayment.php');
require_once(dirname(__DIR__).'/../src/Services/ProcessCartAssociatedWithPayment.php');
require_once(dirname(__DIR__).'/../src/Exceptions/PaymentValidationException.php');
require_once(dirname(__DIR__).'/../src/Exceptions/CartValidationException.php');

class TropipayofficialValidationModuleFrontController extends ModuleFrontController  { 
    
    private ProcessPayment $completePaymentService;
    private ProcessCartAssociatedWithPayment $processCartService;

    public function __construct() {
        parent::__construct();

        $this->completePaymentService = new ProcessPayment();
        $this->processCartService = new ProcessCartAssociatedWithPayment();
    }

    public function postProcess() 
    {
        if (!$this->module->active) {
            $this->module->logger->error("Error. Módulo desactivado.");
            return $this->respond200();
        }

        $this->module->logger->info("Entramos en la validación del pedido");

        try {
            $this->module->logger->info(file_get_contents('php://input'));
            $payment = $this->completePaymentService->parseRequest(file_get_contents('php://input'));
            $cart = $this->processCartService->process($payment);
            // $this->completePaymentService->validatePayment($payment, $cart);
            
            $this->processRequest($payment, $cart);
            $this->module->logger->info("Pedido validado.");
        } 
        
        catch (PaymentValidationException $e)
        {
            $retryPayment = Configuration::get('TROPIPAY_ERROR_PAGO');
            if ($retryPayment === "no"){
                $this->module->validateOrder($payment->getOrder(), _PS_OS_ERROR_, 0, $this->module->displayName, 'errores:'.$e->getMessage());
            }

            $this->module->logger->error("Excepción en la validación: " . $e->getMessage());
        } 
        
        catch (CartValidationException $e)
        {
            $this->module->logger->error(" ++ " . serialize($cart));
            $message += "REVISAR EN EL PORTAL DE ADMINISTRACION LA OPERACION '" . $payment->getOrder() . "' YA QUE ES POSIBLE QUE HAYA SIDO CORRECTA";
            $this->module->validateOrder($payment->getOrder(), _PS_OS_ERROR_, $payment->getAmount()/100, $this->module->displayName, $message);
            Tools::redirect('index.php?controller=order&step=1');
        }
        
        catch (Exception $e) 
        {
            $this->module->logger->error("Excepción en la validación: " . $e->getMessage());
        }

        $this->respond200(); // Always respond with 200 OK
    }

    private function processRequest(Payment $payment, Cart $cart)
    {
        $accessMethod = $_SERVER['REQUEST_METHOD'];

        if ($accessMethod === 'GET')
        {       
            $this->logger->error("Accediendo desde un methdo incorrecto (" . $accessMethod . ")");         
            Tools::redirect('index.php?controller=order&step=1');
        }
     
        $this->module->logger->info("Pedido de Tropipay: " . $payment->getReference());
        $this->module->logger->info("Pedido de Prestashop: " . $payment->getOrder());
        $id_trans = $payment->getBankOrder();
        $this->module->logger->info("ID trans: " . $id_trans);

        $customer = $cart->id_customer !== 0 ? new Customer((int)$cart->id_customer) : new Guest((int)$cart->id_guest);
        
        if (!Validate::isLoadedObject($customer)) {
            throw new \Exception("Error validando el cliente.");
        }
        
        $mailvars['transaction_id'] = (int)$id_trans;
        $this->module->validateOrder(
            $cart->id, 
            Configuration::get("TROPIPAY_ESTADO_PEDIDO"), 
            $payment->getAmount()/100, 
            $this->module->displayName, 
            null, 
            $mailvars, 
            (int)$cart->id_currency, 
            false, 
            (property_exists($customer, "secure_key") && !is_null($customer->secure_key)) ? $customer->secure_key : false
        );
        $this->module->logger->info("El pedido con ID de carrito " . $cart->id . " (" . $payment->getOrder() . ") es válido y se ha registrado correctamente.");
    }    

    private function respond200()
    {
        http_response_code(200);
        echo "OK";
        exit();
    }
}