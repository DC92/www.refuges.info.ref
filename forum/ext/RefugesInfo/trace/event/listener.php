<?php
// Ce fichier centralise les fonctions PHP liées à tracer l'environnement des posts et enregistrements
// Attention: Le code suivant s'exécute dans un "namespace" bien défini

/* Tests à faire
Création user
Création topic
Création post
Réponse post
Quote post
Création point
Création commentaire

Déconnecté refusé
Déconnecté
Connecté refusé
Connecté

Nouveau
Ancien (avant les traces)

Traces avec tri
[i] post,
[i] point,
[i] commentaire,
user
*/

//TODO revoir indentation / tabs
//TODO fichiers de la base geo
//TODO fonction check / bandeau
//TODO BUG affiche coonne : statut REJET null
//TODO BUG Edit ajoute arguments
/*//TODO
  'url' => 'uri',
    //'url-1' => 'referer',
    //'url-2' => 'browser_referer',
    //'agent' => 'user_agent',
  //'langues supportés' => 'language',
  'topic' => 'topic_id',
  'post' => 'post_id',
  'point' => 'id_point',
  'commentaire' => 'id_commentaire',
];
*/

namespace RefugesInfo\trace\event;

include __DIR__.'/../geoip2/geoip2.phar';
use GeoIp2\Database\Reader;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
class listener implements EventSubscriberInterface
{
	protected $forum_root, $u_action, $argument_names, $reader_asn, $reader_city;

	public function __construct()
	{
		global $request, $db;

    $request->enable_super_globals();

		// Calcul de la racine du forum
		preg_match('|'.$_SERVER['DOCUMENT_ROOT'].'(.*/)ext/|', __DIR__, $forum_dirs);
		$this->forum_root = $forum_dirs[1];
		$ns = explode('\\', __NAMESPACE__);
		$this->u_action = $this->forum_root.'mcp.php?i=-'.$ns[0].'-'.$ns[1].'-mcp-main_module';

		// Liste les colonnes pour ne prendre que les arguments qui correspondent
		$this->argument_names = [
      't.ext_error' => 'text',
      't.browser_operator' => 'text',
      't.trace_id' => 'number',
      't.user_id' => 'number',
      't.asn_id' => 'number',
      't.uri' => 'text', // Pour profile user
      't.checked' => 'number',
      't.topic_id' => 'number',
      't.post_id' => 'number',
      't.id_point' => 'number',
      't.id_commentaire' => 'number',
      'u.group_id' => 'number',
      'limit' => 'number',
      'offset' => 'number',
    ];
	}

	static public function getSubscribedEvents()
	{
		return [
			// Log request
			'core.ucp_register_modify_template_data' => 'ucp_register_modify_template_data', // ucp_register.php 682
			'core.submit_post_end' => 'submit_post_end', // functions_posting.php 2634
			'core.posting_modify_template_vars' => 'log_request_context', // posting.php 2089 (post rejeté)
			'core.ucp_register_register_after' => 'log_request_context', // ucp_register.php 562 (user acceptée)
			'refugesinfo.trace.log_request_context' => 'log_request_context',

			// Display traces
			'core.mcp_post_additional_options' => 'display_traces', // mcp_post.php 125
			'core.memberlist_view_profile' => 'display_traces', // memberlist.php 757
			'refugesinfo.trace.display_traces' => 'display_traces',
		];
	}

	// Log le contexte d'une création de user rejetée
	public function ucp_register_modify_template_data($event, $eventName)
	{
		if(isset($_POST['new_password'])) { // Except when load the registration page
			$error = $event['error'];
			$error[] = 'Création d\'un compte rejetée sans erreur documentée';
			$event['error'] = $error;

			$this->log_request_context($event, $eventName);
		}
	}

	// Log le contexte d'une soumission de post acceptée
	public function submit_post_end($event, $eventName)
	{
     // Cas des mises en approbation
		if(isset($event['data']['post_visibility']) &&
			$event['data']['post_visibility'] === ITEM_UNAPPROVED)
		{
			$error = $event['error'];
			$error[] = 'Post mis en approbation par CleanTalk';
			$event['error'] = $error;
		}

		$this->log_request_context($event, $eventName);
	}

