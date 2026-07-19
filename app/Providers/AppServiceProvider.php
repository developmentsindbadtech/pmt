<?php

namespace App\Providers;

use App\Models\Board;
use App\Models\Sheet;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            if (auth()->check()) {
                $user = auth()->user();
                $query = Board::query();
                
                // Admins see all boards, regular users only see assigned boards
                if (! $user->is_admin) {
                    $query->whereHas('users', function ($q) use ($user) {
                        $q->where('users.id', $user->id);
                    });
                }
                
                $view->with('sidebarBoards', $query->orderBy('name', 'asc')
                    ->limit(30)
                    ->get(['id', 'name']));
                $board = request()->route('board');
                $view->with('currentBoardId', $board ? $board->id : null);

                // Same personal list for everyone (including admins) — avoids sidebar clutter.
                $view->with('sidebarSheets', Sheet::query()
                    ->visibleTo($user)
                    ->orderBy('name', 'asc')
                    ->limit(30)
                    ->get(['id', 'name']));
                $sheet = request()->route('sheet');
                $view->with('currentSheetId', $sheet ? $sheet->id : null);
            } else {
                $view->with('sidebarBoards', collect());
                $view->with('currentBoardId', null);
                $view->with('sidebarSheets', collect());
                $view->with('currentSheetId', null);
            }
        });
    }
}
