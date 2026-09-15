<?php
define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 4)); // remonte jusqu'à la racine Joomla
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

$app = Factory::getApplication('site');
$app->initialise();

$home = Route::_('index.php');
echo '
<div style="display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;">
    <div style="background:#fff;padding:30px;border-radius:12px;text-align:center;">
        <h2>✅ Paiement réussi</h2>
        <a href="' . $home . '">Retour à l\'accueil</a>
    </div>
</div>';
$app->close();