	// Log le contexte d'une soumission
	public function log_request_context($event, $eventName)
	{
		global $user, $auth;

		if(count($_POST) && // Except when load a post page
			//strpos($_SERVER['REQUEST_URI'], 'mode=edit') === false && // Edit is not traced
			!isset($_POST['preview'])) // Post preview is not traced
		{
			$post_data = array_filter(
				$event['data'] ??
				$event['post_data'] ??
        []
			);
			$user_data = array_filter(
				$event['user_row'] ??
				$user->data ??
				$_POST ??
        []
			);

			// Données à archiver
			$trace = $this->save_full_row([
				// General
				'appel' => strpos($eventName, 'register') !== false
					? 'création compte'
					: ($event['mode'] ?? '') .str_replace(['core.', 'refugesinfo.'], ' ', $eventName),
				'ext_error' => !empty ($event['error']) ? json_encode($event['error']) : '',

				// Server
				'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
				'uri' => isset($_SERVER['HTTP_HOST']) ? (
						($_SERVER['REQUEST_SCHEME'] ?? '').'://'.
             $_SERVER['HTTP_HOST'].
						($_SERVER['REQUEST_URI'] ?? '')
					) : '',
				'referer' => $_SERVER['HTTP_REFERER'] ?? '',
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
				'date' => date('r'),

				// Navigateur
				'browser_operator' => $_POST['browser_operator'] ?? '',
				'browser_referer' => $_POST['browser_referer'] ?? '',
				'browser_locale' => $_POST['browser_locale'] ?? '',
				'browser_timezone' => $_POST['browser_timezone'] ?? '',

				// Post & Point
				'topic_id' =>
          $event['topic_id'] ??
					$post_data['topic_id'] ??
					$_POST['topic_id'] ??
          0,
				'post_id' =>
          $event['post_id'] ??
					$post_data['post_id'] ??
					$_POST['post_id'] ??
          0,
				'id_point' => $event['point']->id_point ?? 0,
				'id_commentaire' => $event['commentaire']->id_commentaire ?? 0,
				'title' =>
          $event['subject'] ??
					$post_data['topic_title'] ??
					$event['point']->nom ??
          '',
				'text' => mb_substr(
					$_POST['message'] ??
					$event['commentaire']->texte ??
          '',
					0,
          256
				),

				// Infos enregistrées à la création du user
				// Sont gardées dans la table au cas où on supprimerait le user
				'creator_id' => $post_data['poster_id'],
				'creator_name' => $post_data['poster_id'] > 1 ? $event['username'] : 'Anonymous',
				'user_id' => $user_data['user_id'] ?? $event['user_id'] ?? 0,
				'user_name' =>
          $user_data['username'] ??
					$_POST['nom_createur'] ??
					$user_data['username'] ??
          '',
				'user_email' =>
          $user_data['user_email'] ??
					$user_data['email'] ??
          '',
				'user_lang' =>
          $user_data['user_lang'] ??
					$user_data['lang'] ??
          '',
				'user_timezone' =>
          $user_data['user_timezone'] ??
					$user_data['tz'] ??
          '',
				'ip_enregistrement' => $user_data['user_ip'] ?? '',
				'host_enregistrement' => gethostbyaddr($user_data['user_ip'] ?? $user_data['session_ip'] ?? ''),
			]);
		}
	}

