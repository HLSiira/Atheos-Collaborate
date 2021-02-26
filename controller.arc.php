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

	//////////////////////////////////////////////////////////////////////////80
	// Push the current selection to the server
	//////////////////////////////////////////////////////////////////////////80
	case "sendSelectionChange":
		$selection = POST("selection");
		if (!$filename) Common::send("error", "Missing file path.");
		if (!$selection) Common::send("error", "Missing selection.");
		$Collab->updateSelection($filename, $selection);
		break;

	case "getUsersAndSelectionsForFile":
		/* Get an object containing all the users registered to the given file
         * and their associated selections. The data corresponding to the
         * current user is omitted. */
		if (!$filename) Common::send("error", "Missing file path.");

		$usersAndSelections = array();
		$users = getRegisteredUsersForFile($filename);
		foreach ($users as $user) {
			if ($user !== $activeUser) {
				$selection = getSelection($filename, $user);
				if (!empty($selection)) {
					$data = array(
						"selection" => $selection,
						"color" => getColorForUser($user)
					);
					$usersAndSelections[$user] = $data;
				}
			}
		}

		echo formatJSEND("success", $usersAndSelections);
		break;

	case "sendShadow":
		$shadow = POST("shadow");
		if (!$filename) Common::send("error", "Missing file path.");
		if (!$shadow) Common::send("error", "Missing shadow.");

		setShadow($filename, $activeUser, $shadow);

		/* If there is no server text for $filename or if there is still no or
        * only one user registered for $filename, set the server text equal
        * to the shadow. */
		$registeredUsersForFileCount = count(getRegisteredUsersForFile($filename));
		if (!existsServerText($filename) || $registeredUsersForFileCount == 0) {
			setServerText($filename, $shadow);
		}

		echo formatJSEND("success");
		break;

	case "syncText":
		$hash = POST("hash");
		$patch = POST("patch");
		if (!$hash) Common::send("error", "Missing file path.");
		if (!$patch) Common::send("error", "Missing patch.");
		$Collab->syncText($hash, $patch);
		break;

	case "sendHeartbeat":
		/* Hard coded heartbeat time interval. Beware to keep this value here
        * twice the value on client side. */
		$maxHeartbeatInterval = 5;
		$currentTime = time();

		/* Check if the user is a new user, or if it is just an update of
         * his heartbeat. */
		$isUserNewlyConnected = true;

		$query = array("user" => $activeUser);
		$entry = getDB()->select($query, "heartbeat");
		if ($entry != null) {
			$heartbeatTime = $entry->get_value();
			$heartbeatInterval = $currentTime - $heartbeatTime;
			$isUserNewlyConnected = ($heartbeatInterval > 1.5*$maxHeartbeatInterval);

			/* If the user is newly connected and if the heartbeat file
             * exits, that mean that the user was the latest in the previous
             * collaborative session. We need to call the disconnect method
             * to clear the data relatives to the user before calling the
             * connect method. */
			if ($isUserNewlyConnected) {
				onCollaboratorDisconnect($activeUser);
			}
		}

		updateHeartbeatMarker($activeUser);

		/* If the user is newly connected, we fire the
         * corresponding method. */
		if ($isUserNewlyConnected) {
			onCollaboratorConnect($activeUser);
		}

		$usersAndHeartbeatTime = getUsersAndHeartbeatTime();
		foreach ($usersAndHeartbeatTime as $user => $heartbeatTime) {
			if (($currentTime - $heartbeatTime) > $maxHeartbeatInterval) {
				/* The $user heartbeat time is too old, consider him dead and
                 * remove his "registered"  and "heartbeat" marker files. */
				unregisterFromAllFiles($user);
				removeHeartbeatMarker($user);
				onCollaboratorDisconnect($user);
			}
		}

		/* Return the number of connected collaborators. */
		$collaboratorCount = count(getUsersAndHeartbeatTime());
		$data = array();
		$data["collaboratorCount"] = $collaboratorCount;
		echo formatJSEND("success", $data);
		break;

	//////////////////////////////////////////////////////////////////////////80
	// Default: Invalid Action
	//////////////////////////////////////////////////////////////////////////80
	default:
		Common::send("error", "Invalid action.");
		break;

}

