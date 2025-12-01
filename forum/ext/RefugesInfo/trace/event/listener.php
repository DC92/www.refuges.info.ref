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

//TODO fonction check / bandeau -> fonction clic check
//TODO revoir indentation / tabs
//TODO fichiers de la base geo

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
		$this->limit_default = 20;
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
			'refugesinfo.trace.log_request_context' => 'log_request_context', // Saisie commentaires
			'refugesinfo.trace.stats' => 'trace_stats', // Symbole dans le bandeau

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
	public function trace_stats($event)
	{
    global $pdo;

    $sql = 'SELECT COUNT(trace_id)'.
      ' FROM trace_requettes AS t'.
      '   LEFT JOIN phpbb3_users AS u USING (user_id)'.
      ' WHERE t.ext_error IS NULL'.
      '   AND t.uri LIKE \'%edit%\''.
      '   AND u.group_id != 201'.
      '   AND u.group_id != 202';

    if ($res = $pdo->query($sql))
      $event['posts_edit'] = $res->fetch()->count;
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
				'browser_operator' => $_POST['mrk_browser_operator'] ?? '',
				'browser_referer' => $_POST['mrk_browser_referer'] ?? '',
				'browser_locale' => $_POST['mrk_browser_locale'] ?? '',
				'browser_timezone' => $_POST['mrk_browser_timezone'] ?? '',

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
				'creator_id' => $post_data['poster_id'] ?? 0,
				'creator_name' => ($post_data['poster_id'] ?? 0) > 1 ? $event['username'] : 'Anonymous',
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

    // Affichage d'entête de la table
    $this->affiche_une_ligne (['Trace', 'Statut', 'Utilisateur', 'Machine', 'ASN (FAI)', 'IP', 'Contenu']);

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

      // Edition de la requete
      $template->assign_block_vars('inputs_requete', [
        'NAME' => $ns[0],
        'VALUE' => $cond[$ns[0]] ?? '',
      ]);
      
      if(!empty ($cond[$ns[0]]))
        foreach (explode(',', $cond[$ns[0]]) as $k => $v) {
          $vs = array_reverse(explode('!', $v)); // Separate the ! at the beginning

          if($vs[0] === 'false') $vs[0] = 0;
          if($vs[0] === 'true') $vs[0] = 1;

          if($vs[0] === 'null')
            $conditions[] = $name.' IS '.(isset($vs[1]) ? 'NOT ' : '').'NULL';
          elseif (strlen ($vs[0]) && $type === 'number' & !in_array($name, ['limit','offset']))
            $conditions[] = $name.(isset($vs[1]) ? ' != ' : '=').$vs[0];
          elseif (strlen ($vs[0]) && $type === 'text')
            $conditions[] = $name.(isset($vs[1]) ? ' NOT' : '').' LIKE \'%'.$vs[0].'%\'';
        }
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
			' LIMIT '.($_GET['limit'] ?? $this->limit_default).
			(!empty ($_GET['offset']) ? ' OFFSET '.$_GET['offset'] : '');
		$result = $db->sql_query($sql);

		$compteur_traces = 0;
		while($row = $db->sql_fetchrow($result))
			$compteur_traces = $this->affiche_une_trace ($row, $compteur_traces);
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
			'WHERE_SQL' => str_replace(['t.', 'u.'],'', $where),
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

		// Update d'une trace existante (quand un nouveau post est créé, pour ajouter le n° de post
    $row = $this->save_full_row($row);

    foreach($row as $name => $value)
      if (intval($value) > 1000000000)
        $row[$name] = date('r', intval($value));

    $row = array_filter (array_merge (array_map('trim', $row), [
      'user_permissions' => null,
      'user_password' => null,
      'user_form_salt' => null,
      'user_sig' => null,
    ]));

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
        !empty($row['checked']) ? 'Checked' :
          '<a href="'.$this->u_action.'&trace_id='.$row['trace_id'].'&checked=1">Check</a>',
      ] : [],
      $colonne_statut,
      array_merge(
        // Auteur
        strpos($row['appel'] ?? '', 'edit') === 0 && !empty($row['creator_id']) ? [
        	'Créé par: <a title="Voir son profil"'.
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
          !empty($row['user_posts']) && ($row['user_id'] ?? 1) > 1 ? 'Posts: '.$row['user_posts'] : null,
          !empty($row['user_lang']) ? 'Langue: '.$row['user_lang'] : null,
          !empty($row['user_timezone']) ? $row['user_timezone'] : null,
          !empty($row['user_login_attempts']) ? 'Tentatives login: '.$row['user_login_attempts'] : null,
          !empty($row['user_inactive_time']) ? 'Temps inactif: '.$row['user_inactive_time'] : null,
          !empty($row['user_inactive_reason']) ? 'Raison inactif: '.$row['user_inactive_reason'] : null,
        ]
      ),
      [ // Machine
        $row['browser_operator'] ?? '',
        str_replace(['<t>','</t>'], '',$row['user_sig'] ?? '') ? 'Signature: '.$row['user_sig'] : '',
        'Langue: '.($row['browser_locale'] ?? 'inconnue'),
        'Timezone: '.($row['browser_timezone'] ?? 'inconnue'),
      ],
      [ // FAI
        $row['host'] ??'',
        '<a title="Fiche de l\'ASN"'.
          'href="https://ipinfo.io/'.($row['asn_id'] ?? $row['ip'] ?? '').'">'.
          ($row['asn_name'] ?? $row['host'] ?? $row['ip'] ?? '').'</a>',
        ($row['country_name'] ?? '').' / '.($row['city'] ?? ''),
        '<a title="Les contributions passant par '.($row['asn_name'] ?? '').'"'.
          'href="'.$this->u_action.'&asn_id='.($row['asn_id'] ?? '').'">Contributions</a>',
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
      [ // Contenu
        '<b>'.($row['title'] ?? '').'</b>',
        mb_substr($row['text'] ?? '', 0, 240).(strlen($row['text'] ?? '') > 239 ? '...' : ''),
      ],
    ]);

    // Affiche le résultat complet sur la fiche d'une trace
    if(isset ($_GET['trace_id']))
      foreach($row as $name => $value)
        $template->assign_block_vars('full_trace', [
          'NAME' => $name,
          'VALUE' => $value,
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
		if(empty($row['ext_error']))
			$row['ext_error'] = null;

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
