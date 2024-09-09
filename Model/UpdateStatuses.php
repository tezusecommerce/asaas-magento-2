<?php

namespace Asaas\Magento2\Model;

use Asaas\Magento2\Helper\Data;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;

class UpdateStatuses implements \Asaas\Magento2\Api\UpdateStatusesInterface
{
  protected $orderFactory;
  protected $orderRepository;
  protected $helperData;
  
  public function __construct(
    OrderRepositoryInterface $orderRepository,
    OrderFactory             $orderFactory,
    Data                     $helper
  ) {
    $this->orderFactory    = $orderFactory;
    $this->orderRepository = $orderRepository;
    $this->helperData      = $helper;
  }

  /**
   * @param  mixed $event
   * @param  mixed $payment
   * @return  false|string
   * @api
   */
  public function doUpdate($event, $payment)
  {
    $token_magento = $this->helperData->getTokenWebhook();
    $asaas_token = apache_request_headers();

    try {
      if ((isset($asaas_token['Asaas-Access-Token']) && isset($token_magento))) {
        if ($token_magento !== $asaas_token['Asaas-Access-Token']) {
          throw new \Magento\Framework\Webapi\Exception(
            __("Token Webhook not valid."),
            0,
            \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED);
        }
        $this->updateOrder($event, $payment);
      }
      else if ((!isset($asaas_token['Asaas-Access-Token']) && !isset($token_magento))) {
        $this->updateOrder($event, $payment);
      }
      else {
        throw new \Magento\Framework\Webapi\Exception(
          __("Token Webhook not valid."),
          0,
          \Magento\Framework\Webapi\Exception::HTTP_UNAUTHORIZED);
      }
      $response = ['request' => 'successful', 'dataRequest' => ['externalReference' => $payment['externalReference']]];
    } catch (\Exception $e) {
      $response = ['request' => ['error' => ['error' => true, 'message' => $e->getMessage()]]];
    }
    
    return json_encode($response);
  }

  /**
   * @param $event
   * @param $payment
   * @return \Magento\Sales\Model\Order
   * @throws \Magento\Framework\Webapi\Exception
   */
  private function updateOrder($event, $payment)
  {
    try {
      $paymentObj = (array)$payment;
      $order = $this->orderFactory->create()->loadByIncrementId($paymentObj['externalReference']);
      $orderId = $order->getId();

      if (!$orderId) {
        throw new \Magento\Framework\Webapi\Exception(
          __("Order Id not found"),
          0,
          \Magento\Framework\Webapi\Exception::HTTP_NOT_FOUND);
      }

      switch ($event) {
        case "PAYMENT_CONFIRMED":
        case "PAYMENT_RECEIVED":
          $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
          $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
          break;

        case "PAYMENT_OVERDUE":
        case "PAYMENT_DELETED":
        case "PAYMENT_RESTORED":
        case "PAYMENT_REFUNDED":
        case "PAYMENT_AWAITING_CHARGEBACK_REVERSAL":
          $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
          $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
          break;
      }

      $this->orderRepository->save($order);

      return $order;
    } catch (\Exception $e) {
      throw new \Magento\Framework\Webapi\Exception(__($e->getMessage()));
    }
  }
}
