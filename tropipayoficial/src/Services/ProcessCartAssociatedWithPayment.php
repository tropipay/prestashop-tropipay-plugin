<?php

class ProcessCartAssociatedWithPayment {

    public function validateCart(Cart $cart): void {
        $valid = true;
        $errors = array();

        if (!Validate::isLoadedObject($cart)) 
        {
            $valid = false;
            $errors[] = "El carrito no es válido.";
        }


        if ($cart->id_customer === 0 && $cart->id_guest === 0)
        {
            $valid = false;
            $errors[] = "Error validando el carrito. Cliente vacío y Guest vacío.";
        }

        if ($cart->id_address_delivery == 0) 
        {
            $valid = false;
            $errors[] = "Error validando el carrito. Dirección de envío vacía.";
        }

        if ($cart->id_address_invoice == 0)
        {
            $valid = false;
            $errors[] = "Error validando el carrito. Dirección de facturación vacía.";
        }        

        $currency = new Currency($cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            $valid = false;
            $errors[] = "Error Cargando la moneda";
        }

        
        if (!$valid)
        {
            $message = "Errores validando carito: ";
            $first = true;
            foreach ($errors as $key => $error) 
            {

               $messaje .= ($first ? "" : "| " ). $error;
               $first = false;
            }
            throw new CartValidationException($message);
        }
    }

    public function process(Payment $payment): Cart 
    {
        $cart = new Cart($payment->getOrder());
        $isValidCart = $this->validateCart($cart);

        if ($isValidCart)
        {
            // update the context
            $address = new Address((int)$cart->id_address_invoice);
            Context::getContext()->country = new Country((int)$address->id_country);
            Context::getContext()->language = new Language((int)$cart->id_lang);
            Context::getContext()->currency = new Currency((int)$cart->id_currency);
        }

        return $cart;
    }
}
