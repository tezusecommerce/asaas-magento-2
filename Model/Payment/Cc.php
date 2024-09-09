<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Asaas\Magento2\Model\Payment;

use Magento\Sales\Model\Order;
use Magento\TestFramework\ObjectManager;

class Cc extends \Magento\Payment\Model\Method\AbstractMethod {

  protected $_code = "cc";

  protected $_isGateway                   = true;
  protected $_canCapture                  = true;
  protected $_canCapturePartial           = true;
  protected $_canRefund                   = true;
  protected $_canRefundInvoicePartial     = true;

  /** @var \Magento\Framework\Message\ManagerInterface */
  protected $messageManager;

  public function __construct(
    \Magento\Framework\Model\Context $context,
    \Magento\Framework\Registry $registry,
    \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
    \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
    \Magento\Payment\Helper\Data $paymentData,
    \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
    \Magento\Payment\Model\Method\Logger $logger,
    \Asaas\Magento2\Helper\Data $helper,
    \Magento\Checkout\Model\Session $checkout,
    \Magento\Framework\Message\ManagerInterface $messageManager,
    \Magento\Framework\Encryption\EncryptorInterface $encryptor,
    \Magento\Customer\Model\Customer $customerRepositoryInterface,
    \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
    \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
    array $data = []
  ) {
    parent::__construct(
      $context,
      $registry,
      $extensionFactory,
      $customAttributeFactory,
      $paymentData,
      $scopeConfig,
      $logger,
      $resource,
      $resourceCollection,
      $data
    );
    $this->helperData = $helper;
    $this->checkoutSession = $checkout;
    $this->messageManager = $messageManager;
    $this->_decrypt = $encryptor;
    $this->_customerRepositoryInterface = $customerRepositoryInterface;
  }

