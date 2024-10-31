<?php

use Pagantis\OrdersApiClient\Client;
use Pagantis\ModuleUtils\Exception\ConcurrencyException;
use Pagantis\ModuleUtils\Exception\AlreadyProcessedException;
use Pagantis\ModuleUtils\Exception\AmountMismatchException;
use Pagantis\ModuleUtils\Exception\MerchantOrderNotFoundException;
use Pagantis\ModuleUtils\Exception\NoIdentificationException;
use Pagantis\ModuleUtils\Exception\OrderNotFoundException;
use Pagantis\ModuleUtils\Exception\QuoteNotFoundException;
use Pagantis\ModuleUtils\Exception\UnknownException;
use Pagantis\ModuleUtils\Exception\WrongStatusException;
use Pagantis\ModuleUtils\Model\Response\JsonSuccessResponse;
use Pagantis\ModuleUtils\Model\Response\JsonExceptionResponse;
use Pagantis\ModuleUtils\Model\Log\LogEntry;
use Pagantis\OrdersApiClient\Model\Order;

if (!defined('ABSPATH')) {
    exit;
}

class WcPaylaterNotify extends WcPaylaterGateway
{
    /** Concurrency tablename  */
    const CONCURRENCY_TABLE = 'pmt_concurrency';

    /** Seconds to expire a locked request */
    const CONCURRENCY_TIMEOUT = 5;

    /** @var mixed $pmtOrder */
    protected $pmtOrder;

    /** @var $string $origin */
    public $origin;

    /** @var $string */
    public $order;

    /** @var mixed $woocommerceOrderId */
    protected $woocommerceOrderId = '';

    /** @var mixed $cfg */
    protected $cfg;

    /** @var Client $orderClient */
    protected $orderClient;

    /** @var  WC_Order $woocommerceOrder */
    protected $woocommerceOrder;

    /** @var mixed $pmtOrderId */
    protected $pmtOrderId = '';

    /**
     * @return JsonExceptionResponse|JsonSuccessResponse
     * @throws ConcurrencyException
     */
    public function processInformation()
    {
        try {
            require_once(__ROOT__.'/vendor/autoload.php');
            try {
                $this->checkConcurrency();
                $this->getMerchantOrder();
                $this->getPmtOrderId();
                $this->getPmtOrder();
                $checkAlreadyProcessed = $this->checkOrderStatus();
                if ($checkAlreadyProcessed) {
                    return $this->buildResponse();
                }
                $this->validateAmount();
                if ($this->checkMerchantOrderStatus()) {
                    $this->processMerchantOrder();
                }
            } catch (\Exception $exception) {
                $this->insertLog($exception);
                return $this->buildResponse($exception);
            }
            try {
                $this->confirmPmtOrder();
                return $this->buildResponse();
            } catch (\Exception $exception) {
                $this->rollbackMerchantOrder();
                $this->insertLog($exception);
                return $this->buildResponse($exception);
            }
        } catch (\Exception $exception) {
            $this->insertLog($exception);
            return $this->buildResponse($exception);
        }
    }

    /**
     * COMMON FUNCTIONS
     */

    /**
     * @throws ConcurrencyException
     * @throws QuoteNotFoundException
     */
    private function checkConcurrency()
    {
        $this->woocommerceOrderId = $_GET['order-received'];
        if ($this->woocommerceOrderId == '') {
            throw new QuoteNotFoundException();
        }

        $this->unblockConcurrency();
        $this->blockConcurrency($this->woocommerceOrderId);
    }

    /**
     * @throws MerchantOrderNotFoundException
     */
    private function getMerchantOrder()
    {
        try {
            $this->woocommerceOrder = new WC_Order($this->woocommerceOrderId);
        } catch (\Exception $e) {
            throw new MerchantOrderNotFoundException();
        }
    }

    /**
     * @throws NoIdentificationException
     */
    private function getPmtOrderId()
    {
        global $wpdb;
        $this->checkDbTable();
        $tableName = $wpdb->prefix.self::ORDERS_TABLE;
        $queryResult = $wpdb->get_row("select order_id from $tableName where id='".$this->woocommerceOrderId."'");
        $this->pmtOrderId = $queryResult->order_id;

        if ($this->pmtOrderId == '') {
            throw new NoIdentificationException();
        }
    }

    /**
     * @throws OrderNotFoundException
     */
    private function getPmtOrder()
    {
        try {
            $this->cfg = get_option('woocommerce_paylater_settings');
            $this->orderClient = new Client($this->cfg['pmt_public_key'], $this->cfg['pmt_private_key']);
            $this->pmtOrder = $this->orderClient->getOrder($this->pmtOrderId);
        } catch (\Exception $e) {
            throw new OrderNotFoundException();
        }
    }

