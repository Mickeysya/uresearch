<?php

use Illuminate\Support\Facades\Route;

/*
| Application routes live with the module that owns them:
|
|   app/Modules/Core/routes.php       auth, dashboard, tracking, documents
|   app/Modules/<Name>/routes.php     that person's own screens
|
| ModuleServiceProvider loads each of those automatically, so you never edit
| this file to add a feature.
*/

Route::redirect('/', '/dashboard');
