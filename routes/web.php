<?php

use Illuminate\Support\Facades\Route;
use LogInsight\Livewire\AuditViewer;
use LogInsight\Livewire\Dashboard;
use LogInsight\Livewire\IssueList;
use LogInsight\Livewire\LogBrowser;
use LogInsight\Livewire\TagManager;
use LogInsight\Security\AuthorizeAccess;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/logs', LogBrowser::class)->name('logs');
Route::get('/issues', IssueList::class)->name('issues');
Route::get('/tags', TagManager::class)->name('tags')->middleware(AuthorizeAccess::class.':manage');
Route::get('/audit', AuditViewer::class)->name('audit')->middleware(AuthorizeAccess::class.':audit');
