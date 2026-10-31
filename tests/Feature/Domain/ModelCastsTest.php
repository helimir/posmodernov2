<?php

use App\Models\AuditLog;
use App\Models\Language;
use App\Models\LanguageTranslation;
use App\Models\MessageTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\SmsProvider;
use App\Models\Store;
use App\Models\StoreFiscalRegistration;
use App\Models\StoreSetting;
use App\Models\User;
use App\Models\UserSetting;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 0.1.7-T — un importe se lee como decimal exacto y un JSON como estructura,
 * nunca como texto.
 */
test('test_los_modelos_declaran_sus_casts_de_importes_y_de_json', function () {
    // Importes: decimal exacto (`numeric(25,4)`), nunca `float`.
    $decimal = (new Setting(['value' => '10.50', 'type' => 'decimal']))->typedValue();

    expect($decimal)->toBeInstanceOf(BigDecimal::class)
        ->and((string) $decimal)->toBe('10.50');

    // JSON: estructura, nunca texto.
    expect((new AuditLog)->getCasts())->toHaveKey('context', 'array');

    // Booleanos, fechas y enteros con su tipo declarado.
    expect((new Store)->getCasts())->toHaveKey('status', 'boolean')
        ->and((new StoreSetting)->getCasts())->toHaveKey('auto_print', 'boolean')
        ->and((new StoreSetting)->getCasts())->toHaveKey('invoice_edit_lifespan', 'integer')
        ->and((new StoreFiscalRegistration)->getCasts())->toHaveKey('issued_at', 'date')
        ->and((new Role)->getCasts())->toHaveKey('sort_order', 'integer')
        ->and((new Permission)->getCasts())->toHaveKey('slug', 'string')
        ->and((new Setting)->getCasts())->toHaveKey('type', 'string')
        ->and((new UserSetting)->getCasts())->toHaveKey('user_id', 'integer')
        ->and((new User)->getCasts())->toHaveKey('password', 'hashed');

    // Los modelos de i18n, outbox y auditoría declaran también sus casts.
    expect((new Language)->getCasts())->toHaveKey('slug', 'string')
        ->and((new LanguageTranslation)->getCasts())->toHaveKey('lang_id', 'integer')
        ->and((new LanguageTranslation)->getCasts())->toHaveKey('lang_value', 'string')
        ->and((new MessageTemplate)->getCasts())->toHaveKey('tag_name', 'string')
        ->and((new SmsProvider)->getCasts())->toHaveKey('credential_env_prefix', 'string')
        ->and((new SmsProvider)->getCasts())->toHaveKey('supports_scheduling', 'boolean')
        ->and((new SmsProvider)->getCasts())->toHaveKey('sort_order', 'integer')
        ->and((new SmsMessage)->getCasts())->toHaveKey('status', 'string')
        ->and((new SmsMessage)->getCasts())->toHaveKey('sent_at', 'datetime')
        ->and((new SmsMessage)->getCasts())->toHaveKey('attempts', 'integer')
        ->and((new AuditLog)->getCasts())->toHaveKey('action', 'string')
        ->and((new AuditLog)->getCasts())->toHaveKey('entity_type', 'string');

    // Cada FK declara su relación.
    expect((new Store)->settings())->toBeInstanceOf(HasOne::class)
        ->and((new Store)->fiscalRegistrations())->toBeInstanceOf(HasMany::class)
        ->and((new Store)->roles())->toBeInstanceOf(BelongsToMany::class)
        ->and((new StoreSetting)->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new StoreFiscalRegistration)->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Role)->permissions())->toBeInstanceOf(BelongsToMany::class)
        ->and((new Role)->stores())->toBeInstanceOf(BelongsToMany::class)
        ->and((new Permission)->roles())->toBeInstanceOf(BelongsToMany::class)
        ->and((new UserSetting)->user())->toBeInstanceOf(BelongsTo::class)
        ->and((new User)->role())->toBeInstanceOf(BelongsTo::class)
        ->and((new User)->settings())->toBeInstanceOf(HasOne::class)
        ->and((new Language)->translations())->toBeInstanceOf(HasMany::class)
        ->and((new LanguageTranslation)->language())->toBeInstanceOf(BelongsTo::class)
        ->and((new SmsProvider)->messages())->toBeInstanceOf(HasMany::class)
        ->and((new SmsMessage)->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new SmsMessage)->provider())->toBeInstanceOf(BelongsTo::class)
        ->and((new SmsMessage)->template())->toBeInstanceOf(BelongsTo::class)
        ->and((new MessageTemplate)->messages())->toBeInstanceOf(HasMany::class)
        ->and((new AuditLog)->user())->toBeInstanceOf(BelongsTo::class)
        ->and((new AuditLog)->store())->toBeInstanceOf(BelongsTo::class);
});