  public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null) {
    if (!$this->helperData->getStatusCc()) {
      return false;
    }
    return parent::isAvailable($quote);
  }

  public function order(\Magento\Payment\Model\InfoInterface $payment, $amount) {
    try {

      $date = new \DateTime();

      // Info do CC
      $ccInfo = $this->getInfoInstance()->getAdditionalInformation();

      $order = $payment->getOrder();
      $values = explode("-", $ccInfo['cc_installments']);
      $installments = $this->helperData->getInstallments();
      $installmentInterest = $installments[(int)$values[0]];
      $installmentValue = (($order->getGrandTotal() * (($installmentInterest / 100) + 1)) / (int)$values[0]);

      // Monta o Array para o envio das informações ao Asaas
      $request = [
        'origin' => 'Magento',
        'customer' => $ccInfo['cc_customer'],
        'billingType' => 'CREDIT_CARD',
        'dueDate' => $date->format('Y-m-d'),
        'installmentCount' => (int)$values[0],
        'installmentValue' => $installmentValue,
        'creditCardToken' => $ccInfo['cc_token'],
        'description' => "Pedido " . $order->getIncrementId(),
        'externalReference' => $order->getIncrementId(),
        'remoteIp' => $order->getRemoteIp(),
      ];
      
      $paymentDone = (array)$this->doPayment($request);

      if (isset($paymentDone['errors'])) {
        throw new \Exception($paymentDone['errors'][0]->description);
      } else {
        $linkBoleto = $paymentDone['invoiceUrl'];
        $this->checkoutSession->setBoleto($linkBoleto);
        return $this;
      }
    } catch (\Exception $e) {
      $this->messageManager->addErrorMessage($e->getMessage());
      throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
    }
    return $this;
  }

  public function assignData(\Magento\Framework\DataObject $data) {
    $additionalData = $data['additional_data'];

    if (!isset($additionalData['cc_owner_cpf'])) {
      return $this;
    }

    $quote = $this->getInfoInstance()->getQuote();
    $billingAddress = $quote->getBillingAddress();

    $currentUser = $this->getOrCreateAsaasUser($billingAddress, $additionalData);

    $request = [
      'customer' => $currentUser,
      'creditCard' => [
        'holderName' => $additionalData['cc_owner_name'],
        'number' => $additionalData['cc_number'],
        'expiryMonth' => $additionalData['cc_exp_month'],
        'expiryYear' => $additionalData['cc_exp_year'],
        'ccv' => $additionalData['cc_cid'],
      ],
      'creditCardHolderInfo' => [
        'name' => $billingAddress->getFirstName() . ' ' . $billingAddress->getLastName(),
        'email' => $billingAddress->getEmail(),
        'cpfCnpj' => $additionalData['cc_owner_cpf'],
        'postalCode' => $billingAddress->getPostcode(),
        'addressNumber' => $billingAddress->getStreet()[1],
        'addressComplement' => null,
        'phone' => $billingAddress->getTelephone(),
        'mobilePhone' => $additionalData['cc_phone'],
      ],
      'remoteIp' => $quote->getRemoteIp(),
    ];

    $tokenizeDone = (array)$this->tokenize($request);

    $this->getInfoInstance()
      ->setAdditionalInformation('cc_customer', $currentUser ?? null)
      ->setAdditionalInformation('cc_brand', $tokenizeDone['creditCardBrand'] ?? null)
      ->setAdditionalInformation('cc_installments', $additionalData['cc_installments'] ?? null)
      ->setAdditionalInformation('cc_token', $tokenizeDone['creditCardToken'] ?? null);

    return $this;
  }

  public function userExists($cpf) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->helperData->getUrl() . "/api/v3/customers?cpfCnpj=" . $cpf,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_USERAGENT => "magento",
      CURLOPT_HTTPHEADER => array(
        "access_token: " . $this->_decrypt->decrypt($this->helperData->getAcessToken()),
        "Content-Type: application/json"
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response);
  }

  public function createUser($data) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->helperData->getUrl() . "/api/v3/customers",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_USERAGENT => "magento",
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => array(
        "access_token: " . $this->_decrypt->decrypt($this->helperData->getAcessToken()),
        "Content-Type: application/json"
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response);
  }

  public function getOrCreateAsaasUser($billingAddress, $additionalData) {
    // Pegando dados do pedido do cliente
    $customer = $this->_customerRepositoryInterface->load($billingAddress->getCustomerId());

    if (!$customer->getTaxvat()) {
      $cpfCnpj = $additionalData['cc_owner_cpf'];
    } else {
      $cpfCnpj = $customer->getTaxvat();
    }

    if (!isset($billingAddress->getStreet()[2])) {
      throw new \Exception("Por favor, preencha seu endereço corretamente.", 1);
    }

    // Verifica a existência do usuário na Asaas
    $user = (array)$this->userExists(preg_replace('/\D/', '', $cpfCnpj));
    if (!$user) {
      throw new \Exception("Por favor, verifique suas Credenciais (Ambiente, ApiKey)", 1);
    }

    if (count($user['data']) >= 1) {
      $currentUser = $user['data'][0]->id;
    } else {
      // Pega os dados do usuário para a criação da conta na Asaas
      $dataUser['name'] = $billingAddress->getFirstName() . ' ' . $billingAddress->getLastName();
      $dataUser['email'] = $billingAddress->getEmail();
      $dataUser['cpfCnpj'] = preg_replace('/\D/', '', $cpfCnpj);
      $dataUser['postalCode'] = $billingAddress->getPostcode();

      // Habilita notificações entre a Asaas e o comprador
      if (isset($notification)) {
        $dataUser['notificationDisabled'] = 'false';
      } else {
        $dataUser['notificationDisabled'] = 'true';
      }

      // Verifica se número foi informado
      if (isset($billingAddress->getStreet()[1])) {
        $dataUser['addressNumber'] = $billingAddress->getStreet()[1];
      }

      $newUser = (array)$this->createUser($dataUser);
      if (!$newUser) {
        throw new \Exception("Por favor, verifique suas Credenciais (Ambiente, ApiKey)", 1);
      }
      $currentUser = $newUser['id'];
    }

    return $currentUser;
  }

  public function tokenize($data) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->helperData->getUrl() . "/api/v3/creditCard/tokenize",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_USERAGENT => "magento",
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => array(
        "access_token: " . $this->_decrypt->decrypt($this->helperData->getAcessToken()),
        "Content-Type: application/json"
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response);
  }

  public function doPayment($data) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->helperData->getUrl() . "/api/v3/payments",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_USERAGENT => "magento",
      CURLOPT_POSTFIELDS => json_encode($data),
      CURLOPT_HTTPHEADER => array(
        "access_token: " . $this->_decrypt->decrypt($this->helperData->getAcessToken()),
        "Content-Type: application/json"
      ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response);
  }
}
