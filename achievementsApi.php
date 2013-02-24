<?php

interface AccèsApiBlizzardInterface {
	//----------------------------------------------------------------------------------------------------------------------------------------
	// 1-Fonctions pour interroger l'API, à encapsuler en fonction de votre appli et de votre base de données //----------------------------------------------------------------------------------------------------------------------------------------
	public function ApiMajPersonnages($bdd, $charSel, $realmChars, $mustTestIfCharInBdd);
	/*
	== lecture API et lancement de la mise à jour des données personnages, parallélisé
	-> en entrée $bdd = objet PDO, $charSel = {id} de la bdd ou $realmChars = {realmId {CharNames}} peut-être pas encore dans la bdd, $mustTestIfCharInBdd = true s'il faut vérifier si les personnages sont dans la bdd, false si on est sûr du contraire
	<- en sortie tableau {true/false si l'exécution s'est bien passée {nb de personnages en erreur, nb de personnages déjà à jour, nb de personnages mis à jour, nb de mises à jour} {id des personnages}}
	*/

	public function ApiLireClasses($host, $locale);
	/*
	== lecture API des données classes de personnages 
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé, $locale = chaîne de caractères de la locale souhaitée selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions.  Ces infos sont contenues dans $this->HOSTS et $this->LOCALES.
	<- en sortie tableau {true/false si l'exécution s'est bien passée {classe} classe est détaillée sur http://blizzard.github.com/api-wow-docs/#data-resources/character-classes
	*/

	public function ApiLireRoyaumes($host); 
	/*
	== lecture API des données royaumes 
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions
	<- en sortie tableau {true/false si l'exécution s'est bien passée {realm} realm est détaillé sur http://blizzard.github.com/api-wow-docs/#realm-status-api
	*/

	public function ApiLireHautFaits($host, $locale); 
	/*
	== lecture API des descriptions de haut-faits 
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé, $locale = chaîne de caractères de la locale souhaitée selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions. Ces infos sont contenues dans $this->HOSTS et $this->LOCALES.
	<- en sortie tableau {true/false si l'exécution s'est bien passée {achievement} realm est détaillé sur http://blizzard.github.com/api-wow-docs/#data-resources/character-achievements
	*/

}

class AccèsApiBlizzardClass implements AccèsApiBlizzardInterface {
	// hosts par région
	public $HOSTS = array( 
				'US' => 'us.battle.net', 
				'EU' => 'eu.battle.net',
				'KR' => 'kr.battle.net',
				'TW' => 'tw.battle.net',
				'CN' => 'www.battlenet.com.cn',
				);

	// langues par région. la 1ère est la langue par défaut
	public $LOCALES = array( 
				'US' => array('en_US', 'es_MX', 'pt_BR'),
				'EU' => array('en_GB', 'es_ES', 'fr_FR', 'ru_RU', 'de_DE', 'pt_PT', 'it_IT'),
				'KR' => array('ko_KR'),
				'TW' => array('zh_TW'),
				'CN' => array('zh_CN'),
				);


