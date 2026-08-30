-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- TheWalkingDeck implementation : © <Cedor> <cedordev@gmail.com>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----
-- dbmodel.sql
-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here
-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.
CREATE TABLE IF NOT EXISTS `twd_card` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_location` varchar(32) NOT NULL,
  `card_location_arg` int(11) NOT NULL,
  PRIMARY KEY (`card_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_card_info` (
  `info_id` int(10) unsigned NOT NULL,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_name` varchar(64) NOT NULL,
  `is_zombie` TINYINT(1) NOT NULL DEFAULT 0,
  `is_character` TINYINT(1) NOT NULL DEFAULT 0,
  `consequence_black` JSON,
  `consequence_white` JSON,
  `consequence_grey` JSON,
  `special_draw` TINYINT(1) NOT NULL DEFAULT 0,
  `texts` JSON DEFAULT NULL,
  PRIMARY KEY (`info_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_protagonist_info` (
  `info_id` int(10) unsigned NOT NULL,
  `losscon` TINYINT(1) NOT NULL DEFAULT 5,
  FOREIGN KEY (info_id) REFERENCES `twd_card_info` (`info_id`) 
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_character_info` (
  `info_id` int(10) unsigned NOT NULL,
  `weakness_hunger` TINYINT(1) NOT NULL DEFAULT 0,
  `weakness_break` TINYINT(1) NOT NULL DEFAULT 0,
  `weakness_stress` TINYINT(1) NOT NULL DEFAULT 0,
  `wounds` TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (info_id) REFERENCES `twd_card_info` (`info_id`) 
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_disaster` (
  `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `card_type` varchar(16) NOT NULL,
  `card_type_arg` int(11) NOT NULL,
  `card_location` varchar(16) NOT NULL,
  `card_location_arg` int(11) NOT NULL,
  PRIMARY KEY (`card_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_disaster_info` (
  `card_type` varchar(16) NOT NULL,
  `disaster_hunger` TINYINT(1) NOT NULL DEFAULT 0,
  `disaster_break` TINYINT(1) NOT NULL DEFAULT 0,
  `disaster_stress` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_type`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

CREATE TABLE IF NOT EXISTS `twd_event_stack` (
  `event_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) NOT NULL,
  `event_parameters` JSON DEFAULT NULL,
  PRIMARY KEY (`event_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8 AUTO_INCREMENT = 1;

-- Create all the cards info
INSERT INTO `twd_card_info` (`info_id`, `card_type`, `card_type_arg`, `card_name`, `is_zombie`, `is_character`, `consequence_black`, `consequence_white`, `consequence_grey`, `special_draw`, `texts`) VALUES
(1, '1', 1, 'Aénor', 0, 0, NULL, NULL, NULL, 0, '{"defeat" : {"text" : "5 peripeteia in the graveyard"}, "rule" : {"text" : "Once a game : Tap this Protagonist to prevent one damage done on one character"}}'),
(2, '1', 2, 'Boris', 0, 0, NULL, NULL, NULL, 0, '{"start" : {"text" : "Shuffle all peripeteias and separate them in 2 decks"}, "defeat" : {"text" : "5 peripeteia in the graveyard"}, "rule" : {"text" : "Anytime : consume an available ressource to reveal the top card of each deck"}}'),
(3, '1', 3, 'Adrien', 0, 0, NULL, NULL, NULL, 0, '{"defeat" : {"text" : "4 peripeteia in the graveyard"}, "rule" : {"text" : "something something something"}, "caseUp" : {"text" : "something something something"}, "caseDown" : {"text" : "something something something"}}'),
-- (4, '1', 4, 'Éléonore', 0, 0, NULL, NULL, NULL, 0, '{"defeat" : {"text" : "3 peripeteia in the graveyard"}, "rule" : {"text" : "something something something"}, "case1" : {"text" : "something something something"}, "case2" : {"text" : "something something something"}, "case3" : {"text" : "something something something"}}'),
(5, '2', 1, 'Punk', 1, 0, '{"action" : "bury", "bury" : "this"}', NULL, NULL, 0, '{"black" : {"text" : "Bury this peripeteia"}}'),
(6, '2', 2, 'Wolf Trap', 0, 0, '{"action" : "multiple", "number" : 2, "0" : {"action" : "avoid", "avoid" : "zombie"}, "1" : {"action" : "wolftrap"}}', NULL, '{"action" : "disasterignore", "number" : 1, "ignore" : "stress"}', 0, '{"black" : {"text" : "Avoid a zombie from your hand, then draw a disaster. If it is ${disaster1}/${disaster2}, bury this peripeteia", "args" : {"disaster1" : {"type" : "icon", "name" : "breakStress"}, "disaster2" : {"type" : "icon", "name" : "breakHunger"}}}, "grey" : {"text" : "${disaster} and ignore ${stress}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}, "stress" : {"type" : "icon", "name" : "stress"}}}}'),
(7, '2', 3, 'Clown', 1, 0, NULL, NULL, '{"action" : "bury", "bury" : "this"}', 0, '{"grey" : {"text" : "Bury this peripeteia"}}'),
(8, '2', 4, 'Ellie and Joel', 0, 1, NULL, NULL, '{"action" : "disaster", "number" : 1}', 0, '{"grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(9, '2', 5, 'Kieren', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "unremember"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "disaster", "number" : 1}', 0, '{"black" : {"text" : "Look at the top 3 cards of Memory. Return up to 2 of them to your hand, then bury this peripeteia"}, "grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(10, '2', 6, 'Tallahassee', 0, 1, NULL, '{"action" : "escapeTalla"}', '{"action" : "disaster", "number" : 1}', 0, '{"white" : {"text" : "Avoid a zombie from your hand or the top card of Memory"}, "grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(11, '2', 7, 'Gretchen', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "addemptydisasters"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "disaster", "number" : 1}', 0, '{"black" : {"text" : "Add the 2 empty disasters to the bag, then bury this peripeteia"}, "grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(12, '2', 8, 'Robert', 0, 1, NULL, NULL, '{"action" : "nothing"}', 0, '{"grey" : {"text" : "No effect"}}'),
(13, '2', 9, 'Brigade', 1, 0, NULL, NULL, '{"action" : "bite", "bite" : 2}', 1, '{"special" : {"text" : "Immediately memorise this peripeteia, then draw a new one"}, "grey" : {"text" : "2 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(14, '2', 10, 'Bonfire', 0, 0, '{"action" : "draw", "number" : 3}', NULL, '{"action" : "heal", "number" : 3}', 0, '{"black" : {"text" : "Draw three peripeteias"}, "grey" : {"text" : "${heal} up to 3 characters", "args" : {"heal" : {"type" : "icon", "name" : "heal"}}}}'),
(15, '2', 11, 'Horse', 0, 0, '{"action" : "draw", "number" : 2}', NULL, '{"action" : "heal", "number" : 2}', 0, '{"black" : {"text" : "Draw two peripeteias"}, "grey" : {"text" : "${heal} up to 2 characters", "args" : {"heal" : {"type" : "icon", "name" : "heal"}}}}'),
(16, '2', 12, 'RV', 0, 0, '{"action" : "draw", "number" : 2}', NULL, '{"action" : "restore", "ressource" : "ressource_stress"}', 0, '{"black" : {"text" : "Draw two peripeteias"}, "grey" : {"text" : "${stress}", "args" : {"stress" : {"type" : "icon", "name" : "stress"}}}}'),
(17, '2', 13, 'Cellar', 0, 0, '{"action" : "avoid", "avoid" : "zombie"}', NULL, '{"action" : "heal", "number" : 3, "condition" : "hunger", "condition2" : "stress"}', 0, '{"black" : {"text" : "Avoid a zombie from your hand"}, "grey" : {"text" : "${heal} up to 3 characters with ${hunger}/${stress}", "args" : {"heal" : {"type" : "icon", "name" : "heal"}, "hunger" : {"type" : "icon", "name" : "hunger"}, "stress" : {"type" : "icon", "name" : "stress"}}}}'),
(18, '2', 14, 'Teddy Bear', 0, 0, '{"action" : "restore", "ressource" : "ressource_stress"}', NULL, '{"action" : "heal", "number" : 2, "condition" : "stress"}', 0, '{"black" : {"text" : "${stress}", "args" : {"stress" : {"type" : "icon", "name" : "stress"}}}, "grey" : {"text" : "${heal} up to 2 characters with ${stress}", "args" : {"heal" : {"type" : "icon", "name" : "heal"}, "stress" : {"type" : "icon", "name" : "stress"}}}}'),
(19, '2', 15, 'Wild Zero', 1, 0, '{"action" : "avoid", "avoid" : "bothdeck"}', NULL, '{"action" : "bite", "bite" : 2}', 0, '{"black" : {"text" : "Avoid the top card of both decks, all characters avoided will be buried"}, "grey" : {"text" : "${bite} up to 2 characters", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(20, '2', 16, 'Voodoo', 1, 0, '{"action" : "removedisaster", "disaster" : "break"}', NULL, '{"action" : "bite", "bite" : 2}', 0, '{"black" : {"text" : "Definitively remove the two disasters ${break}", "args" : {"break" : {"type" : "icon", "name" : "break"}}}, "grey" : {"text" : "2 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(21, '2', 17, 'Mutt', 0, 0, '{"action" : "draftdisaster"}', NULL, '{"action" : "multiple", "number" : 2, "0" : {"action" : "unearth"}, "1" : {"action" : "disaster", "number" : 1}}', 0, '{"black" : {"text" : "Draw 2 disasters, remove one from the game and return the other to the bag"}, "grey" : {"text" : "Move the top peripeteia from the graveyard to the escaped zone, then draw a disaster"}}'),
(22, '2', 18, 'Grenade', 0, 0, '{"action" : "avoid", "avoid" : "deck"}', NULL, '{"action" : "avoid", "avoid" : "memory"}', 0, '{"black" : {"text" : "Avoid the top 2 cards of one deck without applying their effects"}, "grey" : {"text" : "Avoid the top card of Memory without applying its effects"}}'),
(23, '3', 1, 'Musicians', 1, 0, NULL, NULL, '{"action" : "bite", "bite" : 3}', 0, '{"grey" : {"text" : "3 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(24, '3', 2, 'Site Manager', 1, 0, NULL, '{"action" : "draw", "number" : 1}', '{"action" : "disaster", "number" : 2}', 0, '{"white" : {"text" : "Draw one peripeteia"}, "grey" : {"text" : "${disaster} ${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(25, '3', 3, 'Glenn', 0, 1, NULL, '{"action" : "brainstorm"}', '{"action" : "disasterignore", "number" : 1, "ignore" : "hunger"}', 0, '{"white" : {"text" : "Reorder the top 3 peripeteias of one deck, then put them back visible"}, "grey" : {"text" : "${disaster} and ignore ${hunger}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}, "hunger" : {"type" : "icon", "name" : "hunger"}}}}'),
(26, '3', 4, 'Murphy', 0, 1, '{"action" : "bury", "bury" : "this"}', NULL, '{"action" : "disasteraggravate2", "number" : 1, "aggravate1" : "break", "aggravate2" : "stress"}', 0, '{"black" : {"text" : "Bury this peripeteia"}, "grey" : {"text" : "${disaster} and add ${weakness1} and ${weakness2}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}, "weakness1" : {"type" : "icon", "name" : "break"}, "weakness2" : {"type" : "icon", "name" : "stress"}}}}'),
(27, '3', 5, 'Horde', 1, 0, NULL, NULL, '{"action" : "bite", "bite" : 3}', 1, '{"special" : {"text" : "Immediately memorise this peripeteia, then draw a new one"}, "grey" : {"text" : "3 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(28, '3', 6, 'Butler', 1, 0, NULL, '{"action" : "consume", "ressource" : "ressource_stress"}', '{"action" : "bite", "bite" : 2}', 0, '{"white" : {"text" : "${consumeStress}", "args" : {"consumeStress" : {"type" : "icon", "name" : "consumedStress"}}}, "grey" : {"text" : "2 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(29, '3', 7, 'Canned food', 0, 0, '{"action" : "nothing"}', '{"action" : "consume", "ressource" : "ressource_hunger"}', '{"action" : "heal", "number" : 2}', 0, '{"black" : {"text" : "No effect"}, "white" : {"text" : "${consumeHunger}", "args" : {"consumeHunger" : {"type" : "icon", "name" : "consumedHunger"}}}, "grey" : {"text" : "${heal} up to 2 characters", "args" : {"heal" : {"type" : "icon", "name" : "heal"}}}}'),
(30, '3', 8, 'Warehouse', 0, 0, '{"action" : "unearth"}', NULL, '{"action" : "heal", "number" : 5}', 0, '{"black" : {"text" : "Move the top peripeteia from the graveyard to the escaped zone"}, "grey" : {"text" : "${heal} up to 5 characters", "args" : {"heal" : {"type" : "icon", "name" : "heal"}}}}'),
(31, '3', 9, 'Medical alcohol', 0, 0, '{"action" : "avoid", "avoid" : "zombie"}', NULL, '{"action" : "heal", "number" : 3, "condition" : "break"}', 0, '{"black" : {"text" : "Avoid a zombie from your hand"}, "grey" : {"text" : "${heal} up to 3 characters with ${break}", "args" : {"heal" : {"type" : "icon", "name" : "heal"}, "break" : {"type" : "icon", "name" : "break"}}}}'),
(32, '3', 10, 'Map', 0, 0, '{"action" : "reveal"}', NULL, '{"action" : "heal", "number" : 2, "condition" : "stress"}', 0, '{"black" : {"text" : "Reveal the top peripeteia of each deck"}, "grey" : {"text" : "${heal} up to 2 characters with ${stress}", "args" : {"heal" : {"type" : "icon", "name" : "heal"}, "stress" : {"type" : "icon", "name" : "stress"}}}}'),
(33, '3', 11, 'Domitille', 0, 1, '{"action" : "avoid", "avoid" : "zombieordie"}', NULL, '{"action" : "bite", "bite" : 1}', 0, '{"black" : {"text" : "Avoid a zombie from your hand or bury this peripeteia"}, "grey" : {"text" : "1 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(34, '3', 12, 'The reaper', 1, 0, '{"action" : "bury", "bury" : "character"}', NULL, '{"action" : "bite", "bite" : 3}', 0, '{"black" : {"text" : "Bury a character from your hand or bury this peripeteia"}, "grey" : {"text" : "3 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(35, '3', 13, 'Controller', 1, 0, '{"action" : "fastmemorise", "from" : "deck"}', NULL, '{"action" : "bite", "bite" : 2}', 0, '{"black" : {"text" : "Memorise 2 peripeteias from one deck"}, "grey" : {"text" : "2 ${bite}", "args" : {"bite" : {"type" : "icon", "name" : "bite"}}}}'),
(36, '3', 14, 'Zoey', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "fastmemorise", "from" : "hand"}, "1" : {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "disaster", "number" : 1}', 0, '{"black" : {"text" : "Send up to 2 peripeteias from your hand to the bottom of memory, then bury this peripeteia"}, "grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(37, '3', 15, 'Jill', 0, 1, '{"action" : "multiple", "number" : 3, "0" : {"action" : "draw", "number" : 1}, "1" :{"action" : "avoid", "avoid" : "hand2"}, "2" :  {"action" : "bury", "bury" : "this"}}', NULL, '{"action" : "disaster", "number" : 1}', 0, '{"black" : {"text" : "Draw 1 peripeteia, avoid up to 2 peripeteias from your hand, then bury this peripeteia"}, "grey" : {"text" : "${disaster}", "args" : {"disaster" : {"type" : "icon", "name" : "disaster"}}}}'),
(38, '3', 16, 'Shaun', 0, 1, '{"action" : "multiple", "number" : 2, "0" : {"action" : "draw", "number" : 2}, "1" : {"action" : "bury", "bury" : "this"}}', '{"action" : "consume", "ressource" : "ressource_break"}', '{"action" : "nothing"}', 0, '{"black" : {"text" : "Draw 2 peripeteias, then bury this peripeteia"}, "white" : {"text" : "${consumeBreak}", "args" : {"consumeBreak" : {"type" : "icon", "name" : "consumedBreak"}}}, "grey" : {"text" : "No effect"}}'),
(39, '3', 17, 'LGS', 0, 0, '{"action" : "recover"}', NULL, '{"action" : "restore", "ressource" : "ressource_hunger"}', 0, '{"black" : {"text" : "Pick a card from the Escaped area to move it to your hand"}, "grey" : {"text" : "${hunger}", "args" : {"hunger" : {"type" : "icon", "name" : "hunger"}}}}'),
(40, '3', 18, 'Teacher', 1, 0, '{"action" : "bury", "bury" : "topCard"}', NULL, NULL, 0, '{"black" : {"text" : "Bury the top peripeteia of one deck"}}')
;

-- Create Protagonist info
INSERT INTO `twd_protagonist_info` (`info_id`, `losscon`) VALUES
(1, 5),
(2, 5),
(3, 4)
;
-- Eleonore (info_id 4, losscon 3) is not enabled yet.

-- Create characters info
INSERT INTO `twd_character_info` (`info_id`, `weakness_hunger`, `weakness_break`, `weakness_stress`, `wounds`) VALUES
(8, 1, 0, 1, 0),
(9, 0, 0, 1, 0),
(10, 1, 0, 1, 0),
(11, 0, 0, 1, 0),
(12, 0, 1, 0, 0),
(25, 0, 1, 0, 0),
(26, 0, 0, 1, 0),
(33, 1, 0, 0, 0),
(36, 1, 0, 0, 0),
(37, 0, 0, 1, 0),
(38, 0, 0, 1, 0)
;

-- Create disaster info
INSERT INTO `twd_disaster_info` (`card_type`, `disaster_hunger`, `disaster_break`, `disaster_stress`) VALUES
(1, 1, 0, 0),
(2, 0, 1, 0),
(3, 0, 0, 1),
(4, 1, 1, 0),
(5, 0, 1, 1),
(6, 1, 0, 1),
(7, 0, 0, 0)
;
