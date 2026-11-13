<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // El ROL: la persona de la prueba es la ADMINISTRADORA.
            //
            // REGLA que fija esta factory: *quien actúa en una prueba es el
            // superusuario*. Las pruebas de dominio (el motor de impuestos,
            // el FIFO, el libro contable) usan a un usuario como MEDIO y no
            // como sujeto: lo que se prueba es el dominio, y el permiso se
            // prueba en `tests/Feature/Auth/`, donde el rol se crea
            // EXPRESAMENTE con (o sin) su permiso.
            //
            // Y EL ROL SE CREA SI NO ESTA: la tabla `roles` llega vacia de
            // las migraciones, y la persona de la prueba tiene que poder
            // hacer lo que la prueba pide. Sin rol no hay permiso, y sin
            // permiso no se llega a ninguna pantalla.
            'group_id' => fn (): int => self::idDelSuperusuario(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * El ID del rol SUPERUSUARIO: el rol que puede hacer todo.
     *
     * El slug sale de la configuracion (`auth.superuser_role_slug`), asi que
     * el nombre del rol no esta escrito aqui.
     */
    private static function idDelSuperusuario(): int
    {
        $slug = (string) config('auth.superuser_role_slug');

        $rol = Role::query()->firstWhere('slug', $slug);

        if ($rol === null) {
            $rol = Role::query()->create([
                'name' => 'Superuser',
                'slug' => $slug,
                'sort_order' => 0,
                'status' => true,
            ]);
        }

        return (int) $rol->id;
    }
}
