<?php

namespace App\Providers;

use App\Models\Board;
use App\Models\Sheet;
use App\Services\NavList;
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

                $boardNav = Cache::remember(
                    "pmt.nav.boards.v2.{$user->id}.{$version}",
                    3600,
                    fn () => NavList::boards($user)
                );
                $view->with('sidebarBoards', $boardNav['items']);
                $view->with('hiddenBoardCount', $boardNav['hidden_count']);
                $board = request()->route('board');
                $view->with('currentBoardId', $board ? $board->id : null);

                $sheetNav = Cache::remember(
                    "pmt.nav.sheets.v2.{$user->id}.{$version}",
                    3600,
                    fn () => NavList::sheets($user)
                );
                $view->with('sidebarSheets', $sheetNav['items']);
                $view->with('hiddenSheetCount', $sheetNav['hidden_count']);
                $sheet = request()->route('sheet');
                $view->with('currentSheetId', $sheet ? $sheet->id : null);
            } else {
                $view->with('sidebarBoards', collect());
                $view->with('hiddenBoardCount', 0);
                $view->with('currentBoardId', null);
                $view->with('sidebarSheets', collect());
                $view->with('hiddenSheetCount', 0);
                $view->with('currentSheetId', null);
            }
        });
    }
}