	//----------------------------------------------------------------------------------------------------------------------------------------
	// 1-1-Fonctions d'appel des mises à jour des personnages
	//----------------------------------------------------------------------------------------------------------------------------------------
	function ApiMajPersonnages($bdd, $charSel, $realmChars = null, $mustTestIfCharInBdd = false) {
	/*
	== mise à jour des personnages choisis
	-> en entrée $bdd = objet PDO, $charSel = {id} de la bdd ou $realmChars = {realmId {CharNames}} peut-être pas encore dans la bdd
	<- en sortie tableau {true/false si l'exécution s'est bien passée {nb de personnages en erreur, nb de personnages déjà à jour, nb de personnages mis à jour, nb de mises à jour} {id des personnages}}
	*/
	//le gestionnaire multiple curl permet une parallélisation des demandes de transfert de données
		try {
			@set_time_limit(0); 
			$result = array(false, array(0, 0, 0, 0), array());
			// Création du gestionnaire multiple cURL
			$multiHandle = curl_multi_init();
			$chArray = array('data' => array(), 'img' => array()); //tableau des transferts
			$dataArray = array(); 
			$nbPersonnages = 0;
			//récupération des infos personnages
			if (isset($charSel) and is_array($charSel) and (count($charSel) > 0)) {
				$nbPersonnages = count($charSel);
				$resultChar = $this->ApiLirePersonnages($bdd, $charSel);
				if ($resultChar[0]) {
					foreach($resultChar[1] as $charId => $char) {
						$resultInit = $this->ApiMajPersonnagesInit($multiHandle, $char['region'], $char['realm_id'], $char['realm'], $char['name'], $char['thumbnail'], $char['lastUpdate']);
						if ($resultInit[0]) {
							$chArray['data'][$resultInit[1]] = $resultInit[2];
						}
					}
				}
			} elseif (isset($realmChars) and is_array($realmChars) and (count($realmChars) > 0)) {
				if ($mustTestIfCharInBdd) {
					$resultCharData = $this->ApiLireDonnéesPersonnagesParNom($bdd, $realmChars);
				}
				foreach ($realmChars as $realmId => $charNames) {
					$nbPersonnages = $nbPersonnages + count($charNames);
					$resultRealm = $this->ApiLireRoyaume($bdd, $realmId);
					if ($resultRealm[0]) {
						foreach($charNames as $charName) {
							$oldThumbnail = null;
							$lastUpdate = null;
							if (isset($resultCharData) and $resultCharData[0] and array_key_exists($realmId, $resultCharData[1]) and array_key_exists($charName, $resultCharData[1][$realmId])) {
								$oldThumbnail = $resultCharData[1][$realmId][$charName]['thumbnail'];
								$lastUpdate = $resultCharData[1][$realmId][$charName]['lastUpdate'];
							}
							$resultInit = $this->ApiMajPersonnagesInit($multiHandle, $resultRealm[1]['region'], $realmId, $resultRealm[1]['realm'], $charName, $oldThumbnail, $lastUpdate);
							if ($resultInit[0]) {
								$chArray['data'][$resultInit[1]] = $resultInit[2];
							}
						}
					}
				}
			}

			if (count($chArray['data']) > 0) {
				//démarrage des transferts
				$active = null;
				do {
					$status = curl_multi_exec($multiHandle, $active);
				} while ($status === CURLM_CALL_MULTI_PERFORM);

				//attente d'un changement de statut 
				do {
					curl_multi_select($multiHandle); //on attend qu'un transfert se manifeste

					while($info = curl_multi_info_read($multiHandle)){
						$httpCode = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);

						if($httpCode == 200){
							$resultImg = $this->ApiMajPersonnagesImg($chArray, $multiHandle, $info['handle']);
							if ($resultImg[0] and $resultImg[2]) { // on a identifié l'image
								$chArray['img'][$resultImg[1]] = $resultImg[3];  // le transfert d'image, éventuellement vide pour ne pas se reposer la même question de changement d'image
							}
						}
					}

					do {	//relancer les autres							
						$status = curl_multi_exec($multiHandle, $active);
					} while ($status === CURLM_CALL_MULTI_PERFORM);

				} while ($active);

				// Tous les transferts sont terminés
				foreach($chArray['data'] as $url => $charCh) { //données
					$httpCode = curl_getinfo($charCh['handler'], CURLINFO_HTTP_CODE); 
					if (!array_key_exists($httpCode, $dataArray)) {
						$dataArray[$httpCode] = array();
					}
					if($httpCode == 200){
						$data = curl_multi_getcontent($charCh['handler']);
						$resultChar = getJSON($data);
						if ($resultChar[0]) {
							$dataArray[$httpCode][] = array('realmId' => $charCh['realmId'], 'charName' => $charCh['charName'], 'character' => $resultChar[1]);
							if (!array_key_exists($url, $chArray['img'])) { // on n'a pas déjà demandé l'image
								$resultImg = $this->ApiMajPersonnagesTransfertImg($chArray, $multiHandle, $url, $resultChar[1]['thumbnail']);
								if ($resultImg[0]) { 
									$chArray['img'][$url] = $resultImg[1];  // le transfert d'image
								}
							}
						}
					} else {
						$dataArray[$httpCode][] = array('realmId' => $charCh['realmId'], 'charName' => $charCh['charName']);
					}
					// ferme les gestionnaires
					curl_multi_remove_handle($multiHandle, $charCh['handler']);
					curl_close($charCh['handler']);
				}
				do {	//relancer les images s'il y en a encore							
					curl_multi_select($multiHandle); //on attend qu'un transfert se manifeste
					do {						
						$status = curl_multi_exec($multiHandle, $active);
					} while ($status === CURLM_CALL_MULTI_PERFORM);

				} while ($active);

				foreach($chArray['img'] as $url => $charCh) { // images
					if ($charCh) {
						$httpCode = curl_getinfo($charCh['handler'], CURLINFO_HTTP_CODE);
						if ($httpCode == 200) {
							$imageUrl = curl_getinfo($charCh['handler'], CURLINFO_EFFECTIVE_URL);
							$imageString = curl_multi_getcontent($charCh['handler']);
							$this->ApiSavImagePersonnage($imageUrl, $imageString, $charCh['region'], $charCh['thumbnail'], ((array_key_exists('oldThumbnail', $charCh)) ? $charCh['oldThumbnail'] : null));
						}
						curl_multi_remove_handle($multiHandle, $charCh['handler']);
						curl_close($charCh['handler']);
					}
				}
				curl_multi_close($multiHandle);

				// Traitement des mises à jour
				if (array_key_exists(200, $dataArray)) {
					$resultUpd = $this->ApiUpdatePersonnages($bdd, $dataArray[200]);
					if ($resultUpd[0]) {
						$result[1] = $resultUpd[1];
						$result[2] = $resultUpd[2];
					}
				}
				$result[1][1] = $result[1][1] + ((array_key_exists(304, $dataArray)) ? count($dataArray[304]) : 0);
				$result[1][0] = $nbPersonnages - $result[1][1] - $result[1][2];
				// Traitement des personnages disparus (dans la bdd mais pas/plus dans l'api)
				if (isset($charSel) and ($result[1][0] > 0)) {
					$this->ApiSavPersonnagesDisparus($bdd, date('c', $_SERVER['REQUEST_TIME']), array_diff($charSel, $result[2]));
				}
				$result[0] = true;
			}
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}

