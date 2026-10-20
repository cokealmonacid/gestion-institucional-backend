<?php

declare(strict_types=1);
use App\Jobs\SendUserInvitation;
use App\Models\User;
use App\Platform\Models\PlatformOperator;
use App\Platform\PlatformException;
use App\Platform\Services\CommercialAdministration;
use App\Platform\Services\CustomerOnboarding;
use App\Platform\Services\InstitutionAccessRecovery;
use App\Platform\Services\InstitutionalUserAdministration;
use App\Platform\Services\OperationalAdministration;
use App\Platform\Services\PlatformAccessLinks;
use App\Platform\Services\PlatformAuthentication;
use App\Platform\Services\PlatformOperatorProvisioning;
use App\Services\UserAccess\InvitationService;
use App\Services\UserAccess\PasswordRecoveryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Documents\Actions\UpdateDocumentAction;
use Modules\Documents\Actions\UpdateDocumentResponsibilityAction;
use Modules\Documents\Exceptions\DocumentResponsibilityException;
use Modules\Documents\Services\DocumentEventRecorder;
use Modules\Documents\Services\DocumentResponsibilityWriter;
use Modules\Institution\Actions\InstitutionUserMutations;
use Modules\Institution\Actions\InviteInstitutionUser;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Modules\Institution\Exceptions\RoleChangeException;
use Modules\Institution\Services\RoleChangeImpact;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap-mysql.php';
[$script,$operation,$payloadPath,$ready,$go,$started,$output] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-testing');
$app->make(Kernel::class)->bootstrap();
config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync']);
Bus::fake();
if ($operation === 'tenant_invite_snapshot') {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::beginTransaction();
    User::count(); // Establish REPEATABLE READ before the competing admission.
}
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($go)) {
    if (microtime(true) > $deadline) {
        exit(2);
    } usleep(10000);
}
file_put_contents($started, 'started');
try {
    if ($operation === 'customer_creation') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $created = $app->make(CustomerOnboarding::class)->create($actor, $payload['key'], $payload['data'], $payload['institution'] ?? null);
        $extra = ['result' => $created['result'], 'replayed' => $created['replayed'], 'publications' => Bus::dispatched(SendUserInvitation::class)->count()];
    } elseif (str_starts_with($operation, 'tenant_')) {
        if ($operation === 'tenant_login') {
            $request = Request::create('/api/v1/auth/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload['credentials'], JSON_THROW_ON_ERROR));
            $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
            if (! $response->isSuccessful()) {
                throw new InstitutionAccessException(json_decode($response->getContent(), true)['error']['code'], $response->getStatusCode());
            }
        } elseif ($operation === 'tenant_activate') {
            if (! $app->make(InvitationService::class)->activate($payload['credentials'])) {
                throw new InstitutionAccessException('LINK_UNAVAILABLE');
            }
        } elseif ($operation === 'tenant_reset') {
            if (! $app->make(PasswordRecoveryService::class)->reset($payload['credentials'])) {
                throw new InstitutionAccessException('LINK_UNAVAILABLE');
            }
        } else {
            $actor = User::findOrFail($payload['actor']);
            $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
            if (in_array($operation, ['tenant_invite', 'tenant_invite_snapshot'], true)) {
                $app->make(InviteInstitutionUser::class)->execute($actor, $payload['data'], $app->make(InvitationService::class));
            } elseif ($operation === 'tenant_update') {
                $app->make(InstitutionUserMutations::class)->update($actor, $payload['email'], $payload['data']);
            } elseif ($operation === 'tenant_document') {
                $app->make(UpdateDocumentAction::class)->execute($actor, $payload['document'], ['description' => 'Changed under lock'], $app->make(DocumentEventRecorder::class));
            } elseif ($operation === 'tenant_responsibility') {
                $app->make(UpdateDocumentResponsibilityAction::class)->execute($actor, $payload['document'], $payload['responsible'], $payload['revision'],
                    $app->make(DocumentEventRecorder::class), $app->make(RoleChangeImpact::class), $app->make(DocumentResponsibilityWriter::class));
            } elseif ($operation === 'tenant_password') {
                $request = Request::create('/api/v1/user/update-password', 'PATCH', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$payload['bearer'], 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload['credentials'], JSON_THROW_ON_ERROR));
                $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
                if (! $response->isSuccessful()) {
                    throw new InstitutionAccessException('ACCESS_UNAVAILABLE');
                }
            } else {
                throw new LogicException('Unknown tenant operation.');
            }
        }
        if ($operation === 'tenant_invite_snapshot') {
            DB::commit();
        }
    } elseif (in_array($operation, ['platform_role', 'platform_access'], true)) {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(InstitutionalUserAdministration::class)->execute($actor, $payload['institution'], $payload['user'], $operation === 'platform_role' ? 'role' : 'access', $payload['data']);
    } elseif ($operation === 'recovery_complete') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(InstitutionAccessRecovery::class)->transition($actor, $payload['id'], 'complete', $payload['revision']);
    } elseif ($operation === 'recovery_prepare') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(InstitutionAccessRecovery::class)->prepare($actor, $payload['id'], $payload['data']);
    } elseif ($operation === 'recovery_verify') {
        $app->make(InstitutionAccessRecovery::class)->verify($payload['id'], $payload['proof'], []);
    } elseif ($operation === 'reactivate_admin') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(OperationalAdministration::class)->reactivateAdministrator($actor, $payload['institution'], $payload['user'], $payload['data']);
    } elseif ($operation === 'suspend') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(OperationalAdministration::class)->status($actor, $payload['institution'], false, $payload['data']);
    } elseif ($operation === 'proof') {
        $app->make(PlatformAuthentication::class)->prove($payload, 'challenge');
    } elseif ($operation === 'operator_deactivate') {
        $extra = $app->make(PlatformOperatorProvisioning::class)->execute('deactivate', $payload);
    } elseif ($operation === 'operator_link_consume') {
        $app->make(PlatformAccessLinks::class)->consume($payload['purpose'], $payload['data']);
    } elseif ($operation === 'bootstrap') {
        $app->make(PlatformOperatorProvisioning::class)->execute('bootstrap', $payload);
    } elseif ($operation === 'plan') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $token = PersonalAccessToken::findToken($payload['bearer']);
        $actor->withAccessToken($token);
        $app->make(CommercialAdministration::class)->plan($actor, $payload['data'], $payload['plan']);
    } elseif ($operation === 'contract') {
        $actor = PlatformOperator::findOrFail($payload['actor']);
        $actor->withAccessToken(PersonalAccessToken::findToken($payload['bearer']));
        $app->make(CommercialAdministration::class)->revise($actor, $payload['institution'], $payload['data']);
    } else {
        throw new LogicException('Unknown test operation.');
    }
    $result = ['completed' => true] + ($extra ?? []);
} catch (PlatformException|InstitutionAccessException|RoleChangeException|DocumentResponsibilityException $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result = ['completed' => false, 'code' => $e->errorCode];
} catch (Throwable) {
    $result = ['completed' => false, 'code' => 'UNEXPECTED_TEST_FAILURE'];
}
file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR));
