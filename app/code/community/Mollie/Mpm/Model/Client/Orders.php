<?php
/**
 * Copyright (c) 2012-2018, Mollie B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @category    Mollie
 * @package     Mollie_Mpm
 * @author      Mollie B.V. (info@mollie.nl)
 * @copyright   Copyright (c) 2012-2018 Mollie B.V. (https://www.mollie.nl)
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD-License 2
 */

class Mollie_Mpm_Model_Client_Orders extends Mage_Payment_Model_Method_Abstract
{

    const CHECKOUT_TYPE = 'order';

    /**
     * @var Mollie_Mpm_Helper_Data
     */
    public $mollieHelper;
    /**
     * @var Mollie_Mpm_Model_OrderLines
     */
    public $orderLines;

    /**
     *
     */
    public function __construct()
    {
        parent::_construct();
        $this->mollieHelper = Mage::helper('mpm');
        $this->orderLines = Mage::getModel('mpm/orderLines');
    }

    /**
     * @param Mage_Sales_Model_Order $order
     *
     * @return null|string
     * @throws Mage_Core_Exception
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function startTransaction(Mage_Sales_Model_Order $order)
    {
        $orderId = $order->getId();
        $storeId = $order->getStoreId();
        $apiKey = $this->mollieHelper->getApiKey($storeId);
        $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
        $additionalData = $order->getPayment()->getAdditionalInformation();

        $transactionId = $order->getMollieTransactionId();
        if (!empty($transactionId) && !preg_match('/^ord_\w+$/', $transactionId)) {
            $payment = $mollieApi->payments->get($transactionId);
            return $payment->getCheckoutUrl();
        }

        $paymentToken = $this->mollieHelper->getPaymentToken();
        $method = $this->mollieHelper->getMethodCode($order);
        $orderData = array(
            'amount'         => $this->mollieHelper->getOrderAmountByOrder($order),
            'orderNumber'    => $order->getIncrementId(),
            'billingAddress' => $this->getAddressLine($order->getBillingAddress(), $order->getCustomerEmail()),
            'lines'          => $this->orderLines->getOrderLines($order),
            'redirectUrl'    => $this->mollieHelper->getReturnUrl($orderId, $paymentToken, $storeId),
            'webhookUrl'     => $this->mollieHelper->getWebhookUrl($storeId),
            'locale'         => $this->mollieHelper->getLocaleCode($storeId, self::CHECKOUT_TYPE),
            'method'         => $method,
            'metadata'       => array(
                'order_id'      => $orderId,
                'store_id'      => $order->getStoreId(),
                'payment_token' => $paymentToken
            ),
        );

        if (!$order->getIsVirtual() && $order->hasData('shipping_address_id')) {
            $orderData['shippingAddress'] = $this->getAddressLine($order->getShippingAddress(), $order->getCustomerEmail());
        }

        if (isset($additionalData['selected_issuer'])) {
            $orderData['payment']['issuer'] = $additionalData['selected_issuer'];
        }

        if ($method == 'banktransfer') {
            $orderData['payment']['dueDate'] = $this->mollieHelper->getBanktransferDueDate($storeId);
        }

        if (isset($additionalData['limited_methods'])) {
            $orderData['method'] = $additionalData['limited_methods'];
        }

        $orderData = $this->mollieHelper->validateOrderData($orderData);
        $this->mollieHelper->addTolog('request', $orderData);

        $mollieOrder = $mollieApi->orders->create($orderData);
        $this->processResponse($order, $mollieOrder);

        return $mollieOrder->getCheckoutUrl();
    }

    /**
     * @param Mage_Sales_Model_Order_Address $address
     * @param null                           $customerEmail
     *
     * @return array
     */
    public function getAddressLine(Mage_Sales_Model_Order_Address $address, $customerEmail = null)
    {
        return array(
            'organizationName' => $address->getCompany(),
            'title'            => $address->getPrefix(),
            'givenName'        => $address->getFirstname(),
            'familyName'       => $address->getLastname(),
            'email'            => $customerEmail,
            'streetAndNumber'  => rtrim(implode(' ', $address->getStreet()), ' '),
            'postalCode'       => $address->getPostcode(),
            'city'             => $address->getCity(),
            'region'           => $address->getRegion(),
            'country'          => $address->getCountryId(),
        );
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param                        $mollieOrder
     *
     * @throws Mage_Core_Exception
     */
    public function processResponse(Mage_Sales_Model_Order $order, $mollieOrder)
    {
        $this->mollieHelper->addTolog('response', $mollieOrder);
        $order->getPayment()->setAdditionalInformation('checkout_url', $mollieOrder->getCheckoutUrl());
        $order->getPayment()->setAdditionalInformation('checkout_type', self::CHECKOUT_TYPE);
        $order->getPayment()->setAdditionalInformation('payment_status', $mollieOrder->status);
        if (isset($mollieOrder->expiresAt)) {
            $order->getPayment()->setAdditionalInformation('expires_at', $mollieOrder->expiresAt);
        }

        $this->orderLines->linkOrderLines($mollieOrder->lines, $order);

        $status = $this->mollieHelper->getStatusPending($order->getStoreId());

        $msg = $this->mollieHelper->__('Customer redirected to Mollie');
        if ($order->getPayment()->getMethodInstance()->getCode() == 'mollie_method_paymentlink') {
            $msg = $this->mollieHelper->__('Created Mollie Checkout Url');
        }

        $order->addStatusToHistory($status, $msg, false);
        $order->setMollieTransactionId($mollieOrder->id)->save();
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string                 $type
     * @param null                   $paymentToken
     *
     * @return array
     * @throws Exception
     * @throws Mage_Core_Exception
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function processTransaction(Mage_Sales_Model_Order $order, $type = 'webhook', $paymentToken = null)
    {
        $orderId = $order->getId();
        $storeId = $order->getStoreId();
        $apiKey = $this->mollieHelper->getApiKey($storeId);
        $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
        $transactionId = $order->getMollieTransactionId();
        $mollieOrder = $mollieApi->orders->get($transactionId, array("embed" => "payments"));
        $this->mollieHelper->addTolog($type, $mollieOrder);
        $status = $mollieOrder->status;

        $this->orderLines->updateOrderLinesByWebhook($mollieOrder->lines, $mollieOrder->isPaid());

        /**
         * Check if last payment was canceled, failed or expired and redirect customer to cart for retry.
         */
        $lastPayment = isset($mollieOrder->_embedded->payments) ? end($mollieOrder->_embedded->payments) : null;
        $lastPaymentStatus = isset($lastPayment) ? $lastPayment->status : null;
        if ($lastPaymentStatus == 'canceled' || $lastPaymentStatus == 'failed' || $lastPaymentStatus == 'expired') {
            $order->getPayment()->setAdditionalInformation('payment_status', $lastPaymentStatus)->save();
            $this->mollieHelper->registerCancellation($order, $status);
            $msg = array('success' => false, 'status' => $lastPaymentStatus, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }

        $order->getPayment()->setAdditionalInformation('payment_status', $status)->save();

        if ($mollieOrder->isPaid() || $mollieOrder->isAuthorized()) {
            $amount = $mollieOrder->amount->value;
            $currency = $mollieOrder->amount->currency;
            $orderAmount = $this->mollieHelper->getOrderAmountByOrder($order);

            if ($currency != $orderAmount['currency']) {
                $msg = array('success' => false, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type);
                $this->mollieHelper->addTolog('error', $this->mollieHelper->__('Currency does not match.'));
                return $msg;
            }

            $payment = $order->getPayment();

            if (!$payment->getIsTransactionClosed() && $type == 'webhook') {
                if ($order->isCanceled()) {
                    $order = $this->mollieHelper->uncancelOrder($order);
                }

                if (abs($amount - $orderAmount['value']) < 0.01) {
                    $payment->setTransactionId($transactionId);
                    $payment->setCurrencyCode($order->getBaseCurrencyCode());

                    if ($mollieOrder->isPaid()) {
                        $payment->setIsTransactionClosed(true);
                        $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);
                    }

                    if ($mollieOrder->isAuthorized()) {
                        $payment->setIsTransactionClosed(false);
                        $payment->registerAuthorizationNotification($order->getBaseGrandTotal(), true);

                        /**
                         * Create pending invoice, as order has not been paid.
                         */
                        /** @var Mage_Sales_Model_Service_Order $service */
                        $invoice = $order->prepareInvoice();
                        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::NOT_CAPTURE);
                        $invoice->setTransactionId($transactionId);
                        $invoice->register();

                        Mage::getModel('core/resource_transaction')
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder())
                            ->save();
                    }

                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING)->save();

                    if ($mollieOrder->amountCaptured !== null) {
                        if ($mollieOrder->amount->currency != $mollieOrder->amountCaptured->currency) {
                            $message = $this->mollieHelper->__(
                                'Mollie: Order Amount %s, Captures Amount %s',
                                $mollieOrder->amount->currency . ' ' . $mollieOrder->amount->value,
                                $mollieOrder->amountCaptured->currency . ' ' . $mollieOrder->amountCaptured->value
                            );
                            $order->addStatusHistoryComment($message)->save();
                        }
                    }
                }

                /** @var Mage_Sales_Model_Order_Invoice $invoice */
                $invoice = $payment->getCreatedInvoice();
                $sendInvoice = $this->mollieHelper->sendInvoice($storeId);

                if (!$order->getEmailSent()) {
                    $order->sendNewOrderEmail()->setEmailSent(true)->save();
                }

                if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
                    $invoice->setEmailSent(true)->sendEmail()->save();
                }

                if (!$order->getIsVirtual()) {
                    $defaultStatusProcessing = $this->mollieHelper->getStatusProcessing($storeId);
                    if ($defaultStatusProcessing && ($defaultStatusProcessing != $order->getStatus())) {
                        $order->setStatus($defaultStatusProcessing)->save();
                    }
                }
            }

