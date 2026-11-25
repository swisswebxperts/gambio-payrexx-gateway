<?php

namespace Payrexx\PayrexxPaymentGateway\Classes\Service;

use MainFactory;
use RuntimeException;
use StaticGXCoreLoader;

class OrderConfirmation
{
    public static function sendOnce(int $orderId): bool
    {
        if (SEND_EMAILS !== 'true') {
            return true;
        }
        $db = StaticGXCoreLoader::getDatabaseQueryBuilder();
        $lock = 'payrexx-mail-' . sha1(DB_DATABASE . ':' . $orderId);
        $acquired = $db->query('SELECT GET_LOCK(?, 20) AS acquired', [$lock])->row_array();
        if ((int)$acquired['acquired'] !== 1) {
            throw new RuntimeException("Could not lock order confirmation for order $orderId");
        }

        try {
            $order = $db->select('gm_send_order_status, gm_order_send_date')
                ->get_where('orders', ['orders_id' => $orderId])->row_array();
            if (!$order) {
                throw new RuntimeException("Order $orderId does not exist");
            }
            // Gambio also dates completed dispatch attempts with pending mail. Its resend job owns those messages.
            if ((int)$order['gm_send_order_status'] === 1 || $order['gm_order_send_date'] > '1000-01-01 00:00:00') {
                return true;
            }
            $service = StaticGXCoreLoader::getService('Email');

            // Commit both recipients and the dispatch date before SMTP. Gambio can retry each pending message by ID.
            if (!$db->trans_begin()) {
                throw new RuntimeException('Could not start the order email transaction');
            }
            try {
                $emails = self::prepareEmails($orderId);
                foreach ($emails as $email) {
                    $service->queue($email);
                }
                $db->where('orders_id', $orderId)->update('orders', ['gm_send_order_status' => 0]);
                if (!$db->trans_status() || !$db->trans_commit()) {
                    throw new RuntimeException("Could not queue order confirmation for order $orderId");
                }
            } catch (\Throwable $e) {
                $db->trans_rollback();
                throw $e;
            }

            foreach ($emails as $email) {
                try {
                    $service->send($email);
                } catch (\Exception $e) {
                    \Gambio\Core\Logging\logger('payrexx')->warning("Order $orderId email remains pending", ['exception' => $e]);
                }
            }
            // Gambio's order flag reflects the customer copy, the second message in SendOrderProcess.
            if (!$emails[1]->isPending()) {
                $db->where('orders_id', $orderId)->update('orders', ['gm_send_order_status' => 1]);
            }
            return true;
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function prepareEmails(int $orderId): array
    {
        $db = StaticGXCoreLoader::getDatabaseQueryBuilder();
        $order = $db->select('orders.language, currency, customers_status, payment_method, shipping_class,
                              languages.languages_id, languages.code, languages.language_charset')
            ->join('languages', 'languages.directory = orders.language')
            ->get_where('orders', ['orders_id' => $orderId])->row_array();
        if (!$order) {
            throw new RuntimeException("Could not load order language for order $orderId");
        }

        // SendOrderProcess renders both recipients from the order using the storefront language and price context.
        $session = $_SESSION;
        $price = $GLOBALS['xtPrice'];
        $legacyConnection = $GLOBALS['db_link'];
        try {
            $_SESSION['language'] = $order['language'];
            $_SESSION['languages_id'] = (int)$order['languages_id'];
            $_SESSION['language_code'] = $order['code'];
            $_SESSION['language_charset'] = $order['language_charset'];
            $_SESSION['currency'] = $order['currency'];
            $_SESSION['payment'] = $order['payment_method'];
            $_SESSION['shipping'] = ['id' => $order['shipping_class']];
            unset($_SESSION['credit_covers']);
            $GLOBALS['xtPrice'] = new \xtcPrice($order['currency'], $order['customers_status']);

            $process = MainFactory::create_object('SendOrderProcess');
            $process->set_('order_id', $orderId);
            // gm_save_order() uses the legacy connection. Include its dispatch date in the queue transaction.
            $GLOBALS['db_link'] = $db->conn_id;
            return StaticGXCoreLoader::getService('Email')->preparePayrexxOrderEmails($process);
        } finally {
            $GLOBALS['db_link'] = $legacyConnection;
            $_SESSION = $session;
            $GLOBALS['xtPrice'] = $price;
        }
    }
}