	/*
   * Affichage des traces
   */
	//BEST statistique sur les posts/comptes supprimés
	public function display_traces($event, $eventName)
	{
		global $db, $template, $auth;

		if(!$auth->acl_get('m_')) // Uniquement pour les modérateurs
			return;

    $cond = array_filter (array_merge ($_GET, [
      // Arguments pour mcp_post_additional_options & core.memberlist_view_profile
			'post_id' => $_GET['p'] ?? $_GET['post_id'] ?? 0,
			'user_id' => $_GET['u'] ?? $_GET['user_id'] ?? 0,
			'uri' => empty ($_GET['u']) ? $_GET['uri'] ?? '' : 'register',
    ]));

    // Requetes dans la table des traces
		$conditions = [];
		foreach($this->argument_names as $name => $type) {
			$ns = array_reverse(explode('.', $name ?? '')); // Separate the t. at the beginning
			$vs = array_reverse(explode('!', $cond[$ns[0]] ?? '')); // Separate the ! at the beginning

      // Edition de la requete
      $template->assign_block_vars('inputs_requete', [
        'NAME' => $ns[0],
        'TYPE' => $type,
        'VALUE' => $vs[0],
      ]);

      if (strlen ($vs[0]) && $type === 'number'  & !in_array($name, ['limit','offset']))
        $conditions[] = $name.(isset($vs[1]) ? '!=' : '=').$vs[0];
      if (strlen ($vs[0]) && $type === 'text')
        $conditions[] = $name.(isset($vs[1]) ? ' NOT' : '').' LIKE \'%'.$vs[0].'%\'';
    }

    $tables = 'trace_requettes AS t'.
		 	' LEFT JOIN '.USERS_TABLE.' AS u USING (user_id)';
		$where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

		// Nombre de traces répondant aux critères
		$sql_count = "SELECT COUNT(trace_id) FROM $tables $where";
		$result = $db->sql_query($sql_count);
		$row_count = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		// Liste des traces affichables
		$sql = "SELECT * FROM $tables $where".
			' ORDER BY trace_id DESC'.
			' LIMIT '.($_GET['limit'] ?? 20).
			(!empty ($_GET['offset']) ? ' OFFSET '.$_GET['offset'] : '');
		$result = $db->sql_query($sql);

    // Affichage de la table
    $this->affiche_une_ligne (['Trace', 'Statut', 'Utilisateur', 'Machine', 'IP', 'ASN (FAI)', 'Contenu']);

		$compteur_traces = 0;
		while($row = $db->sql_fetchrow($result))
			$compteur_traces = $this->affiche_une_trace (array_map('trim', $row), $compteur_traces);
		$db->sql_freeresult($result);

		// S'il n'y a pas de trace dans la table, simplement décode l'IP utilisée.
		$event_ip =
      $event['post_info']['poster_ip'] ??
			$event['member']['user_ip'] ??
      '';

		if(!$compteur_traces && $event_ip) {
			$compteur_traces = $this->affiche_une_trace ([
				'ip' => $event_ip,
			]);
    }

		$template->assign_vars([
			'WHERE_SQL' => str_replace(['.t', '.u'],'', $where),
			'REQUETE_SQL' => $sql,
			'NOMBRE_LIGNES' => $compteur_traces,
			'NOMBRE_TRACES' => $row_count['count'] ?? 0,
		]);
	}

