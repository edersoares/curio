<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Http\Controllers\AuthorPaginateController;
use Dex\Laravel\Curio\Workbench\App\Http\Controllers\CommentPaginateController;
use Dex\Laravel\Curio\Workbench\App\Http\Controllers\PostPaginateController;
use Dex\Laravel\Curio\Workbench\App\Http\Controllers\UserPaginateController;
use Dex\Laravel\Curio\Workbench\App\Http\Middleware\AcceptJson;

Route::group([
    'prefix' => 'api',
    'middleware' => [AcceptJson::class],
], function () {
    Route::get('author', AuthorPaginateController::class);
    Route::get('comment', CommentPaginateController::class);
    Route::get('post', PostPaginateController::class);
    Route::get('user', UserPaginateController::class);
});
