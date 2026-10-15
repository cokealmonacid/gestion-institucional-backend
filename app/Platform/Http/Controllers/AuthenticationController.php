<?php

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Http\PlatformInput;
use App\Platform\Services\AdministrativeEventRecorder;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticationController extends Controller
{
    public function __construct(private PlatformAuthentication $auth, private PlatformAccessLinks $links) {}

    private function response(?array $data = null, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'Platform authentication request completed.'], $status)->header('Cache-Control', 'no-store');
    }

    public function login(Request $r)
    {
        $d = PlatformInput::validate($r, ['email' => 'required|string|email|max:255', 'password' => 'required|string']);

        return $this->response($this->auth->login($d['email'], $d['password']), 202);
    }

    private function proof(Request $r, string $operation): array
    {
        $rules = ['challenge_id' => 'required|uuid', 'challenge_token' => 'required|string|max:128'];
        if ($operation === 'confirmation') {
            $rules['code'] = 'required|string|regex:/^\d{6}$/D';
        }
        if ($operation === 'challenge') {
            $rules += ['code' => 'required_without:recovery_code|prohibits:recovery_code|string|regex:/^\d{6}$/D', 'recovery_code' => 'required_without:code|prohibits:code|string|max:64'];
        }

        return $this->auth->prove(PlatformInput::validate($r, $rules), $operation);
    }

    public function enrollment(Request $r)
    {
        return $this->response($this->proof($r, 'enrollment'));
    }

    public function confirmation(Request $r)
    {
        return $this->response($this->proof($r, 'confirmation'));
    }

    public function challenge(Request $r)
    {
        $data = $this->proof($r, 'challenge');

        return $this->response($data, isset($data['next_step']) ? 202 : 200);
    }

    private function consume(Request $r, string $purpose)
    {
        $d = PlatformInput::validate($r, ['email' => 'required|string|email|max:255', 'token' => 'required|string|max:128', 'password' => PlatformInput::password(), 'password_confirmation' => 'required|string']);
        $this->links->consume($purpose, $d);

        return $this->response();
    }

    public function activation(Request $r)
    {
        return $this->consume($r, 'activation');
    }

    public function reset(Request $r)
    {
        return $this->consume($r, 'password_reset');
    }

    public function recovery(Request $r)
    {
        $d = PlatformInput::validate($r, ['email' => 'required|string|email|max:255']);
        $this->links->request($d['email']);

        return response()->json(['success' => true, 'data' => null, 'message' => 'If the account is eligible, recovery instructions will be sent.'], 202);
    }

    public function password(Request $r)
    {
        $d = PlatformInput::validate($r, ['current_password' => 'required|string', 'password' => array_merge(PlatformInput::password(), ['different:current_password']), 'password_confirmation' => 'required|string']);
        $this->auth->changePassword($r->user(), $d);

        return $this->response();
    }

    public function codes(Request $r)
    {
        $d = PlatformInput::validate($r, ['current_password' => 'required|string', 'code' => 'required|string|regex:/^\d{6}$/D']);

        return $this->response($this->auth->regenerate($r->user(), $d));
    }

    public function profile(Request $r)
    {
        PlatformInput::validate($r, []);

        return $this->response(['operator' => $r->user()->summary()]);
    }

    public function logout(Request $r, AdministrativeEventRecorder $audit)
    {
        PlatformInput::validate($r, []);
        DB::transaction(function () use ($r, $audit) {
            $actor = $this->auth->authorized($r->user());
            $r->user()->currentAccessToken()->delete();
            $audit->record($actor, 'auth.logout', 'platform_operator', $actor->id, ['session_active' => true], ['session_active' => false]);
        }, 3);

        return $this->response();
    }
}
