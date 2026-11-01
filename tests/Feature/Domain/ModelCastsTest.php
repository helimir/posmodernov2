<?php

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\FiscalProfileDocumentType;
use App\Models\FiscalProfileEnvironment;
use App\Models\FiscalProfileField;
use App\Models\FiscalProvider;
use App\Models\FiscalStatusVocabulary;
use App\Models\FiscalTaxScheme;
use App\Models\Language;
use App\Models\LanguageTranslation;
use App\Models\MessageTemplate;
use App\Models\NumberFormat;
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

/**
 * 0.2.6-T (parte) — los modelos de la capa de país y fiscal declaran sus casts
 * y sus relaciones con FK. `TaxCategory` y `TaxRate` llegan con M03 (PR6):
 * la tarea 0.2.6 sigue abierta.
 */
test('test_los_modelos_de_la_capa_de_pais_y_fiscal_declaran_sus_casts_y_sus_relaciones', function () {
    expect((new Country)->getCasts())->toHaveKey('default_currency_id', 'integer')
        ->and((new Country)->getCasts())->toHaveKey('iso_code', 'string')
        ->and((new Currency)->getCasts())->toHaveKey('decimals', 'integer')
        ->and((new Currency)->getCasts())->toHaveKey('code', 'string')
        ->and((new NumberFormat)->getCasts())->toHaveKey('country_id', 'integer')
        ->and((new NumberFormat)->getCasts())->toHaveKey('invoice_number_format', 'string')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('requires_control_number', 'boolean')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('holes_allowed', 'boolean')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('tax_inclusive_default', 'boolean')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('signer_model', 'string')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('hash_algorithm', 'string')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('signature_scheme', 'string')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('rounding_rule', 'string')
        ->and((new FiscalProfile)->getCasts())->toHaveKey('effective_from', 'date')
        ->and((new FiscalProfileDocumentType)->getCasts())->toHaveKey('tax_treatment', 'string')
        ->and((new FiscalProfileDocumentType)->getCasts())->toHaveKey('requires_parent_reference', 'boolean')
        ->and((new FiscalProfileDocumentType)->getCasts())->toHaveKey('exemption_marker', 'string')
        ->and((new FiscalProfileEnvironment)->getCasts())->toHaveKey('role', 'string')
        ->and((new FiscalProfileEnvironment)->getCasts())->toHaveKey('provider_id', 'integer')
        ->and((new FiscalProfileField)->getCasts())->toHaveKey('key', 'string')
        ->and((new FiscalProfileField)->getCasts())->toHaveKey('value', 'string')
        ->and((new FiscalProfileField)->getCasts())->toHaveKey('required', 'boolean')
        ->and((new FiscalProfileField)->getCasts())->toHaveKey('evidence_status', 'string')
        ->and((new FiscalProfileField)->getCasts())->toHaveKey('source_ref', 'string')
        ->and((new FiscalTaxScheme)->getCasts())->toHaveKey('is_withholding', 'boolean')
        ->and((new FiscalStatusVocabulary)->getCasts())->toHaveKey('native_code', 'string')
        ->and((new FiscalStatusVocabulary)->getCasts())->toHaveKey('generic_status', 'string')
        ->and((new FiscalStatusVocabulary)->getCasts())->toHaveKey('is_cancellation', 'boolean')
        ->and((new FiscalProvider)->getCasts())->toHaveKey('kind', 'string')
        ->and((new FiscalProvider)->getCasts())->toHaveKey('is_active', 'boolean');

    expect((new Country)->defaultCurrency())->toBeInstanceOf(BelongsTo::class)
        ->and((new Country)->numberFormat())->toBeInstanceOf(HasOne::class)
        ->and((new Country)->fiscalProfiles())->toBeInstanceOf(HasMany::class)
        ->and((new NumberFormat)->country())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfile)->country())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfile)->defaultCurrency())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfile)->documentTypes())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfile)->environments())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfile)->fields())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfile)->taxSchemes())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfile)->statusVocabularies())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfile)->providers())->toBeInstanceOf(HasMany::class)
        ->and((new FiscalProfileDocumentType)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfileEnvironment)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfileEnvironment)->provider())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProfileField)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalTaxScheme)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalStatusVocabulary)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProvider)->fiscalProfile())->toBeInstanceOf(BelongsTo::class)
        ->and((new FiscalProvider)->environments())->toBeInstanceOf(HasMany::class);
});
