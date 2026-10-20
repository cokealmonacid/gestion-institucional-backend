<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Http\PlatformInput;
use App\Platform\Http\Resources\InstitutionUserResource;
use App\Platform\Services\CommercialAdministration;
use App\Platform\Services\OperationalAdministration;
use Illuminate\Http\Request;
use Modules\Institution\Models\Institution;

class OperationalController extends Controller
{
    public function __construct(private OperationalAdministration $operations, private CommercialAdministration $commercial) {}

    private function response(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Platform administration request completed.'], $status);
    }

    public function quotas(Request $r, string $id)
    {
        PlatformInput::validate($r, []);

        return $this->response($this->operations->quotas($r->user(), $id));
    }

    public function suspension(Request $r, string $id)
    {
        return $this->transition($r, $id, false);
    }

    public function reactivation(Request $r, string $id)
    {
        return $this->transition($r, $id, true);
    }

    private function transition(Request $r, string $id, bool $enabled)
    {
        $data = PlatformInput::validate($r, ['expected_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:1000']);

        return $this->response($this->commercial->institutionData($this->operations->status($r->user(), $id, $enabled, $data)));
    }

    public function administrator(Request $r, string $id, string $userId)
    {
        PlatformInput::validate($r, []);

        return $this->response($this->operations->administratorStatus($r->user(), $id, $userId));
    }

    public function administrators(Request $r, string $id)
    {
        $d = PlatformInput::validate($r, ['q' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        Institution::findOrFail($id);
        $query = User::select(['id', 'name', 'email', 'active', 'email_verified_at', 'access_revision'])
            ->where('institution_id', $id)->whereHas('roles', fn ($q) => $q->where('type', 'admin'));
        if (isset($d['q'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$d['q'].'%')->orWhere('email', 'like', '%'.$d['q'].'%'));
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($d['per_page'] ?? 15);

        return $this->response(['items' => $page->getCollection()->map(fn ($user) => [
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'active' => (bool) $user->active, 'activation_status' => $user->email_verified_at ? 'activated' : 'pending_activation',
            'access_revision' => (int) $user->access_revision,
        ])->values()->all(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function users(Request $r)
    {
        $d = PlatformInput::validate($r, ['q' => 'sometimes|string|max:100', 'institution_id' => 'sometimes|uuid', 'user_id' => 'sometimes|uuid',
            'role' => 'sometimes|in:admin,editor,reader,none', 'active' => 'sometimes|in:true,false',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = User::select(['id', 'name', 'email', 'institution_id', 'active', 'email_verified_at', 'access_revision'])
            ->with(['institution:id,name', 'roles', 'invitation' => fn ($q) => $q->select(['id', 'user_id', 'delivery_status', 'expires_at', 'revoked_at', 'consumed_at'])]);
        if (isset($d['q'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$d['q'].'%')->orWhere('email', 'like', '%'.$d['q'].'%'));
        }
        if (isset($d['institution_id'])) {
            $query->where('institution_id', $d['institution_id']);
        }
        if (isset($d['user_id'])) {
            $query->whereKey($d['user_id']);
        }
        if (isset($d['role'])) {
            $d['role'] === 'none' ? $query->whereDoesntHave('roles') : $query->whereHas('roles', fn ($q) => $q->where('type', $d['role']));
        }
        if (isset($d['active'])) {
            $query->where('active', $d['active'] === 'true');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate($d['per_page'] ?? 15);

        return $this->response(['items' => InstitutionUserResource::collection($page->getCollection())->resolve($r),
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function resend(Request $r, string $id, string $userId)
    {
        $data = PlatformInput::validate($r, ['reason' => 'required|string|max:1000']);

        return $this->response($this->operations->resendAdministrator($r->user(), $id, $userId, $data), 202);
    }

    public function reactivateAdministrator(Request $r, string $id, string $userId)
    {
        $data = PlatformInput::validate($r, ['expected_user_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:1000']);

        return $this->response($this->operations->reactivateAdministrator($r->user(), $id, $userId, $data));
    }
}
