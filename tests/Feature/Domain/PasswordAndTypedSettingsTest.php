<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * 0.1.5-T — la contraseña nunca se guarda en claro ni como MD5, y las
 * preferencias no son un blob.
 */
test('test_las_contrasenas_se_guardan_con_hash_y_las_preferencias_son_tablas_tipadas', function () {
    $user = User::factory()->create(['password' => 'contrasena-secreta']);

    $stored = DB::table('users')->where('id', $user->id)->value('password');

    expect($stored)->not->toBe('contrasena-secreta')
        ->and($stored)->not->toBe(md5('contrasena-secreta'))
        ->and(Hash::check('contrasena-secreta', $stored))->toBeTrue();

    // El legacy guardaba la contraseña en claro y las preferencias en un blob.
    expect(Schema::hasColumn('users', 'raw_password'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'preference'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'pass_reset_code'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'reset_code_time'))->toBeFalse();

    // Las preferencias son tablas tipadas.
    expect(Schema::hasTable('user_settings'))->toBeTrue()
        ->and(Schema::hasColumn('user_settings', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('user_settings', 'language'))->toBeTrue()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasColumn('settings', 'key'))->toBeTrue()
        ->and(Schema::hasColumn('settings', 'value'))->toBeTrue()
        ->and(Schema::hasColumn('settings', 'type'))->toBeTrue();

    // `users.group_id` referencia al rol: FK real, no un entero suelto.
    $foreignKeys = collect(Schema::getForeignKeys('users'))->pluck('foreign_table')->all();

    expect($foreignKeys)->toContain('roles');
});
