<?php

require_once(dirname(__DIR__).'/Entities/Payment.php');
require_once(dirname(__DIR__).'/Exceptions/PaymentValidationException.php');

class ProcessPayment {

    public function parseRequest(string $requestBody): Payment 
    {
        $data = json_decode($requestBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PaymentValidationException("JSON inválido en la solicitud.");
        }
       ;

        if ($data['status'] !== 'OK') {
            throw new PaymentValidationException("Estado del pago no es válido.");
        }

        return new Payment($data['data']);
    }

    public function validatePayment(Payment $payment, Cart $cart): void 
    {
        $valid = true;
        $errors = array();
        $localSignature = $this->generateSignature($payment);
        if ($payment->getSignature() !== $localSignature)
        {
            $valid = false;
            $errors[] = "La firma no coincide.";
        }

        if (!$payment->getBankOrder())
        {
            $valid = false;
            $errors[] = "Ds_AuthorisationCode inválido. ($payment->getBankOrder())";
        }

        // $cartCurrency = new Currency($cart->id_currency);
        
        // if ($payment->getCurrency()->iso_code !== $cartCurrency->iso_code)
        // {
        //     $valid = false;
        //     $errors[] = "Las monedas no coinciden " . var_dump($payment->getCurrency()->iso_code, $cartCurrency->iso_code);
        // }

         
        if (!$valid)
        {
            $message = "Errores validando el pago: ";
            $first = true;
            foreach ($errors as $key => $error) 
            {

               $message .= ($first ? "" : "| " ). $error;
               $first = false;
            }
            throw new PaymentValidationException($message);
        }

    }

    private function generateSignature(Payment $payment): string {
        return hash(
            'sha256',
            $payment->getBankOrder() . Configuration::get('TROPIPAY_CLIENTID') .
            Configuration::get('TROPIPAY_CLIENTSECRET') . $payment->getAmount()
        );
    }
}