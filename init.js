//////////////////////////////////////////////////////////////////////////////80
// Collaborative Init
//////////////////////////////////////////////////////////////////////////////80
// Copyright (c) Atheos & Liam Siira (Atheos.io), distributed as-is and without
// warranty under the MIT License. See [root]/LICENSE.md for more.
// This information must remain intact.
//////////////////////////////////////////////////////////////////////////////80
// Authors: Codiad Team, Florent Galland/Luc Verdier, Atheos Team, @hlsiira
//////////////////////////////////////////////////////////////////////////////80

(function(global) {
	'use strict';

	var atheos = global.atheos;

	var self = null;

	carbon.subscribe('system.loadExtra', () => atheos.collab.init());

	//////////////////////////////////////////////////////////////////
	//
	// Collaborative Component for atheos
	// ---------------------------------
	// Displays in real time the selection position and
	// the changes when concurrently editing files.
	//
	//////////////////////////////////////////////////////////////////

	atheos.collab = {

		/* The filename of the file to wich we are currently registered as a
			* collaborator. Might be null if we are not collaborating to any file. */
		activeFilePath: null,

		/* Store the text shadows for every edited files.
			* {'filename': shadowString, ... } */
		shadows: {},

		/* Store the currently displayed usernames and their corresponding
			* current selection.
			* [username: {start: {row: 12, column: 14}, end: {row: 14, column: 19}}, ... ] */
		displayedSelections: [],

		/* Time interval in milisecond to send an heartbeat to the server. */
		heartbeatInterval: 5000,

		/* Status of the collaboration logic. */
		enabled: false,

		init: function() {
			/* FIXME Dynamically load diff match patch lib. Is there any better way? */
			atheos.common.loadScript('lib/differential/diff_match_patch.min.js');
			self = this;

			/* Make sure to start clean by unregistering from any file first. */
			self.unregister(null, true);
			self.resetSelection(null, true);
			self.resetFile(null, true);

			/* Subscribe to know when a file is being closed. */
			carbon.subscribe('active.close', function(path) {
				if (self.activeFilePath === path) {
				self.unregister(self.activeFilePath);
			self.resetSelection(null, true);
				}
			});

			/* Subscribe to know when a file become active. */
			carbon.subscribe('active.focus', function(path) {
				self.unregister(self.activeFilePath);
				self.register(path);

				/* Create the initial shadow for the current file. */
				self.shadows[self.activeFilePath] = atheos.editor.getContent();
				self.sendAsShadow(self.activeFilePath, self.shadows[self.activeFilePath]);
				self.addListeners();
			});

			/* Start to send an heartbeat to notify the server that we are alive. */
			setInterval(self.sendHeartbeat, self.heartbeatInterval);

			/* Start the collaboration logic. */
			self.setStatus(true);

			fX(".collaborate .selection,.collaborate .tooltip").on('mouseover,mouseout', function(e) {
				var tooltip = oX(e.target).parent('.collaborate');
				if (e.type === 'mouseover') {
					self.showTooltip(markup);
				} else if (e.type === 'mouseout') {
					self.showTooltip(markup, 500);
				}
			});
		},

		/* Start or stop the collaboration logic. */
		setStatus: function(enable) {
			if (enable && !self.enabled) {
				log('Starting collaboration timers.');
				self.enabled = true;
				/* Periodically ask for collaborator selections. */
				carbon.sub('chrono.kilo', self.updateCollaboratorsSelections);

				/* Periodically ask for collaborator changes */
				carbon.sub('chrono.kilo', self.synchronizeText);

				/* Sync right away for responsiveness */
				self.synchronizeText();

			} else if (!enable && self.enabled) {
				log('Stopping collaboration timers.');
				self.enabled = false;
				carbon.del('chrono.kilo', self.updateCollaboratorsSelections);
				carbon.del('chrono.kilo', self.synchronizeText);
				self.resetSelection(null, true);
			}
		},

		// Was removeSelectionAndChangesForAllFiles
		resetSelection: function(path, resetAll) {
			path = path || atheos.active.getPath();
			if (!path && !resetAll) return;

			echo({
				data: {
					target: 'Collaborate',
					action: 'resetFile',
					filename: path,
					resetAll
				},
				settled: function(status, reply) {
					// log('complete unregistering');
					log(status, reply);
				}
			});
		},

		// Was removeServerTextForAllFiles
		resetFile: function(path, resetAll) {
			// log('unregister ' + self.activeFilePath);
			path = path || atheos.active.getPath();
			if (!path && !resetAll) return;

			echo({
				data: {
					target: 'Collaborate',
					action: 'resetFile',
					filename: path,
					resetAll
				},
				settled: function(status, reply) {
					// log('complete unregistering');
					log(status, reply);
				}
			});
		},

		register: function(path) {
			path = path || atheos.active.getPath();
			echo({
				data: {
					target: 'Collaborate',
					action: 'register',
					filename: path

				},
				settled: function(status, reply) {
					// log('complete registering');
					log(status, reply);
				}
			});
		},

		unregister: function(path, removeAll) {
			// log('unregister ' + self.activeFilePath);
			path = path || atheos.active.getPath();
			if (!path && !removeAll) return;

			echo({
				data: {
					target: 'Collaborate',
					action: 'unregister',
					filename: path,
					removeAll
				},
				settled: function(status, reply) {
					// log('complete unregistering');
					log(status, reply);
				}
			});
		},

		sendHeartbeat: function() {
			echo({
				data: {
					target: 'Collaborate',
					action: 'sendHeartbeat'
				},
				settled: function(status, reply) {
					// log('complete registering');
					log(status, reply);
					/* The data returned by the server contains the number
						* of connected collaborators. */
					self.setStatus(reply.collaboratorCount > 1);
				}
			});
		},

		/* Add appropriate listeners to the current EditSession. */
		addListeners: function() {
			self.setChangeListener('add');
			self.setSelectionListener('add');
		},

		/* Remove listeners from the current EditSession. */
		removeListeners: function() {
			self.setChangeListener('remove');
			self.setSelectionListener('remove');
		},

		setSelectionListener: function(type) {
			var selection = atheos.editor.getSelection();
			if (type === 'add') {
				selection.addEventListener('changeCursor', self.onSelectionChange);
				selection.addEventListener('changeSelection', self.onSelectionChange);
			} else {
				selection.removeEventListener('changeCursor', self.onSelectionChange);
				selection.removeEventListener('changeSelection', self.onSelectionChange);
			}
		},

		setChangeListener: function(type) {
			var editor = atheos.editor.getActive();
			if (type === 'add') {
				editor.addEventListener('change', self.synchronizeText);
			} else {
				editor.removeEventListener('change', self.synchronizeText);
			}
		},

		/* Throttling mechanism for postSelectionChange */
		onSelectionChange: function(e) {
			var minInterval = 250;
			var now = new Date().getTime();
			if (typeof(self.onSelectionChange.lastSelectionChange) === 'undefined') {
				self.onSelectionChange.lastSelectionChange = now;
			}
			var interval = now - self.onSelectionChange.lastSelectionChange;
			self.onSelectionChange.lastSelectionChange = now;

			if (interval < minInterval) {
				var intervalDifference = minInterval - interval;
				clearTimeout(self.onSelectionChange.deferredPost);
				self.onSelectionChange.deferredPost = setTimeout(self.postSelectionChange, intervalDifference);
			} else {
				self.postSelectionChange();
			}
		},

		postSelectionChange: function() {
			echo({
				data: {
					target: 'Collaborate',
					action: 'sendSelectionChange',
					filename: atheos.active.getPath(),
					selection: JSON.stringify(atheos.editor.getSelectionRange())
				},
				settled: function(status, reply) {
					// log('complete selection change');
					log(status, reply);
				}
			});

		},

		/* Request the server for the collaborators selections for the current
			* file. */
		updateCollaboratorsSelections: function() {
			if (self.activeFilePath === null) return;

			echo({
				data: {
					target: 'Collaborate',
					action: 'getUsersAndSelectionsForFile',
					filename: self.activeFilePath
				},
				settled: function(status, reply) {
					// log('complete getUsersAndSelectionsForFile');
					log(status, reply);
					self.displaySelections(reply);

					if (self.displayedSelections !== null) {
						for (var username in self.displayedSelections) {
							if (self.displayedSelections.hasOwnProperty(username)) {
								if (reply === null || !(username in reply)) {
									self.removeSelection(username);
								}
							}
						}
					}

					self.displayedSelections = reply;

				}
			});
		},

		/* Displays a selection in the current file for the given user.
			* The expected selection object is compatible with what is returned
			* from the getUsersAndSelectionsForFile action on the server
			* controller.
			* Selection object example:
			* {username: {start: {row: 12, column: 14}, end: {row: 14, column: 19}}} */
		displaySelections: function(selections) {
			// log('displaySelection');
			for (var username in selections) {
				if (!selections.hasOwnProperty(username)) return;
				let user = selections[username],
					tooltip = oX('#selection-' + username);

				if (!tooltip) {
					tooltip = oX(self.genTooltip(username));
					oX('body').append(tooltip);
				}


				var screenCoordinates = atheos.editor.getActive().renderer
					.textToScreenCoordinates(user.selection.start.row,
						user.selection.start.column);

				/* Check if the selection has changed. */
				if (tooltip.css('left').slice(0, -2) !== String(screenCoordinates.pageX) ||
					tooltip.css('top').slice(0, -2) !== String(screenCoordinates.pageY)) {

					tooltip.css({
						left: screenCoordinates.pageX,
						top: screenCoordinates.pageY
					});

					tooltip.css('background-color', user.color);
					self.showTooltip(tooltip, 2000);
				}

			}
		},

		/* Show the tooltip of the given markup. If duration is defined,
			* the tooltip is automaticaly hidden when the time is elapsed. */
		showTooltip: function(tooltip, duration) {
			log('SHOW TOOLTIP');
			return;
			var timeoutRef = markup.attr('hideTooltipTimeoutRef');
			if (timeoutRef !== undefined) {
				clearTimeout(timeoutRef);
				tooltip.removeAttr('hideTooltipTimeoutRef');
			}

			tooltip.children('.collaborative-selection-tooltip').fadeIn('fast');

			if (duration !== undefined) {
				timeoutRef = setTimeout(() => self.hideTooltip(tooltip), duration);
				tooltip.attr('hideTooltipTimeoutRef', timeoutRef);
			}
		},

		/* This function must be bound with the markup which contains
			* the tooltip to hide. */
		hideTooltip: function(tooltip) {
			self.children('.collaborative-selection-tooltip').fadeOut('fast');
			self.removeAttr('hideTooltipTimeoutRef');
		},

		/* Remove the selection corresponding to the given username. */
		removeSelection: function(username) {
			log('remove ' + username);
			oX('#selection-' + username).remove();
			delete self.displayedSelections[username];
		},

		/* Remove all the visible selections. */
		removeAllSelections: function() {
			if (self.displayedSelections !== null) {
				for (var username in self.displayedSelections) {
					if (self.displayedSelections.hasOwnProperty(username)) {
						self.removeSelection(username);
					}
				}
			}
		},

		/* Throttling mechanism for postSynchronizeText */
		synchronizeText: throttle(function() {
			self.postSynchronizeText();
		}, 350),

		/* Make a diff of the current file text with the shadow and send it to
			* the server. */
		postSynchronizeText: function() {
			var activeFilePath = self.activeFilePath;

			/* Do not send any request if no file is focused. */
			if (activeFilePath === null) {
				return;
			}

			/* Save the current text state, because it can be modified by the
				* user on the UI thread. */
			var currentText = atheos.editor.getContent();

			/* Make a diff between the current text and the previously saved
				* shadow. */
			atheos.workerManager.addTask({
				taskType: 'diff',
				id: 'collaborative_' + activeFilePath,
				original: self.shadows[activeFilePath],
				changed: currentText
			}, function(success, patch) {
				if (success) {
					/* Send our edits to the server, and get in response a
						* patch of the edits in the server text. */
					// log(patch);
					self.shadows[activeFilePath] = currentText;

					echo({
						data: {
							target: 'collborate',
							action: 'synchronizeText',
							filename: activeFilePath,
							patch: patch
						},
						settled: function(status, reply) {
							// log('complete synchronizeText');
							log(status, reply);
							var patchFromServer = atheos.jsend.parse(data);
							if (patchFromServer === 'error') {
								return;
							}
							// log(patchFromServer);

							/* Apply the patch from the server text to the shadow
								* and the current text. */
							var dmp = new diff_match_patch();
							var patchedShadow = dmp.patch_apply(dmp.patch_fromText(patchFromServer), self.shadows[activeFilePath]);
							// log(patchedShadow);
							self.shadows[activeFilePath] = patchedShadow[0];

							/* Update the current text. */
							currentText = atheos.editor.getContent();
							var patchedCurrentText = dmp.patch_apply(dmp.patch_fromText(patchFromServer), currentText)[0];

							var diff = dmp.diff_main(currentText, patchedCurrentText);
							var deltas = self.diffToAceDeltas(diff, currentText);

							atheos.editor.getDocument().applyDeltas(deltas);
						}
					});
				} else {
					log('problem diffing');
					log(patch);
				}
			}, this);
		},

		/* Send the string 'shadow' as server shadow for 'filename'. */
		sendAsShadow: function(filename, shadow) {
			echo({
				data: {
					target: 'Collaborate',
					action: 'sendShadow',
					filename: filename,
					shadow: shadow
				},
				settled: function(status, reply) {
					// log('complete sendShadow');
					log(status, reply);
				}
			});

		},

		/* Helper method that return a Ace editor delta change from a
			* diff_match_patch diff object and the original text that was
			* used to compute the diff. */
		diffToAceDeltas: function(diff, originalText) {
			var dmp = new diff_match_patch();
			var deltas = dmp.diff_toDelta(diff).split('\t');

			// Code deeply inspired by chaoscollective / Space_Editor
			var offset = 0;
			var row = 1;
			var col = 1;
			var aceDeltas = [];
			var aceDelta = {};
			for (var i = 0; i < deltas.length; ++i) {
				var type = deltas[i].charAt(0);
				var data = decodeURI(deltas[i].substring(1));

				switch (type) {
					case "=":
						/* The new text is equal to the original text for a
							* number of characters. */
						var unchangedCharactersCount = parseInt(data, 10);
						for (var j = 0; j < unchangedCharactersCount; ++j) {
							if (originalText.charAt(offset + j) == "\n") {
								++row;
								col = 1;
							} else {
								col++;
							}
						}
						offset += unchangedCharactersCount;
						break;

					case "+":
						/* Some characters were added. */
						aceDelta = {
							action: "insertText",
							range: {
								start: {
									row: (row - 1),
									column: (col - 1)
								},
								end: {
									row: (row - 1),
									column: (col - 1)
								}
							},
							text: data
						};
						aceDeltas.push(aceDelta);

						var innerRows = data.split("\n");
						var innerRowsCount = innerRows.length - 1;
						row += innerRowsCount;
						if (innerRowsCount <= 0) {
							col += data.length;
						} else {
							col = innerRows[innerRowsCount].length + 1;
						}
						break;

					case "-":
						/* Some characters were subtracted. */
						var deletedCharactersCount = parseInt(data, 10);
						var removedData = originalText.substring(offset, offset + deletedCharactersCount);

						var removedRows = removedData.split("\n");
						var removedRowsCount = removedRows.length - 1;

						var endRow = row + removedRowsCount;
						var endCol = col;
						if (removedRowsCount <= 0) {
							endCol = col + deletedCharactersCount;
						} else {
							endCol = removedRows[removedRowsCount].length + 1;
						}

						aceDelta = {
							action: "removeText",
							range: {
								start: {
									row: (row - 1),
									column: (col - 1)
								},
								end: {
									row: (endRow - 1),
									column: (endCol - 1)
								}
							},
							text: data
						};
						aceDeltas.push(aceDelta);

						offset += deletedCharactersCount;
						break;

					default:
						/* Return an innofensive empty list of Ace deltas. */
						log("Unhandled case '" + type + "' while building Ace deltas.");
						return [];
				}
			}
			return aceDeltas;
		},

		genTooltip: function(username) {
			return '<div id="selection-' + username + '" class="collaborate">' +
				'<div class="selection"></div>' +
				'<div class="tooltip">' + username + '</div>' +
				'</div>';
		},
	};

})(this);