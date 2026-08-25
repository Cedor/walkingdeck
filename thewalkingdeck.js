/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * TheWalkingDeck implementation : © <Cedor> <cedordev@gmail.com>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * thewalkingdeck.js
 *
 * TheWalkingDeck user interface script
 *
 *
 */

define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
  getLibUrl("bga-animations", "1.x"),
  getLibUrl("bga-cards", "1.x"),
], function (dojo, declare, gamegui, counter, BgaAnimations, BgaCards) {
  return declare("bgagame.thewalkingdeck", ebg.core.gamegui, {
    constructor: function () {
      console.log("thewalkingdeck constructor");
      this.cardwidth = 127;
      this.cardheight = 179;
      this.difficulty = 1;
      this.gamePhase = 1;
      this.isTestMode = false;
      this.lossCondition = 5; //default value
      this.resolvingCardIds = new Set();
    },

    /*
            setup:
            
            This method must set up the game user interface according to current game situation specified
            in parameters.
            
            The method is called each time the game interface is displayed to a player, ie:
            _ when the game starts
            _ when a player refreshes the game page (F5)
            
            "gamedatas" argument contains all datas retrieved by your "getAllDatas" PHP method.
        */

    setup: function (gamedatas) {
      console.log("Starting game setup");
      // Setting up the game interface
      var numPlayers = Object.keys(gamedatas.players).length;
      if (numPlayers > 1)
        console.warn(
          "TheWalkingDeck: game setup with more than 1 player is not supported yet. This is a single player game."
        );
      var player = Object.values(gamedatas.players)[numPlayers - 1];
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `
          <div id="player_table" class="whiteblock">
            <div id="table_organiser">
              <div id="deck_rural_wrap" class="location-wrap">
                <b>${_("Rural Deck")}</b>
                <div id="deck_rural"></div>
              </div>
              <div id="deck_urban_wrap" class="location-wrap">
                <b>${_("Urban Deck")}</b>
                <div id="deck_urban"></div>
              </div>
                <div id="escaped_wrap" class="location-wrap">
                <b>${_("Escaped")}</b>
                <div id="escaped"></div>
              </div>
              <div id="memory_wrap" class="location-wrap">
                <b>${_("Memory")}</b>
                <div id="memory"></div>
              </div>
              <div id="protagonist_wrap" class="location-wrap">
                <b>${_("Protagonist")}</b>
                <div id="protagonist_slot">
                </div>
              </div>
              <div id="graveyard_wrap" class="location-wrap">
                <b>${_("Graveyard")}</b>
                <div id="graveyard"></div>
              </div>
              <div id ="ressources_wrap" class="ressources-wrap">
                <b>${_("Ressources")}</b>
                <div id="ressources_slots">
                </div>
              </div>
              <div id="disasters_wrap" class="location-wrap">
                <b>${_("Disasters")}</b>
                <div id="disasters_organiser">
                  <div id="disasters_reserve"></div>
                  <div id="disasters_bag" class="twd-disasters-bag"></div>
                  <div id="disasters_drawn_slot"></div>
                </div>
              </div>
            </div>
            <div id="card_resolution_organiser">
              <div id="current_card_resolution_wrap" class="characters-slot-wrap">
                <b>${_("Current Card Resolution")}</b>
                <div id="current_card_resolution"></div>
              </div>
              <div id="characters_wrap" class="characters-slot-wrap">
                <b>${_("Characters")}</b>
                <div id="characters"></div>
              </div>
            </div>
          </div>
          `
      );
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `<div id="brainstorm_wrap" class="whiteblock">
            <b id="brainstorm_label">${_("Brainstorm")}</b>
            <div id="brainstorm_help" class="brainstorm-help">${_("Drag the cards to reorder them. The left card will be on top.")}</div>
            <div id="brainstorm"></div>
          </div>`
      );
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `<div id="hand_wrap" class="whiteblock">
            <b id="hand_label">${_("My hand")}</b>
            <div id="hand"></div>
          </div>`
      );

      // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
      this.animationManager = new BgaAnimations.Manager({
        animationsActive: () => this.bgaAnimationsActive(),
      });
      this.deckTopTypes = {
        deck_rural: this.gamedatas.ruralDeckTop?.type || "2",
        deck_urban: this.gamedatas.urbanDeckTop?.type || "3",
        memory: this.gamedatas.memoryTop?.type || "2",
        graveyard: this.gamedatas.graveyardTop?.type || "2",
      };

      const cardSpriteGrid = { columns: 8, rows: 6 };
      const setCardSpriteCell = (element, column, row) => {
        const x = (column * 100) / (cardSpriteGrid.columns - 1);
        const y = (row * 100) / (cardSpriteGrid.rows - 1);
        element.style.setProperty("--twd-card-sprite-x", `${x}%`);
        element.style.setProperty("--twd-card-sprite-y", `${y}%`);
      };

      /*
       * Ajustements typographiques mesures sur les cartes source 635 x 895.
       * Les cles sont "type-type_arg", puis le nom de la zone affichee.
       * Une zone absente de cette table conserve la taille normale.
       */
      const cardTextSizeModifiers = {
        "1-3": {
          "body-defeat": "dense",
          "body-rule": "dense",
          "body-caseUp": "dense",
          "body-caseDown": "dense",
        },
        "1-4": {
          "body-rule": "dense",
          "body-case1": "dense",
          "body-case2": "dense",
          "body-case3": "dense",
        },
        "2-1": { black: "short" },
        "2-2": { black: "dense", grey: "short" },
        "2-3": { grey: "short" },
        "2-5": { black: "dense" },
        "2-6": { white: "dense" },
        "2-8": { grey: "short" },
        "2-9": { special: "short", grey: "short" },
        "2-10": { black: "short", grey: "dense" },
        "2-11": { black: "short", grey: "dense" },
        "2-12": { black: "short" },
        "2-13": { grey: "dense" },
        "2-14": { grey: "dense" },
        "2-15": { black: "dense", grey: "short" },
        "2-16": { grey: "short" },
        "3-1": { grey: "short" },
        "3-2": { white: "short" },
        "3-4": { black: "short" },
        "3-5": { special: "short", grey: "short" },
        "3-6": { grey: "short" },
        "3-7": { black: "short", grey: "dense" },
        "3-8": { grey: "dense" },
        "3-9": { grey: "dense" },
        "3-10": { black: "short", grey: "dense" },
        "3-11": { black: "dense", grey: "short" },
        "3-12": { grey: "short" },
        "3-13": { grey: "short" },
        "3-15": { black: "dense" },
        "3-16": { black: "short", grey: "short" },
        "3-17": { black: "short" },
      };

      const getCardTextSizeClass = (cardType, cardTypeArg, zoneName) => {
        const size = cardTextSizeModifiers[`${cardType}-${cardTypeArg}`]?.[zoneName];
        return size ? `twd-card-text-${size}` : null;
      };

      const setupCardContentZones = (card, element) => {
        const cardType = String(card.type);
        const cardTypeArg = Number(card.type_arg);
        let cardTexts = card.texts;
        if (typeof cardTexts === "string") {
          try {
            cardTexts = JSON.parse(cardTexts);
          } catch (_error) {
            cardTexts = {};
          }
        }
        if (!cardTexts || typeof cardTexts !== "object" || Array.isArray(cardTexts)) {
          cardTexts = {};
        }

        element.dataset.cardType = cardType;
        element.dataset.cardTypeArg = String(cardTypeArg);
        [...element.classList]
          .filter((className) => className.startsWith("twd-card-layout-"))
          .forEach((className) => element.classList.remove(className));
        element.querySelector(":scope > .twd-card-content")?.remove();

        // Les dos de carte et les identifiants inconnus n'utilisent pas ces zones.
        if (!["1", "2", "3"].includes(cardType) || !Number.isInteger(cardTypeArg) || cardTypeArg < 1) {
          return;
        }

        element.classList.add(`twd-card-layout-${cardType}-${cardTypeArg}`);
        const content = document.createElement("div");
        content.className = "twd-card-content";

        const appendZoneText = (zone, definition) => {
          if (!definition || typeof definition.text !== "string") {
            return;
          }

          const copy = document.createElement("span");
          copy.className = "twd-card-zone-copy";
          const translatedText = _(definition.text);
          const args = definition.args && typeof definition.args === "object"
            ? definition.args
            : {};
          const iconLabels = {
            bite: "\u{1F9B7}",
            heal: "\u271A",
            disaster: "\u25C6",
            hunger: "H",
            break: "B",
            stress: "S",
            consumedHunger: "H",
            consumedBreak: "B",
            consumedStress: "S",
            breakHunger: "B/H",
            breakStress: "B/S",
          };
          const placeholderPattern = /\$\{([A-Za-z0-9_]+)\}/g;
          let cursor = 0;
          let match;

          while ((match = placeholderPattern.exec(translatedText)) !== null) {
            if (match.index > cursor) {
              copy.appendChild(document.createTextNode(translatedText.slice(cursor, match.index)));
            }

            const argument = args[match[1]];
            if (argument?.type === "icon" && typeof argument.name === "string") {
              const iconName = argument.name.replace(/[^A-Za-z0-9_-]/g, "");
              const icon = document.createElement("span");
              icon.className = `twd-card-text-icon twd-card-text-icon-${iconName}`;
              icon.dataset.icon = iconName;
              icon.setAttribute("role", "img");
              icon.setAttribute("aria-label", iconName);
              icon.textContent = iconLabels[iconName] || iconName;
              copy.appendChild(icon);
            } else {
              const replacement = argument?.value ?? argument;
              copy.appendChild(document.createTextNode(
                replacement === null || replacement === undefined
                  ? match[0]
                  : String(replacement)
              ));
            }
            cursor = placeholderPattern.lastIndex;
          }

          if (cursor < translatedText.length) {
            copy.appendChild(document.createTextNode(translatedText.slice(cursor)));
          }
          zone.appendChild(copy);
        };

        const addZone = (zoneName, text = "", definition = null) => {
          const zone = document.createElement("div");
          zone.className = `twd-card-zone twd-card-zone-${zoneName}`;
          const sizeClass = getCardTextSizeClass(cardType, cardTypeArg, zoneName);
          if (sizeClass) {
            zone.classList.add(sizeClass);
          }
          zone.dataset.cardZone = zoneName;
          zone.textContent = text;
          appendZoneText(zone, definition);
          content.appendChild(zone);
          return zone;
        };

        addZone("name", this.getCardName(card));
        if (cardType === "1") {
          const body = addZone("body");
          const preferredBlockOrder = [
            "start", "defeat", "rule", "caseUp", "caseDown", "case1", "case2", "case3",
          ];
          Object.entries(cardTexts)
            .sort(([left], [right]) => {
              const leftIndex = preferredBlockOrder.indexOf(left);
              const rightIndex = preferredBlockOrder.indexOf(right);
              return (leftIndex < 0 ? preferredBlockOrder.length : leftIndex)
                - (rightIndex < 0 ? preferredBlockOrder.length : rightIndex);
            })
            .forEach(([blockName, definition]) => {
              if (!definition || typeof definition.text !== "string") {
                return;
              }
              const safeBlockName = blockName.replace(/[^A-Za-z0-9_-]/g, "");
              const block = document.createElement("div");
              block.className = `twd-card-body-block twd-card-body-block-${safeBlockName}`;
              const sizeClass = getCardTextSizeClass(
                cardType,
                cardTypeArg,
                `body-${blockName}`
              );
              if (sizeClass) {
                block.classList.add(sizeClass);
              }
              block.dataset.cardBodyBlock = blockName;
              appendZoneText(block, definition);
              body.appendChild(block);
            });
          element.appendChild(content);
          return;
        }

        if (Number(card.special_draw) === 1) {
          addZone("special", "", cardTexts.special);
        }
        ["black", "white", "grey"].forEach((color) => {
          const consequence = card[`consequence_${color}`];
          if (consequence !== null && consequence !== undefined && consequence !== "") {
            addZone(color, "", cardTexts[color]);
          }
        });
        element.appendChild(content);
      };

      // create the card manager
      this.cardsManager = new BgaCards.Manager({
        animationManager: this.animationManager,
        type: "twd-card",
        getId: (card) => `${card.id}`,
        setupDiv: (card, div) => {
          div.classList.add("twd-card");
        },
        setupFrontDiv: (card, div) => {
          div.classList.add("twd-card-front");
          switch (String(card.type)) {
            case "1":
              setCardSpriteCell(div, Number(card.type_arg) - 1, 5);
              break;
            case "2": {
              const spriteIndex = Number(card.type_arg) - 1;
              setCardSpriteCell(div, spriteIndex % 8, Math.floor(spriteIndex / 8));
              break;
            }
            case "3": {
              const spriteIndex = 18 + Number(card.type_arg) - 1;
              setCardSpriteCell(div, spriteIndex % 8, Math.floor(spriteIndex / 8));
              break;
            }
            default:
              setCardSpriteCell(div, 6, 5);
          }
          setupCardContentZones(card, div);
          this.addTooltipHtml(div.id, `tooltip de ${card.type}, ${card.type_arg}`);
        },
        setupBackDiv: (card, div) => {
          switch (card.type) {
            case "2": // rural
            case "4": // fake rural
              div.classList.add("twd-card-back-rural");
              break;
            case "3": // urban
            case "5": // fake urban
              div.classList.add("twd-card-back-urban");
              break;
            default:
              div.classList.add("twd-card-back");
          }
        },
        isCardVisible: (card) => {
          return !card.face_down && (card.type === "1" || card.type === "2" || card.type === "3");
        },
        getCardRotation: (card) => this.getCardRotation(card),
        cardWidth: 127,
        cardHeight: 179,
      });

      // create decks
      this.ruralDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck_rural"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => this.generateDeckFakeCard(
          deckId,
          this.deckTopTypes.deck_rural,
          "deck_rural"
        ),
      });
      this.urbanDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck_urban"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => this.generateDeckFakeCard(
          deckId,
          this.deckTopTypes.deck_urban,
          "deck_urban"
        ),
      });
      // Create protagonist slot
      this.protagonistSlot = new BgaCards.SlotStock(this.cardsManager, document.getElementById("protagonist_slot"), {
        slotsIds: ["A"],
        slotClasses: ["twd-card-slot"],
        mapCardToSlot: (card) => "A",
        cardClickEventFilter: "all",
      });
      // TEST remove
      this.protagonistSlot.onCardAdded = (card) => {
        console.log("Card added to protagonist slot", card);
      };
      // Create hand
      this.hand = new BgaCards.HandStock(this.cardsManager, document.getElementById("hand"), {
        cardClickEventFilter: "selectable",
      });
      this.hand.setSelectionMode("single");

      // Create memory pile
      this.memory = new BgaCards.Deck(this.cardsManager, document.getElementById("memory"), {
        //TEST remove
        // cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
        },
        fakeCardGenerator: (deckId) => this.generateDeckFakeCard(
          deckId,
          this.deckTopTypes.memory,
          "memory"
        ),
      });
      //TEST remove
      // this.memory.setSelectionMode("single");
      // create graveyard pile
      this.graveyard = new BgaCards.Deck(this.cardsManager, document.getElementById("graveyard"), {
        // TEST remove
        // cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
        },
        fakeCardGenerator: (deckId) => this.generateDeckFakeCard(
          deckId,
          this.deckTopTypes.graveyard,
          "graveyard"
        ),
      });
      // TEST remove
      // this.graveyard.setSelectionMode("single");
      // create escaped pile
      this.escaped = new BgaCards.AllVisibleDeck(this.cardsManager, document.getElementById("escaped"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
          position: "bottom",
        },
        direction: "horizontal",
      });
      this.brainstorm = new BgaCards.LineStock(
        this.cardsManager,
        document.getElementById("brainstorm"),
        {
          cardClickEventFilter: "all",
        }
      );

      // Create ressources
      this.ressourcesManager = new BgaCards.Manager({
        animationManager: this.animationManager,
        cardBorderRadius: "50%",
        type: "twd-ressource",
        getId: (token) => token.id,
        setupDiv: (token, div) => {
          div.classList.add("twd-ressource");
          this.updateBorisResourceClickability(token, div);
        },
        setupFrontDiv: (token, div) => {
          div.classList.add("twd-ressource-front");
          div.dataset.id = token.id;
          if (token.id) {
            this.addTooltipHtml(div.id, `tooltip de ${token.id}`);
          }
        },
        setupBackDiv: (token, div) => {
          div.classList.add("twd-ressource-back");
          div.dataset.id = token.id;
          if (token.id) {
            this.addTooltipHtml(div.id, `tooltip de ${token.id}`);
          }
        },
        isCardVisible: (token) => !Boolean(parseInt(token.consumed)),
        cardWidth: 90,
        cardHeight: 90,
      });
      // Create slots for ressources
      this.ressourcesSlots = new BgaCards.SlotStock(
        this.ressourcesManager,
        document.getElementById("ressources_slots"),
        {
          cardClickEventFilter: "all",
          slotsIds: ["slot_ressource_hunger", "slot_ressource_break", "slot_ressource_stress"],
          slotClasses: ["twd-ressources-slot"],
          mapCardToSlot: (token) => `slot_${token.id}`,
        }
      );

      // Create disasters
      this.disastersManager = new BgaCards.Manager({
        animationManager: this.animationManager,
        cardBorderRadius: "50%",
        type: "twd-disaster",
        getId: (token) => `token-${token.id}`,
        setupDiv: (token, div) => {
          div.classList.add("twd-disaster");
        },
        setupFrontDiv: (token, div) => {
          div.classList.add("twd-disaster-front");
          div.dataset.id = token.type;
          if (token.id) {
            this.addTooltipHtml(div.id, `tooltip de ${token.id}`);
          }
        },
        setupBackDiv: (token, div) => {
          div.classList.add("twd-disaster-back");
          div.dataset.id = token.type;
          if (token.id) {
            this.addTooltipHtml(div.id, `tooltip de ${token.id}`);
          }
        },
        isCardVisible: (token) => true,
        cardWidth: 90,
        cardHeight: 90,
      });
      // Create slot for blanks
      this.disastersReserve = new BgaCards.Deck(this.disastersManager, document.getElementById("disasters_reserve"), {
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
          position: "center",
        },
      });
      // Create slot for drawn disasters
      this.disastersDrawnSlot = new BgaCards.LineStock(
        this.disastersManager,
        document.getElementById("disasters_drawn_slot"),
        {
          cardClickEventFilter: "all",
          direction: "row",
          center: false,
        }
      );
      // Create characters area (phase 2)
      this.characters = new BgaCards.LineStock(this.cardsManager, document.getElementById("characters"), {
        cardClickEventFilter: "all",
        direction: "row",
        center: false,
      });
      this.currentCardResolution = new BgaCards.SlotStock(
        this.cardsManager,
        document.getElementById("current_card_resolution"),
        {
          slotsIds: ["current"],
          slotClasses: ["twd-card-slot"],
          mapCardToSlot: () => "current",
        }
      );

      // Set up game interface, according to "gamedatas"
      console.log("gamedatas", this.gamedatas);
      this.difficulty = this.gamedatas.difficultyLevel;
      this.isTestMode = Boolean(this.gamedatas.isTestMode);
      this.setGamePhaseDisplay(this.gamedatas.gamePhase);
      // Hand gamedatas
      for (var i in this.gamedatas.hand) this.hand.addCard(this.gamedatas.hand[i]);
      // Protagonist slot gamedatas
      let protagonistDatas = this.gamedatas.protagonistSlot;
      if (Object.keys(protagonistDatas).length > 1) console.log("Protagonist slot contains multiple cards");
      if (Object.keys(protagonistDatas).length > 0) {
        const protagonist = protagonistDatas[Object.keys(protagonistDatas)[0]];
        protagonist.aenor_ability_used = Boolean(
          Number(this.gamedatas.aenorAbilityUsed)
        );
        this.protagonistSlot.addCard(protagonist);
      } else {
        console.log("Protagonist slot is empty");
      }
      // Urban Deck gamedatas
      this.setDeckState(
        "deck_urban",
        this.gamedatas.urbanDeckNb,
        this.gamedatas.urbanDeckTop
      );
      // Rural Deck gamedatas
      this.setDeckState(
        "deck_rural",
        this.gamedatas.ruralDeckNb,
        this.gamedatas.ruralDeckTop
      );
      // Memory gamedatas
      console.log("Memory gamedatas", this.gamedatas.memoryNb, this.gamedatas.memoryTop);
      this.setDeckState(
        "memory",
        this.gamedatas.memoryNb,
        this.gamedatas.memoryTop
      );
      // Graveyard gamedatas
      console.log("Graveyard gamedatas", this.gamedatas.graveyardNb, this.gamedatas.graveyardTop);
      this.setDeckState(
        "graveyard",
        this.gamedatas.graveyardNb,
        this.gamedatas.graveyardTop
      );
      // Escaped gamedatas
      for (var i in this.gamedatas.escaped) this.escaped.addCard(this.gamedatas.escaped[i]);
      for (var i in this.gamedatas.brainstorm) this.brainstorm.addCard(this.gamedatas.brainstorm[i]);

      // Ressources gamedatas
      console.log("Ressources gamedatas", this.gamedatas.ressources);
      for (let ressource in this.gamedatas.ressources) {
        this.ressourcesSlots.addCard(this.gamedatas.ressources[ressource]);
      }

      // Disasters gamedatas
      console.log("Disasters reserve gamedatas", this.gamedatas.disastersReserve);
      console.log("Disasters drawn gamedatas", this.gamedatas.disastersDrawn);
      for (let disaster in this.gamedatas.disastersReserve) {
        this.disastersReserve.addCard(this.gamedatas.disastersReserve[disaster]);
      }
      for (let disaster in this.gamedatas.disastersDrawn) {
        this.disastersDrawnSlot.addCard(this.gamedatas.disastersDrawn[disaster]);
      }

      // Characters gamedatas
      console.log("Characters gamedatas", this.gamedatas.charactersInPlay);
      for (let character in this.gamedatas.charactersInPlay) {
        this.characters.addCard(this.gamedatas.charactersInPlay[character]);
      }
      if (this.gamedatas.currentCardResolution) {
        this.currentCardResolution.addCard(
          this.gamedatas.currentCardResolution
        );
      }

      // Setup connections
      this.setupConnections();
      // Setup game notifications to handle (see "setupNotifications" method below)
      this.setupNotifications();

      console.log("Ending game setup");
    },

    ///////////////////////////////////////////////////
    //// Game & client states

    // onEnteringState: this method is called each time we are entering into a new game state.
    //                  You can use this method to perform some user interface changes at this moment.
    //
    onEnteringState: function (stateName, args) {
      console.log("Entering state: " + stateName, args);
      console.log("Current active player is: " + this.getActivePlayerId());
      switch (stateName) {
        case "protagonistSelection":
          this.hand.onSelectionChange = this.onProtagonistSelectionChange;
          break;
        case "escapeTallaChoice":
        case "avoidZombieChoice":
          const escapeTallaArgs = args.args || args;
          this.zombieEscapeChoiceActive = true;
          this.escapeTallaMemoryAvailable = Boolean(escapeTallaArgs.canChooseMemory);
          this.hand.onSelectionChange = this.onZombieEscapeSelectionChange;
          if (this.escapeTallaMemoryAvailable) {
            document.getElementById("memory").classList.add("twd-highlight");
          }
          break;
        case "biteChoice":
          this.prepareBiteChoice(args.args || args);
          this.prepareAenorAbility(args.args || args, stateName);
          this.characters.onCardClick = (card) =>
            this.onBiteCharacterClick(card);
          break;
        case "healChoice":
          this.prepareHealChoice(args.args || args);
          this.healChoiceConnector = dojo.connect(
            this.characters,
            "onCardClick",
            this,
            "onHealCharacterClick"
          );
          break;
        case "disasterChoice":
          this.prepareDisasterChoice(args.args || args);
          this.prepareAenorAbility(args.args || args, stateName);
          break;
        case "wolfTrapChoice":
          this.prepareWolfTrapChoice(args.args || args);
          break;
        case "recoverChoice": {
          const recoverArgs = args.args || args;
          this.recoverChoiceActive = true;
          this.recoverEligibleCardIds = (recoverArgs.eligibleCardsIds || []).map(Number);
          document.getElementById("escaped")?.classList.add("twd-highlight");
          this.recoverChoiceConnector = dojo.connect(
            this.escaped,
            "onCardClick",
            this,
            "onRecoverCardClick"
          );
          break;
        }
        case "buryCharacterChoice": {
          const buryArgs = args.args || args;
          this.buryCharacterEligibleCardIds = (buryArgs.eligibleCardsIds || []).map(Number);
          this.hand.unselectAll();
          this.hand.setSelectionMode("single");
          this.hand.onSelectionChange = (selection, lastChange) =>
            this.onBuryCharacterSelectionChange(selection, lastChange);
          break;
        }
        case "drawCards":
        case "specialDraw":
          this.enableDeckSelection(
            ["deck_rural", "deck_urban"],
            (location) => location === "deck_rural"
              ? this.onRuralDeckCardClick()
              : this.onUrbanDeckCardClick()
          );
          break;
        case "brainstormDeckChoice":
          const brainstormChoiceArgs = args.args || args;
          this.brainstormAvailableDecks = brainstormChoiceArgs.availableDecks || [];
          this.enableDeckSelection(
            this.brainstormAvailableDecks,
            (location) => location === "deck_rural"
              ? this.onBrainstormRuralDeckClick()
              : this.onBrainstormUrbanDeckClick()
          );
          break;
        case "fastMemoriseDeckChoice": {
          const fastMemoriseArgs = args.args || args;
          this.fastMemoriseAvailableDecks = fastMemoriseArgs.availableDecks || [];
          this.enableDeckSelection(
            this.fastMemoriseAvailableDecks,
            (location) => location === "deck_rural"
              ? this.onFastMemoriseRuralDeckClick()
              : this.onFastMemoriseUrbanDeckClick()
          );
          break;
        }
        case "buryTopCardChoice": {
          const buryArgs = args.args || args;
          this.buryTopCardAvailableDecks = buryArgs.availableDecks || [];
          this.buryTopCardSelectedDeck = null;
          this.enableDeckSelection(
            this.buryTopCardAvailableDecks,
            (location) => this.selectBuryTopCardDeck(location)
          );
          break;
        }
        case "avoidDeckChoice": {
          const avoidArgs = args.args || args;
          this.avoidDeckAvailableDecks = avoidArgs.availableDecks || [];
          this.avoidDeckSelectedDeck = null;
          this.enableDeckSelection(
            this.avoidDeckAvailableDecks,
            (location) => this.selectAvoidDeck(location)
          );
          break;
        }
        case "draftDisasterChoice": {
          const draftArgs = args.args || args;
          this.draftDisasterPhase = draftArgs.phase;
          this.draftDisasterEligibleIds = (draftArgs.eligibleDisasterIds || []).map(Number);
          this.draftDisasterSelectedId = null;
          if (this.draftDisasterPhase === "draw") {
            document.getElementById("disasters_bag")?.classList.add("twd-highlight");
          } else if (this.draftDisasterPhase === "choose") {
            this.draftDisasterChoiceConnector = dojo.connect(
              this.disastersDrawnSlot,
              "onCardClick",
              this,
              "onDraftDisasterClick"
            );
          }
          break;
        }
        case "fastMemoriseHandChoice": {
          const fastMemoriseArgs = args.args || args;
          this.fastMemoriseMaximumCards = Number(fastMemoriseArgs.maximumCards) || 2;
          this.hand.setSelectionMode("multiple");
          this.hand.onSelectionChange = (selection, lastChange) =>
            this.onFastMemoriseHandSelectionChange(selection, lastChange);
          break;
        }
        case "avoidHandChoice": {
          const avoidHandArgs = args.args || args;
          this.avoidHandMaximumCards = Number(avoidHandArgs.maximumCards) || 2;
          this.hand.setSelectionMode("multiple");
          this.hand.onSelectionChange = (selection, lastChange) =>
            this.onAvoidHandSelectionChange(selection, lastChange);
          break;
        }
        case "brainstormReorder":
          document.getElementById("brainstorm_wrap").style.display = "block";
          this.prepareBrainstormReorder((args.args || args).cards || []);
          break;
        case "unrememberChoice": {
          const unrememberArgs = args.args || args;
          document.getElementById("brainstorm_wrap").style.display = "block";
          this.prepareUnrememberChoice(
            unrememberArgs.cards || [],
            Number(unrememberArgs.maximumCards) || 0
          );
          break;
        }
        case "playCards":
          if (this.hand.getCardCount() === 1) {
            document.getElementById("refill_hand_button").style.visibility = "visible";
          } else {
            document.getElementById("refill_hand_button").style.visibility = "hidden";
          }
          break;
        case "storyCheck":
          document.getElementById("card_resolution_organiser").style.display = "block";
          break;
        case "dummy":
          break;
      }
    },

    setHandVisible: function (visible) {
      const handWrap = document.getElementById("hand_wrap");
      if (handWrap) {
        handWrap.style.display = visible ? "" : "none";
      }
    },

    setGamePhaseDisplay: function (gamePhase) {
      this.gamePhase = Number(gamePhase);
      const phaseTwo = this.gamePhase === 2;
      this.setHandVisible(!phaseTwo);
      document.getElementById("card_resolution_organiser").style.display = phaseTwo
        ? "block"
        : "none";
      if (this.ressourcesSlots) {
        this.updateAllBorisResourceClickability();
      }
    },

    // onLeavingState: this method is called each time we are leaving a game state.
    //                 You can use this method to perform some user interface changes at this moment.
    //
    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      switch (stateName) {
        /* Example:
            
            case 'myGameState':
            
                // Hide the HTML block we are displaying only during this game state
                dojo.style( 'my_html_block_id', 'display', 'none' );
                
                break;
           */
        case "protagonistSelection":
          this.hand.onSelectionChange = null;
          break;
        case "escapeTallaChoice":
        case "avoidZombieChoice":
          this.zombieEscapeChoiceActive = false;
          this.escapeTallaMemoryAvailable = false;
          this.hand.onSelectionChange = null;
          document.getElementById("memory").classList.remove("twd-highlight");
          break;
        case "biteChoice":
          this.clearAenorAbilityUi();
          this.characters.onCardClick = null;
          break;
        case "healChoice":
          if (this.healChoiceConnector) {
            dojo.disconnect(this.healChoiceConnector);
            this.healChoiceConnector = null;
          }
          this.clearHealSelection();
          break;
        case "buryCharacterChoice":
          this.hand.onSelectionChange = null;
          this.hand.unselectAll();
          this.buryCharacterEligibleCardIds = [];
          break;
        case "disasterChoice":
          this.clearAenorAbilityUi();
          this.disasterResolutionPhase = null;
          this.disasterResourceAvailable = false;
          this.disasterResourceId = null;
          document.getElementById("disasters_bag")?.classList.remove("twd-highlight");
          this.clearDisasterResourceHighlight();
          break;
        case "wolfTrapChoice":
          this.wolfTrapPhase = null;
          document.getElementById("disasters_bag")?.classList.remove("twd-highlight");
          break;
        case "recoverChoice":
          this.recoverChoiceActive = false;
          this.recoverEligibleCardIds = [];
          if (this.recoverChoiceConnector) {
            dojo.disconnect(this.recoverChoiceConnector);
            this.recoverChoiceConnector = null;
          }
          document.getElementById("escaped")?.classList.remove("twd-highlight");
          break;
        case "drawCards":
        case "specialDraw":
          this.disableDeckSelection();
          break;
        case "brainstormDeckChoice":
          this.disableDeckSelection();
          this.brainstormAvailableDecks = [];
          break;
        case "fastMemoriseDeckChoice":
          this.disableDeckSelection();
          this.fastMemoriseAvailableDecks = [];
          break;
        case "buryTopCardChoice":
          this.disableDeckSelection();
          this.buryTopCardSelectedDeck = null;
          this.buryTopCardAvailableDecks = [];
          break;
        case "avoidDeckChoice":
          this.disableDeckSelection();
          this.avoidDeckSelectedDeck = null;
          this.avoidDeckAvailableDecks = [];
          break;
        case "draftDisasterChoice":
          this.draftDisasterPhase = null;
          (this.draftDisasterEligibleIds || []).forEach((id) => {
            document.getElementById(`token-${id}`)?.classList.remove("twd-highlight");
          });
          this.draftDisasterEligibleIds = [];
          this.draftDisasterSelectedId = null;
          if (this.draftDisasterChoiceConnector) {
            dojo.disconnect(this.draftDisasterChoiceConnector);
            this.draftDisasterChoiceConnector = null;
          }
          document.getElementById("disasters_bag")?.classList.remove("twd-highlight");
          break;
        case "fastMemoriseHandChoice":
          this.hand.onSelectionChange = null;
          this.hand.unselectAll();
          this.hand.setSelectionMode("single");
          this.fastMemoriseMaximumCards = 0;
          break;
        case "avoidHandChoice":
          this.hand.onSelectionChange = null;
          this.hand.unselectAll();
          this.hand.setSelectionMode("single");
          this.avoidHandMaximumCards = 0;
          break;
        case "brainstormReorder":
          this.disableBrainstormReorder();
          document.getElementById("brainstorm_wrap").style.display = "none";
          break;
        case "unrememberChoice":
          this.disableUnrememberChoice();
          document.getElementById("brainstorm_wrap").style.display = "none";
          break;
        case "playCards":
          break;
        case "dummy":
          break;
      }
    },

    // onUpdateActionButtons: in this method you can manage "action buttons" that are displayed in the
    //                        action status bar (ie: the HTML links in the status bar).
    //
    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons: " + stateName, args);
      var player = this.getActivePlayerId();
      if (this.isCurrentPlayerActive()) {
        console.log("Current player is active");
        switch (stateName) {
          case "protagonistSelection":
            this.statusBar.addActionButton(_("Confirm Selection"), () => this.confirmProtagonistSelection(), {
              id: "confirm_protagonist_selection",
              color: "secondary",
            }).style.visibility = "hidden";
            break;
          case "playCards":
            this.statusBar.addActionButton(_("Refill hand"), () => this.bgaPerformAction("actRefillHand"), {
              id: "refill_hand_button",
              color: "secondary",
            });
            break;
          case "escapeTallaChoice": {
            const escapeArgs = args.args || args;
            if (escapeArgs.mustChooseMemory) {
              this.statusBar.setTitle(_("The top card of the memory will be escaped"));
              this.statusBar.addActionButton(
                _("Confirm"),
                () => this.bgaPerformAction("actEscapeTallaFromMemory"),
                {
                  id: "confirm_talla_memory",
                  color: "primary",
                }
              );
              break;
            }
          }
          case "avoidZombieChoice":
            this.statusBar.addActionButton(_("Confirm choice"), () => this.confirmZombieEscapeChoice(), {
              id: "confirm_escape_zombie",
              color: "primary",
            }).style.visibility = "hidden";
            break;
          case "biteChoice":
            this.statusBar.addActionButton(_("Confirm wounds"), () => this.confirmBiteWounds(), {
              id: "confirm_bite_wounds",
              color: "primary",
            }).style.visibility = this.biteWoundsRemaining === 0 ? "visible" : "hidden";
            this.statusBar.addActionButton(_("Reset"), () => this.resetBiteWounds(), {
              id: "reset_bite_wounds",
              color: "secondary",
            });
            break;
          case "cardBurialConfirmation": {
            const burialArgs = args.args || args;
            this.statusBar.setTitle(
              _("Card ${card_name} will be buried"),
              { card_name: this.getCardName(burialArgs.sourceCard) }
            );
            this.statusBar.addActionButton(
              _("Confirm"),
              () => this.bgaPerformAction("actConfirmCardBurial"),
              {
                id: "confirm_card_burial",
                color: "primary",
              }
            );
            break;
          }
          case "healChoice":
            this.statusBar.addActionButton(
              _("Confirm healing"),
              () => this.confirmHealing(),
              {
                id: "confirm_healing",
                color: "primary",
              }
            );
            break;
          case "disasterChoice": {
            const disasterArgs = args.args || args;
            if (disasterArgs.phase === "draw") {
              this.statusBar.setTitle(
                _("Draw ${total} disasters"),
                {
                  total: disasterArgs.requiredDraws,
                }
              );
            } else if (disasterArgs.phase === "characteristic") {
              const characteristic = disasterArgs.characteristic;
              const affectedNames = (disasterArgs.affectedCharacters || [])
                .map((card) => this.getCardName(card))
                .join(", ");
              if (disasterArgs.characteristicIgnored) {
                this.statusBar.setTitle(
                  _("${characteristic} is ignored: no wound will be applied"),
                  { characteristic }
                );
              } else if (!disasterArgs.characteristicPresent) {
                this.statusBar.setTitle(
                  _("${characteristic} is not present: no wound will be applied"),
                  { characteristic }
                );
              } else if (affectedNames) {
                this.statusBar.setTitle(
                  _("${characteristic} is present: ${characters} will receive 1 wound"),
                  { characteristic, characters: affectedNames }
                );
              } else {
                this.statusBar.setTitle(
                  _("${characteristic} is present, but no character is vulnerable"),
                  { characteristic }
                );
              }
              this.statusBar.addActionButton(
                _("Confirm"),
                () => this.confirmDisasterCharacteristic
                  ? this.confirmDisasterCharacteristic()
                  : this.bgaPerformAction("actConfirmDisasterCharacteristic"),
                {
                  id: `confirm_disaster_${characteristic}`,
                  color: "primary",
                }
              );
            } else if (disasterArgs.phase === "complete") {
              this.statusBar.setTitle(_("Confirm the completed disaster resolution"));
              this.statusBar.addActionButton(
                _("Confirm resolution"),
                () => this.resolveDisaster(),
                {
                  id: "resolve_disaster",
                  color: "primary",
                }
              );
            }
            break;
          }
          case "wolfTrapChoice": {
            const wolfTrapArgs = args.args || args;
            if (wolfTrapArgs.phase === "draw") {
              this.statusBar.setTitle(_("Draw one disaster for Wolf Trap"));
            } else if (wolfTrapArgs.phase === "resolve") {
              this.statusBar.setTitle(
                wolfTrapArgs.willBury
                  ? _("The disaster has break and another symbol: bury Wolf Trap")
                  : _("The disaster does not bury Wolf Trap")
              );
              this.statusBar.addActionButton(
                _("Confirm resolution"),
                () => this.bgaPerformAction("actResolveWolfTrap"),
                {
                  id: "resolve_wolf_trap",
                  color: "primary",
                }
              );
            }
            break;
          }
          case "recoverChoice":
            this.statusBar.setTitle(_("Choose a card from the escaped area to recover"));
            break;
          case "fastMemoriseHandChoice":
            this.statusBar.addActionButton(
              _("Confirm memorisation"),
              () => this.confirmFastMemoriseFromHand(),
              {
                id: "confirm_fast_memorise_hand",
                color: "primary",
              }
            );
            break;
          case "buryCharacterChoice":
            this.statusBar.addActionButton(
              _("Confirm burial"),
              () => this.confirmBuryCharacter(),
              {
                id: "confirm_bury_character",
                color: "primary",
              }
            ).style.visibility = "hidden";
            break;
          case "buryTopCardChoice":
            this.statusBar.addActionButton(
              _("Confirm deck"),
              () => this.confirmBuryTopCard(),
              {
                id: "confirm_bury_top_card",
                color: "primary",
              }
            ).style.visibility = "hidden";
            break;
          case "avoidDeckChoice":
            this.statusBar.addActionButton(
              _("Confirm deck"),
              () => this.confirmAvoidDeck(),
              {
                id: "confirm_avoid_deck",
                color: "primary",
              }
            ).style.visibility = "hidden";
            break;
          case "draftDisasterChoice": {
            const draftArgs = args.args || args;
            if (draftArgs.phase === "draw") {
              this.statusBar.setTitle(_("Draw two disasters from the bag"));
            } else {
              this.statusBar.setTitle(_("Choose a disaster to destroy"));
              this.statusBar.addActionButton(
                _("Confirm destruction"),
                () => this.confirmDraftDisaster(),
                {
                  id: "confirm_draft_disaster",
                  color: "primary",
                }
              ).style.visibility = "hidden";
            }
            break;
          }
          case "avoidHandChoice":
            this.statusBar.addActionButton(
              _("Confirm escape"),
              () => this.confirmAvoidFromHand(),
              {
                id: "confirm_avoid_hand",
                color: "primary",
              }
            );
            break;
          case "brainstormReorder":
            this.statusBar.addActionButton(_("Confirm order"), () => this.confirmBrainstorm(), {
              id: "confirm_brainstorm",
              color: "primary",
            });
            break;
          case "unrememberChoice":
            this.statusBar.addActionButton(
              _("Recover selected cards"),
              () => this.confirmUnremember(),
              {
                id: "confirm_unremember",
                color: "primary",
              }
            );
            break;
          case "storyCheckPlayerChoice":
            const storyArgs = args.args || args;
            const storyCardName = storyArgs.currentCard
              ? this.getCardName(storyArgs.currentCard)
              : _("the current card");
            let storyTitle;
            switch (storyArgs.pendingAction) {
              case "applyGreyConsequence":
                storyTitle = _("The grey consequence of ${card_name} will be applied");
                break;
              default:
                return;
            }
            this.statusBar.setTitle(storyTitle, { card_name: storyCardName });
            this.statusBar.addActionButton(_("Confirm"), () => this.bgaPerformAction("actStoryCheckPlayerChoice"), {
              id: "confirm_story_action",
              color: "primary",
            });
            break;
        }
      } else console.log("Current player is not active, active is " + player);
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    /*
        
            Here, you can defines some utility methods that you can use everywhere in your javascript
            script.
        
        */
    getCardName: function (card) {
      const originalName = typeof card === "string" ? card : card?.card_name;
      const translatedNames = {
        "Aénor": _("Aénor"),
        Boris: _("Boris"),
        Adrien: _("Adrien"),
        "Éléonore": _("Éléonore"),
        Punk: _("Punk"),
        "Wolf Trap": _("Wolf Trap"),
        Clown: _("Clown"),
        "Ellie and Joel": _("Ellie and Joel"),
        Kieren: _("Kieren"),
        Tallahassee: _("Tallahassee"),
        Gretchen: _("Gretchen"),
        Robert: _("Robert"),
        Brigade: _("Brigade"),
        Bonfire: _("Bonfire"),
        Horse: _("Horse"),
        RV: _("RV"),
        Cellar: _("Cellar"),
        "Teddy Bear": _("Teddy Bear"),
        "Wild Zero": _("Wild Zero"),
        Voodoo: _("Voodoo"),
        Mutt: _("Mutt"),
        Grenade: _("Grenade"),
        Musicians: _("Musicians"),
        "Site Manager": _("Site Manager"),
        Glenn: _("Glenn"),
        Murphy: _("Murphy"),
        Horde: _("Horde"),
        Butler: _("Butler"),
        "Canned food": _("Canned food"),
        Warehouse: _("Warehouse"),
        "Medical alcohol": _("Medical alcohol"),
        Map: _("Map"),
        Domitille: _("Domitille"),
        "The reaper": _("The reaper"),
        Controller: _("Controller"),
        Zoey: _("Zoey"),
        Jill: _("Jill"),
        Shaun: _("Shaun"),
        LGS: _("LGS"),
        Teacher: _("Teacher"),
      };

      return translatedNames[originalName] || originalName || _("Unknown card");
    },

    getLocation: function (location) {
      switch (location) {
        case "hand":
          return this.hand;
        case "memory":
          return this.memory;
        case "graveyard":
          return this.graveyard;
        case "escaped":
          return this.escaped;
        case "deck_rural":
          return this.ruralDeck;
        case "deck_urban":
          return this.urbanDeck;
        case "brainstorm":
          return this.brainstorm;
        case "current_card_resolution":
          return this.currentCardResolution;
        case "characters":
          return this.characters;
        default:
          console.log("Unknown location: " + location);
          return null;
      }
    },

    getCardRotation: function (card) {
      if (
        card
        && Number(card.type) === 1
        && Number(card.type_arg) === 1
        && Boolean(card.aenor_ability_used)
      ) return 1;

      if (!card || Number(card.is_character) !== 1) return 0;

      switch (Number(card.wounds)) {
        case 1:
          return 1;
        case 2:
          return 2;
        case 3:
          return 3;
        default:
          return 0;
      }
    },

    generateFakeCard: function (card) {
      // Generate a fake card based on the original card
      let fakeType;
      switch (card.type) {
        case "2": // rural
          fakeType = `4`; // fake rural
          break;
        case "3": // urban
          fakeType = `5`; // fake urban
          break;
        default:
          fakeType = `6`;
      }
      return {
        id: card.id,
        type: fakeType,
        type_arg: `20`,
        location: card.location,
        location_arg: card.location_arg,
      };
    },

    generateDeckFakeCard: function (deckId, cardType, location) {
      const type = ["2", "4"].includes(String(cardType)) ? "4" : "5";
      return {
        id: `${deckId}-${type}-top-card`,
        type,
        location,
      };
    },

    setDeckState: function (location, cardNumber, topCard) {
      const deck = this.getLocation(location);
      if (!deck) return Promise.resolve(false);
      if (topCard) this.deckTopTypes[location] = String(topCard.type);
      return deck.setCardNumber(
        Number(cardNumber),
        topCard || (Number(cardNumber) === 0 ? null : undefined)
      );
    },

    canCardBePlayedInLocation: function (card, location) {
      // Check if the card can be played in the specified location
      console.log("canCardBePlayedInLocation", card, location);
      if (!card || !location) return false;

      switch (location) {
        case "hand":
          return false;
        case "memory":
          return card.consequence_white || card.consequence_grey;
        case "graveyard":
          return false;
        case "escaped":
          return card.consequence_black;
        default:
          return false;
      }
    },

    moveCardToLocation: async function (card, destination, source = "hand", special = false) {
      console.log("moveCardToLocation", card, destination, source);
      if (!card || !destination) return;
      let settings = { fromStock: this.getLocation(source) };
      switch (destination) {
        case "graveyard":
          card = this.generateFakeCard(card);
          settings.autoRemovePreviousCards = true;
          break;
        case "memory":
          settings.autoRemovePreviousCards = true;
          break;
        case "escaped":
        case "hand":
        case "current_card_resolution":
          break;
        case "brainstorm":
          break;
        case "deck_rural":
        case "deck_urban":
          if (card.face_down !== false) card = this.generateFakeCard(card);
          break;
        default:
          console.log("Unknown/Illegal destination for card movement", destination);
          return;
      }
      if (
        this.deckTopTypes
        && Object.prototype.hasOwnProperty.call(this.deckTopTypes, destination)
      ) {
        this.deckTopTypes[destination] = String(card.type);
      }
      // Move the card to the new location
      await this.getLocation(destination).addCard(card, settings);
      if (special) {
        this.clearSpecialDrawHighlight();
        this.specialDrawHighlightedCardId = card.id;
        document.getElementById(`twd-card-${card.id}`)?.classList.add("twd-highlight");
      }
    },

    clearSpecialDrawHighlight: function () {
      if (this.specialDrawHighlightedCardId === undefined) return;

      document
        .getElementById(`twd-card-${this.specialDrawHighlightedCardId}`)
        ?.classList.remove("twd-highlight");
      this.specialDrawHighlightedCardId = undefined;
    },

    ///////////////////////////////////////////////////
    //// Player's action

    /*
        
            Here, you are defining methods to handle player's action (ex: results of mouse click on 
            game objects).
            
            Most of the time, these methods:
            _ check the action is possible at this game state.
            _ make a call to the game server
        
        */
    setupConnections: function () {
      console.log("Setting up connections");
      dojo.connect(document.getElementById("escaped"), "onclick", this, "onEscapedClick");
      dojo.connect(document.getElementById("memory"), "onclick", this, "onMemoryClick");
      dojo.connect(document.getElementById("graveyard"), "onclick", this, "onGraveyardClick");
      dojo.connect(this.ressourcesSlots, "onCardClick", this, "onRessourceClick");
      dojo.connect(document.getElementById("disasters_bag"), "onclick", this, "onDisasterBagClick");
    },

    onProtagonistSelectionChange: function (selection, lastchange) {
      console.log("onProtagonistSelectionChange", selection, lastchange);
      if (selection[0]) {
        document.getElementById("confirm_protagonist_selection").style.visibility = "visible";
      } else {
        document.getElementById("confirm_protagonist_selection").style.visibility = "hidden";
      }
    },

    // Used only during state of protagonist selection
    // triggered by having a character selected in hand and clicking the confirm button
    confirmProtagonistSelection: function () {
      console.log("confirmProtagonistSelection");
      let card = this.hand.getSelection()[0];
      if (card && card.type == "1") {
        this.bgaPerformAction("actPlayProtagonistCard", { card_id: card.id });
      }
    },

    onZombieEscapeSelectionChange: function (selection) {
      const button = document.getElementById("confirm_escape_zombie");
      if (button) {
        const selectedCardIsZombie = selection[0] && Boolean(parseInt(selection[0].is_zombie));
        button.style.visibility = selectedCardIsZombie ? "visible" : "hidden";
      }
    },

    confirmZombieEscapeChoice: function () {
      const card = this.hand.getSelection()[0];
      if (card && Boolean(parseInt(card.is_zombie))) {
        this.bgaPerformAction("actEscapeZombie", { card_id: card.id });
      }
    },

    onBuryCharacterSelectionChange: function (selection, lastChange) {
      const selectedCard = selection[0];
      const isEligible = selectedCard
        && (this.buryCharacterEligibleCardIds || []).includes(Number(selectedCard.id))
        && Boolean(Number(selectedCard.is_character));
      if (selectedCard && !isEligible) {
        this.hand.unselectCard(lastChange || selectedCard);
      }

      const button = document.getElementById("confirm_bury_character");
      if (button) {
        button.style.visibility = isEligible ? "visible" : "hidden";
      }
    },

    confirmBuryCharacter: function () {
      const card = this.hand.getSelection()[0];
      if (
        card
        && Boolean(Number(card.is_character))
        && (this.buryCharacterEligibleCardIds || []).includes(Number(card.id))
      ) {
        this.bgaPerformAction("actBuryCharacter", { card_id: Number(card.id) });
      }
    },

    prepareBiteChoice: function (args) {
      const characters = args.eligibleCharacters || [];
      this.biteWoundsRequired = Number(args.bite) || 0;
      this.biteWoundsToApply = {};
      this.biteInitialWounds = {};
      this.biteInitialCharacters = {};
      this.biteEligibleCardIds = (args.eligibleCardsIds || []).map(Number);

      characters.forEach((card) => {
        const cardId = Number(card.id);
        this.biteInitialWounds[cardId] = Number(card.wounds) || 0;
        this.biteInitialCharacters[cardId] = {
          ...card,
          face_down: Boolean(card.face_down),
        };
      });

      const availableCapacity = characters.reduce(
        (capacity, card) => capacity + Math.max(
          0,
          (card.card_name === "Robert" ? 3 : 4) - (Number(card.wounds) || 0)
        ),
        0
      );
      this.biteWoundsToAssign = Math.min(this.biteWoundsRequired, availableCapacity);
      this.biteWoundsRemaining = this.biteWoundsToAssign;
    },

    prepareAenorAbility: function (args, stateName) {
      this.aenorAbilityAvailable = Boolean(args.aenorAbilityAvailable);
      this.aenorEligibleCharacterIds = (
        args.aenorEligibleCharacterIds || []
      ).map(Number);
      this.aenorProtectedCharacterId = Number(
        args.aenorProtectedCharacterId
      ) || 0;
      this.aenorSelectingCharacter = false;
      this.aenorDamageState = stateName;
      this.aenorDamageArgs = args;
      this.aenorPendingCharacterId = 0;

      if (this.aenorProtectedCharacterId > 0) {
        this.setAenorCharacterHighlighted(
          this.aenorProtectedCharacterId,
          true
        );
      }
      if (!this.aenorAbilityAvailable) return;

      const protagonist = this.protagonistSlot.getCards?.()[0];
      if (!protagonist || Number(protagonist.type_arg) !== 1) return;
      document
        .getElementById(`twd-card-${protagonist.id}`)
        ?.classList.add("twd-highlight");
      this.protagonistSlot.onCardClick = (card) =>
        this.onAenorProtagonistClick(card);
      if (stateName === "disasterChoice") {
        this.characters.onCardClick = (card) =>
          this.onAenorCharacterClick(card);
      }
    },

    getCharacterWoundsRotation: function (card) {
      return this.getCardRotation(card);
    },

    onAenorProtagonistClick: function (card) {
      if (
        !this.aenorAbilityAvailable
        || this.aenorSelectingCharacter
        || Number(card?.type_arg) !== 1
        || (this.isCurrentPlayerActive && !this.isCurrentPlayerActive())
      ) return;

      this.aenorSelectingCharacter = true;
      document
        .getElementById(`twd-card-${card.id}`)
        ?.classList.remove("twd-highlight");
      this.cardsManager.updateCardInformations({
        ...card,
        aenor_ability_used: true,
      });
      (this.aenorEligibleCharacterIds || []).forEach((cardId) => {
        this.setAenorCharacterHighlighted(cardId, true);
      });
      const confirmButtonId = this.aenorDamageState === "biteChoice"
        ? "confirm_bite_wounds"
        : `confirm_disaster_${this.disasterResolutionPhase === "characteristic"
          ? this.disasterCharacteristic
          : ""}`;
      const confirmButton = document.getElementById(confirmButtonId);
      if (confirmButton) confirmButton.style.visibility = "hidden";
      this.statusBar?.setTitle(
        _("Choose the character Aenor will protect")
      );
      this.removeAenorCancelButton();
      this.statusBar?.addActionButton(
        _("Cancel Aenor ability"),
        () => this.cancelAenorAbility(),
        {
          id: "cancel_aenor_ability",
          color: "secondary",
        }
      );
    },

    cancelAenorAbility: function () {
      if (
        !this.aenorSelectingCharacter
        && !(this.aenorPendingCharacterId > 0)
      ) return;

      this.aenorSelectingCharacter = false;
      this.aenorAbilityAvailable = true;
      this.aenorPendingCharacterId = 0;
      this.aenorProtectedCharacterId = 0;
      const protagonist = this.protagonistSlot.getCards?.()[0];
      if (protagonist) {
        this.cardsManager.updateCardInformations({
          ...protagonist,
          aenor_ability_used: false,
        });
        document
          .getElementById(`twd-card-${protagonist.id}`)
          ?.classList.add("twd-highlight");
      }
      (this.aenorEligibleCharacterIds || []).forEach((cardId) => {
        this.setAenorCharacterHighlighted(cardId, false);
      });
      this.restoreAenorDamageUi();
      this.removeAenorCancelButton();
    },

    restoreAenorDamageUi: function () {
      if (this.aenorDamageState === "biteChoice") {
        this.statusBar?.setTitle(
          _("You must assign ${bite} wound(s) to your characters"),
          { bite: this.biteWoundsRequired }
        );
        const confirmButton = document.getElementById("confirm_bite_wounds");
        if (confirmButton) {
          confirmButton.style.visibility = this.biteWoundsRemaining === 0
            ? "visible"
            : "hidden";
        }
        return;
      }

      const args = this.aenorDamageArgs || {};
      const affectedNames = (args.affectedCharacters || [])
        .map((card) => this.getCardName(card))
        .join(", ");
      this.statusBar?.setTitle(
        _("${characteristic} is present: ${characters} will receive 1 wound"),
        {
          characteristic: args.characteristic,
          characters: affectedNames,
        }
      );
      const confirmButton = document.getElementById(
        `confirm_disaster_${this.disasterCharacteristic}`
      );
      if (confirmButton) confirmButton.style.visibility = "visible";
    },

    removeAenorCancelButton: function () {
      document.getElementById("cancel_aenor_ability")?.remove?.();
    },

    onAenorCharacterClick: function (card) {
      if (!this.aenorSelectingCharacter) return false;

      const cardId = Number(card?.id);
      if (!(this.aenorEligibleCharacterIds || []).includes(cardId)) {
        return true;
      }

      this.aenorSelectingCharacter = false;
      this.aenorAbilityAvailable = false;
      this.aenorPendingCharacterId = cardId;
      this.aenorProtectedCharacterId = cardId;
      (this.aenorEligibleCharacterIds || []).forEach((eligibleCardId) => {
        this.setAenorCharacterHighlighted(
          eligibleCardId,
          eligibleCardId === cardId
        );
      });
      this.restoreAenorDamageUi();
      return true;
    },

    submitPendingAenorAbility: async function () {
      const cardId = Number(this.aenorPendingCharacterId) || 0;
      if (cardId < 1) return;

      this.removeAenorCancelButton();
      await this.bgaPerformAction("actUseAenorAbility", { card_id: cardId });
      this.aenorPendingCharacterId = 0;
    },

    setAenorCharacterHighlighted: function (cardId, highlighted) {
      const cardElement = document.getElementById(`twd-card-${cardId}`);
      if (highlighted) {
        cardElement?.classList.add("twd-aenor-protected");
      } else {
        cardElement?.classList.remove("twd-aenor-protected");
      }
    },

    clearAenorAbilityUi: function () {
      this.protagonistSlot.onCardClick = null;
      if (this.aenorDamageState === "disasterChoice") {
        this.characters.onCardClick = null;
      }
      const protagonist = this.protagonistSlot.getCards?.()[0];
      if (protagonist) {
        document
          .getElementById(`twd-card-${protagonist.id}`)
          ?.classList.remove("twd-highlight");
      }
      (this.aenorEligibleCharacterIds || []).forEach((cardId) => {
        this.setAenorCharacterHighlighted(cardId, false);
      });
      if (this.aenorProtectedCharacterId > 0) {
        this.setAenorCharacterHighlighted(
          this.aenorProtectedCharacterId,
          false
        );
      }
      this.aenorSelectingCharacter = false;
      this.aenorPendingCharacterId = 0;
      this.aenorEligibleCharacterIds = [];
      this.aenorDamageState = null;
      this.aenorDamageArgs = null;
      this.removeAenorCancelButton();
    },

    onBiteCharacterClick: function (card) {
      if (this.onAenorCharacterClick && this.onAenorCharacterClick(card)) return;
      const cardId = Number(card?.id);
      if (
        !card
        || this.biteWoundsRemaining <= 0
        || !this.biteEligibleCardIds.includes(cardId)
        || (this.isCurrentPlayerActive && !this.isCurrentPlayerActive())
      ) return;

      const woundsToApply = this.biteWoundsToApply[cardId] || 0;
      const initialWounds = this.biteInitialWounds[cardId] || 0;
      const woundLimit = card.card_name === "Robert" ? 3 : 4;
      if (initialWounds + woundsToApply >= woundLimit) return;

      this.biteWoundsToApply[cardId] = woundsToApply + 1;
      this.biteWoundsRemaining--;
      const newWounds = initialWounds + woundsToApply + 1;
      this.cardsManager.updateCardInformations({
        ...card,
        wounds: newWounds,
        face_down: newWounds === 4,
      });

      const button = document.getElementById("confirm_bite_wounds");
      if (button) {
        button.style.visibility = this.biteWoundsRemaining === 0 ? "visible" : "hidden";
      }
    },

    resetBiteWounds: function () {
      Object.keys(this.biteWoundsToApply).forEach((cardId) => {
        const initialCard = this.biteInitialCharacters[cardId];
        if (initialCard) {
          this.cardsManager.updateCardInformations(initialCard);
        }
      });

      this.biteWoundsRemaining = this.biteWoundsToAssign;
      this.biteWoundsToApply = {};
      const button = document.getElementById("confirm_bite_wounds");
      if (button) {
        button.style.visibility = "hidden";
      }
    },

    confirmBiteWounds: async function () {
      if (this.biteWoundsRemaining !== 0 || this.aenorSelectingCharacter) return;

      await this.submitPendingAenorAbility();
      this.bgaPerformAction("actApplyBiteWounds", {
        wound_allocations: JSON.stringify(this.biteWoundsToApply),
      });
    },

    prepareHealChoice: function (args) {
      this.healMaximumCharacters = Math.max(0, Number(args.number) || 0);
      this.healEligibleCardIds = (args.eligibleCardsIds || []).map(Number);
      this.healSelectedCardIds = [];
    },

    onHealCharacterClick: function (card) {
      const cardId = Number(card?.id);
      if (
        !card
        || !this.healEligibleCardIds.includes(cardId)
        || (this.isCurrentPlayerActive && !this.isCurrentPlayerActive())
      ) return;

      const selectedIndex = this.healSelectedCardIds.indexOf(cardId);
      if (selectedIndex >= 0) {
        this.healSelectedCardIds.splice(selectedIndex, 1);
        this.setHealCharacterHighlighted(cardId, false);
        return;
      }
      if (this.healSelectedCardIds.length >= this.healMaximumCharacters) return;

      this.healSelectedCardIds.push(cardId);
      this.setHealCharacterHighlighted(cardId, true);
    },

    setHealCharacterHighlighted: function (cardId, highlighted) {
      const cardElement = document.getElementById(`twd-card-${cardId}`);
      cardElement?.classList.toggle("twd-heal-selected", highlighted);
    },

    clearHealSelection: function () {
      (this.healSelectedCardIds || []).forEach((cardId) => {
        this.setHealCharacterHighlighted(cardId, false);
      });
      this.healSelectedCardIds = [];
    },

    confirmHealing: function () {
      this.bgaPerformAction("actHealCharacters", {
        selected_character_ids: JSON.stringify(this.healSelectedCardIds || []),
      });
    },

    prepareDisasterChoice: function (args) {
      this.disasterResolutionPhase = args.phase;
      this.disasterCharacteristic = args.characteristic || null;
      this.disasterResourceAvailable = Boolean(args.resourceAvailable);
      this.disasterResourceId = args.resourceId || null;
      const bag = document.getElementById("disasters_bag");
      if (args.phase === "draw") {
        bag?.classList.add("twd-highlight");
      } else {
        bag?.classList.remove("twd-highlight");
      }
      this.clearDisasterResourceHighlight();
      if (this.disasterResourceAvailable && this.disasterResourceId) {
        document
          .getElementById(`twd-ressource-${this.disasterResourceId}`)
          ?.classList.add("twd-highlight");
      }
    },

    prepareWolfTrapChoice: function (args) {
      this.wolfTrapPhase = args.phase;
      const bag = document.getElementById("disasters_bag");
      if (args.phase === "draw") {
        bag?.classList.add("twd-highlight");
      } else {
        bag?.classList.remove("twd-highlight");
      }
    },

    clearDisasterResourceHighlight: function () {
      ["hunger", "break", "stress"].forEach((characteristic) => {
        document
          .getElementById(`twd-ressource-ressource_${characteristic}`)
          ?.classList.remove("twd-highlight");
      });
    },

    resolveDisaster: function () {
      this.bgaPerformAction("actResolveDisaster");
    },

    confirmDisasterCharacteristic: async function () {
      if (this.aenorSelectingCharacter) return;
      await this.submitPendingAenorAbility();
      this.bgaPerformAction("actConfirmDisasterCharacteristic");
    },

    enableDeckSelection: function (availableDecks, onSelected) {
      const decks = {
        deck_rural: this.ruralDeck,
        deck_urban: this.urbanDeck,
      };

      Object.entries(decks).forEach(([location, stock]) => {
        stock.onSelectionChange = null;
        stock.unselectAll();

        if (!availableDecks.includes(location)) {
          stock.setSelectionMode("none");
          return;
        }

        stock.setSelectionMode("single");
        stock.onSelectionChange = (selection) => {
          if (selection.length === 0) return;

          Object.entries(decks).forEach(([otherLocation, otherStock]) => {
            if (otherLocation === location) return;

            const otherSelectionChange = otherStock.onSelectionChange;
            otherStock.onSelectionChange = null;
            otherStock.unselectAll();
            otherStock.onSelectionChange = otherSelectionChange;
          });
          onSelected(location);
        };
      });
    },

    disableDeckSelection: function () {
      [this.ruralDeck, this.urbanDeck].forEach((stock) => {
        stock.onSelectionChange = null;
        stock.unselectAll();
        stock.setSelectionMode("none");
      });
    },

    onRuralDeckCardClick: function (card) {
      console.log("onRuralDeckCardClick");
      this.bgaPerformAction("actDrawFromDeck", { location: "deck_rural" });
    },

    onUrbanDeckCardClick: function (card) {
      console.log("onUrbanDeckCardClick");
      this.bgaPerformAction("actDrawFromDeck", { location: "deck_urban" });
    },

    onBrainstormRuralDeckClick: function () {
      this.bgaPerformAction("actStartBrainstorm", { location: "deck_rural" });
    },

    onBrainstormUrbanDeckClick: function () {
      this.bgaPerformAction("actStartBrainstorm", { location: "deck_urban" });
    },

    onFastMemoriseRuralDeckClick: function () {
      this.bgaPerformAction("actFastMemorise", { location: "deck_rural" });
    },

    onFastMemoriseUrbanDeckClick: function () {
      this.bgaPerformAction("actFastMemorise", { location: "deck_urban" });
    },

    selectBuryTopCardDeck: function (location) {
      if (!(this.buryTopCardAvailableDecks || []).includes(location)) return;

      this.buryTopCardSelectedDeck = location;
      const button = document.getElementById("confirm_bury_top_card");
      if (button) button.style.visibility = "visible";
    },

    confirmBuryTopCard: function () {
      if (
        this.buryTopCardSelectedDeck
        && (this.buryTopCardAvailableDecks || []).includes(this.buryTopCardSelectedDeck)
      ) {
        this.bgaPerformAction("actBuryTopCard", {
          location: this.buryTopCardSelectedDeck,
        });
      }
    },

    selectAvoidDeck: function (location) {
      if (!(this.avoidDeckAvailableDecks || []).includes(location)) return;

      this.avoidDeckSelectedDeck = location;
      const button = document.getElementById("confirm_avoid_deck");
      if (button) button.style.visibility = "visible";
    },

    confirmAvoidDeck: function () {
      if (
        this.avoidDeckSelectedDeck
        && (this.avoidDeckAvailableDecks || []).includes(this.avoidDeckSelectedDeck)
      ) {
        this.bgaPerformAction("actAvoidDeck", {
          location: this.avoidDeckSelectedDeck,
        });
      }
    },

    onFastMemoriseHandSelectionChange: function (selection, lastChange) {
      const maximumCards = this.fastMemoriseMaximumCards || 2;
      if (selection.length > maximumCards && lastChange) {
        this.hand.unselectCard(lastChange);
        return;
      }

      const button = document.getElementById("confirm_fast_memorise_hand");
      if (button) {
        button.style.visibility = "visible";
      }
    },

    confirmFastMemoriseFromHand: function () {
      const selectedCardIds = this.hand.getSelection().map((card) => Number(card.id));
      if (selectedCardIds.length > 2) {
        return;
      }
      this.bgaPerformAction("actFastMemoriseFromHand", {
        card_ids: JSON.stringify(selectedCardIds),
      });
    },

    onAvoidHandSelectionChange: function (selection, lastChange) {
      const maximumCards = this.avoidHandMaximumCards || 2;
      if (selection.length > maximumCards && lastChange) {
        this.hand.unselectCard(lastChange);
        return;
      }

      const button = document.getElementById("confirm_avoid_hand");
      if (button) {
        button.style.visibility = "visible";
      }
    },

    confirmAvoidFromHand: function () {
      const selectedCardIds = this.hand.getSelection().map((card) => Number(card.id));
      if (selectedCardIds.length > 2) {
        return;
      }
      this.bgaPerformAction("actAvoidFromHand", {
        card_ids: JSON.stringify(selectedCardIds),
      });
    },

    prepareBrainstormReorder: async function (cards) {
      document.getElementById("brainstorm_wrap").style.display = "block";
      document.getElementById("brainstorm_label").textContent = _("Brainstorm");
      document.getElementById("brainstorm_help").textContent = _(
        "Drag the cards to reorder them. The left card will be on top."
      );
      for (const card of cards) {
        await this.brainstorm.addCard(card);
      }

      const container = document.getElementById("brainstorm");
      container.querySelectorAll(".twd-card").forEach((cardElement) => {
        cardElement.draggable = true;
        cardElement.ondragstart = () => {
          this.brainstormDraggedCardId = cardElement.id;
          cardElement.classList.add("twd-brainstorm-dragging");
        };
        cardElement.ondragend = () => {
          this.brainstormDraggedCardId = null;
          cardElement.classList.remove("twd-brainstorm-dragging");
        };
        cardElement.ondragover = (event) => {
          event.preventDefault();
          const dragged = document.getElementById(this.brainstormDraggedCardId);
          if (!dragged || dragged === cardElement) return;

          const bounds = cardElement.getBoundingClientRect();
          const insertAfter = event.clientX > bounds.left + bounds.width / 2;
          container.insertBefore(
            dragged,
            insertAfter ? cardElement.nextSibling : cardElement
          );
        };
      });
    },

    disableBrainstormReorder: function () {
      document.querySelectorAll("#brainstorm .twd-card").forEach((cardElement) => {
        cardElement.draggable = false;
        cardElement.ondragstart = null;
        cardElement.ondragend = null;
        cardElement.ondragover = null;
      });
      this.brainstormDraggedCardId = null;
    },

    prepareUnrememberChoice: async function (cards, maximumCards) {
      document.getElementById("brainstorm_wrap").style.display = "block";
      document.getElementById("brainstorm_label").textContent = _("Unremember");
      document.getElementById("brainstorm_help").textContent = _(
        "Choose up to two cards to recover into your hand."
      );
      for (const card of cards) {
        await this.brainstorm.addCard(card);
      }

      this.unrememberMaximumCards = maximumCards;
      this.brainstorm.setSelectionMode("multiple");
      this.brainstorm.onSelectionChange = (selection, lastChange) =>
        this.onUnrememberSelectionChange(selection, lastChange);
    },

    onUnrememberSelectionChange: function (selection, lastChange) {
      if (
        selection.length > (this.unrememberMaximumCards || 0)
        && lastChange
      ) {
        this.brainstorm.unselectCard(lastChange);
      }
    },

    disableUnrememberChoice: function () {
      this.brainstorm.onSelectionChange = null;
      this.brainstorm.unselectAll();
      this.brainstorm.setSelectionMode("none");
      this.unrememberMaximumCards = 0;
    },

    confirmUnremember: function () {
      const selectedCardIds = this.brainstorm
        .getSelection()
        .map((card) => Number(card.id));
      if (selectedCardIds.length > (this.unrememberMaximumCards || 0)) {
        return;
      }
      this.bgaPerformAction("actConfirmUnremember", {
        card_ids: JSON.stringify(selectedCardIds),
      });
    },

    confirmBrainstorm: function () {
      const orderedCardIds = this.getBrainstormCardIds(
        document.querySelectorAll("#brainstorm .twd-card")
      );
      if (orderedCardIds.length > 0) {
        this.bgaPerformAction("actConfirmBrainstorm", {
          card_ids: JSON.stringify(orderedCardIds),
        });
      }
    },

    getBrainstormCardIds: function (cardElements) {
      return Array.from(cardElements).map((cardElement) =>
        parseInt(cardElement.id.replace("twd-card-", ""))
      );
    },

    onEscapedClick: function () {
      console.log("onEscapedClick");
      if (this.recoverChoiceActive) {
        return;
      }
      let card = this.hand.getSelection()[0];
      if (card && this.canCardBePlayedInLocation(card, "escaped")) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "escaped",
        });
      }
    },

    onRecoverCardClick: function (card) {
      if (
        this.recoverChoiceActive
        && this.recoverEligibleCardIds.includes(Number(card.id))
      ) {
        this.bgaPerformAction("actRecoverCard", { card_id: card.id });
      }
    },
    onMemoryClick: function () {
      console.log("onMemoryClick");
      if (this.zombieEscapeChoiceActive) {
        if (this.escapeTallaMemoryAvailable) {
          this.bgaPerformAction("actEscapeTallaFromMemory");
        }
        return;
      }

      let card = this.hand.getSelection()[0];
      if (card && this.canCardBePlayedInLocation(card, "memory")) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "memory",
        });
      }
    },
    onGraveyardClick: function () {
      console.log("onGraveyardClick");
      let card = this.hand.getSelection()[0];
      if (card && this.canCardBePlayedInLocation(card, "graveyard")) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "graveyard",
        });
      }
    },

    onRessourceClick: function (token) {
      console.log("onRessourceClick", token);
      if (this.disasterResolutionPhase === "characteristic") {
        if (this.disasterResourceAvailable && token.id === this.disasterResourceId) {
          this.bgaPerformAction("actUseDisasterResource", { token_id: token.id });
        }
        return;
      }
      if (
        Number(this.gamePhase) === 1
        && Number(this.difficulty) === 2
        && Number(token.consumed) === 0
      ) {
        this.bgaPerformAction("actUseBorisResource", { token_id: token.id });
        return;
      }
    },

    updateBorisResourceClickability: function (token, element = null) {
      const resourceElement = element
        || document.getElementById(`twd-ressource-${token.id}`);
      if (!resourceElement) return;
      resourceElement.classList.toggle(
        "twd-boris-resource-available",
        Number(this.gamePhase) === 1
          && Number(this.difficulty) === 2
          && Number(token.consumed) === 0
      );
    },

    updateAllBorisResourceClickability: function () {
      for (const token of this.ressourcesSlots.getCards()) {
        this.updateBorisResourceClickability(token);
      }
    },

    // Route bag clicks to the action owned by the active disaster state.
    onDisasterBagClick: function () {
      console.log("onDisasterBagClick");
      if (this.draftDisasterPhase === "draw") {
        this.bgaPerformAction("actDrawDraftDisasters");
      } else if (this.wolfTrapPhase === "draw") {
        this.bgaPerformAction("actDrawWolfTrapDisaster");
      } else if (this.disasterResolutionPhase === "draw") {
        this.bgaPerformAction("actDrawDisaster");
      }
    },

    onDraftDisasterClick: function (disaster) {
      const disasterId = Number(disaster?.id);
      if (!(this.draftDisasterEligibleIds || []).includes(disasterId)) return;

      (this.draftDisasterEligibleIds || []).forEach((id) => {
        document.getElementById(`token-${id}`)?.classList.remove("twd-highlight");
      });
      this.draftDisasterSelectedId = disasterId;
      document.getElementById(`token-${disasterId}`)?.classList.add("twd-highlight");
      const button = document.getElementById("confirm_draft_disaster");
      if (button) button.style.visibility = "visible";
    },

    confirmDraftDisaster: function () {
      if ((this.draftDisasterEligibleIds || []).includes(this.draftDisasterSelectedId)) {
        this.bgaPerformAction("actResolveDraftDisaster", {
          disaster_id: this.draftDisasterSelectedId,
        });
      }
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications
    setupNotifications: function () {
      console.log("notifications subscriptions setup");
      // automatically listen to the notifications, based on the `notif_xxx` function on this class.
      this.bgaSetupPromiseNotifications();
    },

    notif_protagonistCardPlayed: async function (args) {
      console.log("notif_protagonistCardPlayed");
      console.log(args);
      let card = args.card;
      if (card) {
        await this.protagonistSlot.addCard(card, { fromStock: this.hand });
        await this.hand.removeAll();
        this.difficulty = Number(args.difficulty);
        this.lossCondition = args.lossCondition;
        this.updateAllBorisResourceClickability();
      }
    },
    notif_borisDecksMerged: async function (args) {
      const urbanTopCard = this.urbanDeck.getTopCard();
      if (urbanTopCard) {
        await this.ruralDeck.addCard(urbanTopCard, {
          fromStock: this.urbanDeck,
          initialSide: "back",
          finalSide: "back",
        });
      }
      await Promise.all([
        this.setDeckState("deck_rural", args.ruralDeckNb, args.ruralDeckTop),
        this.setDeckState("deck_urban", args.urbanDeckNb, args.urbanDeckTop),
      ]);
    },
    notif_borisDeckShuffled: async function (args) {
      await this.ruralDeck.shuffle();
      await this.setDeckState(
        "deck_rural",
        args.ruralDeckNb,
        args.ruralDeckTop
      );
    },
    notif_borisDecksRedistributed: async function (args) {
      const ruralTopCard = this.ruralDeck.getTopCard();
      if (ruralTopCard) {
        await this.urbanDeck.addCard(ruralTopCard, {
          fromStock: this.ruralDeck,
          initialSide: "back",
          finalSide: "back",
        });
      }
      await Promise.all([
        this.setDeckState("deck_rural", args.ruralDeckNb, args.ruralDeckTop),
        this.setDeckState("deck_urban", args.urbanDeckNb, args.urbanDeckTop),
      ]);
    },
    notif_aenorAbilityUsed: function (args) {
      if (args.protagonist) {
        this.cardsManager.updateCardInformations({
          ...args.protagonist,
          aenor_ability_used: true,
        });
      }
      if (args.character) {
        this.setAenorCharacterHighlighted(Number(args.character.id), true);
      }
    },
    notif_aenorWoundPrevented: function (args) {
      if (args.character) {
        this.cardsManager.updateCardInformations(args.character);
        this.setAenorCharacterHighlighted(Number(args.character.id), false);
      }
      this.aenorProtectedCharacterId = 0;
    },
    notif_aenorProtectionExpired: function (args) {
      const characterId = Number(
        args.character?.id || this.aenorProtectedCharacterId
      );
      if (characterId > 0) {
        this.setAenorCharacterHighlighted(characterId, false);
      }
      this.aenorProtectedCharacterId = 0;
    },
    notif_cardDrawn: async function (args) {
      console.log("notif_cardDrawn");
      console.log(args);
      let card = args.card;
      let source = this.getLocation(args.source);
      let destination = this.getLocation(args.destination);
      await destination.addCard(card, { fromStock: source });
    },
    notif_cardResolutionStarted: async function (args) {
      if (!args.card) return;
      if (!this.resolvingCardIds) this.resolvingCardIds = new Set();
      this.resolvingCardIds.add(Number(args.card.id));
      const resolutionOrganiser = document.getElementById(
        "card_resolution_organiser"
      );
      if (resolutionOrganiser) resolutionOrganiser.style.display = "block";
      await this.currentCardResolution.addCard(args.card, {
        fromStock: this.hand,
      });
    },
    notif_storyCheckStarted: async function (args) {
      console.log("notif_storyCheckStarted");
      console.log(args);
      this.setGamePhaseDisplay(2);
      if (args.memoryTopCard) {
        this.deckTopTypes.memory = String(args.memoryTopCard.type);
        await this.memory.addCard(args.memoryTopCard, {
          fadeIn: true,
          autoupdateCardNumber: false,
          autoRemovePreviousCards: true,
        });
      }
    },
    notif_storyCardResolved: async function (args) {
      await this.currentCardResolution.removeCard(args.card);
    },
    notif_storyCardRevealed: async function (args) {
      await this.currentCardResolution.addCard(args.card, {
        fromStock: this.memory,
      });
      await this.setDeckState("memory", args.memoryNb, args.memoryTopCard);
    },
    notif_ressourceFlipped: function (args) {
      console.log("notif_ressourceFlipped");
      console.log(args);
      let token = args.token;
      this.ressourcesSlots.flipCard(token);
      this.updateBorisResourceClickability(token);
    },
    notif_disasterDrawnFromBag: async function (args) {
      console.log("notif_disasterDrawnFromBag");
      console.log(args);
      let disaster = args.disaster;
      let shuffle = args.shuffle;
      if (shuffle) {
        await this.disastersDrawnSlot.removeAll({ slideTo: document.getElementById("disasters_bag") });
      }
      if (disaster) {
        await this.disastersDrawnSlot.addCard(disaster, { fromElement: document.getElementById("disasters_bag") });
      }
    },
    notif_disastersResolved: async function (args) {
      for (const disaster of args.removedDisasters || []) {
        await this.disastersDrawnSlot.removeCard(disaster);
      }
      for (const disaster of args.returnedDisasters || []) {
        await this.disastersDrawnSlot.removeCard(disaster, {
          slideTo: document.getElementById("disasters_bag"),
        });
      }
    },
    notif_emptyDisastersAdded: function (args) {
      console.log("notif_emptyDisastersAdded");
      console.log(args);
      this.disastersReserve.removeAll({
        slideTo: document.getElementById("disasters_bag"),
      });
    },
    notif_characterPutInPlay: async function (args) {
      console.log("notif_characterPutInPlay");
      console.log(args);
      let card = args.card;
      if (card) {
        await this.characters.addCard(card, {
          fromStock: this.currentCardResolution,
        });
      }
    },
    notif_characterWoundsChanged: function (args) {
      console.log("notif_characterWoundsChanged", args);
      if (args.card) {
        this.cardsManager.updateCardInformations(args.card);
      }
    },
    notif_ressourceConsumed: function (args) {
      console.log("notif_ressourceConsumed");
      console.log(args);
      let token = args;
      this.ressourcesManager.flipCard(token);
      this.updateBorisResourceClickability(token);
    },
    notif_ressourceRefilled: function (args) {
      console.log("notif_ressourceRefilled");
      console.log(args);
      let token = args;
      this.ressourcesManager.flipCard(token);
      this.updateBorisResourceClickability(token);
    },
    notif_cardMoved: async function (args) {
      console.log("notif_cardMoved");
      console.log(args);
      if (
        !args.special
        && args.destination === "hand"
        && ["deck_rural", "deck_urban"].includes(args.source)
      ) {
        this.clearSpecialDrawHighlight();
      }
      if (args.destination === "brainstorm") {
        document.getElementById("brainstorm_wrap").style.display = "block";
      }
      const cardId = Number(args.card?.id);
      const wasResolving = Boolean(
        this.resolvingCardIds && this.resolvingCardIds.has(cardId)
      );
      await this.moveCardToLocation(
        args.card,
        args.destination,
        wasResolving ? "current_card_resolution" : args.source,
        args.special || false
      );
      if (wasResolving) {
        this.resolvingCardIds.delete(cardId);
        if (Number(this.gamePhase) !== 2 && this.resolvingCardIds.size === 0) {
          const resolutionOrganiser = document.getElementById(
            "card_resolution_organiser"
          );
          if (resolutionOrganiser) resolutionOrganiser.style.display = "none";
        }
      }
      if (args.sourceDeckNb !== undefined) {
        await this.setDeckState(
          args.source,
          args.sourceDeckNb,
          args.sourceDeckTop || null
        );
      }
      if (args.source === "graveyard" && args.graveyardNb !== undefined) {
        const graveyardTop = args.graveyardTop || null;
        await this.setDeckState("graveyard", args.graveyardNb, graveyardTop);
      }
      if (args.source === "memory" && args.memoryNb !== undefined) {
        await this.setDeckState(
          "memory",
          args.memoryNb,
          args.memoryTopCard || null
        );
      }
    },

    notif_deckTopRevealed: async function (args) {
      const deck = this.getLocation(args.location);
      if (!deck) return;
      await this.setDeckState(args.location, args.cardNumber, args.card);
    },
  });
});
