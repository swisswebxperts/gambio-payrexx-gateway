<?php

use Payrexx\PayrexxPaymentGateway\Classes\Service\OrderConfirmation;

class PayrexxCheckoutProcessProcess extends PayrexxCheckoutProcessProcess_parent
{
    public function send_order_mail()
    {
        if (!in_array($_SESSION['payment'] ?? '', ['payrexx_twint', 'payrexx'], true)) {
            return parent::send_order_mail();
        }

        return OrderConfirmation::sendOnce($this->order_id);
    }
}
