#!/bin/php
<?php
/**
 * Trashman - a simple delayed files removal utility.
 * Copyright (C) 2014  Simon Robert
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use trashman\TrashmanApplication;
define('ROOT_DIR', __DIR__);

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->set('trashman\\', array(ROOT_DIR));

$application = new TrashmanApplication();
$application->run();
