@extends('portal.ninja2020.layout.payments', ['gateway_title' => ctrans('texts.bank_transfer'), 'card_title' => ctrans('texts.bank_transfer')])

@section('gateway_head')
    <meta name="deluxe-jwt" content="{{ $jwt }}">
@endsection

@section('gateway_content')
    <form action="{{ route('client.payments.response') }}" method="post" id="server_response">
        @csrf
        <input type="hidden" name="payment_hash" value="{{ $payment_hash }}">
        <input type="hidden" name="company_gateway_id" value="{{ $gateway->getCompanyGatewayId() }}">
        <input type="hidden" name="payment_method_id" value="{{ $payment_method_id }}">
        <input type="hidden" name="transaction_id" id="transaction_id">
        <input type="hidden" name="gateway_response" id="gateway_response">
    </form>

    <div class="alert alert-failure mb-4" hidden id="errors"></div>

    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.payment_type')])
        {{ ctrans('texts.bank_transfer') }}
    @endcomponent

    @include('portal.ninja2020.gateways.includes.payment_details')

    @component('portal.ninja2020.components.general.card-element-single')
        <div id="embeddedpayments"></div>
    @endcomponent
@endsection

@section('gateway_footer')
    <script src="{{ $gateway->sdkUrl() }}"></script>
    <script>
        (function () {
            const jwt = document.querySelector('meta[name="deluxe-jwt"]').content;
            EmbeddedPayments.init(jwt, {
                countryCode: 'US',
                currencyCode: '{{ $gateway->client->getCurrencyCode() }}',
                paymentMethods: {!! $payment_methods !!},
            }).then(function (instance) {
                instance.setEventHandlers({
                    onTxnSuccess: function (gateway, data) {
                        document.getElementById('transaction_id').value =
                            data.paymentId || data.transactionId || data.id || data.PaymentId || JSON.stringify(data);
                        document.getElementById('gateway_response').value = JSON.stringify(data);
                        document.getElementById('server_response').submit();
                    },
                    onTxnFailed: function (gateway, data) {
                        var el = document.getElementById('errors');
                        el.removeAttribute('hidden');
                        el.textContent = (data && (data.responseMessage || data.message)) || 'Payment failed. Please try again.';
                    },
                    onValidationError: function (gateway, errors) {
                        var el = document.getElementById('errors');
                        el.removeAttribute('hidden');
                        el.textContent = JSON.stringify(errors);
                    },
                    onCancel: function () { window.history.back(); },
                }).render({ containerId: 'embeddedpayments', paymentpanelstyle: 'light' });
            });
        })();
    </script>
@endsection
