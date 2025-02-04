<?php
/**
 * 2018-2022 Alma SAS
 *
 * THE MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and
 * to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
 * Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @author    Alma SAS <contact@getalma.eu>
 * @copyright 2018-2022 Alma SAS
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

namespace Alma\PrestaShop\Controllers\Hook;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Alma\API\RequestError;
use Alma\PrestaShop\API\ClientHelper;
use Alma\PrestaShop\API\PaymentNotFoundError;
use Alma\PrestaShop\Hooks\AdminHookController;
use Alma\PrestaShop\Utils\Logger;
use Alma\PrestaShop\Utils\OrderDataTrait;
use Currency;
use Order;

final class DisplayRefundsHookController extends AdminHookController
{
    use OrderDataTrait;

    /** @var Order */
    public $order;

    public function canRun()
    {
        return parent::canRun();
    }

    /**
     * Run Hook for show block Refund in Order page if is payment Alma
     *
     * @param array $params
     *
     * @return string|null as the template fetched
     */
    public function run($params)
    {
        $order = new Order($params['id_order']);
        try {
            $payment = $this->getPayment($order);
        } catch (PaymentNotFoundError $e) {
            // if we can't have the payment, log why and return null
            Logger::instance()->debug($e->getMessage());

            return null;
        }

        $refundData = null;
        $totalRefund = null;
        $percentRefund = null;
        $orderTotalPaid = $order->getOrdersTotalPaid();
        $fees = almaPriceFromCents($payment->customer_fee);
        $paymentTotalAmount = $order->total_paid_tax_incl + $fees;

        //multi shipping
        $ordersId = null;
        if ($orderTotalPaid > $order->total_paid_tax_incl) {
            $orders = Order::getByReference($order->reference);
            foreach ($orders as $o) {
                if ($o->id != $order->id) {
                    $ordersId .= "{$o->id},";
                }
            }
            $ordersId = rtrim($ordersId, ',');
            $paymentTotalAmount = $orderTotalPaid + $fees;
        }

        if ($payment->refunds) {
            foreach ($payment->refunds as $refund) {
                $totalRefund += $refund->amount;
            }

            $percentRefund = (100 / $paymentTotalAmount) * almaPriceFromCents($totalRefund);

            $refundData = [
                'totalRefundAmount' => almaFormatPrice($totalRefund, (int) $order->id_currency),
                'percentRefund' => $percentRefund,
            ];
        }

        $currency = new Currency($order->id_currency);
        $orderData = [
            'id' => $order->id,
            'maxAmount' => almaFormatPrice(almaPriceToCents($order->total_paid_tax_incl), (int) $order->id_currency),
            'currencySymbol' => $currency->sign,
            'ordersId' => $ordersId,
            'paymentTotalAmount' => almaFormatPrice(almaPriceToCents($paymentTotalAmount), (int) $order->id_currency),
        ];
        $wording = [
            'title' => $this->module->l('Alma refund', 'DisplayRefundsHookController'),
            'description' => sprintf(
                // phpcs:ignore
                $this->module->l('Refund this order thanks to the Alma module. This will be applied in your Alma dashboard automatically. The maximum refundable amount includes client fees. %1$sSee documentation%2$s', 'DisplayRefundsHookController'),
                '<a href="https://docs.getalma.eu/docs/prestashop-refund" target="_blank">',
                '</a>'
            ),
            'labelTypeRefund' => $this->module->l('Refund type:', 'DisplayRefundsHookController'),
            'labelRadioRefundOneOrder' => sprintf(
                $this->module->l('Only this order (%s)', 'DisplayRefundsHookController'),
                $orderData['maxAmount']
            ),
            'labelRadioRefundAllOrder' => $this->module->l('Refund the entire order', 'DisplayRefundsHookController'),
            'labelRadioRefundAllOrderInfoId' => sprintf(
                // phpcs:ignore
                $this->module->l('Refund this order (id: %1$d) and all those linked to the same payment (id: %2$s)', 'DisplayRefundsHookController'),
                $orderData['id'],
                $orderData['ordersId']
            ),
            'labelRadioRefundAllOrderInfoAmount' => sprintf(
                $this->module->l('Total amount: %s', 'DisplayRefundsHookController'),
                $orderData['paymentTotalAmount']
            ),
            'labelRadioRefundTotalAmout' => $this->module->l('Total amount', 'DisplayRefundsHookController'),
            'labelRadioRefundPartial' => $this->module->l('Partial', 'DisplayRefundsHookController'),
            'labelAmoutRefundPartial' => sprintf(
                $this->module->l('Amount (Max. %s):', 'DisplayRefundsHookController'),
                $orderData['paymentTotalAmount']
            ),
            'placeholderInputRefundPartial' => $this->module->l('Amount to refund...', 'DisplayRefundsHookController'),
            'buttonRefund' => $this->module->l('Proceed the refund', 'DisplayRefundsHookController'),
        ];

        $tpl = $this->context->smarty->createTemplate(
            "{$this->module->local_path}views/templates/hook/" . $this->getTemplateName() . '.tpl'
        );

        $tpl->assign([
            'iconPath' => $this->module->getPathUri() . '/views/img/logos/alma_tiny.svg',
            'wording' => $wording,
            'order' => $orderData,
            'refund' => $refundData,
            'actionUrl' => $this->context->link->getAdminLink('AdminAlmaRefunds'),
        ]);

        return $tpl->fetch();
    }

    /**
     * Run Hook for show block Refund in Order page if is payment Alma
     *
     * @param Order $order
     *
     * @return Payment
     */
    private function getPayment($order)
    {
        $alma = ClientHelper::defaultInstance();
        if ($order->module !== 'alma' || !$alma) {
            throw new PaymentNotFoundError('Alma is not available');
        }
        $orderPayment = $this->getOrderPaymentOrFail($order);
        $paymentId = $orderPayment->transaction_id;
        if (empty($paymentId)) {
            throw new PaymentNotFoundError("[Alma] paymentId doesn't exist");
        }
        try {
            return $alma->payments->fetch($paymentId);
        } catch (RequestError $e) {
            throw new PaymentNotFoundError("[Alma] Can't get payment with this payment_id : $paymentId");
        }
    }

    /**
     * Get the name of file for template block refund order page
     *
     * @return string as template name
     */
    private function getTemplateName()
    {
        $refundTpl = 'order_refund_bs4';
        if (version_compare(_PS_VERSION_, '1.6', '<')) {
            $refundTpl = 'order_refund_ps15';
        } elseif (version_compare(_PS_VERSION_, '1.7.7.0', '<')) {
            $refundTpl = 'order_refund_bs3';
        }

        return $refundTpl;
    }
}
