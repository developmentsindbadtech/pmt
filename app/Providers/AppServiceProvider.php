<?php

namespace App\Providers;

use App\Models\Board;
use App\Models\Sheet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Sidebar lists are cached per user. Call this when boards, sheets, or access change.
     */
    public static function bustNavCache(): void
    {
        $current = (int) Cache::get('pmt.nav.version', 1);
        Cache::forever('pmt.nav.version', $current + 1);
    }

    public function boot(): void
    {
        if (config('database.default') === 'sqlite') {
            try {
                DB::statement('PRAGMA journal_mode = WAL');
                DB::statement('PRAGMA busy_timeout = 5000');
                DB::statement('PRAGMA synchronous = NORMAL');
            } catch (\Throwable) {
                // The connection may not be open yet during some artisan commands.
            }
        }

        Board::saved(fn () => self::bustNavCache());
        Board::deleted(fn () => self::bustNavCache());
        Sheet::saved(fn () => self::bustNavCache());
        Sheet::deleted(fn () => self::bustNavCache());

        View::composer('layouts.app', function ($view) {
            if (auth()->check()) {
                $user = auth()->user();
                $version = (int) Cache::get('pmt.nav.version', 1);

                $view->with('sidebarBoards', Cache::remember(
                    "pmt.nav.boards.{$user->id}.{$version}",
                    3600,
                    function () use ($user) {
                        $query = Board::query();
                        if (! $user->is_admin) {
                            $query->whereHas('users', function ($q) use ($user) {
                                $q->where('users.id', $user->id);
                            });
                        }

                        return $query->orderBy('name', 'asc')->limit(30)->get(['id', 'name']);
                    }
                ));
                $board = request()->route('board');
                $view->with('currentBoardId', $board ? $board->id : null);

                $view->with('sidebarSheets', Cache::remember(
                    "pmt.nav.sheets.{$user->id}.{$version}",
                    3600,
                    function () use ($user) {
                        return Sheet::query()
                            ->visibleTo($user)
                            ->orderBy('name', 'asc')
                            ->limit(30)
                            ->get(['id', 'name']);
                    }
                ));
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