	/*
   * Affichage d'une trace
   */
	private function affiche_une_trace($row, $compteur_traces = 0)
	{
		global $db, $template;

    $row = $this->save_full_row($row); //TODO BUG NE DEVRAIT QU'ETRE AU RETOUR D'UNE EDITION OU CREATION

    // Calcul du statut
		$colonne_statut = [];

		if(!empty ($row['appel'])) {
			preg_match('/(.*) ([a-z_]*)/', $row['appel'], $modes);
			if(isset($modes[2]) && strpos($modes[2], '_') !== false) {
				$row['listener'] = $modes[2];

				$appel = str_replace(
					['register', 'post', 'reply',
						'quote', 'edit', ],
					['Création d\'un user', 'Création d\'un sujet', 'Réponse à un post',
						'Quote d\'un post', 'Edition d\'un post'],
					$modes[1]
				);
			}
		}

		if(!empty ($row['ext_error']))
			$colonne_statut[] = 'REJET '.($appel ?? '');
		elseif(!empty ($row['uri'])) {
			//BEST lien vers un post mis en approbation
			if(strpos ($row['uri'] ?? '', 'point_modification') !== false) {
				if(!empty ($row['id_point']))
					$colonne_statut[] = 'création d\'un <a '.
						'href="'.$this->forum_root.'../point/'.$row['id_point'].'"'.
					'>point</a>';
				elseif(!empty ($row['post_id']))
					$colonne_statut[] = 'création d\'un point et de son <a '.
						'href="'.$this->forum_root.'viewtopic.php?p='.$row['post_id'].'"'.
					'>forum</a>';
				else
					$colonne_statut[] = 'erreur modification point sans id_point ni post_id';
			}
			elseif(strpos ($row['uri'] ?? '', 'ajout_commentaire') !== false) {
				if(!empty ($row['id_point']))
					$colonne_statut[] = 'création d\'un <a '.
						'href="'.$this->forum_root.'../point/'.$row['id_point'].'#C'.($row['id_commentaire'] ?? 0).'"'.
					'>commentaire</a>';
				else
					$colonne_statut[] = 'erreur ajout commentaire sans id_point';
			}
			elseif(strpos ($row['uri'] ?? '', 'mode=register') !== false) {
				if(!empty ($row['user_id']))
					$colonne_statut[] = 'création du compte <a '.
						'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.$row['user_id'].'"'.
					'>'.($row['user_name'] ?? 'NONAME').'</a>';
				else
					$colonne_statut[] = 'erreur création du compte sans user_id';
			}
			elseif(strpos ($row['uri'] ?? '', 'mode=post') !== false ||
				strpos ($row['uri'] ?? '', 'contactadmin') !== false) {
				if(!empty ($row['post_id']))
					$colonne_statut[] = 'création d\'un <a '.
						'href="'.$this->forum_root.'viewtopic.php?p='.$row['post_id'].'"'.
					'>sujet</a>';
				elseif(!empty ($row['topic_id']))
					$colonne_statut[] = 'création d\'un <a '.
						'href="'.$this->forum_root.'viewtopic.php?t='.$row['topic_id'].'"'.
					'>sujet</a>';
				else
					$colonne_statut[] = 'erreur création d\'un post sans topic_id ni post_id';
			}
			elseif(strpos ($row['uri'] ?? '', 'posting.php') !== false) { // reply, quote, edit
				if(!empty ($row['post_id']))
					$colonne_statut[] = str_replace(
						'post',
						'<a href="'.$this->forum_root.'viewtopic.php?'.
							'p='.$row['post_id'].'#p'.$row['post_id'].'">post'.
						'</a>',
						($appel ?? '')
					);
				else
					$colonne_statut[] = 'erreur posting sans post_id';
			}
			else
				$colonne_statut[] = 'erreur url inconnue';
		}

    $colonne_statut[] =
      str_replace( // Split encoded lines
        ['","', '["', '"]', 'posting_modify_template_vars : ', 'ucp_register_modify_template_data : '],
        ['<br/>- ', '- ', '', '', ''],
        preg_replace( // Décode unicode if such returned by extensions
          '/\\\\u([a-e0-9]{4})/',
          '&#x$1;',
          $row['ext_error'] ?? '',
        ),
      );

    // Affiche une ligne du tableau
    $this->affiche_une_ligne([
      isset($row['trace_id']) ? [ // Trace
        $row['date'] ?? '',
        'Trace n° <a href="'.$this->u_action.'&trace_id='.$row['trace_id'].'">'.$row['trace_id'].'</a>',
        isset($row['checked']) ? 'Checked' :
          '<a href="'.$this->u_action.'&trace_id='.$row['trace_id'].'&checked=1">Check</a>',
      ] : [],
      $colonne_statut,
      array_merge(
        // Auteur
        strpos($row['appel'] ?? '', 'edit') === 0 ? [
        	'Créé par:'.
          '<a title="Voir son profil"'.
            'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.($row['creator_id'] ?? 0).'">'.
            ($row['creator_name'] ?? '').'</a>',
          'Modifié par:',
        ] : [],
        [ // Utilisateur
        	'<a title="Voir son profil"'.
            'href="'.$this->forum_root.'memberlist.php?mode=viewprofile&u='.($row['user_id'] ?? 0).'">'.
            ($row['user_name'] ?? '').'</a>',
          ($row['user_id'] ?? 0) > 1 ?
            '<a title="Voir ses traces"'.
              'href="'.$this->u_action.'&user_id='.$row['user_id'].'">Contributions</a>' : '',
          $row['user_email'] ?? '' ?
            '<a title="Avis Cleantalk"'.
              'href="https://cleantalk.org/email-checker/'.$row['user_email'].'">'.($row['user_email'] ?? '').'</a>' : '',
          $row['browser_operator'] ?? '',
          str_replace(['<t>','</t>'], '',$row['user_sig'] ?? '') ?
            'Signature: '.$row['user_sig'] : '',
        ]
      ),
      [ // Machine
        'Langue: '.($row['browser_locale'] ?? 'inconnue'),
        'Timezone: '.($row['browser_timezone'] ?? 'inconnue'),
      ],
      isset($row['ip']) ? [ // IP
        $row['ip'],
				'<a href="https://ipinfo.io/'.$row['ip'].'">IpInfo</a>',
				'<a href="https://whatismyipaddress.com/ip/'.$row['ip'].'">WhatIsMyIP</a>',
				'<a href="https://www.iplocation.net/ip-lookup?query='.$row['ip'].'">IpLocation</a>',
				'<a href="https://stopforumspam.com/ipcheck/'.$row['ip'].'">StopForumSpam</a>',
				'<a href="https://www.spamcop.net/w3m?action=checkblock&ip='.
					$row['ip'].'">SpamCop</a>',
				'<a href="https://www.abuseipdb.com/check/'.$row['ip'].'">AbuseIPdb</a>',
				'<a href="https://cleantalk.org/blacklists/'.$row['ip'].'">CleanTalk</a>',
      ] : [],
      [ // FAI
        $row['host'] ??'',
        '<a title="Fiche de l\'ASN"'.
          'href="https://ipinfo.io/'.($row['asn_id'] ?? $row['ip'] ?? '').'">'.
          ($row['asn_name'] ?? $row['host'] ?? $row['ip'] ?? '').'</a>',
        ($row['country_name'] ?? '').' / '.($row['city'] ?? ''),
        '<a title="Les contributions passant par '.($row['asn_name'] ?? '').'"'.
          'href="'.$this->u_action.'&asn_id='.($row['asn_id'] ?? '').'">Contributions</a>',
      ],
      [ // Contenu
        '<b>'.($row['title'] ?? '').'</b>',
        mb_substr($row['text'] ?? '', 0, 240).(strlen($row['text'] ?? '') > 239 ? '...' : ''),
      ],
    ]);

    return $compteur_traces + 1;
	}

