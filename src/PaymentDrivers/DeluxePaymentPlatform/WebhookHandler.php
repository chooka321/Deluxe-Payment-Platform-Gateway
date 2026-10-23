<?php

namespace App\PaymentDrivers\DeluxePaymentPlatform;

use App\Models\CompanyGateway;
use App\Models\GatewayType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookHandler
{
    public function handle(Request $request)
    {
        $payload = $request->json()->all();

        Log::channel('daily')->info('Deluxe webhook received', $payload);

        // Deluxe ships PascalCase keys (EventType, Status, CustomFields...).
        // Normalise the whole tree to lower-case keys so the handler can read them.
        $payload = $this->lowercaseKeys($payload);

        $eventType = strtoupper($payload['eventtype'] ?? '');

        return match ($eventType) {
            'TRANSACTION'                              => $this->handleTransaction($payload),
            'ACH REJECT', 'ACH_REJECT', 'ACHREJECT'    => $this->handleAchReject($payload),
            default                                    => response()->json(['received' => true], 200),
        };
    }

    private function lowercaseKeys($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $key = is_string($k) ? strtolower($k) : $k;
            $out[$key] = $this->lowercaseKeys($v);
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // TRANSACTION — process APPROVED, PENDING, and SETTLED.
    // PENDING covers ACH that hasn't settled yet; an ACH REJECT will
    // later flip it to FAILED if the bank declines.
    // -----------------------------------------------------------------

    private function handleTransaction(array $payload)
    {
        $statusUpper = strtoupper($payload['status'] ?? '');
        if (! in_array($statusUpper, ['APPROVED', 'PENDING', 'SETTLED'], true)) {
            return response()->json(['received' => true], 200);
        }

        // PaymentHash is passed via customFields when the payment link is created,
        // and echoed back in the webhook payload's customFields array
        $hashValue = $this->extractPaymentHash($payload);

        if (! $hashValue) {
            Log::warning('Deluxe webhook: no PaymentHash found', ['payload' => $payload]);
            return response()->json(['received' => true], 200);
        }

        $hash = PaymentHash::where('hash', $hashValue)->first();

        if (! $hash) {
            Log::warning('Deluxe webhook: PaymentHash not found: ' . $hashValue);
            return response()->json(['received' => true], 200);
        }

        // ACH flow can fire PENDING then APPROVED/SETTLED for the same hash.
        // If the first webhook already created the payment, the later webhook
        // upgrades it from STATUS_PENDING to STATUS_COMPLETED.
        if ($hash->payment_id) {
            return $this->maybeCompletePending($hash->payment_id, $statusUpper);
        }

        $invoice = Invoice::withTrashed()->find($hash->fee_invoice_id);

        if (! $invoice) {
            Log::error('Deluxe webhook: invoice not found for hash ' . $hashValue);
            return response()->json(['received' => true], 200);
        }

        $company        = $invoice->company;
        $client         = $invoice->client;
        $companyGateway = CompanyGateway::whereHas('gateway', fn ($q) =>
            $q->where('key', 'deluxepaymentplatform')
        )->where('company_id', $company->id)->first();

        if (! $companyGateway) {
            Log::error('Deluxe webhook: no gateway found for company ' . $company->id);
            return response()->json(['received' => true], 200);
        }

        $isACH       = strtoupper($payload['paymenttype'] ?? '') === 'ACH';
        $paymentType = $isACH ? PaymentType::ACH : PaymentType::CREDIT_CARD_OTHER;
        $gatewayType = $isACH ? GatewayType::BANK_TRANSFER : GatewayType::CREDIT_CARD;

        // Card → always completed. ACH → pending until APPROVED/SETTLED.
        $status = (! $isACH || in_array($statusUpper, ['APPROVED', 'SETTLED'], true))
            ? Payment::STATUS_COMPLETED
            : Payment::STATUS_PENDING;

        $transactionRef = $payload['transactionid']
            ?? $payload['paymentid']
            ?? null;

        $payment = $companyGateway
            ->driver($client)
            ->setPaymentHash($hash)
            ->createPayment([
                'amount'                => (float) ($payload['transactionamount'] ?? 0),
                'payment_type'          => $paymentType,
                'gateway_type_id'       => $gatewayType,
                'transaction_reference' => $transactionRef,
            ], $status);

        Log::info('Deluxe webhook: payment recorded', [
            'payment_id'     => $payment->id,
            'transaction_id' => $transactionRef,
        ]);

        return response()->json(['received' => true], 200);
    }

    private function maybeCompletePending(int $paymentId, string $statusUpper)
    {
        if (! in_array($statusUpper, ['APPROVED', 'SETTLED'], true)) {
            return response()->json(['received' => true, 'note' => 'already processed'], 200);
        }

        $payment = Payment::find($paymentId);
        if ($payment && $payment->status_id === Payment::STATUS_PENDING) {
            $payment->status_id = Payment::STATUS_COMPLETED;
            $payment->save();
            Log::info('Deluxe webhook: pending payment marked completed', [
                'payment_id' => $payment->id,
            ]);
        }
        return response()->json(['received' => true, 'note' => 'pending->completed'], 200);
    }

    // -----------------------------------------------------------------
    // ACH REJECT — bank rejected the debit; flip pending payment to failed
    // -----------------------------------------------------------------

    private function handleAchReject(array $payload)
    {
        $paymentId = $payload['transactionid'] ?? $payload['paymentid'] ?? null;

        if (! $paymentId) {
            return response()->json(['received' => true], 200);
        }

        $payment = Payment::where('transaction_reference', $paymentId)
            ->where('status_id', Payment::STATUS_PENDING)
            ->first();

        if ($payment) {
            $payment->status_id = Payment::STATUS_FAILED;
            $meta               = $payment->meta ?? new \stdClass();
            $meta->ach_reject_reason = $payload['status'] ?? 'ACH Rejected by bank';
            $payment->meta      = $meta;
            $payment->save();

            Log::info('Deluxe webhook: ACH payment marked failed', [
                'payment_id'     => $payment->id,
                'transaction_id' => $paymentId,
            ]);
        }

        return response()->json(['received' => true], 200);
    }

    // -----------------------------------------------------------------
    // Extract PaymentHash from the customFields array (Deluxe's name) or
    // customData (older payload shape). All keys are lower-cased upstream
    // by lowercaseKeys(); the *value* of PaymentHash retains its case.
    // -----------------------------------------------------------------

    private function extractPaymentHash(array $payload): ?string
    {
        $customFields = $payload['customfields'] ?? $payload['customdata'] ?? [];

        foreach ($customFields as $item) {
            $name  = $item['name']  ?? null;
            $value = $item['value'] ?? null;
            if ($name !== null && strtolower($name) === 'paymenthash' && $value) {
                return $value;
            }
        }

        // Fallback: invoiceNumber field carries the same hash for Payment Link flows
        $invoiceNumber = $payload['invoicenumber'] ?? null;
        if ($invoiceNumber && PaymentHash::where('hash', $invoiceNumber)->exists()) {
            return $invoiceNumber;
        }

        return null;
    }
}
