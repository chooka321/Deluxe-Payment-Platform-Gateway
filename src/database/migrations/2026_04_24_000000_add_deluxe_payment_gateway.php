<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Gateway;

return new class extends Migration
{
    public function up(): void
    {
        $fields = json_encode([
            'productionMode'         => false,
            'sandboxAccessToken'     => '',
            'sandboxClientId'        => '',
            'sandboxClientSecret'    => '',
            'sandboxSecretKey'       => '',
            'productionAccessToken'  => '',
            'productionClientId'     => '',
            'productionClientSecret' => '',
            'productionSecretKey'    => '',
        ]);

        $existing = Gateway::where('key', 'deluxepaymentplatform')->first();

        if ($existing) {
            $existing->fields     = $fields;
            $existing->is_offsite = false;
            $existing->save();
            return;
        }

        $gateway = new Gateway();
        $gateway->name                    = 'Deluxe Payment Platform';
        $gateway->key                     = 'deluxepaymentplatform';
        $gateway->provider                = 'DeluxePaymentPlatform';
        $gateway->is_offsite              = false;
        $gateway->fields                  = $fields;
        $gateway->visible                 = true;
        $gateway->site_url                = 'https://developer.deluxe.com';
        $gateway->default_gateway_type_id = 1;
        $gateway->save();
    }

    public function down(): void
    {
        Gateway::where('key', 'deluxepaymentplatform')->delete();
    }
};