	private function affiche_une_ligne($values)
	{
		global $template;

    $template->assign_block_vars('output_requetes_raw', []);
    foreach (array_filter($values) as $v)
			$template->assign_block_vars('output_requetes_raw.output_requetes_col', [
        'VALUE' => getType($v) === 'array' ? implode ('<br/>', array_filter($v)) : $v,
      ]);
  }

	private function save_full_row($row)
	{
		global $db, $config_wri;

		// Purge empty values
		$row = array_filter($row);

		if(!empty ($row['ip'])) {
			if(empty ($row['host']))
				$row['host'] = gethostbyaddr($row['ip']);

			if(empty ($row['asn_id']) || empty ($row['asn_name']) &&
				is_file(__DIR__.'/../geoip2/GeoLite2-ASN.mmdb')) {
					if (!isset ($this->reader_asn))
						$this->reader_asn = new Reader(__DIR__.'/../geoip2/GeoLite2-ASN.mmdb');
					$geodata_asn = $this->reader_asn->asn ($row['ip'] ?? '');
					$row['asn_id'] = 'AS'.$geodata_asn->autonomousSystemNumber;
					$row['asn_name'] = $geodata_asn->autonomousSystemOrganization;
				}

			if(empty ($row['country_name']) || empty ($row['city']) &&
				is_file(__DIR__.'/../geoip2/GeoLite2-City.mmdb')) {
					if (!isset ($this->reader_city))
						$this->reader_city = new Reader(__DIR__.'/../geoip2/GeoLite2-City.mmdb');
					$geodata_city = $this->reader_city->city($row['ip'] ?? '');
					$row['country_name'] = $geodata_city->country->name;
					$row['city'] = $geodata_city->city->name;
				}
		}

		// Force NULL if no error to enable request by "IS NULL"
		if(!isset($row['ext_error']))
			$row['ext_error'] = '';

		// Update d'une trace existante (quand un nouveau post est créé, pour ajouter le n° de post
		if(!empty ($row['trace_id'])) {
			// On récupère la trace existante
			$sql_row = [];
			$sql = 'SELECT *'.
        ' FROM trace_requettes'.
				(isset($config_wri) ? ' LEFT JOIN points USING(topic_id)' : '').
				' WHERE trace_id = '.($row['trace_id'] ?? 0);
			$result = $db->sql_query($sql);
			$sql_row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			// Récupération du n° de point qu'on n'avait pas lors de la création du forum associé
			if (!empty ($sql_row['wri_id_point']))
				$row['id_point'] = $sql_row['wri_id_point'] ?? 0;

			$delta_row = array_filter(
				$row,
				function($v, $k) use($sql_row) {
					return
						// Seulement les colonnes sql
						in_array($k, $this->argument_names) &&
						// On ne garde que les valeurs qui ont changé
						(!empty ($v) || !empty ($sql_row[$k])) &&
						$v !== trim ($sql_row[$k]);
				},
				ARRAY_FILTER_USE_BOTH
			);

			if(count($delta_row)) {
				$sql = 'UPDATE trace_requettes SET '.
					$db->sql_build_array('UPDATE', $delta_row).
					' WHERE trace_id = '.($row['trace_id'] ?? 0);
				$db->sql_query($sql);
			}
		}
		// Nouvelle trace
		elseif(!empty ($row['uri'])) { // Pas pour les vieux posts ou users qui n'ont pas de trace
			$sql = 'INSERT INTO trace_requettes'.$db->sql_build_array('INSERT', $row);
			$db->sql_query($sql);
		}

		return $row;
	}
}


