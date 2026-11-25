<?php

class PayrexxOrderEmailService extends PayrexxOrderEmailService_parent
{
    // xtc_php_mail() obtains a fresh EmailService for each recipient.
    private static $orderEmails;

    public function preparePayrexxOrderEmails(SendOrderProcess $process): array
    {
        if (self::$orderEmails !== null) {
            throw new LogicException('Payrexx order email preparation is already active');
        }

        self::$orderEmails = [];
        try {
            $process->proceed();
            if (count(self::$orderEmails) !== 2) {
                throw new RuntimeException('Could not prepare both Payrexx order confirmations');
            }
            return self::$orderEmails;
        } finally {
            self::$orderEmails = null;
        }
    }

    public function send(EmailInterface $email)
    {
        if (self::$orderEmails === null) {
            return parent::send($email);
        }
        self::$orderEmails[] = $email;
    }
}
