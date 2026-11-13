<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Auth\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'username', 'email', 'mobile', 'password', 'group_id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Los permisos del ROL, ya leidos.
     *
     * Se cachean en la instancia porque la pantalla de permisos y la de
     * usuarios preguntan varias veces, y dentro de una peticion la respuesta
     * no cambia.
     *
     * @var array<string, bool>|null
     */
    private ?array $permisosDelRol = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'group_id' => 'integer',
            'dob' => 'date',
            'last_login' => 'datetime',
            'login_try' => 'integer',
        ];
    }

    /** The role of the user. The legacy called it `group_id`. */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'group_id');
    }

    /** The stores the user operates in. The legacy called it `user_stores`. */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'user_stores')->withTimestamps();
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * ¿El usuario es el SUPERUSUARIO?
     *
     * REGLA que fija este metodo: *el superusuario se identifica por el SLUG
     * de su rol, y ese slug es DATO*. La fila del rol se lee de la base y se
     * compara con la configuracion (`auth.superuser_role_slug`), de modo que
     * el codigo no lleva el nombre del rol escrito dentro.
     *
     * Y EL ROL SUPERUSUARIO LO ES SIEMPRE: un superusuario sin rol no es un
     * superusuario, porque sin rol no hay nada que le conceda el poder.
     */
    public function isSuperuser(): bool
    {
        $slug = $this->role?->slug;

        if ($slug === null) {
            return false;
        }

        return $slug === (string) config('auth.superuser_role_slug');
    }

    /**
     * ¿El usuario tiene ESTE permiso?
     *
     * REGLA que fija este metodo: *el permiso es del CATALOGO, y el
     * superusuario lo tiene todos*. Un slug que no esta en el catalogo no
     * existe, asi que nadie lo tiene: un permiso inventado no concede nada.
     *
     * @param  string  $permission  El slug del permiso.
     */
    public function hasPermission(string $permission): bool
    {
        if (! Permission::tryFrom($permission) instanceof Permission) {
            return false;
        }

        if ($this->isSuperuser()) {
            return true;
        }

        return $this->permissionsOfRole()[$permission] ?? false;
    }

    /**
     * Los SLUGS de los permisos del usuario.
     *
     * Es lo que viaja a la interfaz: la UI oculta un boton con el MISMO
     * slug que protege la ruta, y para eso necesita la lista.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->isSuperuser()) {
            return Permission::slugs();
        }

        return array_keys($this->permissionsOfRole());
    }

    /**
     * Los permisos del ROL del usuario, indexados por slug.
     *
     * Se cachean en la instancia: la pantalla del rol y la de permisos
     * preguntan varias veces, y la respuesta no cambia dentro de la misma
     * peticion.
     *
     * @return array<string, bool>
     */
    private function permissionsOfRole(): array
    {
        if ($this->permisosDelRol !== null) {
            return $this->permisosDelRol;
        }

        $slugs = $this->role?->permissions()->pluck('slug')->all() ?? [];

        $permisos = [];

        foreach ($slugs as $slug) {
            $permisos[(string) $slug] = true;
        }

        return $this->permisosDelRol = $permisos;
    }
}
