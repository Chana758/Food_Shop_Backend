<?php

namespace App\Http\Controllers;

use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    /**
     * Display a listing of the tables.
     */
    public function index()
    {
        // Only fetch active, occupied, or reserved tables
        $tables = Table::where('status', '!=', 'inactive')->get();
        return response()->json(['status' => 'success', 'data' => $tables], 200);
    }

    /**
     * Store a newly created table.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255|unique:tables,name',
            'capacity' => 'required|integer|min:1',
            'status'   => 'sometimes|in:available,occupied,reserved,inactive'
        ]);

        $table = Table::create($validated);
        return response()->json(['status' => 'success', 'data' => $table], 201);
    }

    /**
     * Display the specified table.
     */
    public function show($id)
    {
        $table = Table::findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $table], 200);
    }

    /**
     * Update the table details.
     */
    public function update(Request $request, Table $table)
    {
        $validated = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255', Rule::unique('tables')->ignore($table->id)],
            'capacity' => 'sometimes|integer|min:1',
            'status'   => 'sometimes|in:available,occupied,reserved,inactive'
        ]);

        $table->update($validated);
        return response()->json([
            'status' => 'success', 
            'message' => 'Table updated successfully', 
            'data' => $table
        ], 200);
    }

    /**
     * Remove the specified table (Soft Inactivation).  
     */
    public function destroy(Table $table)
    {
        $table->update(['status' => 'inactive']);
        return response()->json(['status' => 'success', 'message' => 'Table has been deactivated'], 200);
    }
}