// --------------------
/* $filename must contain only the basename of the file. */
function isUserRegisteredForFile($filename, $user) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->select($query, "registered");
	return ($entry != null);
}

/* Unregister the given user from all the files by removing his
     * "registered" marker file. */
function unregisterFromAllFiles($user) {
	$query = array("user" => $user, "filename" => "*");
	$entries = getDB()->select($query, "registered");
	foreach ($entries as $entry) {
		$entry->remove();
	}
}

/* Register as a collaborator for the given filename. Return false if
    * failed. */
function registerToFile($user, $filename) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->select($query, "registered");
	if ($entry != null) {
		debug("Warning: already registered as collaborator for " . $filename);
		return true;
	} else {
		$entry = getDB()->create($query, "registered");
		if ($entry != null) {
			return true;
		} else {
			debug("Error: unable to register as collaborator for " . $filename);
			return false;
		}
	}
}

/* Touch the heartbeat marker file for the given user. Return true on
     * success, false on failure. */
function updateHeartbeatMarker($user) {
	$query = array("user" => $user);
	$entry = getDB()->create($query, "heartbeat");
	if ($entry == null) return false;
	$entry->put_value(time());
	return true;
}

function removeHeartbeatMarker($user) {
	$query = array("user" => $user);
	$entry = getDB()->select($query, "heartbeat");
	if ($entry != null) $entry->remove();
}

/* Return an array containing the user as key and his last heartbeat time
     * as value. */
function &getUsersAndHeartbeatTime() {
	$usersAndHeartbeatTime = array();
	$query = array("user" => "*");
	$entries = getDB()->select($query, "heartbeat");
	foreach ($entries as $entry) {
		$user = $entry->get_field("user");
		$usersAndHeartbeatTime[$user] = $entry->get_value();
	}
	return $usersAndHeartbeatTime;
}

/* $filename must contain only the basename of the file. */
function &getRegisteredUsersForFile($filename) {
	$usernames = array();
	$query = array("user" => "*", "filename" => $filename);
	$entries = getDB()->select($query, "registered");
	foreach ($entries as $entry) {
		$user = $entry->get_field("user");
		$usernames[] = $user;
	}
	return $usernames;
}

/* Return the selection object, if any, for the given filename and user.
     * $filename must contain only the basename of the file. */
function getSelection($filename, $user) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->select($query, "selection");
	if ($entry == null) return null;
	return $entry->get_value();
}

/* Return the list of changes, if any, for the given filename, user and
     * from the given revision number.
     * $filename must contain only the basename of the file. */
function getChanges($filename, $user, $fromRevision) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->select($query, "change");
	if ($entry == null) return null;
	return array_slice($entry->get_value(), $fromRevision, NULL, true);
}

/* Set the server shadow acquiring an exclusive lock on the file. $shadow
     * is a string. */
function setShadow($filename, $user, $shadow) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->create($query, "shadow");
	if ($entry == null) return null;
	$entry->put_value($shadow);
}

/* Return the shadow for the given filename as a string or an empty string
     * if no shadow exists. */
function getShadow($filename, $user) {
	$query = array("user" => $user, "filename" => $filename);
	$entry = getDB()->select($query, "shadow");
	if ($entry == null) return null;
	return $entry->get_value();
}

function existsServerText($filename) {
	$query = array("filename" => $filename);
	$entry = getDB()->select($query, "text");
	return ($entry != null);
}

/* Set the server text acquiring an exclusive lock on the file. $serverText
     * is a string. */
function setServerText($filename, $serverText) {
	$query = array("filename" => $filename);
	$entry = getDB()->create($query, "text");
	if ($entry == null) return null;
	$entry->put_value($serverText);
}

/* Return the server text for the given filename as a string or an empty string
     * if no server text exists. */
function getServerText($filename) {
	$query = array("filename" => $filename);
	$entry = getDB()->select($query, "text");
	if ($entry == null) return null;
	return $entry->get_value();
}

?>