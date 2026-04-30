<?php

namespace Modules\Nodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NodesController extends Controller
{
    public function index()
    {
        return view('nodes::index');
    }

    public function create()
    {
        return view('nodes::create');
    }

    public function store(Request $request) {}

    public function show($id)
    {
        return view('nodes::show');
    }

    public function edit($id)
    {
        return view('nodes::edit');
    }

    public function update(Request $request, $id) {}

    public function destroy($id) {}
}
