<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;

class AdminController extends Controller
{
    public function index() {
        // ទាញយក Order ទាំងអស់មកឱ្យ Admin មើល (រួមទាំងរូប Receipt ផង)
        return Order::with('user')->latest()->get();
    }
}
