<?php

use Illuminate\Support\Facades\Route;
use LogInsight\Http\LoginController;

// Only meaningful in "env" auth mode; the controller 404s otherwise.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// Language switch must work on the login page too.
Route::get('/locale/{lang}', \LogInsight\Http\LocaleController::class)->whereIn('lang', ['en', 'ar'])->name('locale');
