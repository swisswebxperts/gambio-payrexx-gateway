<?php
/**
 * Class PayrexxWebhookController.
 *
 * Payment gateway for Payrexx AG.
 *
 * PHP version 7,8
 *
 * @category  PaymentModule
 * @package   PayrexxPaymentGateway
 * @author    Payrexx <integration@payrexx.com>
 * @copyright Payrexx AG
 * @license   MIT License
 * @link      https://www.payrexx.com
 */
declare(strict_types=1);

use Payrexx\Models\Response\Transaction;
use Payrexx\PayrexxPaymentGateway\Classes\Service\OrderConfirmation;
use Payrexx\PayrexxPaymentGateway\Classes\Service\OrderService;
use Payrexx\PayrexxPaymentGateway\Classes\Service\PayrexxApiService;

/**
 * Class PayrexxWebhookController.
 *
 * @category PaymentModule
 * @package  PayrexxPaymentGateway
 * @author   Payrexx <integration@payrexx.com>
 * @license  MIT License
 * @link     https://www.payrexx.com
 */
class PayrexxWebhookController extends HttpViewController
{
    /**
     * Payrexx Configuration
     *
     * @var PayrexxStorage
     */
    protected $configuration;

    /**
     * Order service
     *
     * @var OrderService
     */
    protected $orderService;

    /**
     * Payrexx Api Service
     *
     * @var PayrexxApiService
     */
    protected $payrexxApiService;

    /**
     * Constructor
     *
     * @param HttpContextReaderInterface     $httpContextReader     Context Reader
     * @param HttpResponseProcessorInterface $httpResponseProcessor Response Processor
     * @param ContentViewInterface           $defaultContentView    Content view
     */
    public function __construct(
        HttpContextReaderInterface $httpContextReader,
        HttpResponseProcessorInterface $httpResponseProcessor,
        ContentViewInterface $defaultContentView
    ) {
        $this->configuration = MainFactory::create('PayrexxStorage');
        $this->orderService = new OrderService();
        $this->payrexxApiService = new PayrexxApiService();

        parent::__construct($httpContextReader, $httpResponseProcessor, $defaultContentView);
    }

    /**
     * Executes default action
     *
     * @return string
     * @throws Exception
     */
    public function actionDefault()
    {
        try {
            $data = $_POST ?: json_decode(file_get_contents('php://input'), true);
            $transaction = $data['transaction'] ?? [];
            $id = filter_var($transaction['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $orderId = filter_var($transaction['referenceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !$orderId || empty($transaction['status'])) {
                echo 'Incomplete transaction';
                exit();
            }

            $verified = $this->payrexxApiService->getTransactionById($id);
            if (!$verified) {
                throw new RuntimeException('Transaction lookup failed');
            }
            if ((string)$verified->getReferenceId() !== (string)$orderId) {
                echo 'Transaction does not belong to this order';
                exit();
            }

            $status = $verified->getStatus();
            if ($status !== $transaction['status']) {
                echo 'Ignored notification for a different transaction status';
                exit();
            }

            $order = new order($orderId);
            $method = $order->info['payment_method'] ?? '';
            if ($method !== 'payrexx' && strpos($method, 'payrexx_') !== 0) {
                echo 'Order does not use Payrexx';
                exit();
            }

            $invoice = in_array($status, [Transaction::REFUNDED, Transaction::PARTIALLY_REFUNDED], true)
                ? ($verified->getInvoice() ?? []) : [];
            $this->orderService->handleTransactionStatus($orderId, $status, $invoice);
            if ($status === Transaction::CONFIRMED) {
                $twint = $method === 'payrexx_twint'
                    || ($method === 'payrexx' && ($verified->getPayment()['brand'] ?? '') === 'twint');
                if ($twint) {
                    OrderConfirmation::sendOnce($orderId);
                }
            }
            echo 'Success: Webhook processed!';
        } catch (DomainException $e) {
            // Retrying an unsupported or obsolete status cannot advance the order.
            echo 'Ignored transaction status';
        } catch (Throwable $e) {
            http_response_code(500);
            \Gambio\Core\Logging\logger('payrexx')->error('Webhook failed for order ' . ($orderId ?? 0), ['exception' => $e]);
            echo 'Webhook processing failed';
        }
        exit();
    }
}
