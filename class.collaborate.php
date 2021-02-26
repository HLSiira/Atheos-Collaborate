<?php

//////////////////////////////////////////////////////////////////////////////80
// Collaborate Class
//////////////////////////////////////////////////////////////////////////////80
// Copyright (c) 2020 Liam Siira (liam@siira.io), distributed as-is and without
// warranty under the MIT License. See [root]/license.md for more.
// This information must remain intact.
//////////////////////////////////////////////////////////////////////////////80
// Authors: Codiad Team, Florent Galland/Luc Verdier, Atheos Team, @hlsiira
//////////////////////////////////////////////////////////////////////////////80

class Collaborate {

	//////////////////////////////////////////////////////////////////////////80
	// PROPERTIES
	//////////////////////////////////////////////////////////////////////////80
	private $activeUser = null;
	private $db = null;

	//////////////////////////////////////////////////////////////////////////80
	// METHODS
	//////////////////////////////////////////////////////////////////////////80

	// ----------------------------------||---------------------------------- //

	//////////////////////////////////////////////////////////////////////////80
	// Construct
	//////////////////////////////////////////////////////////////////////////80
	public function __construct($activeUser) {
		$this->activeUser = $activeUser;
		$this->db = Common::getScroll("collaborate");
		if (!is_dir(DATA . "/collaborate")) {
			mkdir(DATA . "/collaborate");
		}
	}


	//////////////////////////////////////////////////////////////////////////80
	// Register
	//////////////////////////////////////////////////////////////////////////80
	public function register($path) {
		$hash = $this->getHash($path);

		$where = array(["path", "==", $path]);
		$results = $this->db->select($where);

		if (empty($results)) {
			$res = $this->initCollab($hash, $path);
			if ($res) {

				Common::send("success", array(
					"text" => "Registered as collaborator on file.",
					"hash" => $hash
				));
			} else {
				Common::send("success", "Could not initialize collaboration.");
			}
		} else {
			$results["collaborators"][] = $this->activeUser;
			$this->db->update($where, $results);

			$path = DATA . "/collaborate/$hash";
			$content = file_get_contents($path);

			Common::send("success", array(
				"text" => "Registered as collaborator on file.",
				"content" => $content,
				"hash" => $hash
			));
		}
	}


	//////////////////////////////////////////////////////////////////////////80
	// Unregister
	//////////////////////////////////////////////////////////////////////////80
	public function unregister($path = false, $all = false) {
		if ($path) {
			$where = array(["path", "==", $path]);
			$results = $this->db->select($where);

			if (!empty($results) && in_array($this->user, $results["collaborators"])) {
				unset($results["collaborators"][$this->user]);
				$this->db->update($where, $results);

				if (count($results["collaborators"]) <= 1) {
					$this->deleteCollab($path);
				}
			}

			Common::send("success", "Unregistered as collaborator on file.");
		} elseif ($all) {
			Common::send("notice", "Unregister all is a WIP.");
		}
	}


	//////////////////////////////////////////////////////////////////////////80
	// UpdateSelection
	//////////////////////////////////////////////////////////////////////////80
	public function updateSelection($path, $selection) {
		/* If user is not already registerd for the given file, register him. */
		if (!isUserRegisteredForFile($filename, $activeUser)) {
			$isRegistered = registerToFile($filename, $activeUser);
			if (!$isRegistered) {
				// Should only be enabled when testing
				//echo formatJSEND('success', 'Not registered as collaborator for ' . $filename);
				exit;
			}
		}

		$selection = json_decode($_POST['selection']);
		$query = array('user' => $activeUser, 'filename' => $filename);
		$entry = getDB()->create($query, 'selection');
		$entry->put_value($selection);
		echo formatJSEND('success');
	}


	//////////////////////////////////////////////////////////////////////////80
	// InitCollab
	//////////////////////////////////////////////////////////////////////////80
	public function initCollab($hash, $path) {
		copy($path, DATA . "/collaborate/$hash");

		$value = array(
			"path" => $path,
			"name" => $hash,
			"collaborators" => [$this->activeUser],
			"time" => time());

		$this->db->insert($value);

		return $hash;
	}

	//////////////////////////////////////////////////////////////////////////80
	// DeleteCollabe
	//////////////////////////////////////////////////////////////////////////80
	public function deleteCollab($path) {
		$hash = $this->getHash($path);
		unlink(DATA . "/collaborate/$hash");

		$where = array(["path", "==", $path]);
		$this->db->delete($where);

		return $hash;
	}


	//////////////////////////////////////////////////////////////////////////80
	// SyncText
	//////////////////////////////////////////////////////////////////////////80
	public function syncText($hash, $patch) {
		$path = DATA . "/collaborate/$hash";
		$handle = fopen($path, 'w');

		$patchedServer = null;

		/* Patch the shadow text with the edits from the client. */
		if (flock($handle, LOCK_EX)) {
			$shadow = fread($handle, filesize($path));
			// $patch = $dmp->patch_fromText($patch);

			$dmp = new diff_match_patch();
			$patchedServer = $dmp->patch_apply($dmp->patch_fromText($patch), $shadow);

			fwrite($handle, $patchedServer[0]);
			fflush($handle); // flush output before releasing the lock
			flock($handle, LOCK_UN); // release the lock
			fclose($file);
		} else {
			Common::send("error", "Unabled to lock shadow.");
		}


		/* Make a diff between server text and shadow to get the edits to send
         * back to the client. */
		$patch = $dmp->patch_toText($dmp->patch_make($patchedServer[0], $patch));

		Common::send("success", array(
			"text" => "Synchronized shadow text.",
			"patch" => $patch
		));
	}

	public function getHash($path) {
		return md5($path);
	}

}