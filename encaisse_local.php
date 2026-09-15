<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Router\Route;


class plgHikashoppaymentEncaisse extends hikashopPaymentPlugin
{
    public $name = 'encaisse';
    public $plugin_name = 'encaisse';
    public $plugin_type = 'payment';
    public $multiple = true;
    private $baseUrl = 'https://api.sandbox.encaisse.net';

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->plugin_name = 'encaisse';
    }

    /**
     * AFFICHAGE DANS CHECKOUT
     */
    public function onPaymentDisplay(&$order, &$methods, &$usable_methods)
    {
        // Récupération du document Joomla
        // Permet d'ajouter CSS et JS
        $doc = Factory::getDocument();

        // Variable statique pour éviter de charger plusieurs fois
        // les fichiers CSS/JS lorsque HikaShop appelle plusieurs fois la fonction
        static $assetsLoaded = false;

        if (!$assetsLoaded) {
            // Chargement du CSS du plugin
            $doc->addStyleSheet(
                Uri::root() . 'plugins/hikashoppayment/encaisse/assets/css/style.css'
            );
            // Chargement du Javascript du plugin
            $doc->addScript(
                Uri::root() . 'plugins/hikashoppayment/encaisse/assets/js/encaisse.js'
            );
            $assetsLoaded = true;
        }

        // Parcours de toutes les méthodes de paiement
        foreach ($methods as $method) {
            // Vérifie qu'on est bien sur le plugin Encaisse
            if ($method->payment_type != $this->plugin_name) {
                continue;
            }
            // Rend la méthode disponible dans le checkout
            $usable_methods[] = $method;
            // Tableau qui servira à classer les partenaires
            $partnersByType = [
                'api' => [],
                'pdv' => [],
                'cash' => []
            ];

            // Lecture du paramètre Simulation 1 = simulation 0 = API réelle
            $simulation = (int) $this->params->get('simulation', 1);

            // Variable qui recevra la liste des partenaires
            $partners = [];

            /* MODE SIMULATION */
            if ($simulation) {
                // Retourne Wave, Orange, Orabank...
                $partners = $this->getPaymentPartners();
            } else {
                /* MODE API REELLE */
                // Génération du token Encaisse
                $auth = $this->getEncaisseToken(
                    $this->params->get('client_id'),
                    $this->params->get('client_secret')
                );
                // var_dump($auth);

                // Si le token existe
                if (!empty($auth['connect_token'])) {
                    // Appel API partenaires
                    $partners = $this->getPaymentPartners(
                        $auth['connect_token']
                    );
                }
            }

            /* CLASSEMENT DES PARTENAIRES */
            if (is_array($partners)) {
                foreach ($partners as $partner) {
                    // api / pdv / cash
                    $type = $partner['type'] ?? 'api';
                    if (isset($partnersByType[$type])) {
                        $partnersByType[$type][] = $partner;
                    }
                }
            }
            /* CONSTRUCTION DU HTML */
            $html = '';
            // Boîte cachée par défaut
            // Le JS l'affichera lorsque Encaisse sera sélectionné
            $html .= '
            <div class="encaisse-box" style="display:none;">
                <div class="encaisse-title">
                    Choisissez un moyen de paiement
                </div>
            ';
            /* VERIFICATION SI DES PARTENAIRES EXISTENT */
            $hasPartners = false;
            foreach ($partnersByType as $list) {
                if (!empty($list)) {
                    $hasPartners = true;
                    break;
                }
            }
            /* AUCUN PARTENAIRE TROUVE */
            if (!$hasPartners) {
                $html .= '
                <div style="
                    background:#ffe5e5;
                    border:1px solid #ff4d4d;
                    color:#cc0000;
                    padding:15px;
                    margin-top:15px;
                    border-radius:8px;
                    text-align:center;
                    font-weight:bold;
                ">
                    Une erreur est survenue lors du chargement des moyens de paiement.
                    <br><br>
                    Veuillez patienter et réessayer plus tard.
                </div>';
            }
            /* AFFICHAGE DES PARTENAIRES */
            foreach ($partnersByType as $type => $list) {
                if (empty($list)) {
                    continue;
                }
                $html .= '
                <div class="encaisse-section">
                    <h4 style="margin-top:20px;">
                        ' . strtoupper($type) . '
                    </h4>
                    <div class="encaisse-grid">
                ';
                foreach ($list as $partner) {
                    // Code partenaire
                    $code = htmlspecialchars($partner['code']);
                    // Nom partenaire
                    $name = htmlspecialchars($partner['name']);
                    // Logo partenaire
                    $img = Uri::root()
                        . 'plugins/hikashoppayment/encaisse/assets/img/'
                        . $code . '.png';
                    // Logo de secours
                    $default = Uri::root()
                        . 'plugins/hikashoppayment/encaisse/assets/img/logo.png';
                    $html .= '
                    <div class="encaisse-item">
                        <label>
                            <input type="radio" name="encaisse_partner" value="' . $code . '"  >
                            <div class="encaisse-card">
                                <img src="' . $img . '" alt="' . $name . '" onerror="this.src=\'' . $default . '\';" style="max-height:60px;" >
                                <span>' . $name . '  </span>
                            </div>
                        </label>
                    </div>
                    ';
                }
                $html .= '
                    </div>
                </div>
                ';
            }
            // Fermeture du conteneur principal
            $html .= '</div>';
            // Injection du HTML dans la méthode HikaShop
            $method->payment_description = $html;
        }
        return true;
    }

    /**
     * Déclenchée avant la création de la commande.
     *
     * @param object $order Objet commande Hikashop
     * @param bool   $do    Indique si la création de la commande doit continuer
     */
    public function onBeforeOrderCreate(&$order, &$do)
    {
        // Récupère la session Joomla actuellement active
        $session = Factory::getSession();
        // Lit la valeur 'encaisse_partner' stockée en session.
        // Si la clé n'existe pas, retourne une chaîne vide.
        $partner = $session->get('encaisse_partner', '');
        // Vérifie si la propriété payment_params n'existe pas ou si elle n'est pas un objet.
        if (!isset($order->payment_params) || !is_object($order->payment_params)) {
            // Crée un objet vide pour éviter les erreurs lors de l'ajout de nouvelles propriétés.
            $order->payment_params = new stdClass();
        }
        // Ajoute l'identifiant du partenaire Encaisse dans les paramètres de paiement de la commande.
        // Cette information sera sauvegardée avec la commande et pourra être récupérée plus tard.
        $order->payment_params->encaisse_partner = $partner;
    }

    /**
     * CONFIRMATION COMMANDE
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        foreach ($methods as $method) {
            if ($method->payment_id != $method_id) {
                continue;
            }
            $app = Factory::getApplication();
            // Partenaire choisi
            $partner = $order->payment_params->encaisse_partner ?? '';
            $success_url = Uri::root() . 'plugins/hikashoppayment/encaisse/success.php';
            $failure_url = Uri::root() . 'plugins/hikashoppayment/encaisse/error.php';
            // Currency
            $currencyCode = 'XOF';
            if (
                !empty($order->order_currency_info)
                && !empty($order->order_currency_info->currency_code)
            ) {
                $currencyCode = $order->order_currency_info->currency_code;
            } elseif (!empty($order->order_currency_id)) {
                $currencyClass = hikashop_get('class.currency');
                if ($currencyClass !== null) {
                    $currencyObj = $currencyClass->get(
                        $order->order_currency_id
                    );
                    if (!empty($currencyObj->currency_code)) {
                        $currencyCode = $currencyObj->currency_code;
                    }
                }
            }
            // Montant
            $amount = number_format(
                (float) $order->order_full_price,
                2,
                '.',
                ''
            );
            // Téléphone client
            $phone =
                $order->cart->shipping_address->address_telephone
                ?? $order->cart->billing_address->address_telephone
                ?? '';
            // Paramètres plugin
            $plugin = PluginHelper::getPlugin(
                'hikashoppayment',
                'encaisse'
            );
            $params = new Registry($plugin->params);
            $company_id = $params->get('company_id');

            /* MODE SIMULATION */
            $simulation = (int) $params->get('simulation', 1);
            if ($simulation) {
                $dataApi = [
                    'operation' => 'payment',
                    'currency' => $currencyCode,
                    'amount' => $amount,
                    'customer_ref' => $phone,
                    'partner' => $partner,
                    'success_url' => $success_url,
                    'error_url' => $failure_url,
                ];
                $session = Factory::getSession();
                $dataApi['transaction_id'] = 'SIM-' . strtoupper(substr(md5(uniqid()), 0, 10));
                $session->set('encaisse_fake_transaction', $dataApi);
                $app->redirect(Uri::root() . 'index.php?task=fake_payment');
                return true;
            }

            /* MODE PRODUCTION */
            $client_id = $params->get('client_id');
            $client_secret = $params->get('client_secret');
            $auth = $this->getEncaisseToken(
                $client_id,
                $client_secret
            );
            $token = $auth['connect_token'] ?? '';
            if (empty($token)) {
                $app->enqueueMessage('Impossible de récupérer le token Encaisse', 'error');
                return false;
            }
            $dataApi = [
                'operation' => 'payment',
                'currency' => 'XOF',
                'amount' => "1",
                'customer_ref' => $phone,
                'payment_option' => $partner,
                'success_redirect_url' => $success_url,
                'failure_redirect_url' => $failure_url,
            ];
            // var_dump($dataApi);
            // die();
            $ch = curl_init($this->baseUrl . '/api/transactions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($dataApi),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'app_id: ' . $company_id,
                    'Authorization: ' . $token
                ]
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $app->enqueueMessage('Erreur API : ' . curl_error($ch), 'error');
                curl_close($ch);
                return false;
            }
            curl_close($ch);
            $result = json_decode($response);
            // var_dump($result);
            // die();
            if (!empty($result->payment_url)) {
                $app->redirect($result->payment_url);
                return true;
            }
            $app->enqueueMessage('Impossible d\'initier le paiement.', 'error');
            return false;
        }
        return true;
    }

    /**
     * AUTH TOKEN
     */
    private function getEncaisseToken($client_id, $client_secret)
    {
        // $ch = curl_init('https://api.sandbox.encaisse.net/auth/app_connect');
        $ch = curl_init($this->baseUrl . '/auth/app_connect');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $client_id . ':' . $client_secret
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Factory::getApplication()->enqueueMessage('Erreur Token: ' . curl_error($ch), 'error');
        }
        curl_close($ch);
        return json_decode($response, true);
    }
    /**
     * LISTE PARTENAIRES
     */
    private function getPaymentPartners($token = null)
    {
        $simulation = (int) $this->params->get('simulation', 1);
        $company_id = $this->params->get('company_id');
        // var_dump($company_id);
        if ($simulation) {
            return [
                [
                    'code' => 'wave_sn_api',
                    'name' => 'Wave',
                    'type' => 'api'
                ],
                [
                    'code' => 'orange_sn_api',
                    'name' => 'Orange Money',
                    'type' => 'api'
                ],
                [
                    'code' => 'orabank_sn_api',
                    'name' => 'Orabank',
                    'type' => 'api'
                ],
                [
                    'code' => 'wave_sn_pdv',
                    'name' => 'Wave PDV',
                    'type' => 'pdv'
                ],
                [
                    'code' => 'orange_sn_pdv',
                    'name' => 'Orange PDV',
                    'type' => 'pdv'
                ],
                [
                    'code' => 'yas_sn_pdv',
                    'name' => 'Yas',
                    'type' => 'pdv'
                ],
                [
                    'code' => 'cash',
                    'name' => 'Paiement Cash',
                    'type' => 'cash'
                ]
            ];
        }
        // var_dump($token);
        $cleanToken = str_replace('Bearer ', '', $token);
        $ch = curl_init($this->baseUrl . '/api/companies/' . $company_id . '/payment-partners');
        // var_dump($this->baseUrl . '/api/companies/' . $company_id . '/payment-partners');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim($cleanToken),
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        // var_dump("Bonjour", $response);
        curl_close($ch);
        return json_decode($response, true);
    }
    /**
     * Événement Joomla exécuté après le routage de l'URL.
     * À ce stade, Joomla a déjà analysé les paramètres de la requête.
     */
    public function onAfterRoute()
    {
        // Récupère l'application Joomla courante
        $app = Factory::getApplication();
        // Vérifie si on se trouve dans l'administration Joomla (/administrator)
        if ($app->isClient('administrator')) {
            // Si oui, on ne fait rien et on quitte la méthode
            return;
        }
        // Récupère l'objet Input permettant d'accéder aux paramètres GET, POST, etc.
        $input = $app->input;
        // Récupère le paramètre "task" de l'URL
        // Exemple :
        // index.php?task=payment_success
        $task = $input->getCmd('task');
        // Si la tâche demandée est payment_success
        if ($task === 'payment_success') {
            // Affiche la page de succès du paiement
            $this->renderPage(true);
            // Arrête complètement l'exécution du script
            exit;
        }
        // Si la tâche demandée est payment_error
        if ($task === 'payment_error') {
            // Affiche la page d'échec du paiement
            $this->renderPage(false);
            // Arrête complètement l'exécution du script
            exit;
        }
        // Si la tâche demandée est fake_payment
        if ($task === 'fake_payment') {
            // Affiche la page de simulation de paiement
            $this->renderFakePayment();
            // Arrête complètement l'exécution du script
            exit;
        }
        // Gestion AJAX : sauvegarde du partenaire sélectionné
        // Récupère le paramètre "method" Exemple : index.php?method=saveChoice
        $method = $input->getCmd('method');
        if ($method === 'saveChoice') {
            $value = $input->getString('value');
            // var_dump($value);
            // die();
            $session = Factory::getSession();
            $session->set('encaisse_partner', $value);
            echo "OK";
            $app->close();
        }
    }
    /**
     * PAGE SUCCESS / ERROR
     */
    private function renderPage($success)
    {
        $home = Route::_('index.php');
        echo '
        <div style="display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;">
            <div style="background:#fff;padding:30px;border-radius:12px;text-align:center;">
                <h2>' . ($success ? '✅ Paiement réussi' : '❌ Paiement échoué') . '</h2>
                <a href="' . $home . '">Retour à l\'accueil</a>
            </div>
        </div>';

        Factory::getApplication()->close();
    }

    public function testRedirectUrls()
    {
        $success_url = Uri::root() . 'index.php?task=payment_success';
        $failure_url = Uri::root() . 'index.php?task=payment_error';
        echo '<h2>TEST REDIRECTION URLS</h2>';
        echo '<p>SUCCESS: ' . $success_url . '</p>';
        echo '<p>ERROR: ' . $failure_url . '</p>';
        Factory::getApplication()->close();
    }
    private function createFakeTransaction($amount)
    {
        return [
            'id' => rand(1000, 9999),
            'company_id' => 'sn_bu_demo',
            'operation' => 'payment',
            'amount' => $amount,
            'currency' => 'XOF',
            'transaction_id' => uniqid('ENC_'),
            'status' => 'new',
            'payment_url' => Uri::root() . 'index.php?task=fake_payment'
        ];
    }
    private function renderFakePayment()
    {
        $session = Factory::getSession();
        $transaction = $session->get('encaisse_fake_transaction', []);
        $partner = $transaction['partner'] ?? '';
        $amount = $transaction['amount'] ?? '0';
        $currency = $transaction['currency'] ?? 'XOF';
        $phone = $transaction['customer_ref'] ?? '';
        $transactionId = $transaction['transaction_id'] ?? '';
        // Logo Encaisse
        $logo = Uri::root()
            . 'plugins/hikashoppayment/encaisse/assets/img/logo.png';
        // Logo partenaire + nom affiché
        $partnerLogo = '';
        $partnerName = 'Partenaire inconnu';
        switch ($partner) {
            case 'wave_sn_api':
                $partnerName = 'Wave';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/wave_sn_api.png';
                break;
            case 'wave_sn_pdv':
                $partnerName = 'Wave';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/wave_sn_pdv.png';
                break;
            case 'orange_sn_api':
                $partnerName = 'Orange Money';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/orange_sn_api.png';
                break;
            case 'orange_sn_pdv':
                $partnerName = 'Orange Money';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/orange_sn_pdv.png';
                break;
            case 'yas_sn_pdv':
                $partnerName = 'Free Money';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/yas_sn_pdv.png';
                break;
            case 'orabank_sn_api':
                $partnerName = 'Orabank';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/orabank_sn_api.png';
                break;
            case 'cash':
                $partnerName = 'Cash';
                $partnerLogo = Uri::root() . 'plugins/hikashoppayment/encaisse/assets/img/cash.png';
                break;
        }
        echo '
        <div style="
            max-width:700px;
            margin:50px auto;
            font-family:Arial,sans-serif;
            text-align:center;
            background:#fff;
            border:1px solid #e5e5e5;
            border-radius:10px;
            padding:30px;
            box-shadow:0 2px 10px rgba(0,0,0,.08);
        ">
            <img src="' . $logo . '" style="height:70px">
            <h2 style="margin-top:15px;"> ENCAISSE SANDBOX </h2>
            <hr style="margin:25px 0">
            ' . (!empty($partnerLogo) ? '
                <img src="' . $partnerLogo . '" style=" max-height:80px; margin-bottom:20px;" >
            ' : '') . '
            <h3> Paiement via ' . htmlspecialchars($partnerName) . '  </h3>
            <p>
                <strong>Partenaire :</strong> ' . htmlspecialchars($partnerName) . '
            </p>
            <p>
                <strong>Montant :</strong> ' . htmlspecialchars($amount) . ' ' . htmlspecialchars($currency) . '
            </p>
            <p>
                <strong>Téléphone :</strong> ' . htmlspecialchars($phone) . '
            </p>
            <p>
                <strong>Référence :</strong> ' . htmlspecialchars($transactionId) . '
            </p>
            <br>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($transactionId) . '" alt="QR Code" >
            <p style="margin-top:15px;">  Scanner ce QR Code pour simuler le paiement </p>
            <br><br>
            <a href="?task=payment_success"
            style="
                display:inline-block;
                padding:12px 25px;
                background:#28a745;
                color:#fff;
                text-decoration:none;
                border-radius:5px;
                font-weight:bold;
            ">
                ✓ Paiement réussi
            </a>
            <a href="?task=payment_error"
            style="
                display:inline-block;
                padding:12px 25px;
                background:#dc3545;
                color:#fff;
                text-decoration:none;
                border-radius:5px;
                font-weight:bold;
                margin-left:15px;
            ">
                ✗ Paiement échoué
            </a>
        </div>';
        Factory::getApplication()->close();
    }
    public function onAjaxEncaisse()
    {
        $input = Factory::getApplication()->input;
        $value = $input->getString('value');
        $session = Factory::getSession();
        $session->set('encaisse_partner', $value);
        echo 'SAVE=' . $value;
        Factory::getApplication()->close();
    }

}