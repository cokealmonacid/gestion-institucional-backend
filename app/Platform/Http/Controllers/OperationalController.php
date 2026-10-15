<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Http\PlatformInput;
use App\Platform\Services\CommercialAdministration;
use App\Platform\Services\OperationalAdministration;
use Illuminate\Http\Request;

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
