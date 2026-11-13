<?php

namespace Modules\Institution\Http\Controllers\API;

use App\Http\Controllers\BaseController;
use Modules\Institution\Models\Institution;

class InstitutionsController extends BaseController
{
    public function index()
    {
        $institutions = Institution::whereKey(request()->user()->institution_id)->get();

        return $this->sendResponse($institutions, 'Institutions retrieved successfully.');
    }
}
