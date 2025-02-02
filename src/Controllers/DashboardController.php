<?php

namespace Rias\StatamicRedirect\Controllers;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Rias\StatamicRedirect\Data\Error;
use Rias\StatamicRedirect\Facades\Error as ErrorFacade;
use Statamic\Facades\Scope;

class DashboardController
{
    public function __invoke()
    {
        abort_unless(auth()->user()->isSuper() || auth()->user()->hasPermission('view redirects'), 401);

        $hits = cache()->remember('dashboard-hits', now()->addMinutes(10), function () {
            return ErrorFacade::all()->flatMap(function (Error $error) {
                return array_map(function (array $hit) {
                    return $hit['timestamp'];
                }, $error->hits() ?? []);
            });
        });

        $notFoundMonth = cache()->remember('dashboard-getStatsPastMonth', now()->addMinutes(10), function () use ($hits) {
            return $this->getStatsPastMonth($hits);
        });
        $notFoundWeek = cache()->remember('dashboard-getStatsPastWeek', now()->addMinutes(10), function () use ($hits) {
            return $this->getStatsPastWeek($hits);
        });
        $notFoundDay = cache()->remember('dashboard-getStatsPastDay', now()->addMinutes(10), function () use ($hits) {
            return $this->getStatsPastDay($hits);
        });

        $cleanupLastRanAt = null;

        try {
            $cleanupLastRanAt = File::get(storage_path('redirect/clean_last_ran_at.txt'));
        } catch (Exception $e) {
            // Do nothing
        }

        return view('redirect::index', [
            'notFoundMonth' => $notFoundMonth,
            'notFoundWeek' => $notFoundWeek,
            'notFoundDay' => $notFoundDay,
            'filters' => Scope::filters('errors'),
            'cleanupLastRanAt' => $cleanupLastRanAt,
        ]);
    }

    private function getStatsPastMonth(Collection $hits)
    {
        $days = [];
        for ($day = now()->subWeeks(4); $day < now(); $day->addWeek()) {
            $days[] = $day->copy();
        }

        $notFoundMonth = [];
        foreach ($days as $day) {
            $count = $hits->filter(function (int $timestamp) use ($day) {
                return Date::parse($timestamp)->isSameWeek($day);
            })->count();
            $notFoundMonth[] = [$count, "{$day->startOfWeek()->format('d')}-{$day->endOfWeek()->format('d')}"];
        }

        return $notFoundMonth;
    }

    private function getStatsPastWeek(Collection $hits)
    {
        $days = [];
        for ($day = now()->subWeek(); $day < now(); $day->addDay()) {
            $days[] = $day->copy();
        }

        $notFoundWeek = [];
        foreach ($days as $day) {
            $count = $hits->filter(function (int $timestamp) use ($day) {
                $date = Date::parse($timestamp);

                return $date->isSameYear($day) && $date->isSameMonth($day) && $date->isSameDay($day);
            })->count();
            $notFoundWeek[] = [$count, $day->format('d')];
        }

        return $notFoundWeek;
    }

    private function getStatsPastDay(Collection $hits)
    {
        $hours = [];
        for ($hour = now()->subDay(); $hour < now(); $hour->addHours(4)) {
            $hours[] = $hour->copy();
        }

        $notFoundDay = [];
        foreach ($hours as $hour) {
            $count = $hits->filter(function (int $timestamp) use ($hour) {
                return Date::parse($timestamp)->isBetween($hour->copy()->subHour(), $hour->copy()->addHours(3));
            })->count();
            $notFoundDay[] = [$count, $hour->format('H:00')];
        }

        return $notFoundDay;
    }
}
