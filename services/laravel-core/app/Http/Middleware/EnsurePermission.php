<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $organizerId = $request->route('organizer')
            ?? $request->header('X-Organizer-Id')
            ?? $user->organizer_id;

        if (! $organizerId) {
            return response()->json([
                'error' => 'no_organizer_context',
                'message' => 'You are not associated with any organizer.',
            ], 403);
        }

        $membership = $user->memberships()
            ->where('organizer_id', $organizerId)
            ->first();

        if (! $membership) {
            return response()->json([
                'error' => 'not_a_member',
                'message' => 'You do not have access to this organizer.',
            ], 403);
        }

        // Already an OrganizerRole thanks to the model cast.
        $role = $membership->role;
        $requestedPermissions = array_map(
            fn (string $p) => Permission::from($p),
            $required,
        );

        $granted = false;
        foreach ($requestedPermissions as $perm) {
            if ($role->has($perm)) {
                $granted = true;
                break;
            }
        }

        if (! $granted) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'You do not have permission to perform this action.',
                'required' => $required,
            ], 403);
        }

        $request->attributes->set('organizer_membership', $membership);

        return $next($request);
    }
}