/*
/home/users/dom/dom.refuges.info/forum/ext/RefugesInfo/trace/event/listener.php:269:
array (size=112)
  'trace_id' => string '275052' (length=6)
  'uri' => string 'https://dom.refuges.info/forum/posting.php?mode=edit&p=37089' (length=60)
  'ip' => string '90.127.215.228                                                  ' (length=64)
  'real_ip' => null
  'host' => string 'lfbn-idf1-1-2051-228.w90-127.abo.wanadoo.fr                                                                                                                                                                                                                    ' (length=255)
  'user_agent' => string 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36                                                                                                                                                ' (length=255)
  'language' => string 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7                                                                                             ' (length=128)
  'browser_locale' => string 'fr                                                                                                                              ' (length=128)
  'browser_timezone' => string 'Europe/Paris                                                                                                                    ' (length=128)
  'browser_operator' => string 'humain avec mouvement de souris ou tactile                                                                                      ' (length=128)
  'session_id' => null
  'date' => string 'Sun, 23 Nov 2025 10:44:22 +0000                                 ' (length=64)
  'post_id' => string '37089' (length=5)
  'point_id' => null
  'commentaire_id' => null
  'topic_title' => null
  'title' => string 'Re: A quoi sert la page d'accueil de refuges.info ?                                                                                                                                                                                                            ' (length=255)
  'text' => string 'Merci SQFP.
Beaucoup de propositions que je laisse à traiter pour le moment où je pourrai y consacrer le temps que cela mérite.


DOMINIQUE 251123 - 1' (length=157)
  'user_id' => string '216' (length=3)
  'user_name' => string 'Dominique                                                                                                                       ' (length=128)
  'user_email' => string 'refuges@c92.fr' (length=14)
  'user_signature' => null
  'user_posts' => string '3528' (length=4)
  'user_lang' => string 'fr' (length=2)
  'user_timezone' => string 'Africa/Blantyre' (length=15)
  'ip_enregistrement' => string '                                                                ' (length=64)
  'host_enregistrement' => string 'lfbn-idf1-1-2051-228.w90-127.abo.wanadoo.fr                                                                                     ' (length=128)
  'topic_id' => string '11177' (length=5)
  'status' => null
  'ext_error' => null
  'country_code' => null
  'asn' => string '                                                                                                                                ' (length=128)
  'fai' => null
  'id_point' => string '0' (length=1)
  'id_commentaire' => string '0' (length=1)
  'action' => null
  'appel' => string 'edit submit_post_end                                                                                                            ' (length=128)
  'country_name' => string 'France                                                                                                                          ' (length=128)
  'city' => string 'Sèvres                                                                                                                          ' (length=129)
  'debug' => string '                                                                                                    ' (length=100)
  'asn_id' => string 'AS3215                          ' (length=32)
  'asn_name' => string 'Orange                                                                                                                          ' (length=128)
  'referer' => string 'https://dom.refuges.info/forum/posting.php?mode=edit&p=37089                                                                                                                                                                                                   ' (length=255)
  'browser_referer' => string 'https://dom.refuges.info/forum/viewtopic.php?t=11177                                                                                                                                                                                                           ' (length=255)
  'creator_id' => string '216' (length=3)
  'creator_name' => string 'Dominique                                                                                                                       ' (length=128)
  'checked' => string '0' (length=1)
  'user_type' => string '3' (length=1)
  'group_id' => string '202' (length=3)
  'user_permissions' => string 'zik0zjzik0zjzik0zg
zik0sfhrctmo
zik0sfhrctmo

zik0sfhrctmo
zik0sfhrctmo
zik0sfhrctmo
zik0sfhrctmo
zik0zjhrctmo

zik0sfhrctmo
zik0sfhrctmo' (length=137)
  'user_perm_from' => string '0' (length=1)
  'user_ip' => string '' (length=0)
  'user_regdate' => string '1144526300' (length=10)
  'username' => string 'Dominique' (length=9)
  'username_clean' => string 'dominique' (length=9)
  'user_password' => string '$argon2id$v=19$m=65536,t=4,p=2$WUlTLm8yYS9CS3BJQVp1SA$X30e5fGbQxt4TQvHp+zIfztQPvSrWOZd9pVIaUb7A4E' (length=97)
  'user_passchg' => string '1652507537' (length=10)
  'user_birthday' => string ' 0- 0-   0' (length=10)
  'user_lastvisit' => string '1763913435' (length=10)
  'user_lastmark' => string '1494849575' (length=10)
  'user_lastpost_time' => string '1762889663' (length=10)
  'user_lastpage' => string 'mcp.php?ext_error=null&i=-RefugesInfo-trace-mcp-main_module' (length=59)
  'user_last_confirm_key' => string '' (length=0)
  'user_last_search' => string '1757000543' (length=10)
  'user_warnings' => string '0' (length=1)
  'user_last_warning' => string '0' (length=1)
  'user_login_attempts' => string '0' (length=1)
  'user_inactive_reason' => string '0' (length=1)
  'user_inactive_time' => string '0' (length=1)
  'user_dateformat' => string 'd M Y H:i' (length=9)
  'user_style' => string '14' (length=2)
  'user_rank' => string '0' (length=1)
  'user_colour' => string 'FF00FF' (length=6)
  'user_new_privmsg' => string '0' (length=1)
  'user_unread_privmsg' => string '0' (length=1)
  'user_last_privmsg' => string '1666706599' (length=10)
  'user_message_rules' => string '0' (length=1)
  'user_full_folder' => string '-3' (length=2)
  'user_emailtime' => string '1733727589' (length=10)
  'user_topic_show_days' => string '0' (length=1)
  'user_topic_sortby_type' => string 't' (length=1)
  'user_topic_sortby_dir' => string 'd' (length=1)
  'user_post_show_days' => string '0' (length=1)
  'user_post_sortby_type' => string 't' (length=1)
  'user_post_sortby_dir' => string 'd' (length=1)
  'user_notify' => string '1' (length=1)
  'user_notify_pm' => string '1' (length=1)
  'user_notify_type' => string '0' (length=1)
  'user_allow_pm' => string '1' (length=1)
  'user_allow_viewonline' => string '0' (length=1)
  'user_allow_viewemail' => string '0' (length=1)
  'user_allow_massemail' => string '1' (length=1)
  'user_options' => string '230271' (length=6)
  'user_avatar' => string '216_1614011737.jpg' (length=18)
  'user_avatar_type' => string 'avatar.driver.upload' (length=20)
  'user_avatar_width' => string '80' (length=2)
  'user_avatar_height' => string '80' (length=2)
  'user_sig' => string '<r>Dominique <URL url="http://chemineur.fr">http://chemineur.fr</URL></r>' (length=73)
  'user_sig_bbcode_uid' => string '112uy701' (length=8)
  'user_sig_bbcode_bitfield' => string '' (length=0)
  'user_jabber' => string '' (length=0)
  'user_actkey' => string '' (length=0)
  'user_newpasswd' => string '' (length=0)
  'user_form_salt' => string 'syh37k9ytfe236oe' (length=16)
  'user_new' => string '0' (length=1)
  'user_reminded' => string '0' (length=1)
  'user_reminded_time' => string '0' (length=1)
  'reset_token' => string 'xkechbf65rvxniz2uc42xzzl2jylkv9g' (length=32)
  'reset_token_expiration' => string '1666275737' (length=10)
  'user_actkey_expiration' => string '0' (length=1)
  'user_last_active' => string '1764102354' (length=10)
  'ct_marked' => string '0' (length=1)
*/