<?php

declare(strict_types=1);

use Flute\Core\Router\Router;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\OverviewScreen;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\PlayerCardScreen;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\PlayersScreen;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\ServersScreen;
use Flute\Modules\ConnectHistory\Admin\Package\Screens\SessionsScreen;

Router::screen('/admin/connect-history', OverviewScreen::class);
Router::screen('/admin/connect-history/sessions', SessionsScreen::class);
Router::screen('/admin/connect-history/players', PlayersScreen::class);
Router::screen('/admin/connect-history/servers', ServersScreen::class);

// steamid64 остаётся строкой на всём пути: 64-битное число теряет точность
// в PHP int на 32-битной сборке.
Router::screen('/admin/connect-history/player/{steamid64}', PlayerCardScreen::class);
