<?php

namespace App\Console\Commands;

use App\Platform\PlatformException;
use App\Platform\Services\PlatformOperatorProvisioning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

abstract class PlatformOperatorCommand extends Command
{
    protected string $mode;

    public function handle(PlatformOperatorProvisioning $provisioning): int
    {
        $input = ['operation_id' => $this->option('operation-id'), 'reason' => $this->option('reason'), 'accountability' => $this->option('accountability')];
        $rules = ['operation_id' => 'required|uuid', 'reason' => 'required|string|max:1000', 'accountability' => 'required|string|max:255'];
        if (in_array($this->mode, ['bootstrap', 'provision'], true)) {
            $input += ['name' => $this->option('name') ?? ($this->input->isInteractive() ? $this->ask('Operator name') : null), 'email' => strtolower(trim((string) ($this->option('email') ?? ($this->input->isInteractive() ? $this->ask('Operator email') : ''))))];
            $rules += ['name' => 'required|string|max:255', 'email' => 'required|email|max:255'];
        } else {
            $input['operator_id'] = $this->option('operator-id');
            $rules['operator_id'] = 'required|uuid';
        }
        if ($this->mode === 'deactivate') {
            $input['confirmation'] = $this->option('confirm');
            $rules['confirmation'] = 'required|accepted';
        }
        if (Validator::make($input, $rules)->fails() || ($this->mode === 'recover-mfa' && ! $this->option('identity-verified'))) {
            $this->error('Valid identifiers, reason, accountability and the action-specific confirmation or recovery identity verification are required.');

            return self::FAILURE;
        }
        try {
            $result = $provisioning->execute($this->mode, $input);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = $error instanceof PlatformException ? ' '.$error->errorCode.'.' : '';
            $this->error('Platform operator operation failed.'.$code.' No credentials are printed; retry the same operation ID to reconcile a committed result.');

            return self::FAILURE;
        }
    }
}
