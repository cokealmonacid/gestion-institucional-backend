<?php

namespace Modules\Institution\Http\Controllers\API;

use Modules\Institution\Models\Institution;
use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;

class InstitutionsController extends BaseController
{
    public function index()
    {
        $institutions = Institution::all();

        return $this->sendResponse($institutions, 'Institutions retrieved successfully.');
    }
}
