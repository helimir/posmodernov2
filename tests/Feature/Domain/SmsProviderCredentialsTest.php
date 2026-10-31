<?php

use App\Models\SmsProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.1.6-T — ninguna credencial de proveedor vive en la base de datos: la fila
 * guarda sólo el prefijo de la variable de entorno desde la que se resuelve el
 * secreto en runtime.
 */
test('test_las_credenciales_de_proveedor_se_guardan_como_referencia_de_entorno_y_no_en_claro', function () {
    $columns = Schema::getColumnListing('sms_providers');

    // La referencia al secreto es un prefijo de variable de entorno.
    expect($columns)->toContain('credential_env_prefix');

    // El legacy guardaba las credenciales en claro en `sms_setting`
    // (api_id, auth_key, username, password): ninguna de esas columnas existe.
    foreach (['api_id', 'auth_key', 'username', 'password', 'api_key', 'api_secret', 'secret', 'token'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }

    // `sms_messages` es un outbox: el estado y el instante de envío son datos.
    expect(Schema::getColumnListing('sms_messages'))->toContain('status', 'sent_at');

    putenv('SMS_TWILIO_AUTH_TOKEN=twilio-secret');
    $provider = SmsProvider::create([
        'code' => 'twilio',
        'name' => 'Twilio',
        'credential_env_prefix' => 'SMS_TWILIO',
    ]);

    // El secreto se resuelve desde el entorno...
    expect($provider->credential('AUTH_TOKEN'))->toBe('twilio-secret');

    // ...y la fila no lo contiene en ningún campo.
    $row = DB::table('sms_providers')->where('id', $provider->id)->first();
    expect(json_encode($row))->not->toContain('twilio-secret')
        ->and((array) $row)->toHaveKey('credential_env_prefix', 'SMS_TWILIO');

    // Triangulación: otro prefijo resuelve otra variable y una variable
    // ausente no se sustituye por nada leído de la base.
    putenv('SMS_MSG91_AUTH_TOKEN=msg91-secret');
    $other = SmsProvider::create([
        'code' => 'msg91',
        'name' => 'MSG91',
        'credential_env_prefix' => 'SMS_MSG91',
    ]);

    expect($other->credential('AUTH_TOKEN'))->toBe('msg91-secret')
        ->and($other->credential('MISSING_TOKEN'))->toBeNull()
        ->and($other->credential('AUTH_TOKEN'))->not->toBe($provider->credential('AUTH_TOKEN'));

    putenv('SMS_TWILIO_AUTH_TOKEN');
    putenv('SMS_MSG91_AUTH_TOKEN');
});
