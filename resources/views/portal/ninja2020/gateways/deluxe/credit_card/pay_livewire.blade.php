<div class="rounded-lg border bg-card text-card-foreground shadow-sm overflow-hidden py-5 bg-white sm:gap-4">
    @component('portal.ninja2020.components.general.card-element', ['title' => ctrans('texts.payment_type')])
        {{ ctrans('texts.credit_card') }}
    @endcomponent

    @include('portal.ninja2020.gateways.includes.payment_details')

    @component('portal.ninja2020.components.general.card-element-single')
        <p>{{ ctrans('texts.redirecting_title') ?? 'Redirecting to Deluxe…' }}</p>
        <p>
            <a href="{{ $payment_link_url }}" class="button button-primary">
                {{ ctrans('texts.continue') ?? 'Continue' }}
            </a>
        </p>
    @endcomponent
</div>

@script
    <script>
        window.location.replace(@js($payment_link_url));
    </script>
@endscript
