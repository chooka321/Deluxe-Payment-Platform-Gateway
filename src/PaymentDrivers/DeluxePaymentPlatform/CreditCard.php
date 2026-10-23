<?php

namespace App\PaymentDrivers\DeluxePaymentPlatform;

use App\Exceptions\PaymentFailed;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\Jobs\Util\SystemLogger;
use App\PaymentDrivers\DeluxePaymentPlatformPaymentDriver;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;

class CreditCard
{
    use MakesHash;

    public function __construct(public DeluxePaymentPlatformPaymentDriver $driver) {}

    // -----------------------------------------------------------------
    // Create a Deluxe-hosted payment link and redirect the customer.
    // -----------------------------------------------------------------

    public function paymentView(array $data)
    {
        return redirect()->away($this->driver->getPaymentLinkUrl(['Card']));
    }

    // -----------------------------------------------------------------
    // Webhook records the payment; this is only used by the legacy
    // post-back form, which Payment Link does not invoke.
    // -----------------------------------------------------------------

    public function paymentResponse(Request $request)
    {
        $transactionId = $request->input('transaction_id');
        $amount        = array_sum(array_column($this->driver->payment_hash->invoices(), 'amount'))
                       + $this->driver->payment_hash->fee_total;

        if (empty($transactionId)) {
            throw new PaymentFailed('No transaction ID returned from Deluxe', 500);
        }

        $payment = $this->driver->createPayment([
            'amount'                => $amount,
            'payment_type'          => PaymentType::CREDIT_CARD_OTHER,
            'gateway_type_id'       => GatewayType::CREDIT_CARD,
            'transaction_reference' => $transactionId,
        ], Payment::STATUS_COMPLETED);

        SystemLogger::dispatch(
            ['transaction_id' => $transactionId, 'raw' => $request->input('gateway_response')],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_STRIPE,
            $this->driver->client,
            $this->driver->client->company,
        );

        return redirect()->route('client.payments.show', [
            'payment' => $this->encodePrimaryKey($payment->id),
        ]);
    }

    public function paymentData(array $data): array
    {
        $data['gateway']          = $this->driver;
        $data['payment_link_url'] = $this->driver->getPaymentLinkUrl(['Card']);
        return $data;
    }

    public function livewirePaymentView(array $data): string
    {
        return 'gateways.deluxe.credit_card.pay_livewire';
    }
}
