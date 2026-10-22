<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use LogicException;

final class CurrentProject
{
    private static ?Project $project = null;

    public static function use(Project $project): void
    {
        self::$project = $project;
    }

    public static function get(): Project
    {
        return self::$project ?? throw new LogicException('No project configured: call CurrentProject::use() in castor.php before running a task.');
    }

    public static function reset(): void
    {
        self::$project = null;
    }
}