            $msg = array('success' => true, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            $this->checkCheckoutSession($order, $paymentToken, $mollieOrder, $type);
            return $msg;
        }

        if ($mollieOrder->isRefunded()) {
            $msg = array('success' => true, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }

        if ($mollieOrder->isCreated()) {
            if ($mollieOrder->method == 'banktransfer' && !$order->getEmailSent()) {
                $order->sendNewOrderEmail()->setEmailSent(true)->save();
                $message = $this->mollieHelper->__('New order email sent');
                if (!$statusPending = $this->mollieHelper->getStatusPendingBanktransfer($storeId)) {
                    $statusPending = $order->getStatus();
                }

                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
                $order->addStatusToHistory($statusPending, $message, true);
                $order->save();
            }

            $msg = array('success' => true, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            $this->checkCheckoutSession($order, $paymentToken, $mollieOrder, $type);
            return $msg;
        }

        if ($mollieOrder->isCanceled() || $mollieOrder->isExpired()) {
            if ($type == 'webhook') {
                $this->mollieHelper->registerCancellation($order, $status);
            }

            $msg = array('success' => false, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }

        if ($mollieOrder->isCompleted()) {
            $msg = array('success' => true, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }

        $msg = array('success' => false, 'status' => $status, 'order_id' => $orderId, 'type' => $type);
        $this->mollieHelper->addTolog('success', $msg);
        return $msg;
    }

    /**
     * Check if there is an active checkout session and if not, create this based on the payment data.
     * Validates the PaymentToken of the return url with the meta data PaymentToken.
     * Issue #72: https://github.com/mollie/Magento/issues/72
     *
     * @param Mage_Sales_Model_Order $order
     * @param                        $paymentToken
     * @param                        $paymentData
     * @param                        $type
     */
    public function checkCheckoutSession($order, $paymentToken, $paymentData, $type)
    {
        if ($type == 'webhook') {
            return;
        }

        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('checkout/session');
        if ($type != 'webhook' && ($session->getLastOrderId() != $order->getId())) {
            if ($paymentToken && isset($paymentData->metadata->payment_token)) {
                if ($paymentToken == $paymentData->metadata->payment_token) {
                    $session->setLastQuoteId($order->getQuoteId())
                        ->setLastSuccessQuoteId($order->getQuoteId())
                        ->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId());
                }
            }
        }
    }

    /**
     * @param Mage_Sales_Model_Order $order
     *
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function cancelOrder(Mage_Sales_Model_Order $order)
    {
        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Transaction ID not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $apiKey = $this->mollieHelper->getApiKey($order->getStoreId());
        if (empty($apiKey)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Api key not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        try {
            $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
            $mollieApi->orders->cancel($transactionId);
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
            Mage::throwException($this->mollieHelper->__('Mollie: %s', $e->getMessage()));
        }

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Shipment $shipment
     * @param Mage_Sales_Model_Order          $order
     *
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function createShipment(Mage_Sales_Model_Order_Shipment $shipment, Mage_Sales_Model_Order $order)
    {
        $shipAll = false;
        $orderId = $order->getId();

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Transaction ID not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $mollieShipmentId = $shipment->getMollieShipmentId();
        if ($mollieShipmentId !== null) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Shipment already pushed to Mollie'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $apiKey = $this->mollieHelper->getApiKey($order->getStoreId());
        if (empty($apiKey)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Api key not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        /**
         * If products ordered qty equals shipping qty,
         * complete order can be shipped incl. shipping & discount itemLines.
         */
        if ((int)$order->getTotalQtyOrdered() == (int)$shipment->getTotalQty()) {
            $shipAll = true;
        }

        /**
         * If shipping qty equals open physical products count,
         * all remaining lines can be shipped, incl. shipping & discount itemLines.
         */
        $openForShipmentQty = $this->orderLines->getOpenForShipmentQty($orderId);
        if ((int)$shipment->getTotalQty() == (int)$openForShipmentQty) {
            $shipAll = true;
        }

        try {
            $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
            $mollieOrder = $mollieApi->orders->get($transactionId);
            if ($shipAll) {
                $mollieShipment = $mollieOrder->shipAll();
            } else {
                $orderLines = $this->orderLines->getShipmentOrderLines($shipment);
                $mollieShipment = $mollieOrder->createShipment($orderLines);
            }

            $mollieShipmentId = isset($mollieShipment) ? $mollieShipment->id : 0;
            $shipment->setMollieShipmentId($mollieShipmentId);

            /**
             * Check if Transactions needs to be captures (eg. Klarna methods)
             */
            $payment = $order->getPayment();
            /** @var Mage_Sales_Model_Order_Invoice $invoice */
            $invoice = $order->getInvoiceCollection()->getLastItem();
            if ($invoice && $invoice->getState() == 1) {
                $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);
                $order->save();
                $sendInvoice = $this->mollieHelper->sendInvoice($order->getStoreId());
                if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
                    $invoice->setEmailSent(true)->sendEmail()->save();
                }
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
            Mage::throwException($this->mollieHelper->__('Mollie API: %s', $e->getMessage()));
        }

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Shipment       $shipment
     * @param Mage_Sales_Model_Order_Shipment_Track $track
     * @param Mage_Sales_Model_Order                $order
     *
     * @return $this
     */
    public function updateShipmentTrack(Mage_Sales_Model_Order_Shipment $shipment, Mage_Sales_Model_Order_Shipment_Track $track, Mage_Sales_Model_Order $order)
    {
        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Transaction ID not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $shipmentId = $shipment->getMollieShipmentId();
        if (empty($shipmentId)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Shipment ID not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $apiKey = $this->mollieHelper->getApiKey($order->getStoreId());
        if (empty($apiKey)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Api key not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        try {
            $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
            $mollieOrder = $mollieApi->orders->get($transactionId);
            if ($mollieShipment = $mollieOrder->getShipment($shipmentId)) {
                $this->mollieHelper->addTolog(
                    'tracking',
                    sprintf('Added %s shipping for %s', $track->getTitle(), $transactionId)
                );
                $mollieShipment->tracking = array(
                    'carrier' => $track->getTitle(),
                    'code'    => $track->getTrackNumber()
                );
                $mollieShipment->update();
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
        }

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Creditmemo $creditmemo
     * @param Mage_Sales_Model_Order            $order
     *
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function createOrderRefund(Mage_Sales_Model_Order_Creditmemo $creditmemo, Mage_Sales_Model_Order $order)
    {
        $refundAll = false;
        $storeId = $order->getStoreId();
        $orderId = $order->getId();

        /**
         * Skip the creation of an online refund if an offline refund is used
         * and add a message to the core/sessions about this workflow.
         * Registry set at the Mollie_Mpm_Model_Mollie::refund and is set once an online refund is used.
         */
        if (!Mage::registry('online_refund')) {
            Mage::getSingleton('core/session')->addNotice(
                $this->mollieHelper->__(
                    'An offline refund has been created, please make sure to also create this 
                    refund on mollie.com/dashboard or use the online refund option.'
                )
            );
            return $this;
        }

        $methodCode = $this->mollieHelper->getMethodCode($order);
        if (!$order->hasShipments() && ($methodCode == 'klarnapaylater' || $methodCode == 'klarnasliceit')) {
            $msg = $this->mollieHelper->__(
                'Order can only be refunded after Klara has been captured (after shipment)'
            );
            Mage::throwException($msg);
        }

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Transaction ID not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        $apiKey = $this->mollieHelper->getApiKey($storeId);
        if (empty($apiKey)) {
            $msg = array('error' => true, 'msg' => $this->mollieHelper->__('Api key not found'));
            $this->mollieHelper->addTolog('error', $msg);
            return $this;
        }

        /**
         * Check for creditmemo adjusment fee's, positive and negative.
         * Throw exception if these are set, as this is not supportef by the orders api.
         */
        if ($creditmemo->getAdjustmentPositive() > 0 || $creditmemo->getAdjustmentNegative() > 0) {
            $msg = $this->mollieHelper->__(
                'Creating an online refund with adjustment fee\'s is not supported by Mollie'
            );
            $this->mollieHelper->addTolog('error', $msg);
            Mage::throwException($msg);
        }

        /**
         * Check if Shipping Fee needs to be refunded.
         * Throws exception if Shipping Amount of credit does not match Shipping Fee of paid orderLine.
         */
        $addShippingToRefund = null;
        $shippingCostsLine = $this->orderLines->getShippingFeeItemLineOrder($orderId);
        if ($shippingCostsLine->getId() && $shippingCostsLine->getQtyRefunded() == 0) {
            if ($creditmemo->getShippingAmount() > 0) {
                $addShippingToRefund = true;
                if (abs($creditmemo->getShippingInclTax() - $shippingCostsLine->getTotalAmount()) > 0.01) {
                    $msg = $this->mollieHelper->__('Can not create online refund, as shipping costs do not match');
                    $this->mollieHelper->addTolog('error', $msg);
                    Mage::throwException($msg);
                }
            }
        }

        /**
         * If products ordered qty equals refunded qty,
         * complete order can be shipped incl. shipping & discount itemLines.
         */
        if ((int)$order->getTotalQtyOrdered() == (int)$creditmemo->getTotalQty()) {
            $refundAll = true;
        }

        /**
         * If refunded qty equals total paid physical products count,
         * all remaining lines can be refunded, incl. shipping & discount itemLines.
         */
        $openForRefundQty = $this->orderLines->getOpenForRefundQty($orderId);
        if ((int)$creditmemo->getTotalQty() == (int)$openForRefundQty) {
            $refundAll = true;
        }

        try {
            $mollieApi = $this->mollieHelper->getMollieAPI($apiKey);
            $mollieOrder = $mollieApi->orders->get($transactionId);
            if ($refundAll) {
                $mollieOrder->refundAll();
            } else {
                $orderLines = $this->orderLines->getCreditmemoOrderLines($creditmemo, $addShippingToRefund);
                $mollieOrder->refund($orderLines);
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', $e->getMessage());
            Mage::throwException($this->mollieHelper->__('Mollie API: %s', $e->getMessage()));
        }

        return $this;
    }
}