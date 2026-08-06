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
      this.lossCondition = 5; //default value
      this.stateConnectors = [];
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
            <div id="story_organiser">
              <div id="story_current_wrap" class="characters-slot-wrap">
                <b>${_("Current Story Card")}</b>
                <div id="story_current"></div>
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
        `<div id="hand_wrap" class="whiteblock">
            <b id="hand_label">${_("My hand")}</b>
            <div id="hand"></div>
          </div>`
      );
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `<div id="brainstorm_wrap" class="whiteblock">
            <b>${_("Brainstorm")}</b>
            <div class="brainstorm-help">${_("Drag the cards to reorder them. The left card will be on top.")}</div>
            <div id="brainstorm"></div>
          </div>`
      );

      // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
      this.animationManager = new BgaAnimations.Manager({
        animationsActive: () => this.bgaAnimationsActive(),
      });

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
          switch (card.type) {
            case "1":
              div.style.backgroundPositionY = `100%`;
              div.style.backgroundPositionX = `${((card.type_arg - 1) * 100) / 17}%`;
              break;
            case "2":
            case "3":
              div.style.backgroundPositionY = `${((card.type - 2) * 100) / 2}%`;
              div.style.backgroundPositionX = `${((card.type_arg - 1) * 100) / 17}%`;
              break;
            default:
              div.style.backgroundPosition = "-508px -358px";
          }
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
        getCardRotation: (card) => this.getCharacterWoundsRotation(card),
        cardWidth: 127,
        cardHeight: 179,
      });

      // create decks
      this.ruralDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck_rural"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => {
          // Generate a fake card based on the original card
          return {
            id: `rural-top-card`,
            type: `4`, // fake rural
            location: `deck_rural`,
          };
        },
      });
      this.urbanDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck_urban"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => {
          // Generate a fake card based on the original card
          return {
            id: `urban-top-card`,
            type: `5`, // fake urban
            location: `deck_urban`,
          };
        },
      });
      // Create protagonist slot
      this.protagonistSlot = new BgaCards.SlotStock(this.cardsManager, document.getElementById("protagonist_slot"), {
        slotsIds: ["A"],
        slotClasses: ["twd-card-slot"],
        mapCardToSlot: (card) => "A",
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
      });
      // TEST remove
      // this.graveyard.setSelectionMode("single");
      // create escaped pile
      this.escaped = new BgaCards.AllVisibleDeck(this.cardsManager, document.getElementById("escaped"), {
        // TEST remove
        // cardClickEventFilter: "all",
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
      this.storyCurrent = new BgaCards.SlotStock(
        this.cardsManager,
        document.getElementById("story_current"),
        {
          slotsIds: ["current"],
          slotClasses: ["twd-card-slot"],
          mapCardToSlot: () => "current",
        }
      );

      // Set up game interface, according to "gamedatas"
      console.log("gamedatas", this.gamedatas);
      this.difficulty = this.gamedatas.difficultyLevel;
      this.gamePhase = this.gamedatas.gamePhase;
      this.setHandVisible(Number(this.gamePhase) !== 2);
      let charactersVisibility = "none";
      if (this.gamePhase === "2") {
        // Display characters area (phase 2)
        charactersVisibility = "block";
      }
      document.getElementById("story_organiser").style.display = charactersVisibility;
      // Hand gamedatas
      for (var i in this.gamedatas.hand) this.hand.addCard(this.gamedatas.hand[i]);
      // Protagonist slot gamedatas
      let protagonistDatas = this.gamedatas.protagonistSlot;
      if (Object.keys(protagonistDatas).length > 1) console.log("Protagonist slot contains multiple cards");
      if (Object.keys(protagonistDatas).length > 0) {
        this.protagonistSlot.addCard(protagonistDatas[Object.keys(protagonistDatas)[0]]);
      } else {
        console.log("Protagonist slot is empty");
      }
      // Urban Deck gamedatas
      this.urbanDeck.setCardNumber(this.gamedatas.urbanDeckNb);
      // Rural Deck gamedatas
      this.ruralDeck.setCardNumber(this.gamedatas.ruralDeckNb);
      // Memory gamedatas
      console.log("Memory gamedatas", this.gamedatas.memoryNb, this.gamedatas.memoryTop);
      if (this.gamedatas.memoryNb > 0) {
        this.memory.setCardNumber(this.gamedatas.memoryNb - 1);
        this.memory.addCard(this.gamedatas.memoryTop);
      }
      // Graveyard gamedatas
      this.graveyard.setCardNumber(this.gamedatas.graveyardNb);
      console.log("Graveyard gamedatas", this.gamedatas.graveyardNb, this.gamedatas.graveyardTop);
      if (this.gamedatas.graveyardTop) {
        this.graveyard.setCardNumber(this.gamedatas.graveyardNb - 1);
        this.graveyard.addCard(this.gamedatas.graveyardTop);
      }
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
      if (this.gamedatas.storyCurrent) {
        this.storyCurrent.addCard(this.gamedatas.storyCurrent);
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
          this.biteChoiceConnector = dojo.connect(
            this.characters,
            "onCardClick",
            this,
            "onBiteCharacterClick"
          );
          break;
        case "disasterChoice":
          this.prepareDisasterChoice(args.args || args);
          break;
        case "drawCards":
        case "specialDraw":
          this.stateConnectors.push(dojo.connect(this.urbanDeck, "onCardClick", this, "onUrbanDeckCardClick"));
          this.stateConnectors.push(dojo.connect(this.ruralDeck, "onCardClick", this, "onRuralDeckCardClick"));
          break;
        case "brainstormDeckChoice":
          const brainstormChoiceArgs = args.args || args;
          this.brainstormAvailableDecks = brainstormChoiceArgs.availableDecks || [];
          if (this.brainstormAvailableDecks.includes("deck_urban")) {
            document.getElementById("deck_urban").classList.add("twd-highlight");
            this.stateConnectors.push(
              dojo.connect(this.urbanDeck, "onCardClick", this, "onBrainstormUrbanDeckClick")
            );
          }
          if (this.brainstormAvailableDecks.includes("deck_rural")) {
            document.getElementById("deck_rural").classList.add("twd-highlight");
            this.stateConnectors.push(
              dojo.connect(this.ruralDeck, "onCardClick", this, "onBrainstormRuralDeckClick")
            );
          }
          break;
        case "brainstormReorder":
          this.setHandVisible(false);
          document.getElementById("brainstorm_wrap").style.display = "block";
          this.prepareBrainstormReorder((args.args || args).cards || []);
          break;
        case "playCards":
          if (this.hand.getCardCount() === 1) {
            document.getElementById("refill_hand_button").style.visibility = "visible";
          } else {
            document.getElementById("refill_hand_button").style.visibility = "hidden";
          }
          break;
        case "storyCheck":
          document.getElementById("story_organiser").style.display = "block";
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
          if (this.biteChoiceConnector) {
            dojo.disconnect(this.biteChoiceConnector);
            this.biteChoiceConnector = null;
          }
          break;
        case "disasterChoice":
          this.disasterResolutionPhase = null;
          document.getElementById("disasters_bag")?.classList.remove("twd-highlight");
          break;
        case "drawCards":
        case "specialDraw":
          this.stateConnectors.forEach((conn) => dojo.disconnect(conn));
          this.stateConnectors = [];
          break;
        case "brainstormDeckChoice":
          this.stateConnectors.forEach((conn) => dojo.disconnect(conn));
          this.stateConnectors = [];
          document.getElementById("deck_urban").classList.remove("twd-highlight");
          document.getElementById("deck_rural").classList.remove("twd-highlight");
          break;
        case "brainstormReorder":
          this.disableBrainstormReorder();
          document.getElementById("brainstorm_wrap").style.display = "none";
          this.setHandVisible(true);
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
            // TEST remove after tests
            this.statusBar.addActionButton(
              _("Story Check"),
              () => this.bgaPerformAction("actGoToStoryCheck", { force: true }),
              { color: "secondary" }
            );
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
          case "disasterChoice": {
            const disasterArgs = args.args || args;
            if (disasterArgs.phase === "draw") {
              this.statusBar.setTitle(
                _("Draw disaster ${current} of ${total} by clicking the disaster bag"),
                {
                  current: Number(disasterArgs.confirmedDraws) + 1,
                  total: disasterArgs.requiredDraws,
                }
              );
            } else if (disasterArgs.phase === "confirmDraw") {
              this.statusBar.setTitle(
                _("Confirm disaster ${current} of ${total}"),
                {
                  current: Number(disasterArgs.confirmedDraws) + 1,
                  total: disasterArgs.requiredDraws,
                }
              );
              this.statusBar.addActionButton(
                _("Confirm draw"),
                () => this.bgaPerformAction("actConfirmDisasterDraw"),
                { id: "confirm_disaster_draw", color: "primary" }
              );
            } else if (disasterArgs.phase === "characteristic") {
              const characteristic = disasterArgs.characteristic;
              const affectedNames = (disasterArgs.affectedCharacters || [])
                .map((card) => card.card_name)
                .join(", ");
              if (!disasterArgs.characteristicPresent) {
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
                () => this.bgaPerformAction("actConfirmDisasterCharacteristic"),
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
          case "brainstormReorder":
            this.statusBar.addActionButton(_("Confirm order"), () => this.confirmBrainstorm(), {
              id: "confirm_brainstorm",
              color: "primary",
            });
            break;
          case "storyCheckPlayerChoice":
            const storyArgs = args.args || args;
            const storyCardName = storyArgs.currentCard?.card_name || _("the current card");
            let storyTitle;
            switch (storyArgs.pendingAction) {
              case "applyGreyConsequence":
                storyTitle = _("The grey consequence of ${card_name} will be applied");
                break;
              case "placeCharacter":
                storyTitle = _("${card_name} will be placed in the Characters area");
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
        case "story_current":
          return this.storyCurrent;
        case "characters":
          return this.characters;
        default:
          console.log("Unknown location: " + location);
          return null;
      }
    },

    getCharacterWoundsRotation: function (card) {
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
        case "story_current":
          break;
        case "brainstorm":
          break;
        case "deck_rural":
        case "deck_urban":
          card = this.generateFakeCard(card);
          break;
        default:
          console.log("Unknown/Illegal destination for card movement", destination);
          return;
      }
      // Move the card to the new location
      await this.getLocation(destination).addCard(card, settings);
      if (special) {
        document.getElementById(`twd-card-${card.id}`).classList.add("twd-highlight");
      }
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
      dojo.connect(document.getElementById("game_play_area"), "onclick", this, "dispellHighlight");
      dojo.connect(document.getElementById("escaped"), "onclick", this, "onEscapedClick");
      dojo.connect(document.getElementById("memory"), "onclick", this, "onMemoryClick");
      dojo.connect(document.getElementById("graveyard"), "onclick", this, "onGraveyardClick");
      dojo.connect(this.ressourcesSlots, "onCardClick", this, "onRessourceClick");
      dojo.connect(document.getElementById("disasters_bag"), "onclick", this, "onDisasterBagClick");
    },

    dispellHighlight: function () {
      document.querySelectorAll(".twd-highlight").forEach((el) => {
        el.classList.remove("twd-highlight");
      });
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
        (capacity, card) => capacity + Math.max(0, 4 - (Number(card.wounds) || 0)),
        0
      );
      this.biteWoundsToAssign = Math.min(this.biteWoundsRequired, availableCapacity);
      this.biteWoundsRemaining = this.biteWoundsToAssign;
    },

    onBiteCharacterClick: function (card) {
      const cardId = Number(card?.id);
      if (
        !card
        || this.biteWoundsRemaining <= 0
        || !this.biteEligibleCardIds.includes(cardId)
        || (this.isCurrentPlayerActive && !this.isCurrentPlayerActive())
      ) return;

      const woundsToApply = this.biteWoundsToApply[cardId] || 0;
      const initialWounds = this.biteInitialWounds[cardId] || 0;
      if (initialWounds + woundsToApply >= 4) return;

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

    confirmBiteWounds: function () {
      if (this.biteWoundsRemaining !== 0) return;

      this.bgaPerformAction("actApplyBiteWounds", {
        wound_allocations: JSON.stringify(this.biteWoundsToApply),
      });
    },

    prepareDisasterChoice: function (args) {
      this.disasterResolutionPhase = args.phase;
      const bag = document.getElementById("disasters_bag");
      if (args.phase === "draw") {
        bag?.classList.add("twd-highlight");
      } else {
        bag?.classList.remove("twd-highlight");
      }
    },

    resolveDisaster: function () {
      this.bgaPerformAction("actResolveDisaster");
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

    prepareBrainstormReorder: async function (cards) {
      document.getElementById("brainstorm_wrap").style.display = "block";
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
      let card = this.hand.getSelection()[0];
      if (card && this.canCardBePlayedInLocation(card, "escaped")) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "escaped",
        });
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
      this.bgaPerformAction("actFlipRessource", { token_id: token.id });
    },

    // PHASE2 allowing only in phase 2
    onDisasterBagClick: function () {
      console.log("onDisasterBagClick");
      if (this.disasterResolutionPhase === "draw") {
        this.bgaPerformAction("actDrawDisaster");
      } else if (!this.disasterResolutionPhase && this.gamePhase == 2) {
        this.bgaPerformAction("actDrawFromDisasterBag");
      }
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications
    setupNotifications: function () {
      console.log("notifications subscriptions setup");
      // automatically listen to the notifications, based on the `notif_xxx` function on this class.
      this.bgaSetupPromiseNotifications();
    },

    notif_protagonistCardPlayed: function (args) {
      console.log("notif_protagonistCardPlayed");
      console.log(args);
      let card = args.card;
      if (card) {
        this.protagonistSlot.addCard(card, { fromStock: this.hand });
        this.hand.removeAll();
        this.lossCondition = args.lossCondition;
      }
    },
    notif_cardDrawn: async function (args) {
      console.log("notif_cardDrawn");
      console.log(args);
      let card = args.card;
      let source = this.getLocation(args.source);
      let destination = this.getLocation(args.destination);
      await destination.addCard(card, { fromStock: source });
    },
    notif_storyCheckStarted: async function (args) {
      console.log("notif_storyCheckStarted");
      console.log(args);
      this.gamePhase = 2;
      this.setHandVisible(false);
      document.getElementById("story_organiser").style.display = "block";
      if (args.memoryTopCard) {
        await this.memory.addCard(args.memoryTopCard, {
          fadeIn: true,
          autoupdateCardNumber: false,
          autoRemovePreviousCards: true,
        });
      }
    },
    notif_storyCardResolved: async function (args) {
      await this.storyCurrent.removeCard(args.card);
    },
    notif_storyCardRevealed: async function (args) {
      await this.storyCurrent.addCard(args.card, { fromStock: this.memory });
      this.memory.setCardNumber(args.memoryNb);
      if (args.memoryTopCard) {
        await this.memory.addCard(args.memoryTopCard, {
          autoupdateCardNumber: false,
          autoRemovePreviousCards: true,
        });
      } else {
        this.memory.removeAll();
        this.memory.setCardNumber(0);
      }
    },
    notif_ressourceFlipped: function (args) {
      console.log("notif_ressourceFlipped");
      console.log(args);
      let token = args.token;
      this.ressourcesSlots.flipCard(token);
    },
    notif_disasterDrawnFromBag: function (args) {
      console.log("notif_disasterDrawnFromBag");
      console.log(args);
      let disaster = args.disaster;
      let shuffle = args.shuffle;
      if (shuffle) {
        this.disastersDrawnSlot.removeAll({ slideTo: document.getElementById("disasters_bag") });
      }
      if (disaster) {
        this.disastersDrawnSlot.addCard(disaster, { fromElement: document.getElementById("disasters_bag") });
      }
    },
    // PHASE2 Remove
    notif_disasterShuffledBack: function (args) {
      console.log("notif_disasterShuffledBack");
      console.log(args);
      this.disastersDrawnSlot.removeAll({ slideTo: document.getElementById("disasters_bag") });
    },
    notif_characterPutInPlay: async function (args) {
      console.log("notif_characterPutInPlay");
      console.log(args);
      let card = args.card;
      if (card) {
        await this.characters.addCard(card, { fromStock: this.storyCurrent });
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
    },
    notif_ressourceRefilled: function (args) {
      console.log("notif_ressourceRefilled");
      console.log(args);
      let token = args;
      this.ressourcesManager.flipCard(token);
    },
    notif_cardMoved: async function (args) {
      console.log("notif_cardMoved");
      console.log(args);
      if (args.destination === "brainstorm") {
        document.getElementById("brainstorm_wrap").style.display = "block";
      }
      await this.moveCardToLocation(args.card, args.destination, args.source, args.special || false);
    },
  });
});
