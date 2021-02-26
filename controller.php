<?php

//////////////////////////////////////////////////////////////////////////////80
// Collaborate Controller
//////////////////////////////////////////////////////////////////////////////80
// Copyright (c) 2020 Liam Siira (liam@siira.io), distributed as-is and without
// warranty under the MIT License. See [root]/license.md for more.
// This information must remain intact.
//////////////////////////////////////////////////////////////////////////////80
// Authors: Codiad Team, Florent Galland/Luc Verdier, Atheos Team, @hlsiira
//////////////////////////////////////////////////////////////////////////////80

/*
     * Suppose a user wants to register as a collaborator of file "/test/test.js".
     * He registers to a specific file by creating a marker file
     * "data/_test_test.js%%filename%%username%%registered", and he can
     * unregister by deleting this file. Then his current selection will be in
     * file "data/_test_test.js%%username%%selection".
     * The collaborative editing algorithm is based on the differential synchronization
     * algorithm by Neil Fraser. The text shadow and server text are stored
     * respectively in "data/_test_test.js%%filename%%username%%shadow" and
     * "data/_test_test.js%%filename%%text".
     * At regular time intervals, the user send an heartbeat which is stored in
     * "data/_test_test.js%%username%%heartbeat" .
     */

require_once(BASE_PATH . "/lib/differential/diff_match_patch.php");
require_once "class.collaborate.php";

$activeUser = SESSION("user");
$Collab = new Collaborate($activeUser);

$filename = POST("filename");

Common::send("notice", "Collaborate not complete");

//////////////////////////////////////////////////////////////////
// Get Action
//////////////////////////////////////////////////////////////////
switch ($action) {
	//////////////////////////////////////////////////////////////////////////80
	// Register as a collaborator for the given filename
	//////////////////////////////////////////////////////////////////////////80
	case "register":
		if (!$filename) Common::send("error", "Missing file path.");
		$Collab->register($filename);
		break;

	//////////////////////////////////////////////////////////////////////////80
	// Unregister as a collaborator
	//////////////////////////////////////////////////////////////////////////80
	case "unregister":
		$removeAll = POST("removeAll");
		if (!$filename && !$removeAll) Common::send("error", "Missing file path.");
		$Collab->unregister($filename, $removeAll);
		break;

	case "resetSelection":
		$resetAll = POST("resetALl");
		if (!$filename && !$resetAll) Common::send("error", "Missing file path.");

		$query = array("user" => $activeUser, "filename" => "*");
		$entries = getDB()->select($query, "selection");
		foreach ($entries as $entry) {
			$entry->remove();
		}
		$entries = getDB()->select($query, "change");
		foreach ($entries as $entry) {
			$entry->remove();
		}
		echo formatJSEND("success");
		break;

	case "resetFile":
		$entries = getDB()->select_group("text");
		foreach ($entries as $entry) $entry->remove();
		echo formatJSEND("success");
		break;

	case "syncText":
		$hash = POST("hash");
		$patch = POST("patch");
		if (!$hash) Common::send("error", "Missing file path.");
		if (!$patch) Common::send("error", "Missing patch.");
		$Collab->syncText($hash, $patch);
		break;

	//////////////////////////////////////////////////////////////////////////80
	// Default: Invalid Action
	//////////////////////////////////////////////////////////////////////////80
	default:
		Common::send("error", "Invalid action.");
		break;

}
