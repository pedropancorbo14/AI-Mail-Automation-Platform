<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Usuarios de la empresa del usuario autenticado. El tenant sale siempre de
 * TenantService (middleware "tenant"), nunca de la petición.
 */
class UserController extends Controller
{
    public function __construct(private readonly TenantService $tenancy)
    {
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

        return new UserResource($user->load('role'));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('role'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->fill($request->safe()->only(['name', 'email', 'password']));

        if ($request->has('role')) {
            $user->role()->associate(Role::where('name', $request->validated('role'))->firstOrFail());
        }

        $user->save();

        return new UserResource($user->load('role'));
    }

    public function destroy(User $user): Response
    {
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