    /**
     * @throws WrongStatusException
     */
    private function checkOrderStatus()
    {
        try {
            $this->checkPmtStatus(array('AUTHORIZED'));
        } catch (\Exception $e) {
            if ($this->pmtOrder instanceof Order) {
                $status = $this->pmtOrder->getStatus();
            } else {
                $status = '-';
            }

            if ($status === Order::STATUS_CONFIRMED) {
                return true;
            }
            throw new WrongStatusException($status);
        }
    }

    /**
     * @return bool
     */
    private function checkMerchantOrderStatus()
    {
        //Order status reference => https://docs.woocommerce.com/document/managing-orders/
        $validStatus   = array('on-hold', 'pending', 'failed', 'processing', 'completed');
        $isValidStatus = apply_filters(
            'woocommerce_valid_order_statuses_for_payment_complete',
            $validStatus,
            $this
        );

        if (!$this->woocommerceOrder->has_status($isValidStatus)) { // TO CONFIRM
            $logMessage = "WARNING checkMerchantOrderStatus." .
                          " Merchant order id:".$this->woocommerceOrder->get_id().
                          " Merchant order status:".$this->woocommerceOrder->get_status().
                          " Pmt order id:".$this->pmtOrder->getStatus().
                          " Pmt order status:".$this->pmtOrder->getId();

            $this->insertLog(null, $logMessage);
            $this->woocommerceOrder->add_order_note($logMessage);
            $this->woocommerceOrder->save();
            return false;
        }

        return true; //TO SAVE
    }

    /**
     * @throws AmountMismatchException
     */
    private function validateAmount()
    {
        $pmtAmount = $this->pmtOrder->getShoppingCart()->getTotalAmount();
        $wcAmount = (string) floor(100 * $this->woocommerceOrder->get_total());
        if ($pmtAmount != $wcAmount) {
            throw new AmountMismatchException($pmtAmount, $wcAmount);
        }
    }

    /**
     * @throws Exception
     */
    private function processMerchantOrder()
    {
        $this->saveOrder();
        $this->updateBdInfo();
    }

    /**
     * @return false|string
     * @throws UnknownException
     * @throws \Httpful\Exception\ConnectionErrorException
     * @throws \Pagantis\OrdersApiClient\Exception\ClientException
     * @throws \Pagantis\OrdersApiClient\Exception\HttpException
     */
    private function confirmPmtOrder()
    {
        try {
            $this->pmtOrder = $this->orderClient->confirmOrder($this->pmtOrderId);
        } catch (\Exception $e) {
            $this->pmtOrder = $this->orderClient->getOrder($this->pmtOrderId);
            if ($this->pmtOrder->getStatus() !== Order::STATUS_CONFIRMED) {
                throw new UnknownException($e->getMessage());
            } else {
                $logMessage = 'Concurrency issue: Order_id '.$this->pmtOrderId.' was confirmed by other process';
                $this->insertLog(null, $logMessage);
            }
        }

        $jsonResponse = new JsonSuccessResponse();
        return $jsonResponse->toJson();
    }

    /**
     * UTILS FUNCTIONS
     */
    /** STEP 1 CC - Check concurrency */
    /**
     * Check if orders table exists
     */
    private function checkDbTable()
    {
        global $wpdb;
        $tableName = $wpdb->prefix.self::ORDERS_TABLE;

        if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql             = "CREATE TABLE $tableName (id int, order_id varchar(50), wc_order_id varchar(50), 
                  UNIQUE KEY id (id)) $charset_collate";

