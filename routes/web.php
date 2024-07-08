<?php

use App\Http\Controllers\TelegramBotController;
use Illuminate\Support\Facades\Route;

Route::get('/', function() {
    return redirect()->away(config('uitmtimetable.powered_by_url'));
    // return view('index');
});
