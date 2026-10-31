<?php

use Illuminate\Support\Facades\Schema;

/**
 * 0.1.4-T — la configuración por tienda y los permisos son filas, no blobs.
 */
test('test_las_tablas_de_tienda_y_de_permisos_no_guardan_blobs_php_serializados', function () {
    // El blob de preferencias del legacy desaparece de `stores` y el registro
    // fiscal se va a su propia tabla.
    expect(Schema::hasColumn('stores', 'preference'))->toBeFalse()
        ->and(Schema::hasColumn('stores', 'vat_reg_no'))->toBeFalse()
        ->and(Schema::hasTable('store_settings'))->toBeTrue()
        ->and(Schema::hasColumn('store_settings', 'timezone'))->toBeTrue()
        ->and(Schema::hasColumn('store_settings', 'store_id'))->toBeTrue()
        ->and(Schema::hasTable('store_fiscal_registrations'))->toBeTrue()
        ->and(Schema::hasColumn('store_fiscal_registrations', 'tax_id'))->toBeTrue();

    // El blob `user_group.permission` se normaliza en `permissions` + `role_permissions`.
    expect(Schema::hasTable('user_group'))->toBeFalse()
        ->and(Schema::hasColumn('roles', 'permission'))->toBeFalse()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasColumn('permissions', 'slug'))->toBeTrue()
        ->and(Schema::hasTable('role_permissions'))->toBeTrue()
        ->and(Schema::hasColumn('role_permissions', 'role_id'))->toBeTrue()
        ->and(Schema::hasColumn('role_permissions', 'permission_id'))->toBeTrue();

    // Los scopes por tienda y el atajo del panel son filas con FK real.
    expect(Schema::hasTable('role_store_scopes'))->toBeTrue()
        ->and(Schema::hasColumn('role_store_scopes', 'store_id'))->toBeTrue()
        ->and(Schema::hasTable('dashboard_shortcuts'))->toBeTrue()
        ->and(Schema::hasColumn('dashboard_shortcuts', 'permission_id'))->toBeTrue()
        ->and(Schema::hasColumn('dashboard_shortcuts', 'permission_slug'))->toBeFalse();

    // Cada relación es una clave foránea real, no un entero suelto.
    $foreignKeys = collect(Schema::getForeignKeys('role_permissions'))
        ->pluck('foreign_table')
        ->all();

    expect($foreignKeys)->toContain('roles')->toContain('permissions');
});
