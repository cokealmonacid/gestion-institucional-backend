<?php

namespace App\Platform\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Institution\Models\Institution;

class PlatformReadiness
{
    public function inspect(): array
    {
        $required = ['platform_access_recoveries' => ['kind', 'state', 'verification_hash', 'reserved_email', 'result'],
            'document_responsible_histories' => ['actor_platform_operator_id', 'actor_platform_operator_name'],
            'document_events' => ['actor_platform_operator_id', 'actor_platform_operator_name'],
            'institutions' => ['status', 'operational_revision', 'auth_epoch', 'commercial_state'],
            'users' => ['institution_id', 'active', 'deleted_at', 'email_verified_at', 'password', 'access_revision'],
            'role_user' => ['user_id', 'role_id'], 'roles' => ['type'],
            'platform_operations' => ['operator_id', 'operation_type', 'idempotency_key', 'fingerprint', 'result', 'committed_at'],
            'user_invitations' => ['generation', 'revoked_at', 'institution_auth_epoch'],
            'personal_access_tokens' => ['institution_auth_epoch', 'platform_auth_epoch', 'platform_mfa_verified_at'],
            'institution_contracts' => ['current_revision_id'], 'institution_contract_revisions' => ['conditions'],
            'administrative_events' => ['operation_id'], 'sessions' => ['user_id']];
        $missing = [];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                $missing[] = $table;
            }
        }
        $driver = DB::getDriverName();
        $mailer = config('mail.mailers.'.config('mail.default'), []);
        $resendKey = $mailer['key'] ?? config('services.resend.key');
        $config = [
            'php_minimum' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'debug_disabled' => ! config('app.debug'),
            'supported_mysql_driver' => $driver === 'mysql',
            'database_sessions_on_primary_connection' => config('session.driver') === 'database' && in_array(config('session.connection'), [null, config('database.default')], true) && config('session.table') === 'sessions',
            'shared_limiter_store_selected' => in_array(config('cache.default'), ['database', 'redis', 'memcached'], true),
            'asynchronous_queue_selected' => in_array(config('queue.default'), ['database', 'redis', 'sqs', 'beanstalkd'], true),
            'non_test_mailer_selected' => ! in_array(config('mail.default'), [null, 'array', 'log'], true),
            'resend_sdk_and_key_configured' => ($mailer['transport'] ?? null) !== 'resend'
                || (class_exists(\Resend::class) && is_string($resendKey) && trim($resendKey) !== ''),
            'secure_session_cookie' => config('session.secure') === true,
            'trusted_https_origins' => PlatformAccessOrigin::allowed(config('platform.frontend_url')) && $this->httpsOrigin(config('user-access.frontend_url')),
        ];
        $report = ['scope' => 'current_process_configuration_and_database_only', 'php_version' => PHP_VERSION, 'database_driver' => $driver,
            'missing_schema' => $missing, 'local_configuration_checks' => $config,
            'ready_for_production_activation' => false,
            'deployment_verification_required' => ['disposable_mysql_migration_and_concurrency_rehearsal', 'production_php_extensions_and_mysql_innodb', 'shared_limiter_and_supervised_encrypted_queue', 'private_storage_and_signed_file_serving', 'real_provider_delivery_and_trusted_https_origins', 'operations_identity_verification_and_persistent_encryption_key', 'frontend_contract_adoption_and_credential_cutover', 'compatibility_endpoint_retirement_requires_separate_authorization']];
        if ($missing !== []) {
            return $report + ['data_preflight_passed' => false, 'data_findings' => null];
        }
        $constraints = [];
        foreach (['users' => ['email'], 'roles' => ['type'], 'role_user' => ['user_id', 'role_id'],
            'platform_operations' => ['operator_id', 'operation_type', 'idempotency_key'],
            'institution_contracts' => ['institution_id'], 'institution_contract_revisions' => ['contract_id', 'revision']] as $table => $columns) {
            $constraints[$table] = Schema::hasIndex($table, $columns, 'unique');
        }
        $report['unique_constraints'] = $constraints;
        $roles = DB::table('roles')->select('type')->selectRaw('COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');
        $roleIssues = 0;
        foreach (['admin', 'editor', 'reader'] as $role) {
            if ((int) ($roles[$role] ?? 0) !== 1) {
                $roleIssues++;
            }
        }
        $roleIssues += $roles->keys()->diff(['admin', 'editor', 'reader'])->count();
        $emailGroups = [];
        $noncanonical = 0;
        // No email, RUT, password, token, host or configured secret is returned or logged.
        DB::table('users')->select(['id', 'email'])->orderBy('id')->chunkById(500, function ($users) use (&$emailGroups, &$noncanonical) {
            foreach ($users as $user) {
                $normalized = Str::lower(trim($user->email));
                $noncanonical += (int) ($normalized !== $user->email);
                $key = hash('sha256', $normalized);
                $emailGroups[$key] = ($emailGroups[$key] ?? 0) + 1;
            }
        }, 'id');
        $findings = ['role_definition_issues' => $roleIssues,
            'multiple_role_users' => DB::query()->fromSub(DB::table('role_user')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(DISTINCT role_id) > 1'), 'multiple_roles')->count(),
            'orphan_users' => DB::table('users')->leftJoin('institutions', 'institutions.id', '=', 'users.institution_id')->whereNull('institutions.id')->count(),
            'orphan_role_memberships' => DB::table('role_user')->leftJoin('users', 'users.id', '=', 'role_user.user_id')->leftJoin('roles', 'roles.id', '=', 'role_user.role_id')->where(fn ($q) => $q->whereNull('users.id')->orWhereNull('roles.id'))->count(),
            'duplicate_role_memberships' => DB::query()->fromSub(DB::table('role_user')->select('user_id', 'role_id')->groupBy('user_id', 'role_id')->havingRaw('COUNT(*) > 1'), 'duplicates')->count(),
            'noncanonical_user_emails' => $noncanonical, 'normalized_email_collision_groups' => count(array_filter($emailGroups, fn ($count) => $count > 1)),
            'institutions_without_usable_admin' => Institution::whereDoesntHave('users', fn ($q) => $q->where('active', true)->whereNotNull('email_verified_at')->whereNotNull('password')->where('password', '!=', '')->whereHas('roles', fn ($r) => $r->where('type', 'admin')))->count(),
            'unknown_institution_statuses' => DB::table('institutions')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', [0, 1]))->count()];

        return $report + ['data_findings' => $findings, 'data_preflight_passed' => array_sum($findings) === 0 && ! in_array(false, $constraints, true)];
    }

    private function httpsOrigin(?string $url): bool
    {
        $parts = $url ? parse_url($url) : false;

        return $parts && ($parts['scheme'] ?? null) === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
