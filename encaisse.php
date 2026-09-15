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
            // Variable qui recevra la liste des partenaires
            $partners = [];
                // Génération du token Encaisse
                $auth = $this->getEncaisseToken(
                    $this->params->get('client_id'),
                    $this->params->get('client_secret')
                );
                // Si le token existe
                if (!empty($auth['connect_token'])) {
                    // Appel API partenaires
                    $partners = $this->getPaymentPartners(
                        $auth['connect_token']
                    );
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
     * AUTH TOKEN
     */
    private function getEncaisseToken($client_id, $client_secret)
    {
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
        $company_id = $this->params->get('company_id');
        $cleanToken = str_replace('Bearer ', '', $token);
        $ch = curl_init($this->baseUrl . '/api/companies/' . $company_id . '/payment-partners');
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
        curl_close($ch);
        return json_decode($response, true);
    }

  	    /**
     * CONFIRMATION COMMANDE
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        foreach ($methods as $method) {
            // Vérifie que c'est bien notre méthode de paiement
            if ($method->payment_id != $method_id) {  continue; }
            $app = Factory::getApplication();
            
            // Partenaire sélectionné
            $partner = $order->payment_params->encaisse_partner ?? '';
            
            // URL de succès native et officielle d'HikaShop
            $success_url = Uri::root()
                . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end'
                . '&order_id=' . (int) $order->order_id;

            $failure_url = Uri::root()
                . 'index.php?option=com_hikashop&ctrl=checkout&task=step'
                . '&step=backward'
                . '&order_id=' . (int) $order->order_id;

            /*
             * URL de notification HikaShop (Webhook)
             */
			//exemple : https:// great-kirch.165-22-182-189.plesk.page/index.php?																	option=com_hikashop&ctrl=checkout&task=notify&notif_payment=encaisse&tmpl=component
            $notify_url = Uri::root()
                . 'index.php?option=com_hikashop'
                . '&ctrl=checkout'
                . '&task=notify'
                . '&notif_payment=encaisse'
                . '&tmpl=component';

            // Devise
            $currencyCode = 'XOF';
            if (!empty($order->order_currency_info) && !empty($order->order_currency_info->currency_code)) {
                $currencyCode = $order->order_currency_info->currency_code;
            } elseif (!empty($order->order_currency_id)) {
                $currencyClass = hikashop_get('class.currency');
                if ($currencyClass !== null) {
                    $currencyObj = $currencyClass->get($order->order_currency_id);
                    if (!empty($currencyObj->currency_code)) {
                        $currencyCode = $currencyObj->currency_code;
                    }
                }
            }
            
            // Montant
            $amount = number_format((float) $order->order_full_price, 2, '.', '');

            // Téléphone
            $phone = '';
            if (!empty($order->cart->shipping_address->address_telephone)) {
                $phone = $order->cart->shipping_address->address_telephone;
            } elseif (!empty($order->cart->billing_address->address_telephone)) {
                $phone = $order->cart->billing_address->address_telephone;
            }

            // Paramètres du plugin
            $plugin = PluginHelper::getPlugin('hikashoppayment', 'encaisse');
            $params = new Registry($plugin->params);
            $company_id = $params->get('company_id');

            $logFile = dirname(JPATH_ROOT) . '/logs/encaisse_callback.log';
            file_put_contents(
                $logFile,
                "\n" .
                "==============================" . PHP_EOL .
                "CREATION TRANSACTION ENCAISSE" . PHP_EOL .
                "Date : " . date('Y-m-d H:i:s') . PHP_EOL .
                "Order ID : " . $order->order_id . PHP_EOL .
                "Amount : " . $amount . PHP_EOL .
                "Currency : " . $currencyCode . PHP_EOL .
                "Partner : " . $partner . PHP_EOL .
                "Phone : " . $phone . PHP_EOL .
                "Notify URL : " . $notify_url . PHP_EOL,
                FILE_APPEND
            );
            
            $client_id = $params->get('client_id');
            $client_secret = $params->get('client_secret');
            $auth = $this->getEncaisseToken($client_id, $client_secret);
            $token = $auth['connect_token'] ?? '';
            if (empty($token)) {
                $app->enqueueMessage('Impossible de récupérer le token Encaisse.', 'error');
                return false;
            }

            $dataApi = [
                'operation'            => 'payment',
                'currency'             => $currencyCode,
                'amount'               => $amount,
                'customer_ref'         => $phone,
                'payment_option'       => $partner,
                'success_redirect_url' => $success_url,
                'failure_redirect_url' => $failure_url,
                'callback_url'         => $notify_url,
            ];

            $ch = curl_init($this->baseUrl . '/api/transactions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($dataApi),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'app_id: ' . $company_id,
                    'Authorization: ' . $token
                ],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                file_put_contents($logFile, "ERREUR CURL : " . $error . PHP_EOL, FILE_APPEND);
                $app->enqueueMessage('Erreur API Encaisse : ' . $error, 'error');
                return false;
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            file_put_contents(
                $logFile,
                "HTTP CODE : " . $httpCode . PHP_EOL .
                "RESPONSE : " . $response . PHP_EOL .
                "==============================" . PHP_EOL,
                FILE_APPEND
            );
            
            $result = json_decode($response, true);

            /*
             * Enregistrer le transaction_id Encaisse
             */
            $transactionId = $result['transaction_id'] ?? '';
            if (!empty($transactionId)) {
                $db = Factory::getContainer()->get('DatabaseDriver');
                $paymentParams = $order->payment_params ?? new stdClass();
                if (is_string($paymentParams)) {
                    $paymentParams = json_decode($paymentParams) ?: new stdClass();
                }
                if (!is_object($paymentParams)) { $paymentParams = new stdClass(); }
                $paymentParams->encaisse_transaction_id = $transactionId;
                
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__hikashop_order'))
                    ->set(
                        $db->quoteName('order_payment_params')
                        . ' = '
                        . $db->quote(json_encode($paymentParams, JSON_UNESCAPED_UNICODE))
                    )
                    ->where(
                        $db->quoteName('order_id')
                        . ' = '
                        . (int) $order->order_id
                    );
                $db->setQuery($query);
                $db->execute();

                file_put_contents(
                    $logFile,
                    "TRANSACTION ID ENREGISTRE : " . $transactionId . PHP_EOL,
                    FILE_APPEND
                );
            }
            
            /*
             * URL de paiement
             */
            if (!empty($result['payment_url'])) {
                $app->redirect($result['payment_url']);
                return true;
            }
            
            /*
             * Pas d'URL de paiement
             */
            $app->enqueueMessage('Impossible d\'initier le paiement Encaisse.', 'error');
            return false;
        }
        return true;
    }
    
    /**
     * Événement Joomla exécuté après le routage de l'URL.
     * À ce stade, Joomla a déjà analysé les paramètres de la requête.
     */
       public function onAfterRoute()
		{
			$app = Factory::getApplication();
			if ($app->isClient('administrator')) {
				return;
			}
			$input = $app->input;
			$task = $input->getCmd('task');

			// 1. ROUTAGE DU CALLBACK (WEBHOOK SERVEUR)
			if ($task === 'encaisse_callback') {
				$statuses = [];
				$this->onPaymentNotification($statuses);
				exit;
			}

			// 2. INTERCEPTION DE LA PAGE DE SUCCÈS NATIVE
			if ($task === 'after_end') {
				$orderId = $input->getInt('order_id', 0);

				if ($orderId > 0) {
					$db = Factory::getContainer()->get('DatabaseDriver');
					$session = Factory::getSession();

					// Récupération de la commande
					$query = $db->getQuery(true)
						->select($db->quoteName(['order_status', 'order_session_id']))
						->from($db->quoteName('#__hikashop_order'))
						->where($db->quoteName('order_id') . ' = ' . (int) $orderId);
					$db->setQuery($query);
					$orderData = $db->loadObject();

					if ($orderData) {
						// FORCE LA SYNCHRONISATION DE LA SESSION
						if ($orderData->order_session_id !== $session->getId()) {
							$queryUpdate = $db->getQuery(true)
								->update($db->quoteName('#__hikashop_order'))
								->set($db->quoteName('order_session_id') . ' = ' . $db->quote($session->getId()))
								->where($db->quoteName('order_id') . ' = ' . (int) $orderId);
							$db->setQuery($queryUpdate);
							$db->execute();
						}

						// REDIRECTION EXPLICITE VERS LA VUE DE LA COMMANDE
						// On redirige proprement le navigateur vers l'affichage de la commande
						// Cela force HikaShop à réévaluer les droits avec la session synchronisée
						$orderUrl = Uri::root() . 'index.php?option=com_hikashop&ctrl=order&task=show&order_id=' . $orderId;
						$app->redirect($orderUrl);
						return;
					}
				}
			}

			// 3. ROUTAGE DE L'ÉCRAN D'ÉCHEC CLIENT
			if ($task === 'encaisse_error') {
				$this->renderPage(false);
				exit;
			}

			// 4. SAUVEGARDE DU CHOIX DE PARTENAIRE VIA AJAX
			$method = $input->getCmd('method');
			if ($method === 'saveChoice') {
				$value = $input->getString('value');
				$session = Factory::getSession();
				$session->set('encaisse_partner', $value);
				echo "OK";
				$app->close();
			}
		}

	public function onAfterInitialise()
	{
	
	}

 	/** Hook déclenché par la notification de paiement (IPN / callback) */
	public function onPaymentNotification(&$statuses)
	{
		$app = Factory::getApplication();
		/* Fichier de log Plesk */
		$logFile = dirname(JPATH_ROOT). '/logs/encaisse_callback.log';
		/* Récupération de la requête */
		$rawBody = file_get_contents('php://input');
		$postData = $app->input->post->getArray();
		$getData = $app->input->getArray();
		$jsonData = json_decode($rawBody,true);
		
		/* LOG COMPLET DU CALLBACK */
		$logData = [
			'date'       => date('Y-m-d H:i:s'),
			'method'     => $_SERVER['REQUEST_METHOD'] ?? '',
			'raw_body'   => $rawBody,
			'json_data'  => $jsonData,
			'post_data'  => $postData,
			'get_data'   => $getData,
		];
		file_put_contents(
			$logFile,
			"\n" .
			"========================================" . PHP_EOL .
			"CALLBACK ENCAISSE RECU" . PHP_EOL .
			print_r($logData, true) .
			"========================================" . PHP_EOL,
			FILE_APPEND
		);

		/* Déterminer les données reçues */
		$data = [];
		if (is_array($jsonData)) {
			$data = $jsonData;
		} elseif (!empty($postData)) {
			$data = $postData;
		} elseif (!empty($getData)) {
			$data = $getData;
		}

		/* Récupérer le transaction_id envoyé par Encaisse */
		$transactionId = '';
		if (!empty($data['transaction_id'])) {
			$transactionId = trim($data['transaction_id']);
		}

		/* Statut reçu via le Webhook originel */
		$status = '';
		if (!empty($data['status'])) {
			$status = strtolower(trim($data['status']));
		}

		file_put_contents(
			$logFile,
			"TRANSACTION ID : " . $transactionId . PHP_EOL .
			"STATUS WEBHOOK : " . $status . PHP_EOL,
			FILE_APPEND
		);

		/* ==========================================================
		 * 1. DOUBLE VÉRIFICATION PAR API SORTANTE (GET URL)
		 * ========================================================== */
		if (empty($transactionId)) {
			file_put_contents($logFile, "ERREUR : Transaction ID vide." . PHP_EOL, FILE_APPEND);
			http_response_code(400);
			echo 'Invalid Transaction ID';
			$app->close();
			return;
		}

		// Récupération des paramètres de configuration du plugin HikaShop
		$plugin = PluginHelper::getPlugin('hikashoppayment', 'encaisse');
		$params = new Registry($plugin->params);
		$client_id = $params->get('client_id');
		$client_secret = $params->get('client_secret');

		// 1.1 Récupération du Token dynamique via votre méthode
		$auth = $this->getEncaisseToken($client_id, $client_secret);
		$token = $auth['connect_token'] ?? '';

		if (empty($token)) {
			file_put_contents($logFile, "ERREUR : Impossible de récupérer le token Encaisse (Token vide)." . PHP_EOL, 							FILE_APPEND);
			http_response_code(401);
			echo 'API Authentication failed';
			$app->close();
			return;
		}

		// Nettoyage au cas où votre méthode ajoute ou oublie le mot-clé Bearer
		$cleanToken = str_replace('Bearer ', '', $token);

		// 1.2 Requête cURL GET vers l'API d'Encaisse
		// Utilise la propriété de classe $this->baseUrl configurée dans votre plugin
		$apiUrl = rtrim($this->baseUrl, '/') . '/api/transactions/' . urlencode($transactionId);
		
		$ch = curl_init($apiUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . trim($cleanToken)
			]
		]);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		file_put_contents(
			$logFile,
			"VERIFICATION API HTTP CODE : " . $httpCode . PHP_EOL .
			"VERIFICATION API RESPONSE : " . $response . PHP_EOL,
			FILE_APPEND
		);

		if ($httpCode !== 200 || empty($response)) {
			file_put_contents($logFile, "RÉSULTAT SÉCURITÉ : ÉCHEC - Impossible de joindre l'API distante." . PHP_EOL, 							FILE_APPEND);
			http_response_code(502);
			echo 'API check failed';
			$app->close();
			return;
		}

		$apiData = json_decode($response, true);

		// Extraction intelligente du statut (Format racine ou enveloppé)
		$rawApiStatus = '';
		if (isset($apiData['status'])) {
			$rawApiStatus = $apiData['status'];
		} elseif (isset($apiData['data']['status'])) {
			$rawApiStatus = $apiData['data']['status'];
		} elseif (isset($apiData['transaction']['status'])) {
			$rawApiStatus = $apiData['transaction']['status'];
		}

		$apiStatus = strtoupper(trim(preg_replace('/\s+/', '', $rawApiStatus)));
		file_put_contents($logFile, "STATUS API EXTRACTED : " . $apiStatus . PHP_EOL, FILE_APPEND);

		// BLOCAGE STRICT : L'API doit impérativement certifier le statut PAID ou SUCCESS
		if ($apiStatus !== 'PAID' && $apiStatus !== 'SUCCESS') {
			file_put_contents($logFile, "RÉSULTAT SÉCURITÉ : ÉCHEC - Statut API non valide ou impayé (" . $apiStatus . ")" . 					PHP_EOL, FILE_APPEND);
			http_response_code(400);
			echo 'Transaction not paid on API';
			$app->close();
			return;
		}

		/* ==========================================================
		 * 2. PROCESSUS STANDARD HIKASHOP
		 * ========================================================== */

		/* Filtrage additionnel des statuts temporaires du Webhook */
		if ($status === 'new' || $status === 'pending') {
			file_put_contents($logFile, "RÉSULTAT : IGNORÉ - Statut temporaire : " . $status . PHP_EOL, FILE_APPEND);
			http_response_code(200);
			echo 'Temporary status ignored';
			$app->close();
			return;
		}

		/* Rechercher la commande HikaShop grâce au transaction_id Encaisse */
		$order_id = 0;
		if (!empty($transactionId)) {
			$db = Factory::getContainer()->get('DatabaseDriver');
			$query = $db->getQuery(true)->select(
					$db->quoteName('order_id')
				)
				->from(
					$db->quoteName('#__hikashop_order')
				)
				->where(
					$db->quoteName('order_payment_params')
					. ' LIKE '
					. $db->quote('%' . $db->escape($transactionId) . '%')
				);

			$db->setQuery($query);
			$order_id = (int) $db->loadResult();
		}

		file_put_contents($logFile, "ORDER ID TROUVE : " . $order_id . PHP_EOL, FILE_APPEND);

		/* Si aucune commande n'est trouvée */
		if (!$order_id) {
			file_put_contents($logFile, "ERREUR : COMMANDE INTROUVABLE POUR LA TRANSACTION : " . $transactionId . PHP_EOL, 						FILE_APPEND);
			http_response_code(200);
			echo 'OK';
			$app->close();
			return;
		}

		/* Enregistrement de la commande */
		file_put_contents($logFile, "TENTATIVE CONFIRMATION HIKASHOP : " . $order_id . PHP_EOL, FILE_APPEND);

		try {
			//$this->modifyOrder($order_id, 'confirmed', 'Encaisse transaction double-checked ' . $transactionId);
			// Appel natif incluant l'activation explicite de la notification par e-mail
			$this->modifyOrder($order_id, 'confirmed', true, true);

			$db = Factory::getContainer()->get('DatabaseDriver');
			$checkQuery = $db->getQuery(true)
				->select($db->quoteName('order_status'))
				->from($db->quoteName('#__hikashop_order'))
				->where($db->quoteName('order_id') . ' = ' . (int) $order_id);
			$db->setQuery($checkQuery);
			$updatedStatus = $db->loadResult();

			file_put_contents(
				$logFile,
				"COMMANDE CONFIRMEE : " . $order_id
				. " STATUT DB : " . var_export($updatedStatus, true)
				. PHP_EOL,
				FILE_APPEND
			);
		} catch (\Throwable $exception) {
			file_put_contents(
				$logFile,
				"ERREUR CONFIRMATION HIKASHOP : "
				. $exception->getMessage() . PHP_EOL
				. $exception->getTraceAsString() . PHP_EOL,
				FILE_APPEND
			);
		}

		/* Réponse finale à Encaisse */
		http_response_code(200);
		echo 'OK';
		$app->close();
	}

    public function testRedirectUrls()
    {
        $success_url = Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end';
        $failure_url = Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=encaisse_error';
        echo '<h2>TEST REDIRECTION URLS</h2>';
        echo '<p>SUCCESS: ' . $success_url . '</p>';
        echo '<p>ERROR: ' . $failure_url . '</p>';
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
   
        /** 
     * Gestion exclusive de la boucle d'attente client (Polling)
     */
    public function encaisse_success()
    {
        $app = Factory::getApplication();
        $input = $app->input;
        
        $orderId = $input->getInt('order_id', 0);
        $attempts = $input->getInt('attempt', 1);

        if ($orderId <= 0) {
            $app->redirect(Route::_('index.php'));
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select($db->quoteName('order_status'))
            ->from($db->quoteName('#__hikashop_order'))
            ->where($db->quoteName('order_id') . ' = ' . (int) $orderId);
        $db->setQuery($query);
        $orderStatus = $db->loadResult();

        // Si le statut est validé pendant l'attente, on recharge pour afficher le succès officiel
        if ($orderStatus === 'confirmed' || $orderStatus === 'confirmed_payment') {
            $finalUrl = Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . $orderId;
            $app->redirect($finalUrl);
            return;
        }

        if ($attempts >= 6) {
            $app->enqueueMessage('Votre paiement est en cours de traitement par l\'opérateur. Un e-mail de confirmation vous sera 					envoyé dès validation finale.', 'warning');
            $app->redirect(Route::_('index.php?option=com_hikashop&ctrl=order'));
            return;
        }

        $nextAttempt = $attempts + 1;
        $currentUrl = Uri::root() . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id=' . $orderId . 				'&attempt=' . $nextAttempt;

        header("Refresh: 3; URL=" . $currentUrl);
        $this->displayWaitingScreen($attempts);
        $app->close();
    }

}