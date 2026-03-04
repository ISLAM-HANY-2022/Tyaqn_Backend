<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/php-info', function (Request $request) {
    
    if ($request->query('pass') !== '123456') {
        return abort(403, 'غير مسموح لك بالدخول');
    }
    return phpinfo();
});