<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Security\Audit\AuditLogger;
use App\Tenancy\TenantService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Usuarios de la empresa del usuario autenticado. El tenant sale siempre de
 * TenantService (middleware "tenant"), nunca de la petición.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly TenantService $tenancy,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = $this->tenancy->currentOrFail()->users()
            ->with('role')
            ->orderBy('name')
            ->paginate(25);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->tenant()->associate($this->tenancy->currentOrFail());
        $user->role()->associate(Role::where('name', $request->validated('role'))->firstOrFail());
        $user->save();

        $this->audit->record('user.created', $user, $user->name, metadata: ['email' => $user->email, 'role' => $user->role->name]);

        return new UserResource($user->load('role'));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('role'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $previousRole = $user->role?->name;
        $user->fill($request->safe()->only(['name', 'email', 'password']));

        if ($request->has('role')) {
            $user->role()->associate(Role::where('name', $request->validated('role'))->firstOrFail());
        }

        $user->save();

        $changes = AuditLogger::changesOf($user, ['name', 'email', 'password']);

        if ($user->role->name !== $previousRole) {
            $changes['role'] = ['from' => $previousRole, 'to' => $user->role->name];
        }

        // Contraseña cambiada por otra persona (p. ej. cuenta comprometida): fuera
        // sus tokens de API; sus sesiones del panel caen solas (AuthenticateSession).
        if (isset($changes['password'])) {
            $current = $user->is($request->user()) ? $request->user()->currentAccessToken() : null;
            $user->tokens()->when($current instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($current->getKey()))->delete();
        }

        // La contraseña sale como "[oculto]": se sabe que cambió, nunca cuál era ni cuál es.
        if ($changes !== []) {
            $this->audit->record('user.updated', $user, $user->name, $changes);
        }

        return new UserResource($user->load('role'));
    }

    public function destroy(User $user): Response
    {
        Gate::authorize('delete', $user);

        $this->audit->record('user.deleted', $user, $user->name, metadata: ['email' => $user->email, 'role' => $user->role?->name]);

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