	private function ApiMajPersonnagesInit($multiHandle, $region, $realmId, $realmName, $charName, $oldThumbnail = null, $lastUpdate = null) {
	/*
	== initialisation du transfert de personnage
	<- en sortie tableau {true/false l'exécution s'est bien passée, url du personnage, tableau {'region', 'realmId', 'host', 'charName', 'oldThumbnail' (s'il existe), 'handler'}} 
	*/
		try {
			$result = array(false, null, array());
			if (isset($region) and isset($realmName) and isset($charName)) {
				$result[1] = 'http://' . $this->HOSTS[$region] . '/api/wow/character/' . rawUrlEncode($realmName) . '/' . rawUrlEncode($charName) . '?fields=achievements';
				$ch = curl_init($result[1]);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Le retour est attendu en chaîne de caractères
					curl_setopt($ch, CURLOPT_HEADER, 0);// Pas de header
				if (isset($lastUpdate)) {
					curl_setopt($ch, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);// Test si modifié depuis la date de dernière mise à jour 
					curl_setopt($ch, CURLOPT_TIMEVALUE, $lastUpdate);// Date de dernière mise à jour connue
				}
				curl_multi_add_handle($multiHandle, $ch);
				$result[2] = array('region' => $region, 'realmId' => $realmId, 'host' => $this->HOSTS[$region], 'charName' => $charName, 'handler' => $ch);
				if (isset($oldThumbnail)) {
					$result[2]['oldThumbnail'] = $oldThumbnail;
				}
			}
			$result[0] = true;
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}

	private function ApiMajPersonnagesImg($chArray, $multiHandle, $ch) {
	/*
	== le transfert de données s'est activé, essayons de lancer le transfert de l'image
	<- en sortie tableau {true/false l'exécution s'est bien passée, url du personnage, true/false on a identifié le thumbnail, tableau {'region', 'thumbnail', 'oldThumbnail' (s'il existe), 'handler'}} 
	*/
		try {
			$result = array(false, false, null, array());
			$result[1] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			if (!array_key_exists($result[1], $chArray['img'])) { // on n'a pas déjà demandé l'image
				$data = curl_multi_getcontent($ch);
				$thumbnail = array();
				preg_match('#"thumbnail":"([^"]*)"#', $data, $thumbnail);
				$result[2] = array_key_exists(1, $thumbnail);
				if ($result[2]) {
					$resultTransfert = $this->ApiMajPersonnagesTransfertImg($chArray, $multiHandle, $result[1], $thumbnail[1]);
					if ($resultTransfert[0]) {
						$result[3] = $resultTransfert[1];
					}
				}
			}
			$result[0] = true;
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}

	private function ApiMajPersonnagesTransfertImg($chArray, $multiHandle, $url, $thumbnail) {
	/*
	== le transfert de données s'est activé, lançons le transfert de l'image
	<- en sortie tableau {true/false l'exécution s'est bien passée, tableau {'region', 'thumbnail', 'oldThumbnail' (s'il existe), 'handler'}} 
	*/
		try {
			$result = array(false, array());
			if (!array_key_exists('oldThumbnail', $chArray['data'][$url]) or $thumbnail != $chArray['data'][$url]['oldThumbnail']) {  // l'image a changé
				$imageUrl = 'http://' . $chArray['data'][$url]['host'] . '/static-render/' . strToLower($chArray['data'][$url]['region']) . '/' .  $thumbnail;
				$ch = curl_init($imageUrl);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Le retour est attendu en chaîne de caractères
				curl_setopt($ch, CURLOPT_HEADER, 0);// Pas de header
				curl_multi_add_handle($multiHandle, $ch);
				$result[1] = array('region' => $chArray['data'][$url]['region'], 'thumbnail' => $thumbnail, 'handler' => $ch);
				if (array_key_exists('oldThumbnail', $chArray['data'][$url])) {
					$result[1]['oldThumbnail'] = $chArray['data'][$url]['oldThumbnail'];
				}
			}
			$result[0] = true;
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}
	//----------------------------------------------------------------------------------------------------------------------------------------
	// 1-2-Fonctions d'appel des mises à jour des références
	//----------------------------------------------------------------------------------------------------------------------------------------
	function ApiLireClasses($host, $locale) {
	/*
	== accès aux classes de personnages
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé, $locale = chaîne de caractères de la locale souhaitée selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions. Ces infos sont contenues dans $this->HOSTS et $this->LOCALES.
	<- en sortie tableau {true/false si l'exécution s'est bien passée {classe} classe est détaillée sur http://blizzard.github.com/api-wow-docs/#data-resources/character-classes
	*/
		try {
			$result = array(false, null);
			$classes = 'http://' . $host . '/api/wow/data/character/classes?locale=' . $locale;
			$data = file_get_contents($classes);
			$classes = json_decode($data)->classes;
			$result = array(true, $classes);
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}

	function ApiLireRoyaumes($host) {
	/*
	== accès aux royaumes
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions
	<- en sortie tableau {true/false si l'exécution s'est bien passée {realm} realm est détaillé sur http://blizzard.github.com/api-wow-docs/#realm-status-api
	*/
		try {
			$result = array(false, null);
			$realms = 'http://' . $host . '/api/wow/realm/status';
			$data = file_get_contents($realms);
			$realms = json_decode($data)->realms;
			$result = array(true, $realms);
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}


	function ApiLireHautFaits($host, $locale) {
	/*
	== accès aux descriptions de haut-faits
	-> en entrée $host = chaîne de caractères host du serveur Blizzard interrogé, $locale = chaîne de caractères de la locale souhaitée selon http://blizzard.github.com/api-wow-docs/#features/access-and-regions. Ces infos sont contenues dans $this->HOSTS et $this->LOCALES.
	<- en sortie tableau {true/false si l'exécution s'est bien passée {achievement} realm est détaillé sur http://blizzard.github.com/api-wow-docs/#data-resources/character-achievements
	*/
		try {
			$result = array(false, null);
			$data = 'http://' . $host . '/api/wow/data/character/achievements?locale=' . $locale;
			$achievements = file_get_contents($data);
			$data = json_decode($achievements)->achievements;
			$result = array(true, $data);
		} catch(Exception $e) {
			throw $e;
		}
		return $result;
	}
	//----------------------------------------------------------------------------------------------------------------------------------------
	// 2-Fonctions nécessaires à la fonction ApiMajPersonnages, à redéfinir en fonction de votre appli et de votre base de données
	//----------------------------------------------------------------------------------------------------------------------------------------
	
	protected function ApiLireRoyaume($bdd, $realmId) {}
	/*
	== récupération des infos du royaume 
	-> en entrée $bdd = objet PDO, $realmId = id du royaume en bdd 
	<- en sortie tableau {true/false si l'exécution s'est bien passée, {region, realm}}
	*/

	protected function ApiLirePersonnages($bdd, $charSel) {}
	/*
	== récupération des infos personnages 
	-> en entrée $bdd = objet PDO, $charSel = {id} de la bdd 
	<- en sortie tableau {true/false si l'exécution s'est bien passée, {id, {thumbnail, name, lastUpdate, region, realm}}}
	*/

	protected function ApiLireDonnéesPersonnagesParNom($bdd, $realmChars) {}
	/*
	== récupération des infos personnages 
	-> en entrée $bdd = objet PDO, $realmChars = tableau {realmId {CharNames}} 
	<- en sortie tableau {true/false si l'exécution s'est bien passée, {realmId {name {thumbnail, lastUpdate}}}}
	*/

	protected function ApiUpdatePersonnages($bdd, $dataArray) {}
	/*
	== mise à jour des données personnages 
	-> en entrée $bdd = objet PDO, $dataArray = tableau {character} (character est détaillé sur http://blizzard.github.com/api-wow-docs/#data-resources/character-achievements)
	<- en sortie tableau {true/false si l'exécution s'est bien passée {nb de personnages en erreur, nb de personnages déjà à jour, nb de personnages mis à jour, nb de mises à jour} {id des personnages}}
	*/

	protected function ApiSavPersonnagesDisparus($bdd, $date, $charSel) {}
	/*
	== gère les personnages présents en bdd absents de la requète API (renommés, supprimés ou changés de serveur) 
	-> en entrée $bdd = objet PDO, $date du constat de disparition, $charSel = {id} id des personnages en bdd
	<- en sortie true/false si l'exécution s'est bien passée
	*/

	protected function ApiSavImagePersonnage($url, $image, $region, $thumbnail, $oldThumbnail) {}
	/*
	== gestion de l'image d'un personnage
	-> en entrée $url = chaîne de caractères de l'url de l'image à sauvegarder, $image = chaîne de caractères de l'image à sauvegarder, $region = chaîne de caractères de la region du personnage, $thumbnail = chaîne de caractères du nom de l'image, $oldThumbnail = si non null, chaîne de caractères du nom de l'ancienne image du personnage
	<- en sortie true/false l'exécution s'est bien passée 
	*/

}

?>