            require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Check if logs table exists
     */
    private function checkDbLogTable()
    {
        global $wpdb;
        $tableName = $wpdb->prefix.self::LOGS_TABLE;

        if ($wpdb->get_var("SHOW TABLES LIKE '$tableName'") != $tableName) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $tableName ( id int NOT NULL AUTO_INCREMENT, log text NOT NULL, 
                    createdAt timestamp DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY id (id)) $charset_collate";

            require_once(ABSPATH.'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        return;
    }

    /** STEP 2 GMO - Get Merchant Order */
    /** STEP 3 GPOI - Get Pmt OrderId */
    /** STEP 4 GPO - Get Pmt Order */
    /** STEP 5 COS - Check Order Status */
    /**
     * @param $statusArray
     *
     * @throws \Exception
     */
    private function checkPmtStatus($statusArray)
    {
        $pmtStatus = array();
        foreach ($statusArray as $status) {
            $pmtStatus[] = constant("Pagantis\OrdersApiClient\Model\Order::STATUS_$status");
        }

        if ($this->pmtOrder instanceof Order) {
            $payed = in_array($this->pmtOrder->getStatus(), $pmtStatus);
            if (!$payed) {
                if ($this->pmtOrder instanceof Order) {
                    $status = $this->pmtOrder->getStatus();
                } else {
                    $status = '-';
                }
                throw new WrongStatusException($status);
            }
        } else {
            throw new OrderNotFoundException();
        }
    }

    /** STEP 6 CMOS - Check Merchant Order Status */
    /** STEP 7 VA - Validate Amount */
    /** STEP 8 PMO - Process Merchant Order */
    /**
     * @throws \Exception
     */
    private function saveOrder()
    {
        global $woocommerce;
        $paymentResult = $this->woocommerceOrder->payment_complete();
        if ($paymentResult) {
            $this->woocommerceOrder->add_order_note($this->origin);
            $this->woocommerceOrder->reduce_order_stock();
            $this->woocommerceOrder->save();

            $woocommerce->cart->empty_cart();
            sleep(3);
        } else {
            throw new UnknownException('Order can not be saved');
        }
    }

    /**
     * Save the merchant order_id with the related identification
     */
    private function updateBdInfo()
    {
        global $wpdb;

        $this->checkDbTable();
        $tableName = $wpdb->prefix.self::ORDERS_TABLE;

        $wpdb->update(
            $tableName,
            array('wc_order_id'=>$this->woocommerceOrderId),
            array('id' => $this->woocommerceOrderId),
            array('%s'),
            array('%d')
        );
    }

    /** STEP 9 CPO - Confirmation Pmt Order */
    private function rollbackMerchantOrder()
    {
        $this->woocommerceOrder->update_status('pending', __('Pending payment', 'woocommerce'));
    }

    /**
     * @param null $exception
     * @param null $message
     */
    private function insertLog($exception = null, $message = null)
    {
        global $wpdb;

        $this->checkDbLogTable();
        $logEntry     = new LogEntry();
        if ($exception instanceof \Exception) {
            $logEntry = $logEntry->error($exception);
        } else {
            $logEntry = $logEntry->info($message);
        }

        $tableName = $wpdb->prefix.self::LOGS_TABLE;
        $wpdb->insert($tableName, array('log' => $logEntry->toJson()));
    }

    /**
     * @param null $orderId
     *
     * @return bool
     * @throws ConcurrencyException
     */
    private function unblockConcurrency($orderId = null)
    {
        global $wpdb;
        $tableName = $wpdb->prefix.self::CONCURRENCY_TABLE;
        if ($orderId == null) {
            $query = "DELETE FROM $tableName WHERE createdAt<(NOW()- INTERVAL ".self::CONCURRENCY_TIMEOUT." SECOND)";
        } else {
            $query = "DELETE FROM $tableName WHERE order_id = $orderId";
        }
        $resultDelete = $wpdb->query($query);
        if ($resultDelete === false) {
            throw new ConcurrencyException();
        }
    }

    /**
     * @throws ConcurrencyException
     */
    private function blockConcurrency($orderId)
    {
        global $wpdb;
        $tableName = $wpdb->prefix.self::CONCURRENCY_TABLE;
        $insertResult = $wpdb->insert($tableName, array('order_id' => $orderId));
        if ($insertResult === false) {
            if ($this->getOrigin() == 'Notify') {
                throw new ConcurrencyException();
            } else {
                $query = sprintf(
                    "SELECT TIMESTAMPDIFF(SECOND,NOW()-INTERVAL %s SECOND, createdAt) as rest FROM %s WHERE %s",
                    self::CONCURRENCY_TIMEOUT,
                    $tableName,
                    "order_id=$orderId"
                );
                $resultSeconds = $wpdb->get_row($query);
                $restSeconds = isset($resultSeconds) ? ($resultSeconds->rest) : 0;
                $secondsToExpire = ($restSeconds>self::CONCURRENCY_TIMEOUT) ? self::CONCURRENCY_TIMEOUT : $restSeconds;
                sleep($secondsToExpire+1);

                $logMessage = sprintf(
                    "User waiting %s seconds, default seconds %s, bd time to expire %s seconds",
                    $secondsToExpire,
                    self::CONCURRENCY_TIMEOUT,
                    $restSeconds
                );
                $this->insertLog(null, $logMessage);

            }
        }
    }

    /**
     * @param null $exception
     *
     *
     * @return JsonExceptionResponse|JsonSuccessResponse
     * @throws ConcurrencyException
     */
    private function buildResponse($exception = null)
    {
        $this->unblockConcurrency($this->woocommerceOrderId);

        if ($exception == null) {
            $jsonResponse = new JsonSuccessResponse();
        } else {
            $jsonResponse = new JsonExceptionResponse();
            $jsonResponse->setException($exception);
        }

        $jsonResponse->setMerchantOrderId($this->woocommerceOrderId);
        $jsonResponse->setPagantisOrderId($this->pmtOrderId);

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $jsonResponse->printResponse();
        } else {
            return $jsonResponse;
        }
    }

    /**
     * GETTERS & SETTERS
     */

    /**
     * @return mixed
     */
    public function getOrigin()
    {
        return $this->origin;
    }

    /**
     * @param mixed $origin
     */
    public function setOrigin($origin)
    {
        $this->origin = $origin;
    }
}
